<?php

namespace Cm\RateLimiter\TokenBucket;

class LuaScripts
{
    public static function attempt(): string
    {
        return <<<'LUA'
            local current_time = redis.call('TIME')
            local key = KEYS[1]
            local refill_period = tonumber(ARGV[1])
            local max_tokens = tonumber(ARGV[2])
            local current_timestamp = tonumber(current_time[1])
            
            local bucket = redis.call('HMGET', key, 'tokens', 'last_refill', 'attempts', 'window_start')
            local tokens = tonumber(bucket[1]) or max_tokens
            local last_refill = tonumber(bucket[2]) or current_timestamp
            local attempts = tonumber(bucket[3]) or 0
            local window_start = tonumber(bucket[4]) or current_timestamp
            
            -- Calculate tokens to add based on time elapsed
            local time_passed = current_timestamp - last_refill
            local tokens_to_add = math.floor(time_passed * max_tokens / refill_period)
            local new_tokens = math.min(max_tokens, tokens + tokens_to_add)
            
            -- Reset attempts if we've moved to a new window (refill period elapsed)
            if time_passed >= refill_period then
                attempts = 0
                window_start = current_timestamp
            end
            
            if new_tokens < 1 then
                -- No tokens available
                local time_until_token = math.ceil(refill_period / max_tokens)
                return {time_until_token, 0, max_tokens}
            end
            
            -- Consume one token and increment attempts
            new_tokens = new_tokens - 1
            attempts = attempts + 1
            
            -- Update bucket state
            redis.call('HMSET', key, 'tokens', new_tokens, 'last_refill', current_timestamp, 'attempts', attempts, 'window_start', window_start)
            redis.call('EXPIRE', key, refill_period * 2)
            
            local retries_left = max_tokens - attempts
            return {0, retries_left, max_tokens}
LUA;
    }

    public static function attempts(): string
    {
        return <<<'LUA'
            local current_time = redis.call('TIME')
            local key = KEYS[1]
            local refill_period = tonumber(ARGV[1])
            local current_timestamp = tonumber(current_time[1])
            
            local bucket = redis.call('HMGET', key, 'attempts', 'window_start')
            local attempts = tonumber(bucket[1]) or 0
            local window_start = tonumber(bucket[2]) or current_timestamp
            
            -- Reset attempts if we've moved to a new window (refill period elapsed)
            local time_passed = current_timestamp - window_start
            if time_passed >= refill_period then
                attempts = 0
            end
            
            return attempts
LUA;
    }

    public static function availableIn(): string
    {
        return <<<'LUA'
            local current_time = redis.call('TIME')
            local key = KEYS[1]
            local refill_period = tonumber(ARGV[1])
            local max_tokens = tonumber(ARGV[2])
            local current_timestamp = tonumber(current_time[1])
            
            local bucket = redis.call('HMGET', key, 'tokens', 'last_refill')
            local tokens = tonumber(bucket[1]) or max_tokens
            local last_refill = tonumber(bucket[2]) or current_timestamp
            
            -- Calculate tokens to add based on time elapsed
            local time_passed = current_timestamp - last_refill
            local tokens_to_add = math.floor(time_passed * max_tokens / refill_period)
            tokens = math.min(max_tokens, tokens + tokens_to_add)
            
            if tokens >= 1 then
                return 0
            end
            
            -- Calculate time until next token
            return math.ceil(refill_period / max_tokens)
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