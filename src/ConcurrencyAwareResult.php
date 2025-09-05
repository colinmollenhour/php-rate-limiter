<?php

namespace Cm\RateLimiter;

class ConcurrencyAwareResult extends RateLimiterResult
{
    public function __construct(
        public int $retryAfter,
        public int $retriesLeft, 
        public int $limit,
        public bool $concurrencyAcquired,
        public ?string $concurrencyRejectionReason = null,
        public int $currentConcurrency = 0,
        public int $maxConcurrency = 0
    ) {
        parent::__construct($retryAfter, $retriesLeft, $limit);
    }
    
    public function successful(): bool
    {
        return $this->concurrencyAcquired && parent::successful();
    }
    
    public function rejectedByConcurrency(): bool
    {
        return $this->concurrencyRejectionReason === 'CONCURRENCY_LIMIT_EXCEEDED';
    }
    
    public function rejectedByRateLimit(): bool
    {
        return $this->concurrencyRejectionReason === 'RATE_LIMIT_EXCEEDED' ||
               ($this->concurrencyAcquired && !parent::successful());
    }
}