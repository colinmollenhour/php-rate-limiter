<?php

namespace Cm\RateLimiter\Tests;

use PHPUnit\Framework\TestCase;
use Cm\RateLimiter\TokenBucket\RateLimiter;
use Cm\RateLimiter\RateLimiterResult;
use Credis_Client;

class TokenBucketBurstCapacityBugTest extends TestCase
{
    private RateLimiter $rateLimiter;
    private Credis_Client $redis;

    protected function setUp(): void
    {
        // You may need to adjust Redis connection parameters
        $this->redis = new Credis_Client('127.0.0.1', 6379);
        $this->rateLimiter = new RateLimiter($this->redis);
        
        // Clear any existing test keys
        $this->redis->flushdb();
    }

    protected function tearDown(): void
    {
        $this->redis->flushdb();
        $this->redis->script('FLUSH');
    }

    /**
     * Test that demonstrates the TokenBucket burst capacity bug with fast refill rates.
     * 
     * This test should FAIL until the bug is fixed.
     * 
     * Bug: When using fast refill rates (8+ tokens per second), the TokenBucket allows
     * more requests than the configured burst capacity during rapid request bursts.
     * 
     * Expected behavior: Only ~100 requests should succeed (burst capacity)
     * Actual buggy behavior: All 150 requests succeed
     */
    public function testFastRefillRateAllowsMoreThanBurstCapacity(): void
    {
        $key = 'test-fast-refill-bug';
        $burstCapacity = 100;
        $refillRate = 8.0; // 8 tokens per second - this triggers the bug
        $rapidRequests = 150;
        
        $successfulRequests = 0;
        $failedRequests = 0;
        
        // Make rapid requests that should exceed burst capacity
        for ($i = 0; $i < $rapidRequests; $i++) {
            $result = $this->rateLimiter->attempt($key, $burstCapacity, $refillRate, 60);
            
            if ($result->successful()) {
                $successfulRequests++;
            } else {
                $failedRequests++;
            }
        }
        
        // This assertion should PASS when the bug is present (test fails)
        // and should FAIL when the bug is fixed (test passes)
        $this->assertLessThanOrEqual(
            $burstCapacity + 10, // Allow small margin for timing variations
            $successfulRequests,
            "TokenBucket with fast refill rate ($refillRate tokens/sec) allowed $successfulRequests requests " .
            "but should only allow approximately $burstCapacity (burst capacity). " .
            "This indicates the burst capacity bug is present."
        );
        
        // Additional assertion to make the bug more obvious
        $this->assertGreaterThan(
            0,
            $failedRequests,
            "Expected some requests to fail after burst capacity is exceeded, but all $successfulRequests requests succeeded. " .
            "This confirms the burst capacity bug."
        );
    }

    /**
     * Test that slow refill rates work correctly (control test).
     * 
     * This test should PASS both before and after the bug fix.
     * It demonstrates that the issue only occurs with fast refill rates.
     */
    public function testSlowRefillRateRespectsBurstCapacity(): void
    {
        $key = 'test-slow-refill-control';
        $burstCapacity = 5;
        $refillRate = 1.0; // 1 token per second - this should work correctly
        $rapidRequests = 10;
        
        $successfulRequests = 0;
        $failedRequests = 0;
        
        // Make rapid requests
        for ($i = 0; $i < $rapidRequests; $i++) {
            $result = $this->rateLimiter->attempt($key, $burstCapacity, $refillRate, 60);
            
            if ($result->successful()) {
                $successfulRequests++;
            } else {
                $failedRequests++;
            }
        }
        
        // With slow refill rate, should respect burst capacity
        $this->assertLessThanOrEqual(
            $burstCapacity + 2, // Small margin for timing
            $successfulRequests,
            "TokenBucket with slow refill rate ($refillRate tokens/sec) should respect burst capacity of $burstCapacity"
        );
        
        $this->assertGreaterThan(
            0,
            $failedRequests,
            "Expected some requests to fail with slow refill rate after burst capacity is exceeded"
        );
    }

    /**
     * Test with even faster refill rate to make the bug more obvious.
     * 
     * This test should FAIL spectacularly until the bug is fixed.
     */
    public function testVeryFastRefillRateBugIsMoreObvious(): void
    {
        $key = 'test-very-fast-refill-bug';
        $burstCapacity = 10;
        $refillRate = 20.0; // 20 tokens per second - very fast refill
        $rapidRequests = 50;
        
        $successfulRequests = 0;
        
        // Make rapid requests
        for ($i = 0; $i < $rapidRequests; $i++) {
            $result = $this->rateLimiter->attempt($key, $burstCapacity, $refillRate, 60);
            
            if ($result->successful()) {
                $successfulRequests++;
            }
        }
        
        // With very fast refill, the bug should be very obvious
        $this->assertLessThanOrEqual(
            $burstCapacity + 5, // Small margin
            $successfulRequests,
            "TokenBucket with very fast refill rate ($refillRate tokens/sec) allowed $successfulRequests requests " .
            "but should only allow approximately $burstCapacity (burst capacity). " .
            "Bug is very obvious with fast refill rates."
        );
    }

    /**
     * Test the exact scenario from the bug report.
     * 
     * This reproduces the specific case mentioned in the bug report.
     */
    public function testBugReportScenario(): void
    {
        $key = 'test-bug-report-scenario';
        $burstCapacity = 100;
        $refillRate = 8.0; // Exact parameters from bug report
        $rapidRequests = 150;
        
        $successfulRequests = 0;
        
        // Make the exact number of requests from the bug report
        for ($i = 0; $i < $rapidRequests; $i++) {
            $result = $this->rateLimiter->attempt($key, $burstCapacity, $refillRate, 60);
            
            if ($result->successful()) {
                $successfulRequests++;
            }
        }
        
        // According to the bug report: Expected ~100, Actual: 150
        $this->assertLessThanOrEqual(
            110, // Allow 10% margin over burst capacity
            $successfulRequests,
            "Bug report scenario: burst_capacity=100, refill_rate=8.0, rapid_requests=150. " .
            "Expected: ~100 successful requests, Actual: $successfulRequests successful requests. " .
            "This test reproduces the exact bug report scenario."
        );
    }
}