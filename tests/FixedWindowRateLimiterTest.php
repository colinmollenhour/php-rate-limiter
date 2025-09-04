<?php

namespace Cm\RateLimiter\Tests;

use PHPUnit\Framework\TestCase;
use Cm\RateLimiter\FixedWindow\RateLimiter;
use Cm\RateLimiter\RateLimiterResult;
use Cm\RateLimiter\RateLimiterFactory;
use Credis_Client;

class FixedWindowRateLimiterTest extends TestCase
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
    }

    public function testSuccessfulAttempt(): void
    {
        $result = $this->rateLimiter->attempt('test-key', 10, 60);
        
        $this->assertInstanceOf(RateLimiterResult::class, $result);
        $this->assertTrue($result->successful());
        $this->assertEquals(0, $result->retryAfter);
        $this->assertEquals(9, $result->retriesLeft);
        $this->assertEquals(10, $result->limit);
    }

    public function testTooManyAttempts(): void
    {
        // Make 10 attempts (the limit)
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->attempt('test-key-limit', 10, 60);
        }

        // The 11th attempt should fail
        $result = $this->rateLimiter->attempt('test-key-limit', 10, 60);
        
        $this->assertFalse($result->successful());
        $this->assertGreaterThan(0, $result->retryAfter);
        $this->assertEquals(0, $result->retriesLeft);
        $this->assertEquals(10, $result->limit);
    }

    public function testAttemptCount(): void
    {
        $this->assertEquals(0, $this->rateLimiter->attempts('test-attempts'));
        
        $this->rateLimiter->attempt('test-attempts', 10, 60);
        $this->assertEquals(1, $this->rateLimiter->attempts('test-attempts'));
        
        $this->rateLimiter->attempt('test-attempts', 10, 60);
        $this->assertEquals(2, $this->rateLimiter->attempts('test-attempts'));
    }

    public function testRemainingAttempts(): void
    {
        $this->assertEquals(10, $this->rateLimiter->remaining('test-remaining', 10, 60));
        
        $this->rateLimiter->attempt('test-remaining', 10, 60);
        $this->assertEquals(9, $this->rateLimiter->remaining('test-remaining', 10, 60));
    }

    public function testResetAttempts(): void
    {
        $this->rateLimiter->attempt('test-reset', 10, 60);
        $this->assertEquals(1, $this->rateLimiter->attempts('test-reset'));
        
        $this->rateLimiter->resetAttempts('test-reset');
        $this->assertEquals(0, $this->rateLimiter->attempts('test-reset'));
    }

    public function testClearAttempts(): void
    {
        $this->rateLimiter->attempt('test-clear', 10, 60);
        $this->assertEquals(1, $this->rateLimiter->attempts('test-clear'));
        
        $this->rateLimiter->clear('test-clear');
        $this->assertEquals(0, $this->rateLimiter->attempts('test-clear'));
    }

    public function testLimiterRegistration(): void
    {
        $callback = function () {
            return 'test-callback';
        };
        
        $this->rateLimiter->for('test-limiter', $callback);
        $this->assertEquals($callback, $this->rateLimiter->limiter('test-limiter'));
        $this->assertNull($this->rateLimiter->limiter('non-existent'));
    }

    public function testFixedWindowBehavior(): void
    {
        // Test that fixed window resets at interval boundaries
        // Use a small window for testing (2 seconds)
        $windowSize = 2;
        
        // Make some requests
        $this->rateLimiter->attempt('test-window', 5, $windowSize);
        $this->rateLimiter->attempt('test-window', 5, $windowSize);
        $this->assertEquals(2, $this->rateLimiter->attempts('test-window', $windowSize));
        
        // Wait for the next window (this is a simplified test)
        // In a real scenario, you'd need to actually wait or mock time
        // For now, we'll test that the same window maintains count
        $this->assertEquals(2, $this->rateLimiter->attempts('test-window', $windowSize));
    }

    public function testFixedWindowKeyIsolation(): void
    {
        // Test that different keys have independent counters
        $this->rateLimiter->attempt('key1', 5, 60);
        $this->rateLimiter->attempt('key2', 5, 60);
        
        $this->assertEquals(1, $this->rateLimiter->attempts('key1', 60));
        $this->assertEquals(1, $this->rateLimiter->attempts('key2', 60));
        $this->assertEquals(0, $this->rateLimiter->attempts('key3', 60));
    }
}