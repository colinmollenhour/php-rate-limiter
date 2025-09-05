<?php

namespace Cm\RateLimiter\TokenBucket;

class LuaScripts
{
    // SHA1 hashes for Lua scripts
    public const ATTEMPT_SHA = '2b0ed9096885ea7dba14aa1a28fb2f4baaa82006';
    public const ATTEMPTS_SHA = '2ca3118316adc7e45e986857ad62aeee975fb0ab';
    public const TOKENSREMAINING_SHA = 'a11272ac2baa4ba02c34dc40901bb0a35c92edee';
    public const AVAILABLEIN_SHA = '7fdb5bd935191f43f86feb6ee2cf633c0b4939dc';
    public const RESETATTEMPTS_SHA = 'a9ea30b4d3208790b748b13f7e1708faf1f87218';

    public static function attempt(): string
    {
        return <<<'LUA'
            local current_time = redis.call('TIME')
            local key = KEYS[1]
            local sustained_rate = tonumber(ARGV[1])  -- requests per second
            local max_tokens = tonumber(ARGV[2])      -- burst capacity
            local current_timestamp = tonumber(current_time[1])
            local refill_period = 1.0 / sustained_rate  -- seconds per token
            
            local bucket = redis.call('HMGET', key, 'tokens', 'last_refill')
            local tokens = tonumber(bucket[1]) or max_tokens
            local last_refill = tonumber(bucket[2]) or current_timestamp
            
            -- Calculate tokens to add based on time elapsed
            local time_passed = current_timestamp - last_refill
            local tokens_to_add = math.floor(time_passed / refill_period)
            local new_tokens = math.min(max_tokens, tokens + tokens_to_add)
            
            if new_tokens < 1 then
                -- No tokens available
                local time_until_token = refill_period - (time_passed % refill_period)
                return {time_until_token, 0, max_tokens}
            end
            
            -- Consume one token
            new_tokens = new_tokens - 1
            
            -- Update bucket state and track attempts
            redis.call('HMSET', key, 'tokens', new_tokens, 'last_refill', current_timestamp, 'max_tokens', max_tokens, 'attempts', (redis.call('HGET', key, 'attempts') or 0) + 1)
            redis.call('EXPIRE', key, math.max(3600, refill_period * max_tokens * 2))
            
            local retries_left = new_tokens
            return {0, retries_left, max_tokens}
LUA;
    }

    public static function attempts(): string
    {
        return <<<'LUA'
            local current_time = redis.call('TIME')
            local key = KEYS[1]
            local window = tonumber(ARGV[1])
            local current_timestamp = tonumber(current_time[1])
            
            -- Return actual tracked attempts
            local attempts = tonumber(redis.call('HGET', key, 'attempts'))
            return attempts or 0
LUA;
    }

    public static function tokensRemaining(): string
    {
        return <<<'LUA'
            local current_time = redis.call('TIME')
            local key = KEYS[1]
            local sustained_rate = tonumber(ARGV[1])
            local max_tokens = tonumber(ARGV[2])
            local current_timestamp = tonumber(current_time[1])
            local refill_period = 1.0 / sustained_rate
            
            local bucket = redis.call('HMGET', key, 'tokens', 'last_refill')
            local tokens = tonumber(bucket[1]) or max_tokens
            local last_refill = tonumber(bucket[2]) or current_timestamp
            
            -- Calculate tokens to add based on time elapsed
            local time_passed = current_timestamp - last_refill
            local tokens_to_add = math.floor(time_passed / refill_period)
            local new_tokens = math.min(max_tokens, tokens + tokens_to_add)
            
            local retry_after = 0
            if new_tokens < 1 then
                retry_after = refill_period - (time_passed % refill_period)
            end
            
            return {retry_after, new_tokens}
LUA;
    }

    public static function availableIn(): string
    {
        return <<<'LUA'
            local current_time = redis.call('TIME')
            local key = KEYS[1]
            local sustained_rate = tonumber(ARGV[1])
            local max_tokens = tonumber(ARGV[2])
            local current_timestamp = tonumber(current_time[1])
            local refill_period = 1.0 / sustained_rate
            
            local bucket = redis.call('HMGET', key, 'tokens', 'last_refill')
            local tokens = tonumber(bucket[1]) or max_tokens
            local last_refill = tonumber(bucket[2]) or current_timestamp
            
            -- Calculate tokens to add based on time elapsed
            local time_passed = current_timestamp - last_refill
            local tokens_to_add = math.floor(time_passed / refill_period)
            tokens = math.min(max_tokens, tokens + tokens_to_add)
            
            if tokens >= 1 then
                return 0
            end
            
            -- Calculate time until next token
            return refill_period - (time_passed % refill_period)
LUA;
    }

    public static function resetAttempts(): string
    {
        return <<<'LUA'
            local key = KEYS[1]
            local deleted = redis.call('DEL', key)
            return deleted
LUA;
    }
}