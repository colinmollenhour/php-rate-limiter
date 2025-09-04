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
     * Attempt to perform an action within rate limits.
     */
    public function attempt(string $key, int $maxAttempts, int $decay = 60): RateLimiterResult;

    /**
     * Determine if the given key has been accessed too many times.
     */
    public function tooManyAttempts(string $key, int $maxAttempts, int $decay = 60): bool;

    /**
     * Get the number of attempts for the given key.
     */
    public function attempts(string $key, int $decay = 60): int;

    /**
     * Reset the number of attempts for the given key.
     */
    public function resetAttempts(string $key): mixed;

    /**
     * Get the number of retries left for the given key.
     */
    public function remaining(string $key, int $maxAttempts, int $decay = 60): int;

    /**
     * Clear the number of attempts for the given key.
     */
    public function clear(string $key): void;

    /**
     * Get the number of seconds until the key is accessible again.
     */
    public function availableIn(string $key, int $maxAttempts, int $decay = 60): int;

    /**
     * Get the number of retries left for the given key.
     */
    public function retriesLeft(string $key, int $maxAttempts, int $decay = 60): int;
}