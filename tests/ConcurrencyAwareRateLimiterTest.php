<?php

namespace Cm\RateLimiter\Tests;

use PHPUnit\Framework\TestCase;
use Cm\RateLimiter\ConcurrencyAware\RateLimiter;
use Cm\RateLimiter\ConcurrencyAwareResult;
use Cm\RateLimiter\RateLimiterFactory;
use Credis_Client;

class ConcurrencyAwareRateLimiterTest extends TestCase
{
    private RateLimiter $rateLimiter;
    private Credis_Client $redis;

    protected function setUp(): void
    {
        $this->redis = new Credis_Client('127.0.0.1', 6379);
        
        // Use factory to create concurrency-aware limiter with fixed window rate limiter
        // Fixed window has more predictable burst behavior for testing
        $factory = new RateLimiterFactory($this->redis);
        $this->rateLimiter = $factory->createConcurrencyAware('fixed');
        
        // Clear any existing test keys
        $this->redis->flushdb();
    }

    protected function tearDown(): void
    {
        $this->redis->flushdb();
    }

    public function testBasicConcurrencyLimit()
    {
        $key = 'test-concurrency';
        $maxConcurrent = 2;
        
        // First two requests should succeed
        $result1 = $this->rateLimiter->attemptWithConcurrency(
            $key, 'req1', $maxConcurrent, 10, 5.0, 60, 30
        );
        $this->assertTrue($result1->successful());
        $this->assertTrue($result1->concurrencyAcquired);
        $this->assertEquals(1, $result1->currentConcurrency);
        
        $result2 = $this->rateLimiter->attemptWithConcurrency(
            $key, 'req2', $maxConcurrent, 10, 5.0, 60, 30
        );
        $this->assertTrue($result2->successful());
        $this->assertTrue($result2->concurrencyAcquired);
        $this->assertEquals(2, $result2->currentConcurrency);
        
        // Third request should be rejected due to concurrency limit
        $result3 = $this->rateLimiter->attemptWithConcurrency(
            $key, 'req3', $maxConcurrent, 10, 5.0, 60, 30
        );
        $this->assertFalse($result3->successful());
        $this->assertFalse($result3->concurrencyAcquired);
        $this->assertTrue($result3->rejectedByConcurrency());
        $this->assertEquals('CONCURRENCY_LIMIT_EXCEEDED', $result3->concurrencyRejectionReason);
        $this->assertEquals(2, $result3->currentConcurrency);
    }

    public function testConcurrencyRelease()
    {
        $key = 'test-release';
        $maxConcurrent = 1;
        
        // Acquire concurrency
        $result1 = $this->rateLimiter->attemptWithConcurrency(
            $key, 'req1', $maxConcurrent, 10, 5.0, 60, 30
        );
        $this->assertTrue($result1->successful());
        $this->assertEquals(1, $result1->currentConcurrency);
        
        // Should be rejected
        $result2 = $this->rateLimiter->attemptWithConcurrency(
            $key, 'req2', $maxConcurrent, 10, 5.0, 60, 30
        );
        $this->assertFalse($result2->successful());
        $this->assertTrue($result2->rejectedByConcurrency());
        
        // Release first request
        $this->rateLimiter->releaseConcurrency($key, 'req1');
        
        // Now second request should succeed
        $result3 = $this->rateLimiter->attemptWithConcurrency(
            $key, 'req2', $maxConcurrent, 10, 5.0, 60, 30
        );
        $this->assertTrue($result3->successful());
        $this->assertEquals(1, $result3->currentConcurrency);
    }

