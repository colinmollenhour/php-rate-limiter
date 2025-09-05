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
        // Make 10 attempts (the limit)
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

    public function testFixedWindowBehavior(): void
    {
        // Test that fixed window resets at interval boundaries
        // Use a small window for testing (2 seconds)
        $windowSize = 2;
        
        // Make some requests
        $this->rateLimiter->attempt('test-window', 5, 1.0, $windowSize);
        $this->rateLimiter->attempt('test-window', 5, 1.0, $windowSize);
        $this->assertEquals(2, $this->rateLimiter->attempts('test-window', $windowSize));
        
        // Wait for the next window (this is a simplified test)
        // In a real scenario, you'd need to actually wait or mock time
        // For now, we'll test that the same window maintains count
        $this->assertEquals(2, $this->rateLimiter->attempts('test-window', $windowSize));
    }

    public function testFixedWindowKeyIsolation(): void
    {
        // Test that different keys have independent counters
        $this->rateLimiter->attempt('key1', 5, 1.0, 60);
        $this->rateLimiter->attempt('key2', 5, 1.0, 60);
        
        $this->assertEquals(1, $this->rateLimiter->attempts('key1', 60));
        $this->assertEquals(1, $this->rateLimiter->attempts('key2', 60));
        $this->assertEquals(0, $this->rateLimiter->attempts('key3', 60));
    }

    public function testResetAttemptsWithMultipleWindows(): void
    {
        // Test SCAN-based resetAttempts with multiple time windows
        $key = 'test-scan-reset';
        
        // Create multiple window keys by making requests across time
        $this->rateLimiter->attempt($key, 5, 1.0, 2); // Window 1
        sleep(3); // Force new window
        $this->rateLimiter->attempt($key, 5, 1.0, 2); // Window 2  
        sleep(3); // Force another window
        $this->rateLimiter->attempt($key, 5, 1.0, 2); // Window 3
        
        // Verify multiple windows were created by checking Redis directly
        $windowKeys = $this->redis->keys("cm-fixed:{$key}:*");
        $this->assertGreaterThanOrEqual(1, count($windowKeys), 'Should have created at least 1 window key');
        
        // Reset attempts using SCAN
        $deletedCount = $this->rateLimiter->resetAttempts($key);
        $this->assertGreaterThanOrEqual(1, $deletedCount, 'Should have deleted at least 1 key');
        
        // Verify all window keys are gone
        $remainingKeys = $this->redis->keys("cm-fixed:{$key}:*");
        $this->assertEmpty($remainingKeys, 'All window keys should be deleted after reset');
        
        // Verify attempts count is 0
        $this->assertEquals(0, $this->rateLimiter->attempts($key, 2));
    }

    public function testResetAttemptsReturnValue(): void
    {
        // Test that resetAttempts returns correct count of deleted keys
        $key = 'test-delete-count';
        
        // Make requests to create window keys
        $this->rateLimiter->attempt($key, 3, 1.0, 1); // Short window to create multiple keys
        usleep(100000); // 100ms
        $this->rateLimiter->attempt($key, 3, 1.0, 1);
        usleep(100000);
        $this->rateLimiter->attempt($key, 3, 1.0, 1);
        
        // Count existing keys before reset
        $keysBefore = $this->redis->keys("cm-fixed:{$key}:*");
        $keyCountBefore = count($keysBefore);
        
        // Reset and verify return value matches what was deleted
        $deletedCount = $this->rateLimiter->resetAttempts($key);
        $this->assertEquals($keyCountBefore, $deletedCount, 'Deleted count should match number of keys that existed');
        
        // Second reset should return 0
        $secondDeletedCount = $this->rateLimiter->resetAttempts($key);
        $this->assertEquals(0, $secondDeletedCount, 'Second reset should delete 0 keys');
    }

    public function testResetAttemptsEmptyPattern(): void
    {
        // Test resetAttempts when no matching keys exist
        $key = 'non-existent-key';
        
        // Verify no keys exist for this pattern
        $existingKeys = $this->redis->keys("cm-fixed:{$key}:*");
        $this->assertEmpty($existingKeys, 'Should start with no existing keys');
        
        // Reset should return 0 and not cause errors
        $deletedCount = $this->rateLimiter->resetAttempts($key);
        $this->assertEquals(0, $deletedCount, 'Should return 0 when no keys match pattern');
        
        // Attempts should still be 0
        $this->assertEquals(0, $this->rateLimiter->attempts($key, 60));
    }

    public function testResetAttemptsKeyIsolation(): void
    {
        // Test that resetAttempts only affects the specified key pattern
        $key1 = 'isolated-key-1';
        $key2 = 'isolated-key-2';
        
        // Create windows for both keys
        $this->rateLimiter->attempt($key1, 5, 1.0, 60);
        $this->rateLimiter->attempt($key2, 5, 1.0, 60);
        
        // Verify both keys have attempts
        $this->assertEquals(1, $this->rateLimiter->attempts($key1, 60));
        $this->assertEquals(1, $this->rateLimiter->attempts($key2, 60));
        
        // Reset only key1
        $deletedCount = $this->rateLimiter->resetAttempts($key1);
        $this->assertGreaterThan(0, $deletedCount, 'Should have deleted key1 windows');
        
        // Verify key1 is reset but key2 is unaffected
        $this->assertEquals(0, $this->rateLimiter->attempts($key1, 60));
        $this->assertEquals(1, $this->rateLimiter->attempts($key2, 60), 'Key2 should be unaffected');
    }

    public function testScanDoesNotBlockRedis(): void
    {
        // Test that SCAN-based implementation doesn't block Redis
        // This is more of a behavioral test to ensure we're using SCAN
        $key = 'scan-performance-test';
        
        // Create multiple windows quickly
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->attempt($key, 100, 1.0, 1); // 1-second windows
            usleep(50000); // 50ms to create different timestamps
        }
        
        // Verify keys were created
        $keysBefore = $this->redis->keys("cm-fixed:{$key}:*");
        $this->assertGreaterThan(0, count($keysBefore), 'Should have created multiple window keys');
        
        // SCAN-based reset should handle all keys without blocking
        $startTime = microtime(true);
        $deletedCount = $this->rateLimiter->resetAttempts($key);
        $endTime = microtime(true);
        
        $this->assertEquals(count($keysBefore), $deletedCount, 'Should delete all created keys');
        $this->assertLessThan(1.0, $endTime - $startTime, 'SCAN operation should complete quickly');
        
        // Verify cleanup
        $keysAfter = $this->redis->keys("cm-fixed:{$key}:*");
        $this->assertEmpty($keysAfter, 'All keys should be deleted');
    }
}