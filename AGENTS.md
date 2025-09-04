# Code Authoring Guidelines

This file provides guidance to both humans and AI coding agents when working with code in this repository.

## Project Overview

This is a standalone PHP library implementing multiple rate limiting algorithms using Redis. It is meant to be simple, stable and high-performance.

## Development Commands

### Testing
- `composer test` - Run all tests using PHPUnit
- `./vendor/bin/phpunit` - Direct PHPUnit execution

### Dependencies
- Requires PHP ^8.0, Redis server running on localhost:6379 for tests
- Uses `colinmollenhour/credis` for Redis connectivity
- PHPUnit ^9.5 for testing

## Architecture

### Core Components
- `RateLimiterInterface` - Common interface defining rate limiting contract
- `RateLimiterResult` - Standardized result object with `successful()`, `retryAfter`, `retriesLeft` properties
- `RateLimiterFactory` - Factory for creating algorithm instances

### Algorithm Implementations
**Sliding Window** (`SlidingWindow\RateLimiter`)
- Uses Redis sorted sets to track individual request timestamps
- Precise rate limiting without burst issues
- Higher memory usage but excellent accuracy
- Redis key prefix: `sliding_rate_limiter:`

**Fixed Window** (`FixedWindow\RateLimiter`) 
- Simple counter per time window, resets at interval boundaries
- Memory efficient but allows up to 2x burst at window boundaries
- Redis key prefix: `fixed_rate_limiter:`

### Redis Integration
- All algorithms use Lua scripts (`LuaScripts` classes) for atomic operations
- Scripts handle attempt counting, cleanup, and availability calculations
- Each algorithm has separate Lua implementations in its namespace

### Key Methods
- `attempt($key, $maxAttempts, $decay)` - Main rate limiting method
- `attempts($key, $decay)` - Get current attempt count
- `remaining($key, $maxAttempts, $decay)` - Get remaining attempts
- `resetAttempts($key)` - Clear rate limit data
- `availableIn($key, $maxAttempts, $decay)` - Seconds until next attempt allowed

### Testing Structure
- PHPUnit with strict configuration in `phpunit.xml`
- Tests require Redis connection and use `flushdb()` for isolation
- Separate test classes for each algorithm
- Coverage tracking enabled for `src/` directory
