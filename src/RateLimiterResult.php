<?php

namespace Cm\RateLimiter;

class RateLimiterResult
{
    public function __construct(
        public int $retryAfter,
        public int $retriesLeft,
        public int $limit
    ) {
    }

    public function successful(): bool
    {
        return $this->retriesLeft >= 0 && $this->retryAfter === 0;
    }

    public function availableAt(): int
    {
        return time() + $this->retryAfter;
    }
}