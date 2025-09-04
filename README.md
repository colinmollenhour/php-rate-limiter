# Cm\RateLimiter

A flexible PHP library implementing multiple rate limiting algorithms using Redis.

> **Note:** This is a standalone fork of [bvtterfly/sliding-window-rate-limiter](https://github.com/bvtterfly/sliding-window-rate-limiter), refactored to remove Laravel dependencies and support multiple algorithms.

## Installation

```bash
composer require cm/rate-limiter
```

##  Dependencies

- PHP ^8.0
- `redis` extension is optional but recommended
- `colinmollenhour/credis` is automatically installed by composer
- A Redis or Redis-compatible server

## Quick Start

```php
use Cm\RateLimiter\RateLimiterFactory;
use Credis_Client;

$redis = new Credis_Client('127.0.0.1', 6379);
$factory = new RateLimiterFactory($redis);

// Choose your algorithm
$rateLimiter = $factory->createSlidingWindow(); // or createFixedWindow() or createLeakyBucket()

// Rate limit: 10 requests per 60 seconds
$result = $rateLimiter->attempt('user:123', 10, 60);

if ($result->successful()) {
    echo "Request allowed. {$result->retriesLeft} requests remaining";
} else {
    echo "Rate limited. Try again in {$result->retryAfter} seconds";
}
```

## Algorithms

| Algorithm | Accuracy | Memory | Burst Protection | Performance | Best For |
|-----------|----------|---------|------------------|-------------|-----------|
| **Sliding Window** | High | Higher | Excellent | Good | APIs requiring smooth rate limiting |
| **Fixed Window** | Medium | Lower | Poor | Excellent | High-traffic applications |
| **Leaky Bucket** | High | Medium | Good | Good | Traffic spike handling with average rate control |
| **Token Bucket** | High | Lower | Good | Excellent | *Coming soon* |

### Sliding Window
- **How it works**: Tracks individual request timestamps using Redis sorted sets
- **Pros**: Precise rate limiting, no burst issues
- **Cons**: Higher memory usage
- **Use when**: You need accurate rate limiting without bursts

### Fixed Window  
- **How it works**: Simple counter per time window, resets at interval boundaries
- **Pros**: Memory efficient, high performance
- **Cons**: Allows up to 2x burst at window boundaries (e.g., 100 requests at 11:59 + 100 at 12:01)
- **Use when**: High traffic, occasional bursts acceptable

### Leaky Bucket
- **How it works**: Simulates a bucket that leaks at a constant rate, requests fill the bucket
- **Pros**: Allows burst up to capacity, enforces average rate, accommodates traffic spikes
- **Cons**: More complex than fixed window, moderate memory usage
- **Use when**: Need burst tolerance while maintaining long-term average rate limits

## API Reference

### Core Methods
```php
$result = $rateLimiter->attempt($key, $maxAttempts, $decaySeconds);
$count = $rateLimiter->attempts($key, $decaySeconds);
$remaining = $rateLimiter->remaining($key, $maxAttempts, $decaySeconds);
$rateLimiter->resetAttempts($key);
```

### Factory Methods
```php
$factory->createSlidingWindow();
$factory->createFixedWindow();
$factory->createLeakyBucket();
// $factory->createTokenBucket();  // Coming soon
```

### Direct Instantiation
```php
$slidingWindow = new \Cm\RateLimiter\SlidingWindow\RateLimiter($redis);
$fixedWindow = new \Cm\RateLimiter\FixedWindow\RateLimiter($redis);
$leakyBucket = new \Cm\RateLimiter\LeakyBucket\RateLimiter($redis);
```

## Testing

### Unit Tests
```bash
composer test
```
Requires Redis running on `localhost:6379`.

### Stress Testing

Comprehensive stress testing tools are included to benchmark and compare algorithms under load.

#### Test Files
- `stress-test.php` - Full comprehensive stress test with CLI options

#### Prerequisites
1. **PHP Extensions Required:**
   - `pcntl` - For multi-process testing
   - `redis` or Credis library - For Redis connectivity

2. **Redis Server:**
   - Must be running on `localhost:6379`
   - Will be cleared (`FLUSHDB`) during tests

#### Running Stress Tests

**Basic functionality validation:**
```bash
# Use unit tests for functionality validation
composer test
```

**Full stress test with CLI options:**
```bash
# Show help and all available options
php stress-test.php --help

# Default: All scenarios, all algorithms, 30s duration, 20 processes
php stress-test.php

# Test only leaky bucket algorithm with high contention for 10 seconds
php stress-test.php --algorithms=leaky --scenarios=high --duration=10

# Custom test with 100 keys, 50 max attempts, 5 second windows
php stress-test.php --keys=100 --max-attempts=50 --decay=5 --duration=15

# Quick comparison between algorithms on burst scenario
php stress-test.php --scenarios=burst --duration=5 --processes=5

# Test specific scenarios with verbose output
php stress-test.php --scenarios=high,medium --verbose --duration=20
```

#### Test Scenarios

1. **High Contention** (`--scenarios=high`) - 5 keys, tests algorithm behavior under high contention
2. **Medium Contention** (`--scenarios=medium`) - 50 keys, balanced load testing  
3. **Low Contention** (`--scenarios=low`) - 1000 keys, tests distributed load performance
4. **Single Key Burst** (`--scenarios=burst`) - 1 key, extreme contention scenario
5. **Custom** (`--keys=N`) - User-defined parameters

#### CLI Options

- `--algorithms=sliding,fixed,leaky` - Choose which algorithms to test
- `--scenarios=high,medium,low,burst,all,custom` - Select test scenarios
- `--duration=SECONDS` - Test duration (default: 30s)
- `--processes=NUM` - Concurrent processes (default: 20)
- `--keys=NUM` - Custom key count for custom scenarios
- `--max-attempts=NUM` - Custom rate limit
- `--decay=SECONDS` - Custom window size
- `--verbose` - Detailed output
- `--no-clear` - Keep Redis data between tests

#### Metrics Collected

For each algorithm and scenario:
- **Total Requests** - Total attempts made
- **Requests/sec (RPS)** - Throughput achieved  
- **Success Rate %** - Requests allowed through
- **Block Rate %** - Requests blocked by rate limiting
- **Error Rate %** - System/Redis errors
- **Duration** - Actual test execution time

#### Expected Performance Characteristics

**SlidingWindow Algorithm:**
- More accurate rate limiting, fewer burst allowances
- Higher memory usage (Redis sorted sets), slightly lower throughput
- Best for precise rate limiting requirements

**FixedWindow Algorithm:**
- Lower memory usage, higher throughput, simpler Redis operations
- Allows up to 2x burst at window boundaries
- Best for high-performance scenarios where some burst is acceptable

**LeakyBucket Algorithm:**
- Moderate memory usage, good throughput with burst accommodation
- Allows initial burst up to capacity, then enforces steady leak rate
- Best for handling traffic spikes while maintaining average rate limits
- Typically shows higher success rates in high contention scenarios

#### Troubleshooting

**PCNTL Extension Missing:**
```bash
# Ubuntu/Debian
sudo apt-get install php-pcntl
```

**Redis Connection Issues:**
```bash
redis-cli ping
redis-cli config get bind
redis-cli config get port
```

## Architecture

Clean, extensible design with pluggable algorithms:

- `RateLimiterInterface` - Common interface for all algorithms
- `RateLimiterResult` - Standardized result object  
- `RateLimiterFactory` - Simple factory for creating limiters
- Algorithm implementations in separate namespaces

Each algorithm uses atomic Redis operations via Lua scripts for consistency and performance.