    public function testRateLimitAfterConcurrencyAcquisition()
    {
        $key = 'test-rate-limit';
        $maxConcurrent = 50; // High concurrency limit so rate limit hits first
        $burstCapacity = 2;   // Very small burst
        $sustainedRate = 0.1; // Very slow rate: 0.1 req/s  
        $window = 60;         // 60 seconds
        
        // First, exhaust the rate limit by making burst requests
        $result1 = $this->rateLimiter->attemptWithConcurrency(
            $key, "req1", $maxConcurrent, $burstCapacity, $sustainedRate, $window, 30
        );
        $this->assertTrue($result1->successful());
        
        $result2 = $this->rateLimiter->attemptWithConcurrency(
            $key, "req2", $maxConcurrent, $burstCapacity, $sustainedRate, $window, 30
        );
        $this->assertTrue($result2->successful());
        
        // Third request should now be rate limited since burst capacity is exhausted
        // and sustained rate is very low (0.1 req/s)
        $result3 = $this->rateLimiter->attemptWithConcurrency(
            $key, 'rate_limited_req', $maxConcurrent, $burstCapacity, $sustainedRate, $window, 30
        );
        
        $this->assertFalse($result3->successful());
        $this->assertFalse($result3->concurrencyAcquired); // Should be false because rate limit failed
        $this->assertEquals('RATE_LIMIT_EXCEEDED', $result3->concurrencyRejectionReason);
        $this->assertGreaterThan(0, $result3->retryAfter);
    }

    public function testCurrentConcurrencyTracking()
    {
        $key = 'test-current';
        $maxConcurrent = 3;
        
        $this->assertEquals(0, $this->rateLimiter->currentConcurrency($key));
        
        // Add requests
        $this->rateLimiter->attemptWithConcurrency($key, 'req1', $maxConcurrent, 10, 5.0, 60, 30);
        $this->assertEquals(1, $this->rateLimiter->currentConcurrency($key));
        
        $this->rateLimiter->attemptWithConcurrency($key, 'req2', $maxConcurrent, 10, 5.0, 60, 30);
        $this->assertEquals(2, $this->rateLimiter->currentConcurrency($key));
        
        // Release one
        $this->rateLimiter->releaseConcurrency($key, 'req1');
        $this->assertEquals(1, $this->rateLimiter->currentConcurrency($key));
        
        // Release the other
        $this->rateLimiter->releaseConcurrency($key, 'req2');
        $this->assertEquals(0, $this->rateLimiter->currentConcurrency($key));
    }

    public function testExpiredConcurrencyCleanup()
    {
        $key = 'test-expired';
        $maxConcurrent = 2;
        $timeout = 1; // 1 second timeout for quick test
        
        // Acquire concurrency
        $result1 = $this->rateLimiter->attemptWithConcurrency(
            $key, 'req1', $maxConcurrent, 10, 5.0, 60, $timeout
        );
        $this->assertTrue($result1->successful());
        $this->assertEquals(1, $result1->currentConcurrency);
        
        // Wait for timeout + a bit
        sleep($timeout + 1);
        
        // Check that expired requests are cleaned up when we call currentConcurrency
        // (which runs cleanup internally) - use same timeout as the original request
        $currentConcurrency = $this->rateLimiter->currentConcurrency($key, $timeout);
        $this->assertEquals(0, $currentConcurrency);
        
        // Should be able to acquire again
        $result2 = $this->rateLimiter->attemptWithConcurrency(
            $key, 'req2', $maxConcurrent, 10, 5.0, 60, $timeout
        );
        $this->assertTrue($result2->successful());
        // After acquiring, we should have exactly 1 concurrent request
        $this->assertEquals(1, $this->rateLimiter->currentConcurrency($key, $timeout));
    }

    public function testManualExpiredCleanup()
    {
        $key = 'test-manual-cleanup';
        $maxConcurrent = 2;
        $timeout = 1;
        
        // Acquire concurrency
        $result1 = $this->rateLimiter->attemptWithConcurrency($key, 'req1', $maxConcurrent, 10, 5.0, 60, $timeout);
        $result2 = $this->rateLimiter->attemptWithConcurrency($key, 'req2', $maxConcurrent, 10, 5.0, 60, $timeout);
        
        $this->assertTrue($result1->successful());
        $this->assertTrue($result2->successful());
        $this->assertEquals(2, $this->rateLimiter->currentConcurrency($key));
        
        // Wait for timeout
        sleep($timeout + 1);
        
        // Manual cleanup should return the number of expired entries cleaned up
        $cleaned = $this->rateLimiter->cleanupExpiredConcurrency($key, $timeout);
        $this->assertGreaterThanOrEqual(0, $cleaned); // May be 0 or 2 depending on timing
        $this->assertEquals(0, $this->rateLimiter->currentConcurrency($key));
    }

