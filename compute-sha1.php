<?php

// Script to compute SHA1 hashes for all Lua scripts

require_once 'src/LeakyBucket/LuaScripts.php';
require_once 'src/SlidingWindow/LuaScripts.php';
require_once 'src/FixedWindow/LuaScripts.php';
require_once 'src/GCRA/LuaScripts.php';
require_once 'src/TokenBucket/LuaScripts.php';
require_once 'src/ConcurrencyAware/LuaScripts.php';

use Cm\RateLimiter\LeakyBucket\LuaScripts as LeakyBucketScripts;
use Cm\RateLimiter\SlidingWindow\LuaScripts as SlidingWindowScripts;
use Cm\RateLimiter\FixedWindow\LuaScripts as FixedWindowScripts;
use Cm\RateLimiter\GCRA\LuaScripts as GCRAScripts;
use Cm\RateLimiter\TokenBucket\LuaScripts as TokenBucketScripts;
use Cm\RateLimiter\ConcurrencyAware\LuaScripts as ConcurrencyAwareScripts;

function computeScriptHashes($className, $methods) {
    echo "=== {$className} ===\n";
    foreach ($methods as $method) {
        $script = $className::$method();
        $sha1 = sha1($script);
        $constantName = strtoupper($method) . '_SHA';
        echo "    public const {$constantName} = '{$sha1}';\n";
    }
    echo "\n";
}

// LeakyBucket
computeScriptHashes(LeakyBucketScripts::class, ['attempt', 'attempts', 'availableIn', 'resetAttempts']);

// SlidingWindow
computeScriptHashes(SlidingWindowScripts::class, ['attempt', 'attempts', 'availableIn']);

// FixedWindow
computeScriptHashes(FixedWindowScripts::class, ['attempt', 'attempts', 'availableIn', 'resetAttempts']);

// GCRA
computeScriptHashes(GCRAScripts::class, ['attempt', 'attempts', 'availableIn', 'resetAttempts']);

// TokenBucket
computeScriptHashes(TokenBucketScripts::class, ['attempt', 'attempts', 'tokensRemaining', 'availableIn', 'resetAttempts']);

// ConcurrencyAware
computeScriptHashes(ConcurrencyAwareScripts::class, ['checkConcurrency', 'releaseConcurrency', 'currentConcurrency', 'cleanupExpired']);