<?php

namespace Cm\RateLimiter\Tests;

use PHPUnit\Framework\TestCase;
use Cm\RateLimiter\RateLimiterFactory;
use Cm\RateLimiter\RateLimiterInterface;
use Cm\RateLimiter\ConcurrencyAwareRateLimiterInterface;
use Cm\RateLimiter\SlidingWindow\RateLimiter as SlidingWindowRateLimiter;
use Cm\RateLimiter\FixedWindow\RateLimiter as FixedWindowRateLimiter;
use Cm\RateLimiter\LeakyBucket\RateLimiter as LeakyBucketRateLimiter;
use Cm\RateLimiter\GCRA\RateLimiter as GCRARateLimiter;
use Cm\RateLimiter\TokenBucket\RateLimiter as TokenBucketRateLimiter;
use Cm\RateLimiter\ConcurrencyAware\RateLimiter as ConcurrencyAwareRateLimiter;
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
        $result = $rateLimiter->attempt('sliding-factory-test', 5, 5.0/30, 30);
        $this->assertTrue($result->successful());
        $this->assertEquals(4, $result->retriesLeft);
    }

    public function testFixedWindowRateLimiterWorks(): void
    {
        $rateLimiter = $this->factory->createFixedWindow();
        
        // Test that the created rate limiter actually works
        $result = $rateLimiter->attempt('fixed-factory-test', 5, 1.0, 30);
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
        $result = $rateLimiter->attempt('leaky-factory-test', 5, 1.0, 30);
        $this->assertTrue($result->successful());
        $this->assertEquals(4, $result->retriesLeft);
    }

    public function testCreateGCRA(): void
    {
        $rateLimiter = $this->factory->createGCRA();
        
        $this->assertInstanceOf(RateLimiterInterface::class, $rateLimiter);
        $this->assertInstanceOf(GCRARateLimiter::class, $rateLimiter);
    }

    public function testGCRARateLimiterWorks(): void
    {
        $rateLimiter = $this->factory->createGCRA();
        
        // Test that the created rate limiter actually works
        $result = $rateLimiter->attempt('gcra-factory-test', 5, 5.0/30, 30);
        $this->assertTrue($result->successful());
        $this->assertGreaterThanOrEqual(0, $result->retriesLeft);
    }

    public function testCreateTokenBucket(): void
    {
        $rateLimiter = $this->factory->createTokenBucket();
        
        $this->assertInstanceOf(RateLimiterInterface::class, $rateLimiter);
        $this->assertInstanceOf(TokenBucketRateLimiter::class, $rateLimiter);
    }

    public function testTokenBucketRateLimiterWorks(): void
    {
        $rateLimiter = $this->factory->createTokenBucket();
        
        // Test that the created rate limiter actually works
        $result = $rateLimiter->attempt('token-bucket-factory-test', 5, 1.0, 30);
        $this->assertTrue($result->successful());
        $this->assertEquals(4, $result->retriesLeft);
    }

    public function testAllAlgorithmsAreIndependent(): void
    {
        $slidingWindow = $this->factory->createSlidingWindow();
        $fixedWindow = $this->factory->createFixedWindow();
        $leakyBucket = $this->factory->createLeakyBucket();
        $gcra = $this->factory->createGCRA();
        $tokenBucket = $this->factory->createTokenBucket();
        
        // Use the same key but different algorithms - they should be independent
        $slidingResult = $slidingWindow->attempt('independence-test', 5, 5.0/30, 30);
        $fixedResult = $fixedWindow->attempt('independence-test', 5, 1.0, 30);
        $leakyResult = $leakyBucket->attempt('independence-test', 5, 1.0, 30);
        $gcraResult = $gcra->attempt('independence-test', 5, 5.0/30, 30);
        $tokenResult = $tokenBucket->attempt('independence-test', 5, 1.0, 30);
        
        $this->assertTrue($slidingResult->successful());
        $this->assertTrue($fixedResult->successful());
        $this->assertTrue($leakyResult->successful());
        $this->assertTrue($gcraResult->successful());
        $this->assertTrue($tokenResult->successful());
        $this->assertEquals(4, $slidingResult->retriesLeft);
        $this->assertEquals(4, $fixedResult->retriesLeft);
        $this->assertEquals(4, $leakyResult->retriesLeft);
        $this->assertGreaterThanOrEqual(0, $gcraResult->retriesLeft);
        $this->assertEquals(4, $tokenResult->retriesLeft);
        
        // Check that they have different key prefixes
        $this->assertEquals(1, $slidingWindow->attempts('independence-test', 30));
        $this->assertEquals(1, $fixedWindow->attempts('independence-test', 30));
        $this->assertEquals(1, $leakyBucket->attempts('independence-test', 30));
        $this->assertGreaterThanOrEqual(0, $gcra->attempts('independence-test', 30));
        $this->assertEquals(1, $tokenBucket->attempts('independence-test', 30));
    }

    public function testCreateConcurrencyAware(): void
    {
        $rateLimiter = $this->factory->createConcurrencyAware();
        
        $this->assertInstanceOf(ConcurrencyAwareRateLimiterInterface::class, $rateLimiter);
        $this->assertInstanceOf(ConcurrencyAwareRateLimiter::class, $rateLimiter);
    }

    public function testConcurrencyAwareRateLimiterWorks(): void
    {
        $rateLimiter = $this->factory->createConcurrencyAware();
        
        // Test basic concurrency-aware functionality
        $result = $rateLimiter->attemptWithConcurrency(
            'concurrency-factory-test', 
            'req1', 
            2,    // maxConcurrent
            10,   // burstCapacity
            5.0,  // sustainedRate
            60,   // window
            30    // timeoutSeconds
        );
        
        $this->assertTrue($result->successful());
        $this->assertTrue($result->concurrencyAcquired);
        $this->assertEquals(1, $result->currentConcurrency);
        $this->assertEquals(2, $result->maxConcurrency);
        
        // Test backward compatibility
        $backwardResult = $rateLimiter->attempt('backward-compat-test', 10, 5.0, 60);
        $this->assertTrue($backwardResult->successful());
        $this->assertGreaterThan(0, $backwardResult->retriesLeft);
    }
}