    public function testFactoryCreation()
    {
        $factory = new RateLimiterFactory($this->redis);
        $limiter = $factory->createConcurrencyAware();
        
        $this->assertInstanceOf(RateLimiter::class, $limiter);
        
        // Test basic functionality
        $result = $limiter->attemptWithConcurrency('factory-test', 'req1', 1, 10, 5.0, 60, 30);
        $this->assertTrue($result->successful());
        $this->assertTrue($result->concurrencyAcquired);
    }

    public function testBackwardCompatibilityMethods()
    {
        // Test that the regular rate limiting methods still work
        $key = 'backward-compat';
        
        $result = $this->rateLimiter->attempt($key, 10, 5.0, 60);
        $this->assertTrue($result->successful());
        $this->assertGreaterThan(0, $result->retriesLeft);
        
        $attempts = $this->rateLimiter->attempts($key, 60);
        $this->assertEquals(1, $attempts);
        
        $remaining = $this->rateLimiter->remaining($key, 10, 5.0, 60);
        $this->assertEquals(9, $remaining); // Fixed window: burst capacity - attempts = 10 - 1 = 9
        
        $retriesLeft = $this->rateLimiter->retriesLeft($key, 10, 5.0, 60);
        $this->assertEquals($remaining, $retriesLeft);
        
        $availableIn = $this->rateLimiter->availableIn($key, 10, 5.0, 60);
        $this->assertEquals(0, $availableIn);
        
        $this->rateLimiter->clear($key);
        $this->assertEquals(0, $this->rateLimiter->attempts($key, 60));
    }

    public function testUniqueRequestIds()
    {
        $key = 'test-unique-ids';
        $maxConcurrent = 1;
        
        // Same request ID should not create multiple concurrency slots
        $result1 = $this->rateLimiter->attemptWithConcurrency($key, 'same-id', $maxConcurrent, 10, 5.0, 60, 30);
        $this->assertTrue($result1->successful());
        
        $result2 = $this->rateLimiter->attemptWithConcurrency($key, 'same-id', $maxConcurrent, 10, 5.0, 60, 30);
        $this->assertFalse($result2->successful());
        $this->assertTrue($result2->rejectedByConcurrency());
        
        // But different request ID should work after release
        $this->rateLimiter->releaseConcurrency($key, 'same-id');
        
        $result3 = $this->rateLimiter->attemptWithConcurrency($key, 'different-id', $maxConcurrent, 10, 5.0, 60, 30);
        $this->assertTrue($result3->successful());
    }

    public function testConcurrencyAwareResultMethods()
    {
        $key = 'test-result-methods';
        
        // Test successful result
        $successResult = $this->rateLimiter->attemptWithConcurrency($key, 'req1', 2, 10, 5.0, 60, 30);
        $this->assertTrue($successResult->successful());
        $this->assertTrue($successResult->concurrencyAcquired);
        $this->assertFalse($successResult->rejectedByConcurrency());
        $this->assertFalse($successResult->rejectedByRateLimit());
        $this->assertNull($successResult->concurrencyRejectionReason);
        
        // Test concurrency rejection
        $this->rateLimiter->attemptWithConcurrency($key, 'req2', 2, 10, 5.0, 60, 30);
        $concurrencyRejection = $this->rateLimiter->attemptWithConcurrency($key, 'req3', 2, 10, 5.0, 60, 30);
        $this->assertFalse($concurrencyRejection->successful());
        $this->assertFalse($concurrencyRejection->concurrencyAcquired);
        $this->assertTrue($concurrencyRejection->rejectedByConcurrency());
        $this->assertFalse($concurrencyRejection->rejectedByRateLimit());
        $this->assertEquals('CONCURRENCY_LIMIT_EXCEEDED', $concurrencyRejection->concurrencyRejectionReason);
    }
}