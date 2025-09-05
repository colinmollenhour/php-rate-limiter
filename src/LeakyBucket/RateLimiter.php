<?php

namespace Cm\RateLimiter\LeakyBucket;

use Cm\RateLimiter\EvalShaHelper;
use Cm\RateLimiter\RateLimiterInterface;
use Cm\RateLimiter\RateLimiterResult;
use Credis_Client;

class RateLimiter implements RateLimiterInterface
{
    use EvalShaHelper;

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
        // For Leaky Bucket: burstCapacity is bucket size, sustainedRate is leak rate
        if ($this->tooManyAttempts($key, $burstCapacity, $sustainedRate, $window)) {
            return new RateLimiterResult($this->availableIn($key, $burstCapacity, $sustainedRate, $window), 0, $burstCapacity);
        }
        
        $keys = [$this->getKeyWithPrefix($key)];
        $leakRate = $this->calculateLeakRate($sustainedRate);
        $args = [$leakRate, $burstCapacity];
        [$retryAfter, $retriesLeft, $limit] = $this->evalSha($this->redis, LuaScripts::attempt(), LuaScripts::ATTEMPT_SHA, $keys, $args);

        return new RateLimiterResult($retryAfter, $retriesLeft, $limit);
    }

    public function tooManyAttempts(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): bool
    {
        if ($this->attempts($key, $window) >= $burstCapacity) {
            return true;
        }

        return false;
    }

    public function attempts(string $key, int $window = 60): int
    {
        $keys = [$this->getKeyWithPrefix($key)];
        $leakRate = $this->calculateLeakRate(1.0); // Default leak rate for attempts check
        $args = [$leakRate];

        return $this->evalSha($this->redis, LuaScripts::attempts(), LuaScripts::ATTEMPTS_SHA, $keys, $args);
    }

    public function resetAttempts(string $key): mixed
    {
        return $this->redis->del($this->getKeyWithPrefix($key));
    }

    public function remaining(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): int
    {
        $attempts = $this->attempts($key, $window);

        return max(0, $burstCapacity - $attempts);
    }

    public function clear(string $key): void
    {
        $this->resetAttempts($key);
    }

    public function availableIn(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): int
    {
        $keys = [$this->getKeyWithPrefix($key)];
        $leakRate = $this->calculateLeakRate($sustainedRate);
        $args = [$leakRate, $burstCapacity];

        return $this->evalSha($this->redis, LuaScripts::availableIn(), LuaScripts::AVAILABLEIN_SHA, $keys, $args);
    }

    public function retriesLeft(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): int
    {
        return $this->remaining($key, $burstCapacity, $sustainedRate, $window);
    }

    private function getKeyWithPrefix(string $key): string
    {
        return "cm-leaky:{$key}";
    }

    private function calculateLeakRate(float $sustainedRate): int
    {
        // Convert sustained rate to seconds between leaks
        return (int) ceil(1.0 / $sustainedRate);
    }
}