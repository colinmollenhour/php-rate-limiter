<?php

/**
 * Rate Limiter Playground
 * 
 * Interactive endpoint for testing rate limiting algorithms with configurable parameters.
 * Perfect for demonstrating concurrency-aware rate limiting and slow request scenarios.
 * 
 * Usage Examples:
 * 
 * Basic rate limiting test:
 * http://localhost/playground.php?algorithm=sliding&key=user123&burst=5&rate=2.0&window=60
 * 
 * Concurrency-aware test with slow requests (GCRA + concurrency):
 * http://localhost/playground.php?algorithm=gcra&key=api&concurrent=3&burst=10&rate=5.0&sleep=2&timeout=30
 * 
 * Standard rate limiting (no concurrency control):
 * http://localhost/playground.php?algorithm=sliding&key=api&concurrent=0&burst=10&rate=5.0
 * 
 * Compare algorithms:
 * http://localhost/playground.php?algorithm=gcra&key=test&burst=10&rate=1.0&sleep=0.5&format=json
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Set headers for CORS and content type (only in web context)
if (php_sapi_name() !== 'cli') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    // Handle preflight requests
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

require_once __DIR__ . '/../vendor/autoload.php';

use Cm\RateLimiter\RateLimiterFactory;
use Cm\RateLimiter\ConcurrencyAwareRateLimiterInterface;

class RateLimiterPlayground
{
    private RateLimiterFactory $factory;
    private array $params;
    private float $startTime;
    
    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->params = $this->parseParameters();
        
        // Initialize Redis connection
        try {
            $redis = new Credis_Client(
                $this->params['redis_host'], 
                $this->params['redis_port']
            );
            $redis->ping();
            $this->factory = new RateLimiterFactory($redis);
        } catch (Exception $e) {
            $this->sendErrorResponse("Redis connection failed: " . $e->getMessage(), 500);
        }
    }
    
    public function handleRequest(): void
    {
        try {
            // Show help if requested
            if ($this->params['help']) {
                $this->showHelp();
                return;
            }
            
            // Validate required parameters
            $this->validateParameters();
            
            // Execute rate limit test
            $result = $this->executeRateLimitTest();
            
            // Send response
            $this->sendResponse($result);
            
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage(), 400);
        }
    }
    
    private function parseParameters(): array
    {
        // Handle CLI arguments or GET parameters
        if (php_sapi_name() === 'cli') {
            $args = $this->parseCLIArguments();
        } else {
            $args = $_GET;
        }
        
        $params = [
            // Core parameters
            'algorithm' => $args['algorithm'] ?? 'sliding',
            'key' => $args['key'] ?? 'playground_test',
            'burst' => (int)($args['burst'] ?? 10),
            'rate' => (float)($args['rate'] ?? 2.0),
            'window' => (int)($args['window'] ?? 60),
            
            // Concurrency parameters  
            'concurrent' => (int)($args['concurrent'] ?? 5), // 0 = disabled, >0 = enabled
            'timeout' => (int)($args['timeout'] ?? 30),
            
            // Simulation parameters  
            'sleep' => (float)($args['sleep'] ?? 0),
            'error_chance' => (float)($args['error_chance'] ?? 0),
            
            // Redis connection
            'redis_host' => $args['redis_host'] ?? getenv('REDIS_HOST') ?: 'localhost',
            'redis_port' => (int)($args['redis_port'] ?? 6379),
            
            // Output parameters
            'format' => $args['format'] ?? (php_sapi_name() === 'cli' ? 'json' : 'html'),
            'debug' => isset($args['debug']),
            'help' => isset($args['help']),
            
            // Request metadata
            'request_id' => $args['request_id'] ?? uniqid('req_', true),
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'cli'
        ];
        
        return $params;
    }
    
    private function parseCLIArguments(): array
    {
        global $argv;
        $args = [];
        
        if (isset($argv)) {
            foreach ($argv as $arg) {
                if (strpos($arg, '=') !== false) {
                    list($key, $value) = explode('=', $arg, 2);
                    // Remove leading -- from key
                    if (strpos($key, '--') === 0) {
                        $key = substr($key, 2);
                    }
                    $args[$key] = $value;
                } elseif (strpos($arg, '--') === 0) {
                    $args[substr($arg, 2)] = true;
                } elseif (isset($previous_key)) {
                    $args[$previous_key] = $arg;
                    unset($previous_key);
                } else {
                    $previous_key = $arg;
                }
            }
        }
        
        return $args;
    }
    
    private function validateParameters(): void
    {
        $validAlgorithms = ['sliding', 'fixed', 'leaky', 'gcra', 'token', 'concurrency'];
        if (!in_array($this->params['algorithm'], $validAlgorithms)) {
            throw new InvalidArgumentException(
                "Invalid algorithm '{$this->params['algorithm']}'. Valid options: " . 
                implode(', ', $validAlgorithms)
            );
        }
        
        if ($this->params['burst'] <= 0) {
            throw new InvalidArgumentException("Burst capacity must be positive");
        }
        
        if ($this->params['rate'] <= 0) {
            throw new InvalidArgumentException("Rate must be positive");
        }
        
        if ($this->params['window'] <= 0) {
            throw new InvalidArgumentException("Window must be positive");
        }
        
        if ($this->params['sleep'] < 0) {
            throw new InvalidArgumentException("Sleep duration cannot be negative");
        }
        
        if ($this->params['error_chance'] < 0 || $this->params['error_chance'] > 1) {
            throw new InvalidArgumentException("Error chance must be between 0 and 1");
        }
    }
    
    private function executeRateLimitTest(): array
    {
        $algorithm = $this->params['algorithm'];
        $startTime = microtime(true);
        
        // Create rate limiter
        $limiter = $this->createLimiter($algorithm);
        
        // Simulate random error if configured
        if ($this->params['error_chance'] > 0 && mt_rand() / mt_getrandmax() < $this->params['error_chance']) {
            throw new Exception("Simulated error (error_chance={$this->params['error_chance']})");
        }
        
        // Execute rate limit check
        if ($limiter instanceof ConcurrencyAwareRateLimiterInterface) {
            $result = $this->executeConcurrencyAwareTest($limiter, $startTime);
        } else {
            $result = $this->executeStandardTest($limiter, $startTime);
        }
        
        return $result;
    }
    
    private function createLimiter($algorithm)
    {
        // If concurrency is enabled (concurrent > 0), wrap the rate limiter
        if ($this->params['concurrent'] > 0) {
            return $this->factory->createConcurrencyAware($algorithm);
        }
        
        // Otherwise just use the standard rate limiter
        return match ($algorithm) {
            'sliding' => $this->factory->createSlidingWindow(),
            'fixed' => $this->factory->createFixedWindow(),
            'leaky' => $this->factory->createLeakyBucket(),
            'gcra' => $this->factory->createGCRA(),
            'token' => $this->factory->createTokenBucket(),
            default => throw new InvalidArgumentException("Unknown algorithm: {$algorithm}")
        };
    }
    
    private function executeStandardTest($limiter, float $startTime): array
    {
        // Perform rate limit check
        $rateLimitStart = microtime(true);
        $rateLimitResult = $limiter->attempt(
            $this->params['key'],
            $this->params['burst'],
            $this->params['rate'],
            $this->params['window']
        );
        $rateLimitDuration = (microtime(true) - $rateLimitStart) * 1000;
        
        // Simulate slow request processing if allowed
        $sleepDuration = 0;
        $processingStart = null;
        $processingEnd = null;
        
        if ($rateLimitResult->successful() && $this->params['sleep'] > 0) {
            $processingStart = microtime(true);
            usleep((int)($this->params['sleep'] * 1000000));
            $processingEnd = microtime(true);
            $sleepDuration = $processingEnd - $processingStart;
        }
        
        $totalDuration = (microtime(true) - $startTime) * 1000;
        
        return [
            'success' => $rateLimitResult->successful(),
            'algorithm' => $this->params['algorithm'],
            'rate_limit_result' => [
                'successful' => $rateLimitResult->successful(),
                'retry_after' => $rateLimitResult->retryAfter,
                'retries_left' => $rateLimitResult->retriesLeft,
                'limit' => $rateLimitResult->limit,
                'available_at' => $rateLimitResult->availableAt(),
            ],
            'concurrency_result' => null, // Not applicable for standard algorithms
            'timing' => [
                'rate_limit_check_ms' => round($rateLimitDuration, 3),
                'processing_time_ms' => round($sleepDuration * 1000, 3),
                'total_request_ms' => round($totalDuration, 3)
            ],
            'request_info' => [
                'timestamp' => date('Y-m-d H:i:s'),
                'request_id' => $this->params['request_id'],
                'key' => $this->params['key'],
                'parameters' => $this->getPublicParameters()
            ],
            'debugging' => $this->params['debug'] ? $this->getDebuggingInfo($limiter) : null
        ];
    }
    
    private function executeConcurrencyAwareTest(ConcurrencyAwareRateLimiterInterface $limiter, float $startTime): array
    {
        $requestId = $this->params['request_id'];
        $concurrencyAcquired = false;
        
        // Perform concurrency-aware rate limit check
        $rateLimitStart = microtime(true);
        $rateLimitResult = $limiter->attemptWithConcurrency(
            $this->params['key'],
            $requestId,
            $this->params['concurrent'],
            $this->params['burst'],
            $this->params['rate'],
            $this->params['window'],
            $this->params['timeout']
        );
        $rateLimitDuration = (microtime(true) - $rateLimitStart) * 1000;
        
        $concurrencyAcquired = $rateLimitResult->concurrencyAcquired;
        
        // Simulate slow request processing if allowed
        $sleepDuration = 0;
        $processingStart = null;
        $processingEnd = null;
        
        if ($rateLimitResult->successful() && $this->params['sleep'] > 0) {
            $processingStart = microtime(true);
            usleep((int)($this->params['sleep'] * 1000000));
            $processingEnd = microtime(true);
            $sleepDuration = $processingEnd - $processingStart;
        }
        
        // Always try to release concurrency slot if it was acquired
        if ($concurrencyAcquired) {
            try {
                $limiter->releaseConcurrency($this->params['key'], $requestId);
            } catch (Exception $e) {
                // Log but don't fail the request
                error_log("Failed to release concurrency: " . $e->getMessage());
            }
        }
        
        $totalDuration = (microtime(true) - $startTime) * 1000;
        
        return [
            'success' => $rateLimitResult->successful(),
            'algorithm' => $this->params['algorithm'],
            'rate_limit_result' => [
                'successful' => $rateLimitResult->successful(),
                'retry_after' => $rateLimitResult->retryAfter,
                'retries_left' => $rateLimitResult->retriesLeft,
                'limit' => $rateLimitResult->limit,
                'available_at' => $rateLimitResult->availableAt(),
            ],
            'concurrency_result' => [
                'concurrency_acquired' => $rateLimitResult->concurrencyAcquired,
                'rejected_by_concurrency' => $rateLimitResult->rejectedByConcurrency(),
                'rejected_by_rate_limit' => $rateLimitResult->rejectedByRateLimit(),
                'concurrency_rejection_reason' => $rateLimitResult->concurrencyRejectionReason,
                'current_concurrency' => $rateLimitResult->currentConcurrency,
                'max_concurrency' => $rateLimitResult->maxConcurrency,
            ],
            'timing' => [
                'rate_limit_check_ms' => round($rateLimitDuration, 3),
                'processing_time_ms' => round($sleepDuration * 1000, 3),
                'total_request_ms' => round($totalDuration, 3)
            ],
            'request_info' => [
                'timestamp' => date('Y-m-d H:i:s'),
                'request_id' => $requestId,
                'key' => $this->params['key'],
                'parameters' => $this->getPublicParameters()
            ],
            'debugging' => $this->params['debug'] ? $this->getDebuggingInfo($limiter) : null
        ];
    }
    
    private function getPublicParameters(): array
    {
        return array_filter($this->params, function($key) {
            return !in_array($key, ['redis_host', 'redis_port', 'user_ip', 'user_agent']);
        }, ARRAY_FILTER_USE_KEY);
    }
    
    private function getDebuggingInfo($limiter): array
    {
        $debug = [
            'server_time' => date('Y-m-d H:i:s'),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];
        
        // Add algorithm-specific debugging info
        try {
            $debug['current_attempts'] = $limiter->attempts($this->params['key'], $this->params['window']);
            $debug['remaining_attempts'] = $limiter->remaining(
                $this->params['key'], 
                $this->params['burst'], 
                $this->params['rate'], 
                $this->params['window']
            );
            $debug['available_in_seconds'] = $limiter->availableIn(
                $this->params['key'], 
                $this->params['burst'], 
                $this->params['rate'], 
                $this->params['window']
            );
            
            // Add concurrency-specific debug info
            if ($limiter instanceof ConcurrencyAwareRateLimiterInterface) {
                $debug['current_concurrency'] = $limiter->currentConcurrency(
                    $this->params['key'], 
                    $this->params['timeout']
                );
            }
        } catch (Exception $e) {
            $debug['debug_error'] = $e->getMessage();
        }
        
        return $debug;
    }
    
    private function sendResponse(array $result): void
    {
        // Set appropriate HTTP status code (only in web context)
        if (php_sapi_name() !== 'cli') {
            if (!$result['success']) {
                if ($result['concurrency_result'] && $result['concurrency_result']['rejected_by_concurrency']) {
                    http_response_code(503); // Service Unavailable for concurrency limits
                    header('Retry-After: ' . max(1, $result['rate_limit_result']['retry_after']));
                } else {
                    http_response_code(429); // Too Many Requests for rate limits
                    header('Retry-After: ' . $result['rate_limit_result']['retry_after']);
                }
            } else {
                http_response_code(200);
            }
        }
        
        // Send response in requested format
        if ($this->params['format'] === 'json') {
            if (php_sapi_name() !== 'cli') {
                header('Content-Type: application/json');
            }
            echo json_encode($result, JSON_PRETTY_PRINT);
        } else {
            if (php_sapi_name() !== 'cli') {
                header('Content-Type: text/html');
            }
            $this->sendHtmlResponse($result);
        }
    }
    
    private function sendHtmlResponse(array $result): void
    {
        $statusIcon = $result['success'] ? '✅' : '❌';
        $statusText = $result['success'] ? 'ALLOWED' : 'BLOCKED';
        $statusClass = $result['success'] ? 'success' : 'blocked';
        
        // Determine blocking reason for concurrency-aware algorithm
        $blockingReason = '';
        if (!$result['success'] && $result['concurrency_result']) {
            if ($result['concurrency_result']['rejected_by_concurrency']) {
                $blockingReason = ' (Concurrency Limit)';
            } elseif ($result['concurrency_result']['rejected_by_rate_limit']) {
                $blockingReason = ' (Rate Limit)';
            }
        } elseif (!$result['success']) {
            $blockingReason = ' (Rate Limit)';
        }
        
        echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Rate Limiter Playground</title>
    <style>
        body { font-family: 'Monaco', 'Consolas', monospace; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 30px; }
        .status { font-size: 24px; font-weight: bold; margin: 20px 0; text-align: center; }
        .success { color: #28a745; }
        .blocked { color: #dc3545; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .section h3 { margin-top: 0; color: #333; }
        .param-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 10px 0; }
        .param { padding: 5px; }
        .param strong { color: #555; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 3px; overflow-x: auto; }
        .timing { background: #e3f2fd; }
        .concurrency { background: #f3e5f5; }
        .debug { background: #fff3cd; }
        .footer { text-align: center; margin-top: 30px; color: #666; }
        .examples { background: #e8f5e8; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .examples h4 { margin-top: 0; }
        .examples code { background: #fff; padding: 2px 4px; border-radius: 3px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🚦 Rate Limiter Playground</h1>
            <p>Interactive testing environment for rate limiting algorithms</p>
        </div>
        
        <div class='status $statusClass'>
            $statusIcon Request $statusText$blockingReason
        </div>
        
        <div class='section'>
            <h3>📊 Rate Limit Result</h3>
            <div class='param-grid'>
                <div class='param'><strong>Algorithm:</strong> {$result['algorithm']}</div>
                <div class='param'><strong>Success:</strong> " . ($result['rate_limit_result']['successful'] ? 'Yes' : 'No') . "</div>
                <div class='param'><strong>Retries Left:</strong> {$result['rate_limit_result']['retries_left']}</div>
                <div class='param'><strong>Limit:</strong> {$result['rate_limit_result']['limit']}</div>
                <div class='param'><strong>Retry After:</strong> {$result['rate_limit_result']['retry_after']} seconds</div>
                <div class='param'><strong>Available At:</strong> " . date('H:i:s', $result['rate_limit_result']['available_at']) . "</div>
            </div>
        </div>";
        
        // Show concurrency results if available
        if ($result['concurrency_result']) {
            echo "<div class='section concurrency'>
                <h3>🔄 Concurrency Control Result</h3>
                <div class='param-grid'>
                    <div class='param'><strong>Concurrency Acquired:</strong> " . ($result['concurrency_result']['concurrency_acquired'] ? 'Yes' : 'No') . "</div>
                    <div class='param'><strong>Current/Max Concurrent:</strong> {$result['concurrency_result']['current_concurrency']}/{$result['concurrency_result']['max_concurrency']}</div>
                    <div class='param'><strong>Rejected by Concurrency:</strong> " . ($result['concurrency_result']['rejected_by_concurrency'] ? 'Yes' : 'No') . "</div>
                    <div class='param'><strong>Rejected by Rate Limit:</strong> " . ($result['concurrency_result']['rejected_by_rate_limit'] ? 'Yes' : 'No') . "</div>
                    <div class='param'><strong>Rejection Reason:</strong> " . ($result['concurrency_result']['concurrency_rejection_reason'] ?? 'None') . "</div>
                </div>
            </div>";
        }
        
        echo "<div class='section timing'>
            <h3>⏱️ Performance Metrics</h3>
            <div class='param-grid'>
                <div class='param'><strong>Rate Limit Check:</strong> {$result['timing']['rate_limit_check_ms']} ms</div>
                <div class='param'><strong>Processing Time:</strong> {$result['timing']['processing_time_ms']} ms</div>
                <div class='param'><strong>Total Request Time:</strong> {$result['timing']['total_request_ms']} ms</div>
            </div>
        </div>
        
        <div class='section'>
            <h3>📝 Request Information</h3>
            <div class='param-grid'>
                <div class='param'><strong>Timestamp:</strong> {$result['request_info']['timestamp']}</div>
                <div class='param'><strong>Request ID:</strong> {$result['request_info']['request_id']}</div>
                <div class='param'><strong>Key:</strong> {$result['request_info']['key']}</div>
            </div>
            <h4>Parameters Used:</h4>
            <pre>" . json_encode($result['request_info']['parameters'], JSON_PRETTY_PRINT) . "</pre>
        </div>";
        
        // Show debugging info if requested
        if ($result['debugging']) {
            echo "<div class='section debug'>
                <h3>🔍 Debug Information</h3>
                <pre>" . json_encode($result['debugging'], JSON_PRETTY_PRINT) . "</pre>
            </div>";
        }
        
        echo "<div class='examples'>
            <h4>💡 Example Requests:</h4>
            <p><strong>Basic Rate Limiting:</strong><br>
            <code>?algorithm=sliding&key=user123&burst=5&rate=2.0&window=60</code></p>
            
            <p><strong>Concurrency-Aware with Slow Requests:</strong><br>
            <code>?algorithm=concurrency&key=api&concurrent=3&burst=10&rate=5.0&sleep=2&timeout=30</code></p>
            
            <p><strong>JSON Response with Debug:</strong><br>
            <code>?algorithm=gcra&key=test&burst=10&rate=1.0&format=json&debug=1</code></p>
            
            <p><strong>Simulate Errors:</strong><br>
            <code>?algorithm=token&key=test&burst=5&rate=1.0&error_chance=0.1</code></p>
        </div>
        
        <div class='footer'>
            <p>🚀 Powered by <strong>Cm\\RateLimiter</strong> - Concurrency-Aware Rate Limiting</p>
        </div>
    </div>
</body>
</html>";
    }
    
    private function sendErrorResponse(string $message, int $code = 400): void
    {
        if (php_sapi_name() !== 'cli') {
            http_response_code($code);
        }
        
        if (($this->params['format'] ?? 'json') === 'json') {
            if (php_sapi_name() !== 'cli') {
                header('Content-Type: application/json');
            }
            echo json_encode([
                'error' => true,
                'message' => $message,
                'code' => $code,
                'timestamp' => date('Y-m-d H:i:s'),
                'request_duration_ms' => round((microtime(true) - $this->startTime) * 1000, 3)
            ], JSON_PRETTY_PRINT);
        } else {
            if (php_sapi_name() !== 'cli') {
                header('Content-Type: text/html');
            }
            echo "<!DOCTYPE html>
<html><head><title>Error</title></head><body>
<h1>Error $code</h1>
<p>$message</p>
<p><a href='?help=1'>Show Help</a></p>
</body></html>";
        }
        exit;
    }
    
    private function showHelp(): void
    {
        if (php_sapi_name() !== 'cli') {
            header('Content-Type: text/html');
        }
        echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Rate Limiter Playground - Help</title>
    <style>
        body { font-family: 'Monaco', 'Consolas', monospace; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .section h3 { margin-top: 0; color: #333; }
        code { background: #f8f9fa; padding: 2px 4px; border-radius: 3px; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 3px; overflow-x: auto; }
        .param-table { width: 100%; border-collapse: collapse; }
        .param-table th, .param-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .param-table th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🚦 Rate Limiter Playground - Help</h1>
        
        <div class='section'>
            <h3>📖 Overview</h3>
            <p>Interactive testing environment for rate limiting algorithms with support for:</p>
            <ul>
                <li>Multiple rate limiting algorithms (sliding, fixed, leaky, gcra, token)</li>
                <li>Concurrency-aware rate limiting to prevent request pileup (concurrent parameter)</li>
                <li>Slow request simulation with configurable sleep duration</li>
                <li>Error simulation for testing error handling</li>
                <li>Comprehensive debugging and timing information</li>
            </ul>
        </div>
        
        <div class='section'>
            <h3>🔧 Parameters</h3>
            <table class='param-table'>
                <tr><th>Parameter</th><th>Description</th><th>Default</th><th>Example</th></tr>
                <tr><td><code>algorithm</code></td><td>Rate limiting algorithm</td><td>sliding</td><td>gcra, token, fixed</td></tr>
                <tr><td><code>key</code></td><td>Rate limit key identifier</td><td>playground_test</td><td>user123</td></tr>
                <tr><td><code>burst</code></td><td>Burst capacity (max requests)</td><td>10</td><td>5</td></tr>
                <tr><td><code>rate</code></td><td>Sustained rate (requests/second)</td><td>2.0</td><td>1.5</td></tr>
                <tr><td><code>window</code></td><td>Time window in seconds</td><td>60</td><td>30</td></tr>
                <tr><td><code>concurrent</code></td><td>Max concurrent requests (0=disabled)</td><td>5</td><td>3, 0</td></tr>
                <tr><td><code>timeout</code></td><td>Concurrency timeout in seconds</td><td>30</td><td>60</td></tr>
                <tr><td><code>sleep</code></td><td>Simulate slow request (seconds)</td><td>0</td><td>2.5</td></tr>
                <tr><td><code>error_chance</code></td><td>Probability of simulated error (0-1)</td><td>0</td><td>0.1</td></tr>
                <tr><td><code>format</code></td><td>Response format (html/json)</td><td>html</td><td>json</td></tr>
                <tr><td><code>debug</code></td><td>Include debug information</td><td>false</td><td>debug=1</td></tr>
                <tr><td><code>request_id</code></td><td>Custom request identifier</td><td>auto-generated</td><td>req_123</td></tr>
            </table>
        </div>
        
        <div class='section'>
            <h3>🎯 Example Requests</h3>
            
            <h4>Basic Rate Limiting Test:</h4>
            <pre><code>GET /?algorithm=sliding&key=user123&burst=5&rate=2.0&window=60</code></pre>
            
            <h4>Concurrency-Aware with Slow Requests:</h4>
            <pre><code>GET /?algorithm=gcra&key=api&concurrent=3&burst=10&rate=5.0&sleep=2&timeout=30</code></pre>
            
            <h4>Algorithm Comparison (JSON):</h4>
            <pre><code>GET /?algorithm=gcra&key=test&burst=10&rate=1.0&sleep=0.5&format=json&debug=1</code></pre>
            
            <h4>Error Simulation:</h4>
            <pre><code>GET /?algorithm=token&key=test&burst=5&rate=1.0&error_chance=0.1</code></pre>
        </div>
        
        <div class='section'>
            <h3>🔄 Algorithms Available</h3>
            <ul>
                <li><strong>sliding</strong> - Sliding window (precise, smooth rate limiting)</li>
                <li><strong>fixed</strong> - Fixed window (efficient, allows bursts at window boundaries)</li>
                <li><strong>leaky</strong> - Leaky bucket (burst tolerance with average rate control)</li>
                <li><strong>gcra</strong> - GCRA (memory-efficient, highest performance)</li>
                <li><strong>token</strong> - Token bucket (perfect burst + sustained rate support)</li>
            </ul>
        </div>
        
        <div class='section'>
            <h3>📊 Response Codes</h3>
            <ul>
                <li><strong>200 OK</strong> - Request allowed by rate limiter</li>
                <li><strong>429 Too Many Requests</strong> - Blocked by rate limit</li>
                <li><strong>503 Service Unavailable</strong> - Blocked by concurrency limit</li>
                <li><strong>400 Bad Request</strong> - Invalid parameters</li>
                <li><strong>500 Internal Server Error</strong> - Server error (Redis connection, etc.)</li>
            </ul>
        </div>
        
        <div class='section'>
            <h3>🚀 Use Cases</h3>
            <p><strong>Demonstrate Request Pileup Problem:</strong></p>
            <ol>
                <li>Set up slow endpoint: <code>?algorithm=sliding&key=slow_api&concurrent=0&burst=10&rate=2.0&sleep=5</code></li>
                <li>Send multiple concurrent requests</li>
                <li>Watch requests pile up even with rate limiting</li>
                <li>Compare with concurrency control: <code>?algorithm=sliding&key=slow_api&concurrent=3&burst=10&rate=2.0&sleep=5</code></li>
                <li>Test different algorithms: <code>?algorithm=gcra&key=test&concurrent=2&burst=5&rate=1.0&sleep=2</code></li>
            </ol>
        </div>
        
        <div class='footer' style='text-align: center; margin-top: 30px; color: #666;'>
            <p>🚀 Powered by <strong>Cm\\RateLimiter</strong></p>
        </div>
    </div>
</body>
</html>";
    }
}

// Handle the request
$playground = new RateLimiterPlayground();
$playground->handleRequest();