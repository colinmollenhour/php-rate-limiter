<?php

namespace Cm\RateLimiter\Tests;

use PHPUnit\Framework\TestCase;
use Cm\RateLimiter\TokenBucket\RateLimiter;
use Cm\RateLimiter\RateLimiterResult;
use Cm\RateLimiter\RateLimiterFactory;
use Credis_Client;

class TokenBucketRateLimiterTest extends TestCase
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

    public function testSuccessfulAttempt(): void
    {
        $result = $this->rateLimiter->attempt('test-key', 10, 1.0, 60);
        
        $this->assertInstanceOf(RateLimiterResult::class, $result);
        $this->assertTrue($result->successful());
        $this->assertEquals(0, $result->retryAfter);
        $this->assertEquals(9, $result->retriesLeft);
        $this->assertEquals(10, $result->limit);
    }

    public function testTooManyAttempts(): void
    {
        // Make 10 attempts rapidly (should consume all tokens)
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->attempt('test-key-limit', 10, 1.0, 60);
        }

        // The 11th attempt should fail
        $result = $this->rateLimiter->attempt('test-key-limit', 10, 1.0, 60);
        
        $this->assertFalse($result->successful());
        $this->assertGreaterThan(0, $result->retryAfter);
        $this->assertEquals(0, $result->retriesLeft);
        $this->assertEquals(10, $result->limit);
    }

    public function testTokenRefill(): void
    {
        // Use up all tokens
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->attempt('test-refill', 10, 1.0, 60);
        }

        // Should be out of tokens
        $result = $this->rateLimiter->attempt('test-refill', 10, 1.0, 60);
        $this->assertFalse($result->successful());

        // Wait a bit for tokens to refill (simulate time passing)
        // In a real test environment, you might want to mock time or use Redis TIME manipulation
        sleep(2); // Wait 2 seconds, should get 2 tokens back (1 req/sec)

        // Should now have a token available
        $result = $this->rateLimiter->attempt('test-refill', 10, 1.0, 60);
        $this->assertTrue($result->successful());
    }

    public function testAttemptCount(): void
    {
        $this->assertEquals(0, $this->rateLimiter->attempts('test-attempts', 60));
        
        $this->rateLimiter->attempt('test-attempts', 10, 1.0, 60);
        $this->assertEquals(1, $this->rateLimiter->attempts('test-attempts', 60));
        
        $this->rateLimiter->attempt('test-attempts', 10, 1.0, 60);
        $this->assertEquals(2, $this->rateLimiter->attempts('test-attempts', 60));
    }

    public function testRemainingAttempts(): void
    {
        $this->assertEquals(10, $this->rateLimiter->remaining('test-remaining', 10, 1.0, 60));
        
        $this->rateLimiter->attempt('test-remaining', 10, 1.0, 60);
        $this->assertEquals(9, $this->rateLimiter->remaining('test-remaining', 10, 1.0, 60));
    }

    public function testResetAttempts(): void
    {
        $this->rateLimiter->attempt('test-reset', 10, 1.0, 60);
        $this->assertEquals(1, $this->rateLimiter->attempts('test-reset', 60));
        
        $this->rateLimiter->resetAttempts('test-reset');
        $this->assertEquals(0, $this->rateLimiter->attempts('test-reset', 60));
    }

    public function testClearAttempts(): void
    {
        $this->rateLimiter->attempt('test-clear', 10, 1.0, 60);
        $this->assertEquals(1, $this->rateLimiter->attempts('test-clear', 60));
        
        $this->rateLimiter->clear('test-clear');
        $this->assertEquals(0, $this->rateLimiter->attempts('test-clear', 60));
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

    public function testAvailableIn(): void
    {
        // Start with full bucket, should be available immediately
        $this->assertEquals(0, $this->rateLimiter->availableIn('test-available', 10, 1.0, 60));
        
        // Use up all tokens
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->attempt('test-available', 10, 1.0, 60);
        }
        
        // Should now have a wait time
        $availableIn = $this->rateLimiter->availableIn('test-available', 10, 1.0, 60);
        $this->assertGreaterThan(0, $availableIn);
        $this->assertLessThanOrEqual(1, $availableIn); // Should be close to 1 second per token
    }

    public function testFactoryCreation(): void
    {
        $factory = new RateLimiterFactory($this->redis);
        $tokenBucketLimiter = $factory->createTokenBucket();
        
        $this->assertInstanceOf(RateLimiter::class, $tokenBucketLimiter);
        
        $result = $tokenBucketLimiter->attempt('factory-test', 5, 1.0, 30);
        $this->assertTrue($result->successful());
        $this->assertEquals(4, $result->retriesLeft);
    }
}