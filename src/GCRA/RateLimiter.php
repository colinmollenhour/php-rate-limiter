<?php

namespace Cm\RateLimiter\GCRA;

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
        // For GCRA, we use smooth rate limiting (ignore burst capacity)
        // Total requests = sustainedRate * window
        $maxAttempts = (int)($sustainedRate * $window);
        
        $keys = [$this->getKeyWithPrefix($key)];
        $args = [$window, $maxAttempts];
        [$retryAfter, $retriesLeft, $limit] = $this->evalSha($this->redis, LuaScripts::attempt(), LuaScripts::ATTEMPT_SHA, $keys, $args);

        return new RateLimiterResult($retryAfter, $retriesLeft, $limit);
    }

    public function tooManyAttempts(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): bool
    {
        $maxAttempts = (int)($sustainedRate * $window);
        return $this->availableIn($key, $maxAttempts, $window) > 0;
    }

    public function attempts(string $key, int $window = 60): int
    {
        $keys = [$this->getKeyWithPrefix($key)];
        $args = [$window, 1]; // For attempts calculation, we use limit=1 as baseline

        return $this->evalSha($this->redis, LuaScripts::attempts(), LuaScripts::ATTEMPTS_SHA, $keys, $args);
    }

    public function resetAttempts(string $key): mixed
    {
        $keys = [$this->getKeyWithPrefix($key)];
        $args = [];

        return $this->evalSha($this->redis, LuaScripts::resetAttempts(), LuaScripts::RESETATTEMPTS_SHA, $keys, $args);
    }

    public function remaining(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): int
    {
        $maxAttempts = (int)($sustainedRate * $window);
        $attempts = $this->attempts($key, $window);
        return max(0, $maxAttempts - $attempts);
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

        return $this->evalSha($this->redis, LuaScripts::availableIn(), LuaScripts::AVAILABLEIN_SHA, $keys, $args);
    }

    public function retriesLeft(string $key, int $burstCapacity, float $sustainedRate, int $window = 60): int
    {
        return $this->remaining($key, $burstCapacity, $sustainedRate, $window);
    }

    private function getKeyWithPrefix(string $key): string
    {
        return "gcra_rate_limiter:{$key}";
    }
}