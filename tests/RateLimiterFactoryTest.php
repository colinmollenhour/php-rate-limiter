<?php

namespace Cm\RateLimiter\Tests;

use PHPUnit\Framework\TestCase;
use Cm\RateLimiter\RateLimiterFactory;
use Cm\RateLimiter\RateLimiterInterface;
use Cm\RateLimiter\SlidingWindow\RateLimiter as SlidingWindowRateLimiter;
use Cm\RateLimiter\FixedWindow\RateLimiter as FixedWindowRateLimiter;
use Cm\RateLimiter\LeakyBucket\RateLimiter as LeakyBucketRateLimiter;
use Credis_Client;

class RateLimiterFactoryTest extends TestCase
{
    private RateLimiterFactory $factory;
    private Credis_Client $redis;

    protected function setUp(): void
    {
        $this->redis = new Credis_Client('127.0.0.1', 6379);
        $this->factory = new RateLimiterFactory($this->redis);
        
        // Clear any existing test keys
        $this->redis->flushdb();
    }

    protected function tearDown(): void
    {
        $this->redis->flushdb();
    }

    public function testCreateSlidingWindow(): void
    {
        $rateLimiter = $this->factory->createSlidingWindow();
        
        $this->assertInstanceOf(RateLimiterInterface::class, $rateLimiter);
        $this->assertInstanceOf(SlidingWindowRateLimiter::class, $rateLimiter);
    }

    public function testCreateFixedWindow(): void
    {
        $rateLimiter = $this->factory->createFixedWindow();
        
        $this->assertInstanceOf(RateLimiterInterface::class, $rateLimiter);
        $this->assertInstanceOf(FixedWindowRateLimiter::class, $rateLimiter);
    }

    public function testSlidingWindowRateLimiterWorks(): void
    {
        $rateLimiter = $this->factory->createSlidingWindow();
        
        // Test that the created rate limiter actually works
        $result = $rateLimiter->attempt('sliding-factory-test', 5, 30);
        $this->assertTrue($result->successful());
        $this->assertEquals(4, $result->retriesLeft);
    }

    public function testFixedWindowRateLimiterWorks(): void
    {
        $rateLimiter = $this->factory->createFixedWindow();
        
        // Test that the created rate limiter actually works
        $result = $rateLimiter->attempt('fixed-factory-test', 5, 30);
        $this->assertTrue($result->successful());
        $this->assertEquals(4, $result->retriesLeft);
    }

    public function testCreateLeakyBucket(): void
    {
        $rateLimiter = $this->factory->createLeakyBucket();
        
        $this->assertInstanceOf(RateLimiterInterface::class, $rateLimiter);
        $this->assertInstanceOf(LeakyBucketRateLimiter::class, $rateLimiter);
    }

    public function testLeakyBucketRateLimiterWorks(): void
    {
        $rateLimiter = $this->factory->createLeakyBucket();
        
        // Test that the created rate limiter actually works
        $result = $rateLimiter->attempt('leaky-factory-test', 5, 30);
        $this->assertTrue($result->successful());
        $this->assertEquals(4, $result->retriesLeft);
    }

    public function testAllAlgorithmsAreIndependent(): void
    {
        $slidingWindow = $this->factory->createSlidingWindow();
        $fixedWindow = $this->factory->createFixedWindow();
        $leakyBucket = $this->factory->createLeakyBucket();
        
        // Use the same key but different algorithms - they should be independent
        $slidingResult = $slidingWindow->attempt('independence-test', 5, 30);
        $fixedResult = $fixedWindow->attempt('independence-test', 5, 30);
        $leakyResult = $leakyBucket->attempt('independence-test', 5, 30);
        
        $this->assertTrue($slidingResult->successful());
        $this->assertTrue($fixedResult->successful());
        $this->assertTrue($leakyResult->successful());
        $this->assertEquals(4, $slidingResult->retriesLeft);
        $this->assertEquals(4, $fixedResult->retriesLeft);
        $this->assertEquals(4, $leakyResult->retriesLeft);
        
        // Check that they have different key prefixes
        $this->assertEquals(1, $slidingWindow->attempts('independence-test', 30));
        $this->assertEquals(1, $fixedWindow->attempts('independence-test', 30));
        $this->assertEquals(1, $leakyBucket->attempts('independence-test', 30));
    }
}