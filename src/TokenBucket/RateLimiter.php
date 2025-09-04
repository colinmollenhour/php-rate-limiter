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

    public function attempt(string $key, int $maxAttempts, int $decay = 60): RateLimiterResult
    {
        if ($this->tooManyAttempts($key, $maxAttempts, $decay)) {
            return new RateLimiterResult($this->availableIn($key, $maxAttempts, $decay), 0, $maxAttempts);
        }
        
        $keys = [$this->getKeyWithPrefix($key)];
        $args = [$decay, $maxAttempts];
        [$retryAfter, $retriesLeft, $limit] = $this->redis->eval(LuaScripts::attempt(), $keys, $args);

        return new RateLimiterResult($retryAfter, $retriesLeft, $limit);
    }

    public function tooManyAttempts(string $key, int $maxAttempts, int $decay = 60): bool
    {
        if ($this->attempts($key, $decay) >= $maxAttempts) {
            return true;
        }

        return false;
    }

    public function attempts(string $key, int $decay = 60): int
    {
        $keys = [$this->getKeyWithPrefix($key)];
        $args = [$decay];

        return $this->redis->eval(LuaScripts::attempts(), $keys, $args);
    }

    public function resetAttempts(string $key): mixed
    {
        $keys = [$this->getKeyWithPrefix($key)];
        $args = [];

        return $this->redis->eval(LuaScripts::resetAttempts(), $keys, $args);
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
        $keys = [$this->getKeyWithPrefix($key)];
        $args = [$decay, $maxAttempts];

        return $this->redis->eval(LuaScripts::availableIn(), $keys, $args);
    }

    public function retriesLeft(string $key, int $maxAttempts, int $decay = 60): int
    {
        return $this->remaining($key, $maxAttempts, $decay);
    }

    private function getKeyWithPrefix(string $key): string
    {
        return "token_bucket_rate_limiter:{$key}";
    }
}