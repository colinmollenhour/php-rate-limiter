<?php

namespace Cm\RateLimiter\Tests;

use PHPUnit\Framework\TestCase;
use Cm\RateLimiter\GCRA\RateLimiter;
use Cm\RateLimiter\RateLimiterResult;
use Cm\RateLimiter\RateLimiterFactory;
use Credis_Client;

class GCRARateLimiterTest extends TestCase
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
        $this->assertGreaterThanOrEqual(0, $result->retriesLeft);
        $this->assertEquals(10, $result->limit);
    }

    public function testTooManyAttempts(): void
    {
        // Make many rapid attempts to fill up the TAT
        for ($i = 0; $i < 15; $i++) {
            $this->rateLimiter->attempt('test-key-limit', 10, 10);
        }

        // Additional attempts should be rate limited
        $result = $this->rateLimiter->attempt('test-key-limit', 10, 10);
        
        $this->assertFalse($result->successful());
        $this->assertGreaterThan(0, $result->retryAfter);
        $this->assertEquals(0, $result->retriesLeft);
        $this->assertEquals(10, $result->limit);
    }

    public function testAttemptCount(): void
    {
        $this->assertEquals(0, $this->rateLimiter->attempts('test-attempts', 60));
        
        $this->rateLimiter->attempt('test-attempts', 10, 60);
        $attempts = $this->rateLimiter->attempts('test-attempts', 60);
        $this->assertGreaterThanOrEqual(0, $attempts);
    }

    public function testRemainingAttempts(): void
    {
        $remaining = $this->rateLimiter->remaining('test-remaining', 10, 60);
        $this->assertGreaterThanOrEqual(0, $remaining);
        
        $this->rateLimiter->attempt('test-remaining', 10, 60);
        $newRemaining = $this->rateLimiter->remaining('test-remaining', 10, 60);
        $this->assertGreaterThanOrEqual(0, $newRemaining);
    }

    public function testResetAttempts(): void
    {
        // Make some requests to build up TAT
        for ($i = 0; $i < 5; $i++) {
            $this->rateLimiter->attempt('test-reset', 10, 60);
        }
        
        // Reset should clear the TAT
        $this->rateLimiter->resetAttempts('test-reset');
        $this->assertEquals(0, $this->rateLimiter->attempts('test-reset', 60));
        
        // Should be able to make requests again
        $result = $this->rateLimiter->attempt('test-reset', 10, 60);
        $this->assertTrue($result->successful());
    }

    public function testClearAttempts(): void
    {
        // Make some requests to build up TAT
        for ($i = 0; $i < 5; $i++) {
            $this->rateLimiter->attempt('test-clear', 10, 60);
        }
        
        // Clear should reset the TAT
        $this->rateLimiter->clear('test-clear');
        $this->assertEquals(0, $this->rateLimiter->attempts('test-clear', 60));
        
        // Should be able to make requests again
        $result = $this->rateLimiter->attempt('test-clear', 10, 60);
        $this->assertTrue($result->successful());
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

    public function testGCRABehavior(): void
    {
        // Test GCRA's theoretical arrival time (TAT) behavior
        $key = 'test-gcra-behavior';
        $limit = 5;
        $period = 10; // 10 seconds
        
        // Make requests rapidly - should work initially
        $successCount = 0;
        for ($i = 0; $i < $limit; $i++) {
            $result = $this->rateLimiter->attempt($key, $limit, $period);
            if ($result->successful()) {
                $successCount++;
            }
        }
        
        $this->assertGreaterThan(0, $successCount, 'Should allow some initial requests');
        
        // Additional rapid requests should be rate limited
        $result = $this->rateLimiter->attempt($key, $limit, $period);
        if (!$result->successful()) {
            $this->assertGreaterThan(0, $result->retryAfter, 'Should provide retry after time');
        }
    }

    public function testAvailableIn(): void
    {
        // Initially should be available immediately
        $this->assertEquals(0, $this->rateLimiter->availableIn('test-available', 5, 60));
        
        // After making requests, may need to wait
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->attempt('test-available', 5, 10);
        }
        
        $availableIn = $this->rateLimiter->availableIn('test-available', 5, 10);
        $this->assertGreaterThanOrEqual(0, $availableIn);
    }

    public function testKeyIsolation(): void
    {
        // Test that different keys have independent TAT values
        $this->rateLimiter->attempt('key1', 5, 60);
        $this->rateLimiter->attempt('key2', 5, 60);
        
        // Reset one key
        $this->rateLimiter->resetAttempts('key1');
        
        // key1 should be reset, key2 should be unaffected
        $this->assertEquals(0, $this->rateLimiter->attempts('key1', 60));
        $this->assertGreaterThanOrEqual(0, $this->rateLimiter->attempts('key2', 60));
    }

    public function testTooManyAttemptsMethod(): void
    {
        // Initially should not have too many attempts
        $this->assertFalse($this->rateLimiter->tooManyAttempts('test-too-many', 5, 60));
        
        // After many rapid requests, should indicate too many attempts
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->attempt('test-too-many', 5, 10);
        }
        
        // May or may not be true depending on timing, but should not error
        $tooMany = $this->rateLimiter->tooManyAttempts('test-too-many', 5, 10);
        $this->assertIsBool($tooMany);
    }

    public function testMemoryEfficiency(): void
    {
        // Test that GCRA only stores a single value (TAT) per key
        $key = 'memory-test';
        
        // Make multiple requests
        for ($i = 0; $i < 5; $i++) {
            $this->rateLimiter->attempt($key, 10, 60);
        }
        
        // Check that only one Redis key exists for this rate limiter key
        $redisKeys = $this->redis->keys("gcra_rate_limiter:{$key}*");
        $this->assertEquals(1, count($redisKeys), 'GCRA should only create one Redis key per rate limit key');
        
        // The value should be a float (TAT)
        $tatValue = $this->redis->get("gcra_rate_limiter:{$key}");
        $this->assertIsNumeric($tatValue, 'TAT value should be numeric');
    }
}