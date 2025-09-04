<?php

namespace Cm\RateLimiter\TokenBucket;

use Cm\RateLimiter\RateLimiterInterface;
use Cm\RateLimiter\RateLimiterResult;
use Credis_Client;

/**
 * Token Bucket implementation without Lua scripts for performance comparison
 */
class RateLimiterNonLua implements RateLimiterInterface
{
    protected array $limiters = [];
    protected Credis_Client $redis;

    public function __construct(Credis_Client $redis)
    {
        $this->redis = $redis;
    }

    public function for(string $name, \Closure $callback): RateLimiterInterface
    {
        $this->limiters[$name] = $callback;
        return $this;
    }

    public function limiter(string $name): ?\Closure
    {
        return $this->limiters[$name] ?? null;
    }

    public function attempt(string $key, int $maxAttempts, int $decay = 60): RateLimiterResult
    {
        if ($this->tooManyAttempts($key, $maxAttempts, $decay)) {
            return new RateLimiterResult($this->availableIn($key, $maxAttempts, $decay), 0, $maxAttempts);
        }

        $redisKey = $this->getKeyWithPrefix($key);
        $currentTime = microtime(true);
        
        // Use Redis transaction with WATCH for race condition safety
        $maxRetries = 5;
        for ($i = 0; $i < $maxRetries; $i++) {
            $this->redis->watch($redisKey);
            
            // Get current state
            $bucket = $this->redis->hmget($redisKey, ['tokens', 'last_refill', 'attempts', 'window_start']);
            $tokens = isset($bucket['tokens']) ? (float)$bucket['tokens'] : (float)$maxAttempts;
            $lastRefill = isset($bucket['last_refill']) ? (float)$bucket['last_refill'] : $currentTime;
            $attempts = isset($bucket['attempts']) ? (int)$bucket['attempts'] : 0;
            $windowStart = isset($bucket['window_start']) ? (float)$bucket['window_start'] : $currentTime;
            
            // Calculate token refill
            $timePassed = $currentTime - $lastRefill;
            $tokensToAdd = floor($timePassed * $maxAttempts / $decay);
            $newTokens = min($maxAttempts, $tokens + $tokensToAdd);
            
            // Reset attempts if window elapsed
            if ($timePassed >= $decay) {
                $attempts = 0;
                $windowStart = $currentTime;
            }
            
            if ($newTokens < 1) {
                $this->redis->unwatch();
                $timeUntilToken = ceil($decay / $maxAttempts);
                return new RateLimiterResult($timeUntilToken, 0, $maxAttempts);
            }
            
            // Consume token and increment attempts
            $newTokens -= 1;
            $attempts += 1;
            
            // Atomic update using MULTI/EXEC
            $this->redis->multi();
            $this->redis->hmset($redisKey, [
                'tokens' => $newTokens,
                'last_refill' => $currentTime,
                'attempts' => $attempts,
                'window_start' => $windowStart
            ]);
            $this->redis->expire($redisKey, $decay * 2);
            $result = $this->redis->exec();
            
            if ($result !== false) {
                // Transaction succeeded
                $retriesLeft = $maxAttempts - $attempts;
                return new RateLimiterResult(0, $retriesLeft, $maxAttempts);
            }
            
            // Transaction failed due to WATCH, retry
        }
        
        // If we get here, we exceeded max retries
        return new RateLimiterResult(1, 0, $maxAttempts);
    }

    public function tooManyAttempts(string $key, int $maxAttempts, int $decay = 60): bool
    {
        return $this->attempts($key, $decay) >= $maxAttempts;
    }

    public function attempts(string $key, int $decay = 60): int
    {
        $redisKey = $this->getKeyWithPrefix($key);
        $currentTime = microtime(true);
        
        $bucket = $this->redis->hmget($redisKey, ['attempts', 'window_start']);
        $attempts = isset($bucket['attempts']) ? (int)$bucket['attempts'] : 0;
        $windowStart = isset($bucket['window_start']) ? (float)$bucket['window_start'] : $currentTime;
        
        // Reset attempts if window elapsed
        $timePassed = $currentTime - $windowStart;
        if ($timePassed >= $decay) {
            $attempts = 0;
        }
        
        return $attempts;
    }

    public function resetAttempts(string $key): mixed
    {
        $redisKey = $this->getKeyWithPrefix($key);
        return $this->redis->del($redisKey);
    }

    public function remaining(string $key, int $maxAttempts, int $decay = 60): int
    {
        $attempts = $this->attempts($key, $decay);
        return $maxAttempts - $attempts;
    }

    public function clear(string $key): void
    {
        $this->resetAttempts($key);
    }

    public function availableIn(string $key, int $maxAttempts, int $decay = 60): int
    {
        $redisKey = $this->getKeyWithPrefix($key);
        $currentTime = microtime(true);
        
        $bucket = $this->redis->hmget($redisKey, ['tokens', 'last_refill']);
        $tokens = isset($bucket['tokens']) ? (float)$bucket['tokens'] : (float)$maxAttempts;
        $lastRefill = isset($bucket['last_refill']) ? (float)$bucket['last_refill'] : $currentTime;
        
        // Calculate token refill
        $timePassed = $currentTime - $lastRefill;
        $tokensToAdd = floor($timePassed * $maxAttempts / $decay);
        $newTokens = min($maxAttempts, $tokens + $tokensToAdd);
        
        if ($newTokens >= 1) {
            return 0;
        }
        
        return ceil($decay / $maxAttempts);
    }

    public function retriesLeft(string $key, int $maxAttempts, int $decay = 60): int
    {
        return $this->remaining($key, $maxAttempts, $decay);
    }

    private function getKeyWithPrefix(string $key): string
    {
        return "token_bucket_non_lua:{$key}";
    }
}