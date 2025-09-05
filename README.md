# Cm\RateLimiter

A flexible PHP library implementing multiple rate limiting algorithms using Redis with **enhanced API supporting separate burst capacity and sustained rates** plus **concurrency-aware rate limiting** to prevent request pileup. Includes comprehensive performance testing and supports Redis alternatives like Dragonfly, KeyDB, and Valkey.

> **Note:** This is a standalone fork of [bvtterfly/sliding-window-rate-limiter](https://github.com/bvtterfly/sliding-window-rate-limiter), refactored to remove Laravel dependencies and support multiple algorithms.

## Features

- **Multiple Rate Limiting Algorithms** - Sliding Window, Fixed Window, Leaky Bucket, GCRA, Token Bucket, and Concurrency-Aware
- **Concurrency-Aware Limiting** - Prevents request pileup from slow operations by limiting both rate AND concurrency
- **Enhanced API** - Separate burst capacity and sustained rate parameters for fine-grained control
- **Automatic Key Expiration** - Redis keys automatically expire to prevent memory leaks with random keys
- **High Performance** - GCRA algorithm achieves ~25,900 RPS with sub-millisecond latency
- **Redis Compatible** - Works with Redis, Dragonfly, KeyDB, Valkey, AWS ElastiCache
- **Comprehensive Testing** - Multi-process stress testing with detailed performance metrics including concurrency blocking
- **Laravel-Free** - Standalone library with minimal dependencies (PHP 8.0+, [Credis](https://github.com/colinmollenhour/credis))
- **Atomic Operations** - All algorithms use Lua scripts for consistency and thread safety

## Installation

```bash
composer require cm/rate-limiter
```

### Docker Setup (FrankenPHP)

Run the playground as a FrankenPHP web application with Docker:

```bash
# Clone or download the repository
git clone <repository-url>
cd sliding-window-rate-limiter/standalone

# Navigate to playground directory
cd playground

# Start the services
docker-compose up -d

# Access the playground
open http://localhost:8080
```

The Docker setup includes:
- **FrankenPHP** server running the playground on port 8080
- **Redis** server for rate limiting storage
- **Auto-reload** during development (volume mounted)

#### Docker Services

- **Web App**: `http://localhost:8080` - Interactive rate limiter playground
- **Redis**: `localhost:6379` - Redis server for testing

#### Docker Commands

```bash
# Navigate to playground directory first
cd playground

# Start services in background
docker-compose up -d

# View logs
docker-compose logs -f

# Stop services
docker-compose down

# Rebuild after code changes
docker-compose up --build

# Access Redis CLI
docker-compose exec redis redis-cli
```

## Quick Start

```php
use Cm\RateLimiter\RateLimiterFactory;
use Credis_Client;

$redis = new Credis_Client('127.0.0.1', 6379);
$factory = new RateLimiterFactory($redis);

// Choose your algorithm
$rateLimiter = $factory->createSlidingWindow(); // or createFixedWindow(), createLeakyBucket(), createGCRA(), createTokenBucket()

// Rate limit: 10 burst capacity, 1 request/second sustained, 60 second window
$result = $rateLimiter->attempt('user:123', 10, 1.0, 60);

if ($result->successful()) {
    echo "Request allowed. {$result->retriesLeft} requests remaining";
} else {
    echo "Rate limited. Try again in {$result->retryAfter} seconds";
}
```

## 🚀 Concurrency-Aware Rate Limiting

**Solves the "request pileup" problem**: Even with rate limiting, slow operations can pile up and overwhelm backends.

**Example Problem:**
- Rate limit: 10 requests/second ✅  
- Request duration: 5 seconds ⚠️
- Result: ~40 concurrent requests piling up! ❌

### Composable API Design

**Any rate limiting algorithm** can be combined with concurrency control using the factory:

```php
// GCRA + concurrency control
$limiter = $factory->createConcurrencyAware('gcra');

// Token bucket + concurrency control  
$limiter = $factory->createConcurrencyAware('token');

// Pure concurrency limiting (no rate limiting)
$limiter = $factory->createConcurrencyAware(null);

// Pure rate limiting (no concurrency control)
$limiter = $factory->createGCRA(); // or any other algorithm
```

### Quick Start with Concurrency Control

```php
// Create GCRA rate limiter with concurrency control
$limiter = $factory->createConcurrencyAware('gcra');
$requestId = uniqid('req_', true);

$result = $limiter->attemptWithConcurrency(
    key: 'api:user:123',
    requestId: $requestId,
    maxConcurrent: 5,        // Max 5 concurrent requests
    burstCapacity: 10,       // Rate limit burst allowance  
    sustainedRate: 2.0,      // 2 requests/second sustained
    window: 60,              // 60-second window
    timeoutSeconds: 30       // 30s timeout for slow requests
);

if ($result->successful()) {
    try {
        // Process request - guaranteed max 5 concurrent + 2/s rate
    } finally {
        $limiter->releaseConcurrency('api:user:123', $requestId);
    }
} elseif ($result->rejectedByConcurrency()) {
    // Handle concurrency limit (don't count against rate limit)
    http_response_code(503);
    echo "Service temporarily unavailable";
} else {
    // Handle rate limit
    http_response_code(429);
    header("Retry-After: " . $result->retryAfter);
    echo "Rate limit exceeded";
}
```

### Algorithm Combinations

```php
// High-performance: GCRA + concurrency
$limiter = $factory->createConcurrencyAware('gcra');

// Burst-friendly: Token bucket + concurrency  
$limiter = $factory->createConcurrencyAware('token');

// Smooth rate: Sliding window + concurrency
$limiter = $factory->createConcurrencyAware('sliding');

// Memory efficient: Fixed window + concurrency
$limiter = $factory->createConcurrencyAware('fixed');

// Pure concurrency control (no rate limiting)
$limiter = $factory->createConcurrencyAware(null);
```

### How It Works

1. **Concurrency Check First** - Acquire a concurrency slot using Redis semaphore pattern
2. **Rate Limit Check Second** - Only requests with concurrency slots count against rate limits  
3. **Clear Failure Modes** - Different response codes for concurrency (503) vs rate limiting (429)
4. **Composable Design** - Any algorithm can be combined with concurrency control

### Use Cases

```php
// Web API Protection (GCRA for performance + concurrency for slow queries)
$limiter = $factory->createConcurrencyAware('gcra');
$result = $limiter->attemptWithConcurrency('api:search', $requestId, 5, 20, 1.0, 60, 30);

// File Upload Service (Token bucket for bursts + concurrency for upload congestion)
$limiter = $factory->createConcurrencyAware('token');
$result = $limiter->attemptWithConcurrency('upload:user:'.$userId, $requestId, 2, 5, 0.5, 3600, 300);

// Background Job Processing (Pure concurrency for resource control)
$limiter = $factory->createConcurrencyAware(null);
$result = $limiter->attemptWithConcurrency('jobs:heavy', $jobId, 3, 0, 0, 0, 1800);
```

## Algorithms

| Algorithm | Accuracy | Memory | Burst Support | Performance | Best For |
|-----------|----------|---------|---------------|-------------|-----------|
| **Sliding Window** | High | Higher | Configurable (smooth rate) | Good | APIs requiring smooth rate limiting |
| **Fixed Window** | Medium | Lower | Full (window limit) | Excellent | High-traffic applications |
| **Leaky Bucket** | High | Medium | Full (bucket capacity) | Good | Traffic spike handling with average rate control |
| **GCRA** | High | Lower | Configurable (smooth rate) | Excellent | Memory-efficient smooth rate limiting |
| **Token Bucket** | High | Medium | **Perfect** (burst + refill) | Excellent | Burst-tolerant APIs with gradual refill |

### Sliding Window
- **How it works**: Tracks individual request timestamps using Redis sorted sets, smooths traffic over time
- **Burst behavior**: Ignores burst capacity parameter, provides smooth rate limiting based on sustained rate
- **Pros**: Precise rate limiting, smooth traffic distribution
- **Cons**: Higher memory usage, no true burst support
- **Use when**: You need accurate, smooth rate limiting

### Fixed Window  
- **How it works**: Simple counter per time window, resets at interval boundaries
- **Burst behavior**: Uses burst capacity as the window limit, ignores sustained rate parameter
- **Pros**: Memory efficient, high performance, allows full burst at window start
- **Cons**: Can allow up to 2x burst at window boundaries (e.g., 100 requests at 11:59 + 100 at 12:01)
- **Use when**: High traffic where burst at window boundaries is acceptable

### Leaky Bucket
- **How it works**: Simulates a bucket that leaks at a constant rate, requests fill the bucket
- **Burst behavior**: Uses burst capacity as bucket size, ignores sustained rate (leak rate fixed at capacity/window)
- **Pros**: Allows initial burst up to capacity, enforces steady leak rate, accommodates traffic spikes
- **Cons**: More complex than fixed window, moderate memory usage
- **Use when**: Need burst tolerance while maintaining steady average rate

### GCRA (Generic Cell Rate Algorithm)
- **How it works**: Uses theoretical arrival time (TAT) to lazily compute when next request can be made
- **Burst behavior**: Ignores burst capacity, provides smooth rate limiting based on sustained rate
- **Pros**: Very memory efficient (single value per key), smooth rate limiting, precise control, **highest performance**
- **Cons**: More complex algorithm, no true burst support
- **Use when**: Need memory-efficient rate limiting with smooth, predictable behavior, or maximum performance

### Token Bucket
- **How it works**: A bucket holds tokens that are consumed by requests and refilled at a constant rate
- **Burst behavior**: **Perfect implementation** - supports both burst capacity (initial tokens) and sustained rate (refill rate)
- **Pros**: True burst + sustained rate support, allows bursts up to capacity, intuitive model, gradual refill prevents starvation
- **Cons**: More memory usage than GCRA, moderate complexity
- **Use when**: Need true burst tolerance with separate sustained rate control, web APIs with bursty traffic patterns

## Performance Comparison

Based on max-speed benchmarking (requests/second with no throttling):

| Algorithm | Throughput (RPS) | Latency Avg (ms) | Latency P99 (ms) | Memory per Key | Best Use Case |
|-----------|------------------|------------------|------------------|----------------|---------------|
| **GCRA** | ~25,900 | 0.151 | 0.460 | Single float | High-performance applications |
| **Fixed Window** | ~13,700 | 0.286 | 0.670 | Single counter + TTL | Simple high-traffic apps |
| **Leaky Bucket** | ~13,300 | 0.298 | 0.710 | Hash with 3 fields | Traffic spike handling |
| **Sliding Window** | ~12,900 | 0.307 | 0.740 | Sorted set | Precise rate limiting |
| **Token Bucket** | ~12,800 | 0.308 | 0.800 | Hash with 4 fields | Burst-tolerant APIs |

> **Key Insight**: GCRA offers the lowest memory usage, highest performance, AND lowest latency (~3x faster than other algorithms), making it ideal for high-scale applications.

## Enhanced API with Burst + Sustained Rate Support

This library provides a powerful API that separates **burst capacity** from **sustained rate**, giving you fine-grained control over rate limiting behavior.

### Core Methods
```php
// Enhanced API: attempt(key, burstCapacity, sustainedRate, window)
$result = $rateLimiter->attempt('user:123', 10, 1.0, 60);
// Allows: 10 requests immediately (burst), then 1 request/second (sustained)

$count = $rateLimiter->attempts($key, $window);
$remaining = $rateLimiter->remaining($key, $burstCapacity, $sustainedRate, $window);
$availableIn = $rateLimiter->availableIn($key, $burstCapacity, $sustainedRate, $window);
$rateLimiter->resetAttempts($key);
```

### API Parameters
- **`$key`**: Unique identifier for the rate limit (e.g., 'user:123', 'api:endpoint')
- **`$burstCapacity`**: Maximum requests allowed immediately (burst)
- **`$sustainedRate`**: Requests per second for sustained traffic (float)
- **`$window`**: Time window in seconds (default: 60)

### Algorithm-Specific Behavior

#### Token Bucket (Perfect Burst + Sustained)
```php
$tokenBucket = $factory->createTokenBucket();
// 5 requests immediately, then 2 requests/second
$result = $tokenBucket->attempt('user:123', 5, 2.0, 60);
```

#### Fixed Window (Burst as Window Limit)
```php
$fixedWindow = $factory->createFixedWindow();
// 100 requests per 60-second window (sustained rate ignored)
$result = $fixedWindow->attempt('user:123', 100, 1.0, 60);
```

#### Sliding Window & GCRA (Smooth Rate)
```php
$slidingWindow = $factory->createSlidingWindow();
// Smooth rate limiting at 10 requests/minute (burst ignored)
$result = $slidingWindow->attempt('user:123', 10, 10.0/60, 60);
```

#### Leaky Bucket (Burst as Capacity)
```php
$leakyBucket = $factory->createLeakyBucket();
// 20 request capacity, leaks at 20/window rate (sustained rate ignored)
$result = $leakyBucket->attempt('user:123', 20, 1.0, 60);
```

### Factory Methods
```php
$factory->createSlidingWindow();      // Smooth rate limiting
$factory->createFixedWindow();        // Window-based with burst
$factory->createLeakyBucket();        // Bucket capacity with leak
$factory->createGCRA();               // Memory-efficient smooth limiting
$factory->createTokenBucket();        // Perfect burst + sustained rate
$factory->createConcurrencyAware('gcra');  // GCRA + concurrency control
$factory->createConcurrencyAware(null);   // Pure concurrency limiting
```

### Direct Instantiation
```php
$slidingWindow = new \Cm\RateLimiter\SlidingWindow\RateLimiter($redis);
$fixedWindow = new \Cm\RateLimiter\FixedWindow\RateLimiter($redis);
$leakyBucket = new \Cm\RateLimiter\LeakyBucket\RateLimiter($redis);
$gcra = new \Cm\RateLimiter\GCRA\RateLimiter($redis);
$tokenBucket = new \Cm\RateLimiter\TokenBucket\RateLimiter($redis);
$concurrencyAware = new \Cm\RateLimiter\ConcurrencyAware\RateLimiter($redis, $tokenBucket);
```

### Result Objects

#### Standard Rate Limiting Result
```php
class RateLimiterResult {
    public function successful(): bool;     // Was the request allowed?
    public int $retryAfter;                // Seconds until next request allowed
    public int $retriesLeft;               // Requests remaining in current window
    public int $limit;                     // Total limit (burst capacity)
}
```

#### Concurrency-Aware Result
```php
class ConcurrencyAwareResult extends RateLimiterResult {
    public bool $concurrencyAcquired;           // Was concurrency slot acquired?
    public ?string $concurrencyRejectionReason; // Why was concurrency rejected?
    public int $currentConcurrency;              // Current concurrent requests
    public int $maxConcurrency;                  // Maximum allowed concurrent requests
    
    public function rejectedByConcurrency(): bool; // Was blocked by concurrency limit?
    public function rejectedByRateLimit(): bool;   // Was blocked by rate limit?
}
```

## Common Use Cases

### Web API with Burst Tolerance
```php
$tokenBucket = $factory->createTokenBucket();
// Allow 20 requests immediately, then 5 requests/second
$result = $tokenBucket->attempt("api:{$userId}", 20, 5.0, 60);
```

### Concurrency-Aware API with Token Bucket
```php
// Perfect for APIs with bursty traffic AND slow backend operations
$limiter = $factory->createConcurrencyAware('token');
$requestId = uniqid('req_', true);

$result = $limiter->attemptWithConcurrency(
    key: "api:upload:{$userId}",
    requestId: $requestId,
    maxConcurrent: 3,        // Max 3 concurrent uploads per user
    burstCapacity: 10,       // Allow 10 uploads immediately
    sustainedRate: 2.0,      // Then 2 uploads/second sustained
    window: 60,              // 60-second rate limit window
    timeoutSeconds: 300      // 5-minute timeout for slow uploads
);

if ($result->successful()) {
    try {
        // Process file upload - guaranteed max 3 concurrent + 2/s rate
        processFileUpload($file);
    } finally {
        // Always release concurrency slot when done
        $limiter->releaseConcurrency("api:upload:{$userId}", $requestId);
    }
} elseif ($result->rejectedByConcurrency()) {
    // Too many concurrent uploads - don't count against rate limit
    http_response_code(503);
    echo "Too many concurrent uploads. Try again shortly.";
} else {
    // Rate limit exceeded
    http_response_code(429);
    header("Retry-After: " . $result->retryAfter);
    echo "Upload rate limit exceeded. Try again in {$result->retryAfter} seconds.";
}
```

### Pure Concurrency Control (No Rate Limiting)
```php
// Perfect for resource-intensive operations like database migrations or heavy processing
$limiter = $factory->createConcurrencyAware(null); // null = no rate limiting
$jobId = uniqid('job_', true);

$result = $limiter->attemptWithConcurrency(
    key: 'jobs:heavy-processing',
    requestId: $jobId,
    maxConcurrent: 2,        // Only 2 heavy jobs at once
    burstCapacity: 0,        // No rate limiting
    sustainedRate: 0,        // No rate limiting
    window: 0,               // No rate limiting
    timeoutSeconds: 1800     // 30-minute timeout for long jobs
);

if ($result->successful()) {
    try {
        // Process heavy job - guaranteed max 2 concurrent, no rate limits
        processHeavyJob($jobData);
    } finally {
        $limiter->releaseConcurrency('jobs:heavy-processing', $jobId);
    }
} else {
    // Only concurrency rejection possible (no rate limiting)
    echo "Too many concurrent jobs. Current: {$result->currentConcurrency}/{$result->maxConcurrency}";
}
```

### Smooth Rate Limiting
```php
$slidingWindow = $factory->createSlidingWindow();
// Smooth 100 requests per hour (no bursts)
$result = $slidingWindow->attempt("user:{$id}", 100, 100.0/3600, 3600);
```

### High-Performance with Acceptable Bursts
```php
$fixedWindow = $factory->createFixedWindow();
// 1000 requests per minute window
$result = $fixedWindow->attempt("endpoint:{$route}", 1000, 1.0, 60);
```

### Memory-Efficient Smooth Limiting
```php
$gcra = $factory->createGCRA();
// 50 requests per minute, smooth distribution
$result = $gcra->attempt("service:{$key}", 50, 50.0/60, 60);
```

## Testing

### Unit Tests
```bash
composer test
```
Requires Redis running on `localhost:6379`.

#### Testing with Docker
```bash
# Run tests in Docker environment
docker-compose exec app composer test

# Or run tests with fresh Redis
docker-compose up -d redis
composer test
```

## 🎮 Interactive Playground

The playground provides a web interface for testing rate limiting algorithms with real-time results:

### Quick Examples

```bash
# GCRA rate limiting with concurrency control (concurrent=5 by default)
GET /?algorithm=gcra&key=api&burst=10&rate=2.0&sleep=1&format=json

# Token bucket without concurrency control
GET /?algorithm=token&key=api&concurrent=0&burst=5&rate=1.0&format=json

# Pure concurrency limiting (no rate limiting)  
GET /?algorithm=sliding&key=jobs&concurrent=3&burst=0&rate=0&sleep=2&format=json
```

### Key Parameters

- `algorithm` - Rate limiting algorithm (sliding, fixed, leaky, gcra, token)
- `concurrent` - Max concurrent requests (0=disabled, default=5)  
- `sleep` - Simulate slow requests (seconds)
- `burst`/`rate`/`window` - Rate limiting parameters
- `format` - Response format (html or json)

### Accessing the Playground

**Docker (Recommended):**
```bash
cd playground && docker-compose up -d
open http://localhost:8080
```

**PHP Built-in Server:**
```bash
php -S localhost:8000 playground/index.php
open http://localhost:8000
```

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

# Test only GCRA algorithm with high contention for 10 seconds
php stress-test.php --algorithms=gcra --scenarios=high --duration=10

# Test algorithms with concurrency control enabled
php stress-test.php --algorithms=gcra,token --concurrency-max=5 --limiter-rps=10 --duration=15

# Compare different algorithms with and without concurrency
php stress-test.php --algorithms=gcra,sliding --concurrency-max=10 --scenarios=high --duration=20

# Custom test with 100 keys, 50 RPS rate limit, 25 burst capacity, 30 second windows
php stress-test.php --keys=100 --limiter-rps=50 --limiter-burst=25 --limiter-window=30 --duration=15

# Quick comparison between algorithms on burst scenario
php stress-test.php --scenarios=burst --duration=5 --processes=5

# Performance benchmark (max speed, no throttling)
php stress-test.php --max-speed --duration=5 --processes=4

# Test specific scenarios with verbose output
php stress-test.php --scenarios=high,medium --verbose --duration=20

# High-precision latency analysis
php stress-test.php --latency-precision=5 --algorithms=gcra,sliding --scenarios=medium

# Memory-efficient latency sampling for long tests
php stress-test.php --latency-sample=100 --max-speed --duration=30 --processes=10
```

#### HTTP Load Testing with "hey" CLI

For real-world HTTP load testing against the playground, use the [`hey`](https://github.com/rakyll/hey) CLI tool.

**Basic Load Testing:**
```bash
# Start the playground first
cd playground && docker-compose up -d

# Test GCRA algorithm with concurrency control
hey -n 1000 -c 50 -q 10 "http://localhost:8080/?algorithm=gcra&key=api&burst=20&rate=5.0&concurrent=10&format=json"

# Test token bucket without concurrency (pure rate limiting)
hey -n 500 -c 20 -q 5 "http://localhost:8080/?algorithm=token&key=api&burst=10&rate=2.0&concurrent=0&format=json"

# Test pure concurrency limiting (no rate limits)
hey -n 200 -c 15 "http://localhost:8080/?algorithm=gcra&key=jobs&burst=0&rate=0&concurrent=5&sleep=1&format=json"
```

**Advanced Load Testing Scenarios:**
```bash
# Burst traffic simulation - high concurrency, short duration
hey -n 2000 -c 100 -t 30 "http://localhost:8080/?algorithm=token&key=burst&burst=50&rate=10.0&concurrent=20&format=json"

# Sustained load test - moderate concurrency, longer duration
hey -n 5000 -c 25 -q 15 -t 60 "http://localhost:8080/?algorithm=gcra&key=sustained&burst=30&rate=8.0&concurrent=15&format=json"

# Slow request simulation - test concurrency limits with delays
hey -n 100 -c 20 -t 120 "http://localhost:8080/?algorithm=sliding&key=slow&burst=10&rate=2.0&concurrent=5&sleep=3&format=json"

# Algorithm comparison - same parameters, different algorithms
hey -n 1000 -c 30 -q 12 "http://localhost:8080/?algorithm=gcra&key=compare&burst=25&rate=6.0&concurrent=8&format=json"
hey -n 1000 -c 30 -q 12 "http://localhost:8080/?algorithm=token&key=compare&burst=25&rate=6.0&concurrent=8&format=json"
hey -n 1000 -c 30 -q 12 "http://localhost:8080/?algorithm=sliding&key=compare&burst=25&rate=6.0&concurrent=8&format=json"
```

**Key "hey" Parameters:**
- `-n` - Total number of requests
- `-c` - Number of concurrent workers
- `-q` - Rate limit (requests per second per worker)
- `-t` - Timeout for each request (seconds)
- `-d` - Duration of test (alternative to `-n`)

**Expected Results:**
- **200 OK** - Request allowed by both rate and concurrency limits
- **429 Too Many Requests** - Rate limit exceeded (`Retry-After` header included)
- **503 Service Unavailable** - Concurrency limit exceeded (try again shortly)

**Monitoring During Tests:**
```bash
# Watch Redis keys in real-time
docker-compose exec redis redis-cli --scan --pattern "*rate_limiter*" | head -10
docker-compose exec redis redis-cli --scan --pattern "concurrency:*" | head -10

# Monitor playground logs
docker-compose logs -f app
```

#### Test Scenarios

1. **High Contention** (`--scenarios=high`) - 5 keys, tests algorithm behavior under high contention
2. **Medium Contention** (`--scenarios=medium`) - 50 keys, balanced load testing  
3. **Low Contention** (`--scenarios=low`) - 1000 keys, tests distributed load performance
4. **Single Key Burst** (`--scenarios=burst`) - 1 key, extreme contention scenario
5. **Custom** (`--keys=N`) - User-defined parameters

#### CLI Options

- `--algorithms=sliding,fixed,leaky,gcra,token` - Choose which algorithms to test
- `--scenarios=high,medium,low,burst,all,custom` - Select test scenarios
- `--duration=SECONDS` - Test duration (default: 30s)
- `--processes=NUM` - Concurrent processes (default: 20)
- `--keys=NUM` - Custom key count for custom scenarios
- `--limiter-rps=NUM` - Rate limiter sustained rate (requests/sec)
- `--limiter-burst=NUM` - Rate limiter burst capacity
- `--limiter-window=SECONDS` - Time window size (default: 60s)
- `--concurrency-max=NUM` - Maximum concurrent requests (enables concurrency mode for all algorithms)
- `--concurrency-timeout=SECONDS` - Timeout for concurrency requests (default: 30s)
- `--verbose` - Detailed output
- `--no-clear` - Keep Redis data between tests
- `--max-speed` - Performance mode: send requests as fast as possible (no throttling)
- `--latency-precision=N` - Number of decimal places for latency rounding (default: 2)
- `--latency-sample=N` - Sample rate for latency collection - collect every Nth measurement (default: 1 = all measurements)

#### Metrics Collected

For each algorithm and scenario:
- **Total Requests** - Total attempts made
- **Requests/sec (RPS)** - Throughput achieved  
- **Success Rate %** - Requests allowed through
- **Block Rate %** - Total requests blocked (rate + concurrency)
- **Concurrency Block %** - Requests blocked by concurrency limits (concurrency-aware algorithm only)
- **Rate Limit Block %** - Requests blocked by rate limits
- **Error Rate %** - System/Redis errors
- **Duration** - Actual test execution time
- **Latency Metrics** - Detailed latency analysis of each rate limit check:
  - **Latency Avg (ms)** - Average latency per request
  - **Latency P50 (ms)** - Median latency (50th percentile)
  - **Latency P95 (ms)** - 95th percentile latency
  - **Latency P99 (ms)** - 99th percentile latency
  - **Latency Max (ms)** - Maximum observed latency

#### Latency Measurement

The stress test includes comprehensive latency measurement using high-precision `microtime()` to measure the added latency of each rate limit check:

**Features:**
- **Configurable Precision**: Use `--latency-precision=N` to set decimal places (0-10, default: 2)
- **Sampling Control**: Use `--latency-sample=N` to collect every Nth measurement (default: 1 = all measurements)
- **Memory Efficient**: Uses counter-based storage instead of storing individual measurements
- **Detailed Statistics**: Provides avg, P50, P95, P99, and max latency metrics

**Examples:**
```bash
# Maximum precision latency analysis
php stress-test.php --latency-precision=5 --algorithms=gcra,sliding

# Memory-efficient sampling for high-load tests
php stress-test.php --latency-sample=100 --max-speed --processes=10
```

#### Testing Modes

The stress test supports two distinct testing modes:

1. **Rate Limiting Behavior Test** (default): Tests how algorithms behave under controlled load with request throttling
2. **Max Speed Performance Test** (`--max-speed`): Tests raw algorithm throughput with no throttling to reveal true performance differences

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

**GCRA Algorithm:**
- Lowest memory usage (single float per key), **highest throughput** (~2x faster than other algorithms)
- Uses theoretical arrival time (TAT) for precise, predictable rate limiting  
- Best for high-performance, memory-constrained environments requiring smooth rate control
- Shows moderate success rates with consistent, predictable blocking behavior

**Token Bucket Algorithm:**
- Moderate memory usage (hash with 4 fields per key), good throughput
- Allows initial bursts up to bucket capacity, then gradual token refill
- Excellent success rates in burst scenarios, very low block rates
- Best for web APIs with bursty traffic patterns that need burst tolerance

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

## Choosing the Right Algorithm

### Need True Burst + Sustained Rate Control?
**→ Use Token Bucket** - The only algorithm that properly implements both parameters.

### Need Maximum Performance?
**→ Use GCRA** - Highest throughput (~2x faster) with lowest memory usage.

### Need Smooth Rate Limiting?
**→ Use Sliding Window or GCRA** - Both provide smooth traffic distribution without burst spikes.

### Need Simple High-Performance Solution?
**→ Use Fixed Window** - Lowest complexity, highest throughput after GCRA, acceptable burst behavior.

### Need Burst with Average Rate Control?
**→ Use Leaky Bucket** - Good balance of burst tolerance and average rate enforcement.
