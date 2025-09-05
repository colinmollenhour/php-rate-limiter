<?php

namespace Cm\RateLimiter\ConcurrencyAware;

use Cm\RateLimiter\ConcurrencyAwareRateLimiterInterface;
use Cm\RateLimiter\ConcurrencyAwareResult;
use Cm\RateLimiter\EvalShaHelper;
use Cm\RateLimiter\RateLimiterInterface;
use Cm\RateLimiter\RateLimiterResult;
use Credis_Client;

class RateLimiter implements ConcurrencyAwareRateLimiterInterface
{
    use EvalShaHelper;

    protected array $limiters = [];
    protected Credis_Client $redis;
    protected ?RateLimiterInterface $rateLimiter;

    public function __construct(Credis_Client $redis, ?RateLimiterInterface $rateLimiter = null)
    {
        $this->redis = $redis;
        $this->rateLimiter = $rateLimiter;
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

    public function attemptWithConcurrency(
        string $key,
        string $requestId,
        int $maxConcurrent,
        int $burstCapacity, 
        float $sustainedRate,
        int $window = 60,
        int $timeoutSeconds = 30
    ): ConcurrencyAwareResult {
        
        // Step 1: Always check concurrency first
        $concurrencyResult = $this->checkConcurrency($key, $requestId, $maxConcurrent, $timeoutSeconds);
        
        if (!$concurrencyResult['acquired']) {
            return new ConcurrencyAwareResult(
                retryAfter: 1, // Minimal retry for concurrency
                retriesLeft: 0,
                limit: $maxConcurrent,
                concurrencyAcquired: false,
                concurrencyRejectionReason: 'CONCURRENCY_LIMIT_EXCEEDED',
                currentConcurrency: $concurrencyResult['current'],
                maxConcurrency: $maxConcurrent
            );
        }
        
        // Step 2: Check rate limiting (if rate limiter is provided)
        if ($this->rateLimiter !== null) {
            $rateResult = $this->rateLimiter->attempt($key, $burstCapacity, $sustainedRate, $window);
            
            if (!$rateResult->successful()) {
                // Rate limit failed, release concurrency slot
                $this->releaseConcurrency($key, $requestId);
                
                return new ConcurrencyAwareResult(
                    retryAfter: $rateResult->retryAfter,
                    retriesLeft: $rateResult->retriesLeft,
                    limit: $rateResult->limit,
                    concurrencyAcquired: false, // Released due to rate limit failure
                    concurrencyRejectionReason: 'RATE_LIMIT_EXCEEDED',
                    currentConcurrency: $concurrencyResult['current'] - 1, // Decremented after release
                    maxConcurrency: $maxConcurrent
                );
            }
            
            // Both concurrency and rate limit passed
            return new ConcurrencyAwareResult(
                retryAfter: 0,
                retriesLeft: $rateResult->retriesLeft,
                limit: $rateResult->limit,
                concurrencyAcquired: true,
                concurrencyRejectionReason: null,
                currentConcurrency: $concurrencyResult['current'],
                maxConcurrency: $maxConcurrent
            );
        }
        
        // Only concurrency check (no rate limiting)
        return new ConcurrencyAwareResult(
            retryAfter: 0,
            retriesLeft: PHP_INT_MAX, // Unlimited rate-wise
            limit: $maxConcurrent,     // Only concurrency limit applies
            concurrencyAcquired: true,
            concurrencyRejectionReason: null,
            currentConcurrency: $concurrencyResult['current'],
            maxConcurrency: $maxConcurrent
        );
    }

    private function checkConcurrency(string $key, string $requestId, int $maxConcurrent, int $timeoutSeconds): array
    {
        $concurrencyKey = $this->getConcurrencyKey($key);
        $keys = [$concurrencyKey];
        $args = [$requestId, $maxConcurrent, $timeoutSeconds];
        
        $result = $this->evalSha($this->redis, LuaScripts::checkConcurrency(), LuaScripts::CHECKCONCURRENCY_SHA, $keys, $args);
        
        return [
            'acquired' => (bool)$result[0],
            'current' => (int)$result[1]
        ];
    }

    public function releaseConcurrency(string $key, string $requestId): void
    {
        $concurrencyKey = $this->getConcurrencyKey($key);
        $keys = [$concurrencyKey];
        $args = [$requestId];
        
        $this->evalSha($this->redis, LuaScripts::releaseConcurrency(), LuaScripts::RELEASECONCURRENCY_SHA, $keys, $args);
    }

    public function currentConcurrency(string $key, int $timeoutSeconds = 30): int
    {
        $concurrencyKey = $this->getConcurrencyKey($key);
        $keys = [$concurrencyKey];
        $args = [$timeoutSeconds];
        
        return (int) $this->evalSha($this->redis, LuaScripts::currentConcurrency(), LuaScripts::CURRENTCONCURRENCY_SHA, $keys, $args);
    }

    public function cleanupExpiredConcurrency(string $key, int $timeoutSeconds = 30): int
    {
        $concurrencyKey = $this->getConcurrencyKey($key);
        $keys = [$concurrencyKey];
        $args = [$timeoutSeconds];
        
        return $this->evalSha($this->redis, LuaScripts::cleanupExpired(), LuaScripts::CLEANUPEXPIRED_SHA, $keys, $args);
    }

    // Implement existing RateLimiterInterface methods by delegating to the injected rate limiter
    public function attempt(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): RateLimiterResult
    {
        if ($this->rateLimiter === null) {
            // No rate limiting - always allow
            return new RateLimiterResult(0, PHP_INT_MAX, PHP_INT_MAX);
        }
        
        return $this->rateLimiter->attempt($key, $burstCapacity, $sustainedRate, $window);
    }

    public function tooManyAttempts(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): bool
    {
        if ($this->rateLimiter === null) {
            return false; // No rate limiting - never too many
        }
        
        return $this->rateLimiter->tooManyAttempts($key, $burstCapacity, $sustainedRate, $window);
    }

    public function attempts(string $key, int $window = 60): int
    {
        if ($this->rateLimiter === null) {
            return 0; // No rate limiting - no attempts tracked
        }
        
        return $this->rateLimiter->attempts($key, $window);
    }

    public function resetAttempts(string $key): mixed
    {
        if ($this->rateLimiter === null) {
            return 0; // No rate limiting - nothing to reset
        }
        
        return $this->rateLimiter->resetAttempts($key);
    }

    public function remaining(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): int
    {
        if ($this->rateLimiter === null) {
            return PHP_INT_MAX; // No rate limiting - unlimited remaining
        }
        
        return $this->rateLimiter->remaining($key, $burstCapacity, $sustainedRate, $window);
    }

    public function clear(string $key): void
    {
        // Clear both concurrency and rate limit data
        $concurrencyKey = $this->getConcurrencyKey($key);
        $this->redis->del($concurrencyKey);
        
        if ($this->rateLimiter !== null) {
            $this->rateLimiter->clear($key);
        }
    }

    public function availableIn(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): int
    {
        if ($this->rateLimiter === null) {
            return 0; // No rate limiting - always available
        }
        
        return $this->rateLimiter->availableIn($key, $burstCapacity, $sustainedRate, $window);
    }

    public function retriesLeft(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): int
    {
        return $this->remaining($key, $burstCapacity, $sustainedRate, $window);
    }

    private function getConcurrencyKey(string $key): string
    {
        return "cm-conc:{$key}";
    }
}