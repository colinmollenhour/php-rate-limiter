<?php

namespace Cm\RateLimiter\ConcurrencyAware;

class LuaScripts
{
    // SHA1 hashes for Lua scripts
    public const CHECKCONCURRENCY_SHA = '314d03146ccb8e98152f5773c624fd6de43a1ac0';
    public const CURRENTCONCURRENCY_SHA = 'c772250dfe0ede49c159d69f8cedf1d4b82fa1bb';
    public const CLEANUPEXPIRED_SHA = '7fbb0b82bd81c67f537c16cf4e33d4ea2d969291';

    public static function checkConcurrency(): string
    {
        return <<<'LUA'
            local concurrency_key = KEYS[1]
            local request_id = ARGV[1]
            local max_concurrent = tonumber(ARGV[2])
            local timeout_seconds = tonumber(ARGV[3])
            
            local current_time = redis.call('TIME')
            local timestamp = tonumber(current_time[1])
            
            -- Clean up expired concurrency requests
            local cleanup_threshold = timestamp - timeout_seconds
            redis.call('ZREMRANGEBYSCORE', concurrency_key, 0, cleanup_threshold)
            
            -- Check concurrency limit
            local current_concurrent = redis.call('ZCARD', concurrency_key)
            if current_concurrent >= max_concurrent then
                return {0, current_concurrent}
            end
            
            -- Acquire concurrency slot
            redis.call('ZADD', concurrency_key, timestamp, request_id)
            redis.call('EXPIRE', concurrency_key, timeout_seconds * 2)
            
            return {1, current_concurrent + 1}
LUA;
    }
    
    public static function currentConcurrency(): string
    {
        return <<<'LUA'
            local concurrency_key = KEYS[1]
            local timeout_seconds = tonumber(ARGV[1])
            local current_time = redis.call('TIME')
            local timestamp = tonumber(current_time[1])
            
            -- Clean up expired requests first
            local cleanup_threshold = timestamp - timeout_seconds
            redis.call('ZREMRANGEBYSCORE', concurrency_key, 0, cleanup_threshold)
            
            return redis.call('ZCARD', concurrency_key)
LUA;
    }
    
    public static function cleanupExpired(): string
    {
        return <<<'LUA'
            local concurrency_key = KEYS[1]
            local timeout_seconds = tonumber(ARGV[1])
            local current_time = redis.call('TIME')
            local timestamp = tonumber(current_time[1])
            
            local cleanup_threshold = timestamp - timeout_seconds
            return redis.call('ZREMRANGEBYSCORE', concurrency_key, 0, cleanup_threshold)
LUA;
    }
}