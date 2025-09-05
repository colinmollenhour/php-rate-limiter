<?php

namespace Cm\RateLimiter\FixedWindow;

class LuaScripts
{
    // SHA1 hashes for Lua scripts
    public const ATTEMPT_SHA = '70142323ec50fdfd4ece46f081c418840e8f41d5';
    public const ATTEMPTS_SHA = '41d3109b4aeb1f0509c4307b136b4bcde280c491';
    public const AVAILABLEIN_SHA = 'e0aef0574fd6ef0dd5994c37adce363b8bdae8ae';
    public const RESETATTEMPTS_SHA = '4671b302f7371df0f5eaeecb854ac3678a2cdc3e';

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
            local keys_to_delete = {}
            local cursor = '0'
            local deleted_count = 0
            
            repeat
                local result = redis.call('SCAN', cursor, 'MATCH', key_pattern, 'COUNT', 100)
                cursor = result[1]
                local found_keys = result[2]
                
                if #found_keys > 0 then
                    deleted_count = deleted_count + redis.call('DEL', unpack(found_keys))
                end
            until cursor == '0'
            
            return deleted_count
LUA;
    }
}