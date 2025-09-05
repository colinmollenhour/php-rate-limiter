<?php

namespace Cm\RateLimiter\TokenBucket;

class LuaScripts
{
    // SHA1 hashes for Lua scripts
    public const ATTEMPT_SHA = 'd7444a9493c3d553a8a236db40e145dc705374aa';
    public const ATTEMPTS_SHA = '2ca3118316adc7e45e986857ad62aeee975fb0ab';
    public const TOKENSREMAINING_SHA = '72511b3ee9f1243353aa41aa0a5172f4a7b83c5c';
    public const AVAILABLEIN_SHA = '562fd78e2e3e44955e767abd5cb7d9e5e46cd325';
    public const RESETATTEMPTS_SHA = 'a9ea30b4d3208790b748b13f7e1708faf1f87218';

    public static function attempt(): string
    {
        return <<<'LUA'
            local current_time = redis.call('TIME')
            local key = KEYS[1]
            local sustained_rate = tonumber(ARGV[1])  -- requests per second
            local max_tokens = tonumber(ARGV[2])      -- burst capacity
            local current_timestamp = tonumber(current_time[1])
            
            -- Use integer arithmetic to avoid floating-point precision issues
            -- Instead of refill_period = 1.0 / sustained_rate, calculate tokens directly
            local refill_rate_per_second = sustained_rate
            
            local bucket = redis.call('HMGET', key, 'tokens', 'last_refill')
            local tokens = tonumber(bucket[1]) or max_tokens
            local last_refill = tonumber(bucket[2]) or current_timestamp
            
            -- Calculate tokens to add using direct time-based calculation
            local time_passed = current_timestamp - last_refill
            
            -- Calculate how many tokens should be added based on elapsed time
            -- tokens_to_add = time_passed * refill_rate_per_second
            local tokens_to_add = math.floor(time_passed * refill_rate_per_second)
            
            -- Burst protection: Adaptive based on refill rate
            -- For fast refill rates, use longer protection; for slow rates, use shorter protection
            local refill_period = 1.0 / refill_rate_per_second
            local min_refill_time = math.max(1.0, refill_period * 2)  -- At least 1 second, or 2x refill period
            local updated_last_refill = last_refill
            
            if time_passed < min_refill_time then
                tokens_to_add = 0  -- No refill during rapid burst
            else
                -- Update last_refill to current time when we actually add tokens
                if tokens_to_add > 0 then
                    updated_last_refill = current_timestamp
                end
            end
            
            local new_tokens = math.min(max_tokens, tokens + tokens_to_add)
            
            if new_tokens < 1 then
                -- No tokens available, calculate time until next token
                local time_until_token = math.max(refill_period, min_refill_time - time_passed)
                return {time_until_token, 0, max_tokens}
            end
            
            -- Consume one token
            new_tokens = new_tokens - 1
            
            -- Update bucket state and track attempts
            redis.call('HMSET', key, 'tokens', new_tokens, 'last_refill', updated_last_refill, 'max_tokens', max_tokens, 'attempts', (redis.call('HGET', key, 'attempts') or 0) + 1)
            redis.call('EXPIRE', key, math.max(3600, max_tokens * 2))
            
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
            local refill_rate_per_second = sustained_rate
            
            local bucket = redis.call('HMGET', key, 'tokens', 'last_refill')
            local tokens = tonumber(bucket[1]) or max_tokens
            local last_refill = tonumber(bucket[2]) or current_timestamp
            
            -- Calculate tokens to add with burst protection
            local time_passed = current_timestamp - last_refill
            local tokens_to_add = math.floor(time_passed * refill_rate_per_second)
            
            -- Burst protection: Adaptive based on refill rate
            local refill_period = 1.0 / refill_rate_per_second
            local min_refill_time = math.max(1.0, refill_period * 2)
            if time_passed < min_refill_time then
                tokens_to_add = 0
            end
            
            local new_tokens = math.min(max_tokens, tokens + tokens_to_add)
            
            local retry_after = 0
            if new_tokens < 1 then
                retry_after = math.max(refill_period, min_refill_time - time_passed)
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
            local refill_rate_per_second = sustained_rate
            
            local bucket = redis.call('HMGET', key, 'tokens', 'last_refill')
            local tokens = tonumber(bucket[1]) or max_tokens
            local last_refill = tonumber(bucket[2]) or current_timestamp
            
            -- Calculate tokens to add with burst protection
            local time_passed = current_timestamp - last_refill
            local tokens_to_add = math.floor(time_passed * refill_rate_per_second)
            
            -- Burst protection: Adaptive based on refill rate
            local refill_period = 1.0 / refill_rate_per_second
            local min_refill_time = math.max(1.0, refill_period * 2)
            if time_passed < min_refill_time then
                tokens_to_add = 0
            end
            
            tokens = math.min(max_tokens, tokens + tokens_to_add)
            
            if tokens >= 1 then
                return 0
            end
            
            -- Calculate time until next token
            return math.max(refill_period, min_refill_time - time_passed)
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