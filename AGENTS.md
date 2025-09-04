# Code Authoring Guidelines

This file provides guidance to both humans and AI coding agents when working with code in this repository.

## Project Overview

This is a standalone PHP library implementing multiple rate limiting algorithms using Redis. It is meant to be simple, stable and high-performance.

## Development Commands

### Testing
- `composer test` - Run all tests using PHPUnit
- `./vendor/bin/phpunit` - Direct PHPUnit execution

### Stress Testing
- `php test-basic.php` - Basic functionality validation
- `php stress-test.php` - Full comprehensive stress test with CLI options
- `php stress-test.php --help` - Show all CLI options and usage examples

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
- `attempt($key, $burstCapacity, $sustainedRate, $window)` - Main rate limiting method
- `attempts($key, $window)` - Get current attempt count
- `remaining($key, $burstCapacity, $sustainedRate, $window)` - Get remaining attempts
- `resetAttempts($key)` - Clear rate limit data
- `availableIn($key, $burstCapacity, $sustainedRate, $window)` - Seconds until next attempt allowed

### Testing Structure
- PHPUnit with strict configuration in `phpunit.xml`
- Tests require Redis connection and use `flushdb()` for isolation
- Separate test classes for each algorithm
- Coverage tracking enabled for `src/` directory

### Stress Testing Tools
- **Multi-process testing** using `pcntl_fork()` for concurrent load simulation
- **Comprehensive CLI interface** with configurable algorithms, scenarios, duration, processes
- **Built-in scenarios**: high/medium/low contention + single-key burst test
- **Custom scenarios** with `--keys=N --limiter-rps=N --limiter-burst=N` parameters
- **Performance metrics**: RPS, success/block/error rates, algorithm comparison
- **Prerequisites**: Requires `pcntl` extension and Redis on localhost:6379

### Stress Test CLI Examples
```bash
# Compare both algorithms on high contention (5 keys)
php stress-test.php --scenarios=high --duration=10

# Test only sliding window with custom parameters
php stress-test.php --algorithms=sliding --keys=100 --limiter-rps=50 --limiter-burst=25 --limiter-window=30

# Quick burst test comparison
php stress-test.php --scenarios=burst --duration=5 --processes=5
```
