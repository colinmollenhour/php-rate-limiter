<?php

/**
 * Rate Limiter Playground - FrankenPHP Compatible
 * 
 * Interactive endpoint for testing rate limiting algorithms with configurable parameters.
 * Perfect for demonstrating concurrency-aware rate limiting and slow request scenarios.
 * 
 * Usage Examples:
 *
 * Basic rate limiting test (no concurrency control):
 * http://localhost/playground.php?algorithm=sliding&key=user123&burst=5&rate=2.0&window=60
 *
 * GCRA with concurrency control and slow requests:
 * http://localhost/playground.php?algorithm=gcra&key=api&concurrent=3&burst=10&rate=5.0&sleep=2&timeout=30
 *
 * Compare algorithms with JSON output:
 * http://localhost/playground.php?algorithm=token&key=test&burst=10&rate=1.0&concurrent=0&format=json
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

use Cm\RateLimiter\RateLimiterFactory;
use Cm\RateLimiter\ConcurrencyAwareRateLimiterInterface;

class RateLimiterPlayground
{
    private RateLimiterFactory $factory;
    private ?Credis_Client $redis = null;
    
    public function __construct()
    {
        // Redis connection will be initialized lazily when needed
        // This allows "algorithm=none" to work without Redis
    }
    
    private function initializeRedis(): void
    {
        if ($this->redis !== null) {
            return; // Already initialized
        }
        
        try {
            $redisHost = getenv('REDIS_HOST') ?: 'redis';
            $redisPort = (int)(getenv('REDIS_PORT') ?: 6379);
            
            $this->redis = new Credis_Client($redisHost, $redisPort);
            $this->redis->ping();
            $this->factory = new RateLimiterFactory($this->redis);
        } catch (Exception $e) {
            throw new Exception("Redis connection failed: " . $e->getMessage());
        }
    }
    
    public function handleRequest(array $get = null, array $post = null, array $cookie = null, array $files = null, array $server = null): void
    {
        // Use provided superglobals or fall back to actual superglobals
        $get = $get ?? $_GET;
        $post = $post ?? $_POST;
        $cookie = $cookie ?? $_COOKIE;
        $files = $files ?? $_FILES;
        $server = $server ?? $_SERVER;
        
        $startTime = microtime(true);
        
        try {
            // Set headers for CORS and content type
            if (php_sapi_name() !== 'cli') {
                header('Access-Control-Allow-Origin: *');
                header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
                header('Access-Control-Allow-Headers: Content-Type');

                // Handle preflight requests
                if (($server['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
                    http_response_code(200);
                    return;
                }
            }
            
            $params = $this->parseParameters($get, $server);
            
            // Show help if requested
            if ($params['help']) {
                $this->showHelp($params);
                return;
            }
            
            // Validate required parameters
            $this->validateParameters($params);
            
            // Execute rate limit test
            $result = $this->executeRateLimitTest($params, $startTime);
            
            // Send response
            $this->sendResponse($result, $params);
            
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage(), 400);
        }
    }
    
    private function parseParameters(array $get, array $server): array
    {
        $params = [
            // Core parameters
            'algorithm' => $get['algorithm'] ?? 'sliding',
            'key' => $get['key'] ?? 'playground_test',
            'burst' => (int)($get['burst'] ?? 10),
            'rate' => (float)($get['rate'] ?? 2.0),
            'window' => (int)($get['window'] ?? 60),
            
            // Concurrency parameters
            'concurrent' => (int)($get['concurrent'] ?? 0),
            'timeout' => (int)($get['timeout'] ?? 30),
            
            // Simulation parameters
            'sleep' => (float)($get['sleep'] ?? 0),
            'error_chance' => (float)($get['error_chance'] ?? 0),
            
            // Output parameters
            'format' => $get['format'] ?? 'html',
            'debug' => isset($get['debug']),
            'help' => isset($get['help']),
            
            // Request metadata
            'request_id' => $get['request_id'] ?? uniqid('req_', true),
            'user_ip' => $server['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $server['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        
        return $params;
    }
    
    private function validateParameters(array $params): void
    {
        $validAlgorithms = ['sliding', 'fixed', 'leaky', 'gcra', 'token', 'none'];
        if (!in_array($params['algorithm'], $validAlgorithms)) {
            throw new InvalidArgumentException(
                "Invalid algorithm '{$params['algorithm']}'. Valid options: " .
                implode(', ', $validAlgorithms)
            );
        }
        
        if ($params['burst'] <= 0) {
            throw new InvalidArgumentException("Burst capacity must be positive");
        }
        
        if ($params['rate'] <= 0) {
            throw new InvalidArgumentException("Rate must be positive");
        }
        
        if ($params['window'] <= 0) {
            throw new InvalidArgumentException("Window must be positive");
        }
        
        if ($params['sleep'] < 0) {
            throw new InvalidArgumentException("Sleep duration cannot be negative");
        }
        
        if ($params['error_chance'] < 0 || $params['error_chance'] > 1) {
            throw new InvalidArgumentException("Error chance must be between 0 and 1");
        }
    }
    
    private function executeRateLimitTest(array $params, float $startTime): array
    {
        $algorithm = $params['algorithm'];
        $useConcurrency = $params['concurrent'] > 0;
        
        // Simulate random error if configured
        if ($params['error_chance'] > 0 && mt_rand() / mt_getrandmax() < $params['error_chance']) {
            throw new Exception("Simulated error (error_chance={$params['error_chance']})");
        }
        
        // Handle "none" algorithm - skip rate limiting entirely for performance baseline
        if ($algorithm === 'none') {
            return $this->executeNoRateLimitTest($params, $startTime);
        }
        
        // Initialize Redis connection for rate limiting algorithms
        $this->initializeRedis();
        
        // Create rate limiter (with or without concurrency control)
        $limiter = $this->createLimiter($algorithm, $useConcurrency);
        
        // Execute rate limit check
        if ($useConcurrency) {
            $result = $this->executeConcurrencyAwareTest($limiter, $params, $startTime);
        } else {
            $result = $this->executeStandardTest($limiter, $params, $startTime);
        }
        
        return $result;
    }
    
    private function createLimiter($algorithm, bool $useConcurrency = false)
    {
        // Handle "none" algorithm early to avoid Redis initialization
        if ($algorithm === 'none') {
            return null;
        }
        
        // Create the base rate limiter
        $baseLimiter = match ($algorithm) {
            'sliding' => $this->factory->createSlidingWindow(),
            'fixed' => $this->factory->createFixedWindow(),
            'leaky' => $this->factory->createLeakyBucket(),
            'gcra' => $this->factory->createGCRA(),
            'token' => $this->factory->createTokenBucket(),
            default => throw new InvalidArgumentException("Unknown algorithm: {$algorithm}")
        };
        
        // Wrap with concurrency control if requested
        if ($useConcurrency && $baseLimiter !== null) {
            return new \Cm\RateLimiter\ConcurrencyAware\RateLimiter($this->redis, $baseLimiter);
        }
        
        return $baseLimiter;
    }
    
    private function executeNoRateLimitTest(array $params, float $startTime): array
    {
        // Simulate slow request processing without any rate limiting
        $sleepDuration = 0;
        if ($params['sleep'] > 0) {
            $processingStart = microtime(true);
            usleep((int)($params['sleep'] * 1000000));
            $processingEnd = microtime(true);
            $sleepDuration = $processingEnd - $processingStart;
        }
        
        $totalDuration = (microtime(true) - $startTime) * 1000;
        
        return [
            'success' => true, // Always successful when no rate limiting
            'algorithm' => 'none',
            'rate_limit_result' => [
                'successful' => true,
                'retry_after' => 0,
                'retries_left' => PHP_INT_MAX,
                'limit' => PHP_INT_MAX,
                'available_at' => time(),
            ],
            'concurrency_result' => null,
            'timing' => [
                'rate_limit_check_ms' => 0.0, // No rate limit check performed
                'processing_time_ms' => round($sleepDuration * 1000, 3),
                'total_request_ms' => round($totalDuration, 3)
            ],
            'request_info' => [
                'timestamp' => date('Y-m-d H:i:s'),
                'request_id' => $params['request_id'],
                'key' => $params['key'],
                'parameters' => $this->getPublicParameters($params)
            ],
            'debugging' => $params['debug'] ? [
                'server_time' => date('Y-m-d H:i:s'),
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
                'note' => 'No rate limiting applied - performance baseline mode'
            ] : null
        ];
    }
    
    private function executeStandardTest($limiter, array $params, float $startTime): array
    {
        // Perform rate limit check
        $rateLimitStart = microtime(true);
        $rateLimitResult = $limiter->attempt(
            $params['key'],
            $params['burst'],
            $params['rate'],
            $params['window']
        );
        $rateLimitDuration = (microtime(true) - $rateLimitStart) * 1000;
        
        // Simulate slow request processing if allowed
        $sleepDuration = 0;
        if ($rateLimitResult->successful() && $params['sleep'] > 0) {
            $processingStart = microtime(true);
            usleep((int)($params['sleep'] * 1000000));
            $processingEnd = microtime(true);
            $sleepDuration = $processingEnd - $processingStart;
        }
        
        $totalDuration = (microtime(true) - $startTime) * 1000;
        
        return [
            'success' => $rateLimitResult->successful(),
            'algorithm' => $params['algorithm'],
            'rate_limit_result' => [
                'successful' => $rateLimitResult->successful(),
                'retry_after' => $rateLimitResult->retryAfter,
                'retries_left' => $rateLimitResult->retriesLeft,
                'limit' => $rateLimitResult->limit,
                'available_at' => $rateLimitResult->availableAt(),
            ],
            'concurrency_result' => null,
            'timing' => [
                'rate_limit_check_ms' => round($rateLimitDuration, 3),
                'processing_time_ms' => round($sleepDuration * 1000, 3),
                'total_request_ms' => round($totalDuration, 3)
            ],
            'request_info' => [
                'timestamp' => date('Y-m-d H:i:s'),
                'request_id' => $params['request_id'],
                'key' => $params['key'],
                'parameters' => $this->getPublicParameters($params)
            ],
            'debugging' => $params['debug'] ? $this->getDebuggingInfo($limiter, $params) : null
        ];
    }
    
    private function executeConcurrencyAwareTest(ConcurrencyAwareRateLimiterInterface $limiter, array $params, float $startTime): array
    {
        $requestId = $params['request_id'];
        $concurrencyAcquired = false;
        
        // Perform concurrency-aware rate limit check
        $rateLimitStart = microtime(true);
        $rateLimitResult = $limiter->attemptWithConcurrency(
            $params['key'],
            $requestId,
            $params['concurrent'],
            $params['burst'],
            $params['rate'],
            $params['window'],
            $params['timeout']
        );
        $rateLimitDuration = (microtime(true) - $rateLimitStart) * 1000;
        
        $concurrencyAcquired = $rateLimitResult->concurrencyAcquired;
        
        // Simulate slow request processing if allowed
        $sleepDuration = 0;
        if ($rateLimitResult->successful() && $params['sleep'] > 0) {
            $processingStart = microtime(true);
            usleep((int)($params['sleep'] * 1000000));
            $processingEnd = microtime(true);
            $sleepDuration = $processingEnd - $processingStart;
        }
        
        // Always try to release concurrency slot if it was acquired
        if ($concurrencyAcquired) {
            try {
                $limiter->releaseConcurrency($params['key'], $requestId);
            } catch (Exception $e) {
                error_log("Failed to release concurrency: " . $e->getMessage());
            }
        }
        
        $totalDuration = (microtime(true) - $startTime) * 1000;
        
        return [
            'success' => $rateLimitResult->successful(),
            'algorithm' => $params['algorithm'],
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
                'key' => $params['key'],
                'parameters' => $this->getPublicParameters($params)
            ],
            'debugging' => $params['debug'] ? $this->getDebuggingInfo($limiter, $params) : null
        ];
    }
    
    private function getPublicParameters(array $params): array
    {
        return array_filter($params, function($key) {
            return !in_array($key, ['user_ip', 'user_agent']);
        }, ARRAY_FILTER_USE_KEY);
    }
    
    private function getDebuggingInfo($limiter, array $params): array
    {
        $debug = [
            'server_time' => date('Y-m-d H:i:s'),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];
        
        // Skip rate limiter debugging for "none" algorithm
        if ($params['algorithm'] === 'none' || $limiter === null) {
            $debug['note'] = 'No rate limiting applied - performance baseline mode';
            return $debug;
        }
        
        try {
            $debug['current_attempts'] = $limiter->attempts($params['key'], $params['window']);
            $debug['remaining_attempts'] = $limiter->remaining(
                $params['key'],
                $params['burst'],
                $params['rate'],
                $params['window']
            );
            $debug['available_in_seconds'] = $limiter->availableIn(
                $params['key'],
                $params['burst'],
                $params['rate'],
                $params['window']
            );
            
            if ($limiter instanceof ConcurrencyAwareRateLimiterInterface) {
                $debug['current_concurrency'] = $limiter->currentConcurrency(
                    $params['key'],
                    $params['timeout']
                );
            }
        } catch (Exception $e) {
            $debug['debug_error'] = $e->getMessage();
        }
        
        return $debug;
    }
    
    private function sendResponse(array $result, array $params): void
    {
        // Set appropriate HTTP status code
        if (php_sapi_name() !== 'cli') {
            if (!$result['success']) {
                if ($result['concurrency_result'] && $result['concurrency_result']['rejected_by_concurrency']) {
                    http_response_code(430);  // Custom code for concurrency limit (not web server limit)
                } else {
                    http_response_code(429);  // Standard rate limit exceeded
                    header('Retry-After: ' . $result['rate_limit_result']['retry_after']);
                }
            } else {
                http_response_code(200);
            }
        }
        
        if ($params['format'] === 'json') {
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
                <h3>🔄 Concurrency Control</h3>
                <div class='param-grid'>
                    <div class='param'><strong>Concurrency Acquired:</strong> " . ($result['concurrency_result']['concurrency_acquired'] ? 'Yes' : 'No') . "</div>
                    <div class='param'><strong>Current Concurrency:</strong> {$result['concurrency_result']['current_concurrency']}</div>
                    <div class='param'><strong>Max Concurrency:</strong> {$result['concurrency_result']['max_concurrency']}</div>
                    <div class='param'><strong>Rejected by Concurrency:</strong> " . ($result['concurrency_result']['rejected_by_concurrency'] ? 'Yes' : 'No') . "</div>
                    <div class='param'><strong>Rejected by Rate Limit:</strong> " . ($result['concurrency_result']['rejected_by_rate_limit'] ? 'Yes' : 'No') . "</div>";
            
            if ($result['concurrency_result']['concurrency_rejection_reason']) {
                echo "<div class='param'><strong>Rejection Reason:</strong> {$result['concurrency_result']['concurrency_rejection_reason']}</div>";
            }
            
            echo "</div></div>";
        }
        
        // Add timing information
        echo "<div class='section timing'>
            <h3>⏱️ Performance Metrics</h3>
            <div class='param-grid'>
                <div class='param'><strong>Rate Limit Check:</strong> {$result['timing']['rate_limit_check_ms']} ms</div>
                <div class='param'><strong>Processing Time:</strong> {$result['timing']['processing_time_ms']} ms</div>
                <div class='param'><strong>Total Request Time:</strong> {$result['timing']['total_request_ms']} ms</div>
            </div>
        </div>";
        
        // Add debug information if available
        if ($result['debugging']) {
            echo "<div class='section debug'>
                <h3>🔍 Debug Information</h3>
                <pre>" . json_encode($result['debugging'], JSON_PRETTY_PRINT) . "</pre>
            </div>";
        }
        
        // Add examples
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
            
            <p><strong>Performance Baseline (No Rate Limiting):</strong><br>
            <code>?algorithm=none&key=baseline&sleep=0.1&format=json</code></p>
        </div>
        
        <div class='footer'>
            <p>🚀 Powered by <strong>FrankenPHP</strong> + <strong>Cm\\RateLimiter</strong></p>
        </div>
    </div>
</body>
</html>";
    }
    
    private function sendErrorResponse(string $message, int $code = 400): void
    {
        if (php_sapi_name() !== 'cli') {
            http_response_code($code);
            header('Content-Type: text/html');
        }
        
        echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Rate Limiter Playground - Error</title>
    <style>
        body { font-family: 'Monaco', 'Consolas', monospace; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .error { color: #dc3545; text-align: center; }
        .footer { text-align: center; margin-top: 30px; color: #666; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='error'>
            <h1>❌ Error {$code}</h1>
            <p>{$message}</p>
        </div>
        <div class='footer'>
            <p>🚀 Powered by <strong>FrankenPHP</strong> + <strong>Cm\\RateLimiter</strong></p>
        </div>
    </div>
</body>
</html>";
        exit;
    }
    
    private function showHelp(array $params): void
    {
        $helpContent = "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Rate Limiter Playground - Help</title>
    <style>
        body { font-family: 'Monaco', 'Consolas', monospace; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 30px; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .section h3 { margin-top: 0; color: #333; }
        code { background: #f8f9fa; padding: 2px 4px; border-radius: 3px; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 3px; overflow-x: auto; }
        .footer { text-align: center; margin-top: 30px; color: #666; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🚦 Rate Limiter Playground - Help</h1>
            <p>Interactive testing environment for rate limiting algorithms</p>
        </div>
        
        <div class='section'>
            <h3>📋 Available Parameters</h3>
            <ul>
                <li><code>algorithm</code> - Algorithm to use: sliding, fixed, leaky, gcra, token, none</li>
                <li><code>key</code> - Rate limit key (default: playground_test)</li>
                <li><code>burst</code> - Burst capacity (default: 10)</li>
                <li><code>rate</code> - Sustained rate per second (default: 2.0)</li>
                <li><code>window</code> - Time window in seconds (default: 60)</li>
                <li><code>concurrent</code> - Max concurrent requests (0=disabled, >0=enabled, default: 0)</li>
                <li><code>timeout</code> - Concurrency timeout in seconds (default: 30)</li>
                <li><code>sleep</code> - Simulate slow processing in seconds (default: 0)</li>
                <li><code>error_chance</code> - Probability of simulated error 0-1 (default: 0)</li>
                <li><code>format</code> - Response format: html or json (default: html)</li>
                <li><code>debug</code> - Enable debug information</li>
            </ul>
        </div>
        
        <div class='section'>
            <h3>💡 Example URLs</h3>
            <pre>
# Basic sliding window test (no concurrency control)
?algorithm=sliding&key=user123&burst=5&rate=2.0&window=60

# GCRA with concurrency control and slow requests
?algorithm=gcra&key=api&concurrent=3&burst=10&rate=5.0&sleep=2

# Token bucket without concurrency control
?algorithm=token&key=test&burst=10&rate=1.0&concurrent=0&format=json&debug=1

# Fixed window with concurrency control
?algorithm=fixed&key=test&burst=5&rate=1.0&concurrent=2&error_chance=0.1

# No rate limiting - performance baseline test
?algorithm=none&key=baseline&sleep=0.1&format=json
            </pre>
        </div>
        
        <div class='footer'>
            <p>🚀 Powered by <strong>FrankenPHP</strong> + <strong>Cm\\RateLimiter</strong></p>
        </div>
    </div>
</body>
</html>";
        
        if ($params['format'] === 'json') {
            if (php_sapi_name() !== 'cli') {
                header('Content-Type: application/json');
            }
            echo json_encode(['help' => 'Rate Limiter Playground Help', 'parameters' => [
                'algorithm' => 'sliding, fixed, leaky, gcra, token, none',
                'key' => 'Rate limit key',
                'burst' => 'Burst capacity',
                'rate' => 'Sustained rate per second',
                'window' => 'Time window in seconds',
                'concurrent' => 'Max concurrent requests',
                'timeout' => 'Concurrency timeout',
                'sleep' => 'Simulate processing delay',
                'error_chance' => 'Error simulation probability',
                'format' => 'html or json',
                'debug' => 'Enable debug info'
            ]], JSON_PRETTY_PRINT);
        } else {
            echo $helpContent;
        }
    }
}

$app = new RateLimiterPlayground();

// Check if we're running in FrankenPHP worker mode
// Worker mode is detected by checking if we're being called as a worker script
if (function_exists('frankenphp_handle_request')) {
    // Try to determine if we're in worker mode by attempting to call the function
    try {
        // FrankenPHP worker mode
        ignore_user_abort(true);
        
        
        $handler = static function () use ($app) {
            $app->handleRequest($_GET, $_POST, $_COOKIE, $_FILES, $_SERVER);
        };
        
        $maxRequests = (int)($_SERVER['MAX_REQUESTS'] ?? 0);
        for ($nbRequests = 0; !$maxRequests || $nbRequests < $maxRequests; ++$nbRequests) {
            $keepRunning = \frankenphp_handle_request($handler);
            gc_collect_cycles();
            if (!$keepRunning) break;
        }
        echo "Shut down after {$nbRequests} requests.\n";
    } catch (RuntimeException $e) {
        echo $e . "\n";
        exit(1);
    }
} else {
    // Regular PHP mode
    $app->handleRequest();
}