<?php

namespace Cm\RateLimiter\LeakyBucket;

class LuaScripts
{
    // SHA1 hashes for Lua scripts
    public const ATTEMPT_SHA = 'f071db52a3de9d573e08dc4f851fafc927620762';
    public const ATTEMPTS_SHA = 'fa962095f632bc9bd24658c8b12a41cdae89998e';
    public const AVAILABLEIN_SHA = 'dabba91a59ab9a91b3228101dd61780da3670f71';

    public static function attempt(): string
    {
        return <<<'LUA'
            local current_time = redis.call('TIME')[1]
            local key = KEYS[1]
            local leak_rate = tonumber(ARGV[1])
            local bucket_capacity = tonumber(ARGV[2])
            
            -- Get bucket state: [level, last_leak_time]
            local bucket_data = redis.call('HMGET', key, 'level', 'last_leak')
            local bucket_level = tonumber(bucket_data[1] or 0)
            local last_leak_time = tonumber(bucket_data[2] or current_time)
            
            -- Calculate how much the bucket has leaked since last access
            local time_passed = current_time - last_leak_time
            local leaked_amount = math.floor(time_passed / leak_rate)
            
            -- Update bucket level (can't go below 0)
            bucket_level = math.max(0, bucket_level - leaked_amount)
            
            -- Check if bucket is full
            if bucket_level >= bucket_capacity then
                -- Calculate when next request will be available
                local next_leak_time = last_leak_time + ((bucket_level - bucket_capacity + 1) * leak_rate)
                local retry_after = math.ceil(next_leak_time - current_time)
                return {retry_after, 0, bucket_capacity}
            end
            
            -- Add this request to the bucket
            bucket_level = bucket_level + 1
            
            -- Update bucket state
            redis.call('HSET', key, 'level', bucket_level, 'last_leak', current_time)
            redis.call('EXPIRE', key, bucket_capacity * leak_rate + 60)
            
            local retries_left = bucket_capacity - bucket_level
            return {0, retries_left, bucket_capacity}
LUA;
    }

    public static function attempts(): string
    {
        return <<<'LUA'
            local current_time = redis.call('TIME')[1]
            local key = KEYS[1]
            local leak_rate = tonumber(ARGV[1])
            
            -- Get bucket state
            local bucket_data = redis.call('HMGET', key, 'level', 'last_leak')
            local bucket_level = tonumber(bucket_data[1] or 0)
            local last_leak_time = tonumber(bucket_data[2] or current_time)
            
            -- Calculate leaked amount since last access
            local time_passed = current_time - last_leak_time
            local leaked_amount = math.floor(time_passed / leak_rate)
            
            -- Update bucket level
            bucket_level = math.max(0, bucket_level - leaked_amount)
            
            return bucket_level
LUA;
    }

    public static function availableIn(): string
    {
        return <<<'LUA'
            local current_time = redis.call('TIME')[1]
            local key = KEYS[1]
            local leak_rate = tonumber(ARGV[1])
            local bucket_capacity = tonumber(ARGV[2])
            
            -- Get bucket state
            local bucket_data = redis.call('HMGET', key, 'level', 'last_leak')
            local bucket_level = tonumber(bucket_data[1] or 0)
            local last_leak_time = tonumber(bucket_data[2] or current_time)
            
            -- Calculate leaked amount since last access
            local time_passed = current_time - last_leak_time
            local leaked_amount = math.floor(time_passed / leak_rate)
            
            -- Update bucket level
            bucket_level = math.max(0, bucket_level - leaked_amount)
            
            -- Check if bucket is full
            if bucket_level >= bucket_capacity then
                -- Calculate when next request will be available
                local next_leak_time = last_leak_time + ((bucket_level - bucket_capacity + 1) * leak_rate)
                local available_in = math.ceil(next_leak_time - current_time)
                return available_in
            end
            
            return 0
LUA;
    }

}