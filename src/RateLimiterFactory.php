<?php

namespace Cm\RateLimiter;

use Cm\RateLimiter\SlidingWindow\RateLimiter as SlidingWindowRateLimiter;
use Cm\RateLimiter\FixedWindow\RateLimiter as FixedWindowRateLimiter;
use Cm\RateLimiter\LeakyBucket\RateLimiter as LeakyBucketRateLimiter;
use Cm\RateLimiter\GCRA\RateLimiter as GCRARateLimiter;
use Cm\RateLimiter\TokenBucket\RateLimiter as TokenBucketRateLimiter;
use Cm\RateLimiter\ConcurrencyAware\RateLimiter as ConcurrencyAwareRateLimiter;
use Credis_Client;

class RateLimiterFactory
{
    private Credis_Client $redis;

    public function __construct(Credis_Client $redis)
    {
        $this->redis = $redis;
    }

    public function createSlidingWindow(): RateLimiterInterface
    {
        return new SlidingWindowRateLimiter($this->redis);
    }

    public function createFixedWindow(): RateLimiterInterface
    {
        return new FixedWindowRateLimiter($this->redis);
    }

    public function createLeakyBucket(): RateLimiterInterface
    {
        return new LeakyBucketRateLimiter($this->redis);
    }

    public function createGCRA(): RateLimiterInterface
    {
        return new GCRARateLimiter($this->redis);
    }

    public function createTokenBucket(): RateLimiterInterface
    {
        return new TokenBucketRateLimiter($this->redis);
    }

    public function createConcurrencyAware(?string $algorithm = 'sliding'): ConcurrencyAwareRateLimiterInterface
    {
        $rateLimiter = null;
        
        if ($algorithm !== null) {
            $rateLimiter = match ($algorithm) {
                'sliding' => $this->createSlidingWindow(),
                'fixed' => $this->createFixedWindow(), 
                'leaky' => $this->createLeakyBucket(),
                'gcra' => $this->createGCRA(),
                'token' => $this->createTokenBucket(),
                default => throw new InvalidArgumentException("Unknown algorithm: {$algorithm}")
            };
        }
        
        return new ConcurrencyAwareRateLimiter($this->redis, $rateLimiter);
    }
}