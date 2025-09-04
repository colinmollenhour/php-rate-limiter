<?php

namespace Cm\RateLimiter\SlidingWindow;

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
        // For Sliding Window, we ignore burst capacity and use smooth rate limiting
        // Total requests = sustainedRate * window
        $maxAttempts = (int)($sustainedRate * $window);
        
        if ($this->tooManyAttempts($key, $maxAttempts, $window)) {
            return new RateLimiterResult($this->availableIn($key, $maxAttempts, $window), 0, $maxAttempts);
        }
        
        $keys = [$this->getKeyWithPrefix($key)];
        $args = [$window, $maxAttempts];
        [$retryAfter, $retriesLeft, $limit] = $this->redis->eval(LuaScripts::attempt(), $keys, $args);

        return new RateLimiterResult($retryAfter, $retriesLeft, $limit);
    }

    public function tooManyAttempts(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): bool
    {
        $maxAttempts = (int)($sustainedRate * $window);
        if ($this->attempts($key, $window) >= $maxAttempts) {
            return true;
        }

        return false;
    }

    public function attempts(string $key, int $window = 60): int
    {
        $keys = [$this->getKeyWithPrefix($key)];
        $args = [$window];

        return $this->redis->eval(LuaScripts::attempts(), $keys, $args);
    }

    public function resetAttempts(string $key): mixed
    {
        $key = $this->getKeyWithPrefix($key);

        return $this->redis->del($key);
    }

    public function remaining(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): int
    {
        $maxAttempts = (int)($sustainedRate * $window);
        $attempts = $this->attempts($key, $window);

        return $maxAttempts - $attempts;
    }

    public function clear(string $key): void
    {
        $this->resetAttempts($key);
    }

    public function availableIn(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): int
    {
        $maxAttempts = (int)($sustainedRate * $window);
        $keys = [$this->getKeyWithPrefix($key)];
        $args = [$window, $maxAttempts];

        return $this->redis->eval(LuaScripts::availableIn(), $keys, $args);
    }

    public function retriesLeft(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): int
    {
        return $this->remaining($key, $burstCapacity, $sustainedRate, $window);
    }

    private function getKeyWithPrefix(string $key): string
    {
        return "sliding_rate_limiter:{$key}";
    }
}