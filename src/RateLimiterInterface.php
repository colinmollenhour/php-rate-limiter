<?php

namespace Cm\RateLimiter;

interface RateLimiterInterface
{
    /**
     * Register a named limiter configuration.
     */
    public function for(string $name, \Closure $callback): self;

    /**
     * Get the given named rate limiter.
     */
    public function limiter(string $name): ?\Closure;

    /**
     * Attempt to perform an action within rate limits with burst and sustained rates.
     * 
     * @param string $key Unique identifier for the rate limit
     * @param int $burstCapacity Maximum requests that can be made instantly
     * @param float $sustainedRate Requests per second after burst is consumed
     * @param int $window Time window in seconds (for window-based algorithms)
     */
    public function attempt(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): RateLimiterResult;

    /**
     * Determine if the given key has been accessed too many times.
     */
    public function tooManyAttempts(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): bool;

    /**
     * Get the number of attempts for the given key.
     */
    public function attempts(string $key, int $window = 60): int;

    /**
     * Reset the number of attempts for the given key.
     */
    public function resetAttempts(string $key): mixed;

    /**
     * Get the number of retries left for the given key.
     */
    public function remaining(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): int;

    /**
     * Clear the number of attempts for the given key.
     */
    public function clear(string $key): void;

    /**
     * Get the number of seconds until the key is accessible again.
     */
    public function availableIn(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): int;

    /**
     * Get the number of retries left for the given key.
     */
    public function retriesLeft(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): int;
}