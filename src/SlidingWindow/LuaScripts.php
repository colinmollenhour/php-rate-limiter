<?php

namespace Cm\RateLimiter\SlidingWindow;

class LuaScripts
{
    // SHA1 hashes for Lua scripts
    public const ATTEMPT_SHA = 'd3f5353abf11c3ce2f7addb4091d05e7782da5d5';
    public const ATTEMPTS_SHA = 'dd37b06f631076e8714fea7ac014d19a29a678fd';
    public const AVAILABLEIN_SHA = 'c0b6154c242c12f0d59a294791845321c36ea998';

    public static function attempt(): string
    {
        return <<<'LUA'
            local current_time = redis.call('TIME')
            local key = KEYS[1]
            local window = tonumber(ARGV[1])
            local max_requests = tonumber(ARGV[2])
            local trim_time = tonumber(current_time[1]) - window
            redis.call('ZREMRANGEBYSCORE', key, 0, trim_time)
            local request_count = redis.call('ZCARD',key)
            if request_count >= max_requests then
               local elements = redis.call('zrange', key, 0, 0, 'WITHSCORES')
               local next_ts = elements[2] + window
               local available_in = next_ts - tonumber(current_time[1])
               return {available_in, 0, max_requests}
            end
            redis.call('ZADD', key, current_time[1], current_time[1] .. current_time[2])
            redis.call('EXPIRE', key, window)
            return {0, max_requests - request_count - 1, max_requests}
LUA;
    }

    public static function attempts(): string
    {
        return <<<'LUA'
            local current_time = redis.call('TIME')
            local key = KEYS[1]
            local window = tonumber(ARGV[1])
            local trim_time = tonumber(current_time[1]) - window
            redis.call('ZREMRANGEBYSCORE', key, 0, trim_time)
            local request_count = redis.call('ZCARD',key)
            return request_count
LUA;
    }

    public static function availableIn(): string
    {
        return <<<'LUA'
            local current_time = redis.call('TIME')
            local key = KEYS[1]
            local window = tonumber(ARGV[1])
            local max_requests = tonumber(ARGV[2])
            local trim_time = tonumber(current_time[1]) - window
            redis.call('ZREMRANGEBYSCORE', key, 0, trim_time)
            local request_count = redis.call('ZCARD',key)
            if request_count >= max_requests then
               local elements = redis.call('zrange', key, 0, 0, 'WITHSCORES')
               local next_ts = elements[2] + window
               local available_in = next_ts - tonumber(current_time[1])
               return available_in
            end
            return 0
LUA;
    }
}