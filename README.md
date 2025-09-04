# Cm\RateLimiter

A flexible PHP library implementing multiple rate limiting algorithms using Redis.

> **Note:** This is a standalone fork of [bvtterfly/sliding-window-rate-limiter](https://github.com/bvtterfly/sliding-window-rate-limiter), refactored to remove Laravel dependencies and support multiple algorithms.

## Installation

```bash
composer require cm/rate-limiter
```

## Requirements

- PHP ^8.0
- Redis server  
- `colinmollenhour/credis` (automatically installed)

## Quick Start

```php
use Cm\RateLimiter\RateLimiterFactory;
use Credis_Client;

$redis = new Credis_Client('127.0.0.1', 6379);
$factory = new RateLimiterFactory($redis);

// Choose your algorithm
$rateLimiter = $factory->createSlidingWindow(); // or createFixedWindow()

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
| **Token Bucket** | High | Lower | Good | Excellent | *Coming soon* |
| **Leaky Bucket** | High | Lower | Excellent | Good | *Coming soon* |

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
// $factory->createTokenBucket();  // Coming soon
// $factory->createLeakyBucket();  // Coming soon
```

### Direct Instantiation
```php
$slidingWindow = new \Cm\RateLimiter\SlidingWindow\RateLimiter($redis);
$fixedWindow = new \Cm\RateLimiter\FixedWindow\RateLimiter($redis);
```

## Testing

```bash
composer test
```

Requires Redis running on `localhost:6379`.

## Architecture

Clean, extensible design with pluggable algorithms:

- `RateLimiterInterface` - Common interface for all algorithms
- `RateLimiterResult` - Standardized result object  
- `RateLimiterFactory` - Simple factory for creating limiters
- Algorithm implementations in separate namespaces

Each algorithm uses atomic Redis operations via Lua scripts for consistency and performance.