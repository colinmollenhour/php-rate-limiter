<?php

namespace Cm\RateLimiter\FixedWindow;

use Cm\RateLimiter\RateLimiterInterface;
use Cm\RateLimiter\RateLimiterResult;
use Credis_Client;

/**
 * Fixed Window implementation without Lua scripts for performance comparison
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
        
        // For Fixed Window, we can use simple INCR which is naturally atomic
        $count = $this->redis->incr($redisKey);
        
        if ($count === 1) {
            // First request in this window - set expiration
            $this->redis->expire($redisKey, $decay);
        }
        
        if ($count > $maxAttempts) {
            $retriesLeft = 0;
            $retryAfter = $this->redis->ttl($redisKey);
            if ($retryAfter < 0) $retryAfter = $decay; // TTL expired, use decay
            return new RateLimiterResult($retryAfter, $retriesLeft, $maxAttempts);
        }
        
        $retriesLeft = max(0, $maxAttempts - $count);
        return new RateLimiterResult(0, $retriesLeft, $maxAttempts);
    }

    public function tooManyAttempts(string $key, int $maxAttempts, int $decay = 60): bool
    {
        return $this->attempts($key, $decay) >= $maxAttempts;
    }

    public function attempts(string $key, int $decay = 60): int
    {
        $redisKey = $this->getKeyWithPrefix($key);
        $count = $this->redis->get($redisKey);
        return $count ? (int)$count : 0;
    }

    public function resetAttempts(string $key): mixed
    {
        $redisKey = $this->getKeyWithPrefix($key);
        return $this->redis->del($redisKey);
    }

    public function remaining(string $key, int $maxAttempts, int $decay = 60): int
    {
        $attempts = $this->attempts($key, $decay);
        return max(0, $maxAttempts - $attempts);
    }

    public function clear(string $key): void
    {
        $this->resetAttempts($key);
    }

    public function availableIn(string $key, int $maxAttempts, int $decay = 60): int
    {
        if (!$this->tooManyAttempts($key, $maxAttempts, $decay)) {
            return 0;
        }
        
        $redisKey = $this->getKeyWithPrefix($key);
        $ttl = $this->redis->ttl($redisKey);
        return $ttl > 0 ? $ttl : 0;
    }

    public function retriesLeft(string $key, int $maxAttempts, int $decay = 60): int
    {
        return $this->remaining($key, $maxAttempts, $decay);
    }

    private function getKeyWithPrefix(string $key): string
    {
        return "fixed_rate_limiter_non_lua:{$key}";
    }
}