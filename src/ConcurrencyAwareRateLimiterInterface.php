<?php

namespace Cm\RateLimiter;

interface ConcurrencyAwareRateLimiterInterface extends RateLimiterInterface
{
    /**
     * Attempt to acquire both concurrency slot and rate limit.
     * 
     * @param string $key Rate limit key
     * @param string $requestId Unique request identifier  
     * @param int $maxConcurrent Maximum concurrent requests
     * @param int $burstCapacity Rate limit burst capacity
     * @param float $sustainedRate Rate limit sustained rate (req/s)
     * @param int $window Rate limit time window
     * @param int $timeoutSeconds Concurrency acquisition timeout in seconds
     */
    public function attemptWithConcurrency(
        string $key,
        string $requestId,
        int $maxConcurrent,
        int $burstCapacity, 
        float $sustainedRate,
        int $window = 60,
        int $timeoutSeconds = 30
    ): ConcurrencyAwareResult;
    
    /**
     * Release concurrency slot (call when request completes)
     */
    public function releaseConcurrency(string $key, string $requestId): void;
    
    /**
     * Get current concurrency usage
     */
    public function currentConcurrency(string $key, int $timeoutSeconds = 30): int;
    
    /**
     * Clean up expired concurrency requests
     */
    public function cleanupExpiredConcurrency(string $key, int $timeoutSeconds = 30): int;
}