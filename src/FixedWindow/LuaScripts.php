<?php

namespace Cm\RateLimiter\FixedWindow;

class LuaScripts
{
    public static function attempt(): string
    {
        return <<<'LUA'
            local current_time = redis.call('TIME')[1]
            local key = KEYS[1]
            local window = tonumber(ARGV[1])
            local max_requests = tonumber(ARGV[2])
            
            -- Calculate the current window start time
            local window_start = math.floor(current_time / window) * window
            local window_key = key .. ':' .. window_start
            
            -- Get current count
            local current_count = tonumber(redis.call('GET', window_key) or 0)
            
            -- Check if we've exceeded the limit
            if current_count >= max_requests then
                local window_end = window_start + window
                local retry_after = window_end - current_time
                return {retry_after, 0, max_requests}
            end
            
            -- Increment and set expiry
            local new_count = redis.call('INCR', window_key)
            redis.call('EXPIRE', window_key, window)
            
            local retries_left = max_requests - new_count
            return {0, retries_left, max_requests}
LUA;
    }

    public static function attempts(): string
    {
        return <<<'LUA'
            local current_time = redis.call('TIME')[1]
            local key = KEYS[1]
            local window = tonumber(ARGV[1])
            
            -- Calculate the current window start time
            local window_start = math.floor(current_time / window) * window
            local window_key = key .. ':' .. window_start
            
            -- Get current count
            local current_count = tonumber(redis.call('GET', window_key) or 0)
            return current_count
LUA;
    }

    public static function availableIn(): string
    {
        return <<<'LUA'
            local current_time = redis.call('TIME')[1]
            local key = KEYS[1]
            local window = tonumber(ARGV[1])
            local max_requests = tonumber(ARGV[2])
            
            -- Calculate the current window start time
            local window_start = math.floor(current_time / window) * window
            local window_key = key .. ':' .. window_start
            
            -- Get current count
            local current_count = tonumber(redis.call('GET', window_key) or 0)
            
            if current_count >= max_requests then
                local window_end = window_start + window
                local available_in = window_end - current_time
                return available_in
            end
            
            return 0
LUA;
    }

    public static function resetAttempts(): string
    {
        return <<<'LUA'
            local key_pattern = KEYS[1] .. ':*'
            local keys_to_delete = redis.call('KEYS', key_pattern)
            
            if #keys_to_delete > 0 then
                return redis.call('DEL', unpack(keys_to_delete))
            end
            
            return 0
LUA;
    }
}