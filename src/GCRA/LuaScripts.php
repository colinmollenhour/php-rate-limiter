<?php

namespace Cm\RateLimiter\GCRA;

class LuaScripts
{
    public static function attempt(): string
    {
        return <<<'LUA'
            local key = KEYS[1]
            local period_in_seconds = tonumber(ARGV[1])
            local limit = tonumber(ARGV[2])
            
            -- Get current Redis time with microsecond precision
            local redis_time = redis.call('TIME')
            local now = tonumber(redis_time[1]) + (tonumber(redis_time[2]) / 1000000)
            
            -- Calculate separation between requests
            local separation = period_in_seconds / limit
            
            -- Initialize key if it doesn't exist
            local current_tat = redis.call('GET', key)
            if not current_tat then
                current_tat = now
            else
                current_tat = tonumber(current_tat)
            end
            
            -- Calculate theoretical arrival time (TAT)
            local tat = math.max(current_tat, now)
            
            -- Check if request should be allowed
            if tat - now <= period_in_seconds - separation then
                -- Allow request and update TAT
                local new_tat = math.max(tat, now) + separation
                redis.call('SET', key, new_tat, 'EX', math.ceil(period_in_seconds * 2))
                
                -- Calculate remaining attempts and retry after
                local time_until_next = math.max(0, new_tat - now)
                local requests_ahead = math.floor(time_until_next / separation)
                local retries_left = math.max(0, limit - requests_ahead - 1)
                
                return {0, retries_left, limit}
            else
                -- Deny request
                local retry_after = math.ceil(tat - now - period_in_seconds + separation)
                return {retry_after, 0, limit}
            end
LUA;
    }

    public static function attempts(): string
    {
        return <<<'LUA'
            local key = KEYS[1]
            local period_in_seconds = tonumber(ARGV[1])
            local limit = tonumber(ARGV[2])
            
            -- Get current Redis time
            local redis_time = redis.call('TIME')
            local now = tonumber(redis_time[1]) + (tonumber(redis_time[2]) / 1000000)
            
            -- Calculate separation between requests
            local separation = period_in_seconds / limit
            
            -- Get current TAT
            local current_tat = redis.call('GET', key)
            if not current_tat then
                return 0
            end
            
            current_tat = tonumber(current_tat)
            local tat = math.max(current_tat, now)
            
            -- Calculate how many requests are "queued" based on TAT
            local time_ahead = math.max(0, tat - now)
            local requests_ahead = math.floor(time_ahead / separation)
            
            return math.min(requests_ahead, limit)
LUA;
    }

    public static function availableIn(): string
    {
        return <<<'LUA'
            local key = KEYS[1]
            local period_in_seconds = tonumber(ARGV[1])
            local limit = tonumber(ARGV[2])
            
            -- Get current Redis time
            local redis_time = redis.call('TIME')
            local now = tonumber(redis_time[1]) + (tonumber(redis_time[2]) / 1000000)
            
            -- Calculate separation between requests
            local separation = period_in_seconds / limit
            
            -- Get current TAT
            local current_tat = redis.call('GET', key)
            if not current_tat then
                return 0
            end
            
            current_tat = tonumber(current_tat)
            local tat = math.max(current_tat, now)
            
            -- Check if request would be allowed
            if tat - now <= period_in_seconds - separation then
                return 0
            else
                return math.ceil(tat - now - period_in_seconds + separation)
            end
LUA;
    }

    public static function resetAttempts(): string
    {
        return <<<'LUA'
            local key = KEYS[1]
            return redis.call('DEL', key)
LUA;
    }
}