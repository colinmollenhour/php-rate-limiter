<?php

namespace Cm\RateLimiter\TokenBucket;

use Cm\RateLimiter\RateLimiterInterface;
use Cm\RateLimiter\RateLimiterResult;
use Credis_Client;

class RateLimiter implements RateLimiterInterface
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

    public function attempt(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): RateLimiterResult
    {
        if ($this->tooManyAttempts($key, $burstCapacity, $sustainedRate, $window)) {
            return new RateLimiterResult($this->availableIn($key, $burstCapacity, $sustainedRate, $window), 0, $burstCapacity);
        }
        
        $keys = [$this->getKeyWithPrefix($key)];
        // Pass sustained rate directly to avoid floating point precision issues
        $args = [$sustainedRate, $burstCapacity];
        [$retryAfter, $retriesLeft, $limit] = $this->redis->eval(LuaScripts::attempt(), $keys, $args);

        return new RateLimiterResult($retryAfter, $retriesLeft, $limit);
    }

    public function tooManyAttempts(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): bool
    {
        // For token bucket, we check if there are tokens available
        return $this->availableIn($key, $burstCapacity, $sustainedRate, $window) > 0;
    }

    public function attempts(string $key, int $window = 60): int
    {
        $keys = [$this->getKeyWithPrefix($key)];
        $args = [$window];

        return $this->redis->eval(LuaScripts::attempts(), $keys, $args);
    }

    public function resetAttempts(string $key): mixed
    {
        $keys = [$this->getKeyWithPrefix($key)];
        $args = [];

        return $this->redis->eval(LuaScripts::resetAttempts(), $keys, $args);
    }

    public function remaining(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): int
    {
        $keys = [$this->getKeyWithPrefix($key)];
        $args = [$sustainedRate, $burstCapacity];
        
        // Get current token count
        [$retryAfter, $tokensRemaining] = $this->redis->eval(LuaScripts::tokensRemaining(), $keys, $args);
        
        return max(0, $tokensRemaining);
    }

    public function clear(string $key): void
    {
        $this->resetAttempts($key);
    }

    public function availableIn(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): int
    {
        $keys = [$this->getKeyWithPrefix($key)];
        $args = [$sustainedRate, $burstCapacity];

        return $this->redis->eval(LuaScripts::availableIn(), $keys, $args);
    }

    public function retriesLeft(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): int
    {
        return $this->remaining($key, $burstCapacity, $sustainedRate, $window);
    }

    private function getKeyWithPrefix(string $key): string
    {
        return "token_bucket_rate_limiter:{$key}";
    }
}