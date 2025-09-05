<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once 'vendor/autoload.php';

use Cm\RateLimiter\RateLimiterFactory;

class StressTestRunner
{
    private RateLimiterFactory $factory;
    private Credis_Client $redis;
    private array $options;
    
    public function __construct(array $options = [])
    {
        $this->options = array_merge([
            'algorithms' => ['sliding', 'fixed', 'leaky', 'gcra', 'token', 'concurrency'],
            'scenarios' => ['all'],
            'duration' => 30,
            'processes' => 20,
            'redis_host' => '127.0.0.1',
            'redis_port' => 6379,
            'target_rps' => null,
            'custom_keys' => null,
            'limiter_window' => null,
            'limiter_rps' => null,
            'limiter_burst' => null,
            'concurrency_max' => null,
            'concurrency_timeout' => null,
            'use_concurrency' => false,
            'verbose' => false,
            'no_clear' => false,
            'max_speed' => false,
            'latency_precision' => 2,
            'latency_sample' => 1
        ], $options);
        
        $this->redis = new Credis_Client($this->options['redis_host'], $this->options['redis_port']);
        $this->factory = new RateLimiterFactory($this->redis);
        
        // Clear Redis before tests unless disabled
        if (!$this->options['no_clear']) {
            $this->redis->flushdb();
        }
    }
    
    public function run(): void
    {
        $testType = $this->options['max_speed'] ? "Max Speed Performance Test" : "Rate Limiter Stress Test";
        echo "=== {$testType} ===\n";
        echo "Testing algorithms: " . implode(', ', $this->options['algorithms']) . "\n";
        echo "PHP Version: " . PHP_VERSION . "\n";
        echo "Processes: " . $this->options['processes'] . "\n";
        echo "Duration: " . $this->options['duration'] . "s per test\n";
        echo "Redis: {$this->options['redis_host']}:{$this->options['redis_port']}\n";
        echo "Server: " . $this->getRedisServerInfo() . "\n";
        if ($this->options['max_speed']) {
            echo "Mode: Maximum speed (no throttling) - measuring raw algorithm performance\n";
        } else {
            echo "Mode: Throttled load testing - measuring rate limiting behavior\n";
        }
        
        // Show rate limiter configuration
        $this->displayRateLimiterConfig();
        echo "\n";
        
        $testConfigs = $this->getTestConfigurations();
        
        // Filter scenarios if specified
        if (!in_array('all', $this->options['scenarios'])) {
            $testConfigs = array_filter($testConfigs, function($config) {
                return in_array($config['key'], $this->options['scenarios']);
            });
        }
        
        foreach ($testConfigs as $config) {
            echo "Running test: {$config['name']}\n";
            echo str_repeat("=", 60) . "\n";
            
            // Show effective rate limiter config for scenario-based tests
            if (!isset($this->options['limiter_rps']) || $this->options['limiter_rps'] === null) {
                $this->displayScenarioRateLimiterConfig($config);
            }
            
            $results = [];
            
            foreach ($this->options['algorithms'] as $algorithm) {
                if ($this->options['verbose']) {
                    echo "Testing {$algorithm} algorithm...\n";
                }
                
                $results[$algorithm] = $this->runAlgorithmTest($algorithm, $config);
                
                // Clear Redis between algorithms unless disabled
                if (!$this->options['no_clear']) {
                    $this->redis->flushdb();
                }
            }
            
            $this->printTestResults($config['name'], $results);
            echo "\n";
        }
    }
    
    private function getTestConfigurations(): array
    {
        // Check for custom configuration
        if ($this->options['custom_keys'] !== null) {
            return [[
                'name' => 'Custom Test - ' . $this->options['custom_keys'] . ' Keys',
                'key' => 'custom',
                'keys' => $this->options['custom_keys'],
                'processes' => $this->options['processes'],
                'duration' => $this->options['duration'],
                'max_attempts' => 10,  // Default fallback - prefer using --limiter-rps/--limiter-burst
                'decay' => 60,       // Default window - prefer using --limiter-window
                'window' => $this->options['limiter_window'] ?? 60,
                'limiter_rps' => $this->options['limiter_rps'],
                'limiter_burst' => $this->options['limiter_burst'],
                'concurrency_max' => $this->options['concurrency_max'],
                'concurrency_timeout' => $this->options['concurrency_timeout'] ?? 30,
                'target_rps' => $this->options['target_rps'] ?? 500
            ]];
        }
        
        // In max-speed mode, use the same scenarios but with no throttling
        // The throttling will be disabled in the worker process based on max_speed flag
        
        return [
            [
                'name' => 'High Contention - 5 Keys',
                'key' => 'high',
                'keys' => 5,
                'processes' => $this->options['processes'],
                'duration' => $this->options['duration'],
                'max_attempts' => 100,
                'decay' => 10,
                'window' => $this->options['limiter_window'] ?? 60,
                'limiter_rps' => $this->options['limiter_rps'],
                'limiter_burst' => $this->options['limiter_burst'],
                'concurrency_max' => $this->options['concurrency_max'] ?? 10,
                'concurrency_timeout' => $this->options['concurrency_timeout'] ?? 30,
                'target_rps' => $this->options['target_rps'] ?? 500
            ],
            [
                'name' => 'Medium Contention - 50 Keys', 
                'key' => 'medium',
                'keys' => 50,
                'processes' => $this->options['processes'],
                'duration' => $this->options['duration'],
                'max_attempts' => 50,
                'decay' => 10,
                'window' => $this->options['limiter_window'] ?? 60,
                'limiter_rps' => $this->options['limiter_rps'],
                'limiter_burst' => $this->options['limiter_burst'],
                'concurrency_max' => $this->options['concurrency_max'] ?? 15,
                'concurrency_timeout' => $this->options['concurrency_timeout'] ?? 30,
                'target_rps' => $this->options['target_rps'] ?? 1000
            ],
            [
                'name' => 'Low Contention - 1000 Keys',
                'key' => 'low',
                'keys' => 1000,
                'processes' => $this->options['processes'],
                'duration' => $this->options['duration'],
                'max_attempts' => 10,
                'decay' => 10,
                'window' => $this->options['limiter_window'] ?? 60,
                'limiter_rps' => $this->options['limiter_rps'],
                'limiter_burst' => $this->options['limiter_burst'],
                'concurrency_max' => $this->options['concurrency_max'] ?? 25,
                'concurrency_timeout' => $this->options['concurrency_timeout'] ?? 30,
                'target_rps' => $this->options['target_rps'] ?? 2000
            ],
            [
                'name' => 'Single Key Burst Test',
                'key' => 'burst',
                'keys' => 1,
                'processes' => min(50, $this->options['processes'] * 2),
                'duration' => max(10, $this->options['duration'] / 3),
                'max_attempts' => 100,
                'decay' => 5,
                'window' => $this->options['limiter_window'] ?? 60,
                'limiter_rps' => $this->options['limiter_rps'],
                'limiter_burst' => $this->options['limiter_burst'],
                'concurrency_max' => $this->options['concurrency_max'] ?? 5,
                'concurrency_timeout' => $this->options['concurrency_timeout'] ?? 30,
                'target_rps' => $this->options['target_rps'] ?? 1000
            ]
        ];
    }
    
    private function runAlgorithmTest(string $algorithm, array $config): array
    {
        echo "Testing {$algorithm} algorithm...\n";
        
        $startTime = microtime(true);
        $processes = [];
        $results = [
            'successful' => 0,
            'blocked' => 0,
            'blocked_by_concurrency' => 0,
            'blocked_by_rate_limit' => 0,
            'errors' => 0,
            'total_requests' => 0,
            'duration' => 0,
            'rps' => 0,
            'success_rate' => 0,
            'block_rate' => 0,
            'concurrency_block_rate' => 0,
            'rate_limit_block_rate' => 0,
            'error_rate' => 0,
            'latency_avg' => 0,
            'latency_p50' => 0,
            'latency_p95' => 0,
            'latency_p99' => 0,
            'latency_max' => 0,
            'latency_min' => 0
        ];
        
        // Create temporary files for process communication
        $tempFiles = [];
        for ($i = 0; $i < $config['processes']; $i++) {
            $tempFiles[$i] = tempnam(sys_get_temp_dir(), 'stress_test_');
        }
        
        // Fork processes
        for ($i = 0; $i < $config['processes']; $i++) {
            $pid = pcntl_fork();
            
            if ($pid == -1) {
                die("Could not fork process $i\n");
            } elseif ($pid == 0) {
                // Child process
                $this->runWorkerProcess($algorithm, $config, $i, $tempFiles[$i]);
                exit(0);
            } else {
                // Parent process
                $processes[] = $pid;
            }
        }
        
        // Wait for all processes to complete
        foreach ($processes as $pid) {
            pcntl_waitpid($pid, $status);
        }
        
        $endTime = microtime(true);
        $results['duration'] = $endTime - $startTime;
        
        // Collect results from temp files
        $allLatencyCounters = [];
        foreach ($tempFiles as $i => $tempFile) {
            if (file_exists($tempFile)) {
                $data = json_decode(file_get_contents($tempFile), true);
                if ($data) {
                    $results['successful'] += $data['successful'];
                    $results['blocked'] += $data['blocked'];
                    $results['blocked_by_concurrency'] += $data['blocked_by_concurrency'] ?? 0;
                    $results['blocked_by_rate_limit'] += $data['blocked_by_rate_limit'] ?? 0;
                    $results['errors'] += $data['errors'];
                    $results['total_requests'] += $data['total_requests'];
                    
                    // Merge latency counters
                    if (isset($data['latency_counters']) && is_array($data['latency_counters'])) {
                        foreach ($data['latency_counters'] as $latency => $count) {
                            if (!isset($allLatencyCounters[$latency])) {
                                $allLatencyCounters[$latency] = 0;
                            }
                            $allLatencyCounters[$latency] += $count;
                        }
                    }
                }
                unlink($tempFile);
            }
        }
        
        // Calculate metrics
        if ($results['total_requests'] > 0) {
            $results['rps'] = $results['total_requests'] / $results['duration'];
            $results['success_rate'] = ($results['successful'] / $results['total_requests']) * 100;
            $results['block_rate'] = ($results['blocked'] / $results['total_requests']) * 100;
            $results['concurrency_block_rate'] = ($results['blocked_by_concurrency'] / $results['total_requests']) * 100;
            $results['rate_limit_block_rate'] = ($results['blocked_by_rate_limit'] / $results['total_requests']) * 100;
            $results['error_rate'] = ($results['errors'] / $results['total_requests']) * 100;
        }
        
        // Calculate latency statistics from counter data
        if (!empty($allLatencyCounters)) {
            $results = array_merge($results, $this->calculateLatencyStatistics($allLatencyCounters));
        }
        
        return $results;
    }
    
    private function runWorkerProcess(string $algorithm, array $config, int $workerId, string $tempFile): void
    {
        // Create new Redis connection for this process
        $redis = new Credis_Client('127.0.0.1', 6379);
        $factory = new RateLimiterFactory($redis);
        
        $limiter = match ($algorithm) {
            'sliding' => $factory->createSlidingWindow(),
            'fixed' => $factory->createFixedWindow(),
            'leaky' => $factory->createLeakyBucket(),
            'gcra' => $factory->createGCRA(),
            'token' => $factory->createTokenBucket(),
            'concurrency' => $factory->createConcurrencyAware(),
            default => throw new InvalidArgumentException("Unknown algorithm: {$algorithm}")
        };
        
        $stats = [
            'successful' => 0,
            'blocked' => 0,
            'blocked_by_concurrency' => 0,
            'blocked_by_rate_limit' => 0, 
            'errors' => 0,
            'total_requests' => 0,
            'latency_counters' => [] // Store latency counts by rounded value (5 decimal places)
        ];
        
        // Track concurrency request IDs for cleanup
        $concurrencyRequestIds = [];
        
        $testEndTime = time() + $config['duration'];
        
        // Calculate request delay - skip in max speed mode
        $requestDelay = 0;
        if (!$this->options['max_speed']) {
            $requestDelay = $config['processes'] > 0 ? (1000000 / $config['target_rps']) * $config['processes'] : 1000;
        }
        
        while (time() < $testEndTime) {
            // Select random key from available set
            $keyId = rand(1, $config['keys']);
            $key = "test_key_{$keyId}";
            
            try {
                // Measure latency of the rate limit check
                $latencyStartTime = microtime(true);
                
                $timeWindow = $config['window'];                    // Time window in seconds
                
                // Allow independent control of rate limiter rate vs test load rate
                if (isset($config['limiter_rps']) && $config['limiter_rps'] !== null) {
                    // Use explicit limiter RPS if provided
                    $sustainedRate = (float)$config['limiter_rps'];      // Requests per second for rate limiter
                    
                    // Allow explicit burst capacity or reasonable default
                    if (isset($config['limiter_burst']) && $config['limiter_burst'] !== null) {
                        $burstCapacity = (int)$config['limiter_burst'];    // Explicit burst capacity
                    } else {
                        // Default: allow 10 seconds worth of sustained rate as burst
                        $burstCapacity = max(10, (int)($sustainedRate * 10)); 
                    }
                } else {
                    // Fallback to old behavior: derive from max_attempts
                    $burstCapacity = $config['max_attempts'];           // Burst capacity
                    $sustainedRate = $burstCapacity / (float)$timeWindow; // Calculate sustained rate (requests/second)
                }
                
                // Handle concurrency-aware algorithm
                if ($algorithm === 'concurrency') {
                    $requestId = 'stress_test_' . $workerId . '_' . uniqid();
                    $maxConcurrent = $config['concurrency_max'] ?? 10;
                    $timeoutSeconds = $config['concurrency_timeout'] ?? 30;
                    
                    // Debug output for concurrency rate limiter configuration (only show occasionally)
                    static $debugCountConcurrency = 0;
                    if ($this->options['verbose'] && ++$debugCountConcurrency <= 3) {
                        echo "[DEBUG] Concurrency rate limiter config: concurrent={$maxConcurrent}, burst={$burstCapacity}, rate={$sustainedRate} req/sec, window={$timeWindow}s, timeout={$timeoutSeconds}s\n";
                    }
                    
                    $result = $limiter->attemptWithConcurrency(
                        $key, 
                        $requestId, 
                        $maxConcurrent, 
                        $burstCapacity, 
                        $sustainedRate, 
                        $timeWindow, 
                        $timeoutSeconds
                    );
                    
                    // Track request ID for cleanup
                    if ($result->successful()) {
                        $concurrencyRequestIds[$key][] = $requestId;
                    }
                } else {
                    // Debug output for regular rate limiter configuration (only show occasionally)
                    static $debugCount = 0;
                    if ($this->options['verbose'] && ++$debugCount <= 3) {
                        echo "[DEBUG] Rate limiter config: burst={$burstCapacity}, rate={$sustainedRate} req/sec, window={$timeWindow}s\n";
                    }
                    
                    $result = $limiter->attempt($key, $burstCapacity, $sustainedRate, $timeWindow);
                }
                
                $latencyEndTime = microtime(true);
                
                $latency = ($latencyEndTime - $latencyStartTime) * 1000; // Convert to milliseconds
                
                // Collect latency with configurable sampling
                $shouldSample = ($stats['total_requests'] % $this->options['latency_sample'] === 0);
                
                if ($shouldSample) {
                    // Round to configurable precision and use as string key to avoid float->int conversion
                    $roundedLatency = (string)round($latency, $this->options['latency_precision']);
                    if (!isset($stats['latency_counters'][$roundedLatency])) {
                        $stats['latency_counters'][$roundedLatency] = 0;
                    }
                    $stats['latency_counters'][$roundedLatency]++;
                }
                $stats['total_requests']++;
                
                if ($result->successful()) {
                    $stats['successful']++;
                } else {
                    $stats['blocked']++;
                    
                    // For concurrency-aware algorithm, track the specific blocking reason
                    if ($algorithm === 'concurrency' && method_exists($result, 'rejectedByConcurrency')) {
                        if ($result->rejectedByConcurrency()) {
                            $stats['blocked_by_concurrency']++;
                        } elseif ($result->rejectedByRateLimit()) {
                            $stats['blocked_by_rate_limit']++;
                        }
                    } else {
                        // For regular algorithms, assume all blocks are rate limit blocks
                        $stats['blocked_by_rate_limit']++;
                    }
                }
                
            } catch (Exception $e) {
                $stats['errors']++;
                $stats['total_requests']++;
                // Don't record latency for errors since the operation didn't complete normally
            }
            
            // Rate limiting to prevent overwhelming the system
            if ($requestDelay > 0) {
                usleep((int)$requestDelay);
            }
        }
        
        // Cleanup concurrency requests if using concurrency algorithm
        if ($algorithm === 'concurrency' && !empty($concurrencyRequestIds)) {
            foreach ($concurrencyRequestIds as $key => $requestIds) {
                foreach ($requestIds as $requestId) {
                    try {
                        $limiter->releaseConcurrency($key, $requestId);
                    } catch (Exception $e) {
                        // Ignore cleanup errors
                    }
                }
            }
        }
        
        // Write results to temp file
        file_put_contents($tempFile, json_encode($stats));
    }
    
    private function printTestResults(string $testName, array $results): void
    {
        echo "Results for: $testName\n";
        echo str_repeat("-", 80) . "\n";
        
        $algorithms = array_keys($results);
        $columnWidth = max(15, (60 / count($algorithms)));
        
        // Build format string dynamically based on number of algorithms
        $format = "%-22s |"; // Slightly wider for latency labels
        foreach ($algorithms as $alg) {
            $format .= sprintf(" %%-%ds |", $columnWidth);
        }
        if (count($algorithms) > 1) {
            $format .= " %-10s";
        }
        $format .= "\n";
        
        // Print header
        $headers = array_merge(['Metric'], array_map('ucfirst', $algorithms));
        if (count($algorithms) > 1) {
            $headers[] = 'Difference';
        }
        printf($format, ...$headers);
        echo str_repeat("-", 80) . "\n";
        
        $metrics = [
            'Total Requests' => ['total_requests', '%d'],
            'Requests/sec' => ['rps', '%.2f'],
            'Success Rate %' => ['success_rate', '%.2f%%'],
            'Block Rate %' => ['block_rate', '%.2f%%'],
            'Concurrency Block %' => ['concurrency_block_rate', '%.2f%%'],
            'Rate Limit Block %' => ['rate_limit_block_rate', '%.2f%%'],
            'Error Rate %' => ['error_rate', '%.2f%%'],
            'Duration (s)' => ['duration', '%.2f'],
            'Latency Avg (ms)' => ['latency_avg', '%.3f'],
            'Latency P50 (ms)' => ['latency_p50', '%.3f'],
            'Latency P95 (ms)' => ['latency_p95', '%.3f'],
            'Latency P99 (ms)' => ['latency_p99', '%.3f'],
            'Latency Max (ms)' => ['latency_max', '%.3f'],
        ];
        
        foreach ($metrics as $label => $config) {
            $key = $config[0];
            $fmt = $config[1];
            
            $values = [$label];
            $algorithmValues = [];
            
            foreach ($algorithms as $alg) {
                $value = $results[$alg][$key];
                $values[] = sprintf($fmt, $value);
                $algorithmValues[] = $value;
            }
            
            // Add difference column if comparing multiple algorithms
            if (count($algorithms) > 1) {
                $diff = $algorithmValues[0] - $algorithmValues[1];
                if (strpos($fmt, '%%') !== false) {
                    $values[] = sprintf('%.2f%%', $diff);
                } elseif (strpos($fmt, '%d') !== false) {
                    $values[] = sprintf('%+d', $diff);
                } else {
                    $values[] = sprintf('%+.2f', $diff);
                }
            }
            
            printf($format, ...$values);
        }
        
        echo str_repeat("-", 80) . "\n";
        
        // Analysis for multiple algorithms
        if (count($algorithms) > 1) {
            echo "Analysis:\n";
            
            // Find best performers in different categories
            $bestRps = ['algorithm' => '', 'value' => 0];
            $bestErrorRate = ['algorithm' => '', 'value' => 100];
            $bestLatency = ['algorithm' => '', 'value' => PHP_FLOAT_MAX];
            $bestSuccessRate = ['algorithm' => '', 'value' => 0];
            
            foreach ($algorithms as $algorithm) {
                $result = $results[$algorithm];
                
                // Best RPS
                if ($result['rps'] > $bestRps['value']) {
                    $bestRps = ['algorithm' => $algorithm, 'value' => $result['rps']];
                }
                
                // Best (lowest) error rate
                if ($result['error_rate'] < $bestErrorRate['value']) {
                    $bestErrorRate = ['algorithm' => $algorithm, 'value' => $result['error_rate']];
                }
                
                // Best (lowest) average latency
                if (isset($result['latency_avg']) && $result['latency_avg'] < $bestLatency['value']) {
                    $bestLatency = ['algorithm' => $algorithm, 'value' => $result['latency_avg']];
                }
                
                // Best success rate
                if ($result['success_rate'] > $bestSuccessRate['value']) {
                    $bestSuccessRate = ['algorithm' => $algorithm, 'value' => $result['success_rate']];
                }
            }
            
            // Report findings
            printf("🏆 Highest Throughput: %s (%.3f RPS)\n", $bestRps['algorithm'], $bestRps['value']);
            
            // Only show error rate comparison if there are meaningful differences
            // Skip only if ALL algorithms have 0% error rate
            $allHaveZeroErrors = true;
            foreach ($algorithms as $algorithm) {
                if ($results[$algorithm]['error_rate'] > 0) {
                    $allHaveZeroErrors = false;
                    break;
                }
            }
            if (!$allHaveZeroErrors) {
                printf("🛡️  Lowest Error Rate: %s (%.3f%%)\n", $bestErrorRate['algorithm'], $bestErrorRate['value']);
            }
            
            // Only show success rate comparison if there are meaningful differences
            // Skip only if ALL algorithms have 100% success rate
            $allHavePerfectSuccess = true;
            foreach ($algorithms as $algorithm) {
                if ($results[$algorithm]['success_rate'] < 100) {
                    $allHavePerfectSuccess = false;
                    break;
                }
            }
            if (!$allHavePerfectSuccess) {
                printf("🎯 Highest Success Rate: %s (%.3f%%)\n", $bestSuccessRate['algorithm'], $bestSuccessRate['value']);
            }

            if ($bestLatency['value'] < PHP_FLOAT_MAX) {
                echo "⚡ Lowest Avg Latency: {$bestLatency['algorithm']} (" . number_format($bestLatency['value'], 3) . " ms)\n";
            }
            
            // Additional insights
            echo "\nInsights:\n";
            
            // Performance comparison
            $rpsRange = max(array_column($results, 'rps')) - min(array_column($results, 'rps'));
            $avgRps = array_sum(array_column($results, 'rps')) / count($results);
            
            if ($rpsRange > $avgRps * 0.5) {
                echo "• Significant performance differences between algorithms (range: " . number_format($rpsRange) . " RPS)\n";
            } else {
                echo "• Similar performance across algorithms (range: " . number_format($rpsRange) . " RPS)\n";
            }
            
            // Error rate analysis
            $errorRates = array_column($results, 'error_rate');
            $maxErrorRate = max($errorRates);
            $minErrorRate = min($errorRates);
            
            if ($maxErrorRate > 1.0 && ($maxErrorRate - $minErrorRate) > 0.5) {
                echo "• Some algorithms struggled with errors - consider reducing load or checking Redis performance\n";
            } elseif ($maxErrorRate < 0.1) {
                echo "• All algorithms handled the load well with minimal errors\n";
            }
            
            // Success rate insights
            $successRates = array_column($results, 'success_rate');
            $avgSuccessRate = array_sum($successRates) / count($successRates);
            
            if ($avgSuccessRate > 80) {
                echo "• High success rates indicate good algorithm behavior under this load\n";
            } elseif ($avgSuccessRate > 50) {
                echo "• Moderate rate limiting - algorithms are working as expected\n";
            } else {
                echo "• Heavy rate limiting - consider if this matches your expected behavior\n";
            }
        }
    }
    
    private function getRedisServerInfo(): string
    {
        try {
            $info = $this->redis->info();
            
            if (!$info) {
                return 'Unknown (INFO command failed)';
            }
            
            // Parse INFO response - it can be a string or array depending on Redis client
            if (is_string($info)) {
                $lines = explode("\n", $info);
                $infoData = [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || $line[0] === '#') {
                        continue;
                    }
                    if (strpos($line, ':') !== false) {
                        [$key, $value] = explode(':', $line, 2);
                        $infoData[trim($key)] = trim($value);
                    }
                }
            } else {
                $infoData = $info;
            }
            
            // Check for various Redis variants
            if (isset($infoData['dragonfly_version'])) {
                return "Dragonfly {$infoData['dragonfly_version']}";
            }
            
            if (isset($infoData['keydb_version'])) {
                return "KeyDB {$infoData['keydb_version']}";
            }
            
            if (isset($infoData['valkey_version'])) {
                return "Valkey {$infoData['valkey_version']}";
            }
            
            // Check for AWS ElastiCache Redis
            if (isset($infoData['redis_version']) && isset($infoData['os']) && 
                strpos($infoData['os'], 'Amazon ElastiCache') !== false) {
                return "AWS ElastiCache Redis {$infoData['redis_version']}";
            }
            
            if (isset($infoData['redis_version'])) {
                $version = $infoData['redis_version'];
                $mode = '';
                
                // Check if it's Redis Cluster, Sentinel, etc.
                if (isset($infoData['redis_mode'])) {
                    $mode = " ({$infoData['redis_mode']} mode)";
                } elseif (isset($infoData['cluster_enabled']) && $infoData['cluster_enabled'] === '1') {
                    $mode = ' (cluster mode)';
                } else {
                    $mode = ' (standalone mode)';
                }
                
                return "Redis {$version}{$mode}";
            }
            
            // Fallback for unknown Redis-compatible servers
            if (isset($infoData['server_version']) || isset($infoData['version'])) {
                $version = $infoData['server_version'] ?? $infoData['version'];
                return "Redis-compatible {$version}";
            }
            
            return 'Redis-compatible server (version unknown)';
            
        } catch (Exception $e) {
            return 'Unknown (connection error: ' . $e->getMessage() . ')';
        }
    }

    private function displayRateLimiterConfig(): void
    {
        // Calculate the effective rate limiter configuration that will be used
        $timeWindow = $this->options['limiter_window'] ?? 60;
        
        if (isset($this->options['limiter_rps']) && $this->options['limiter_rps'] !== null) {
            // Using explicit limiter RPS configuration
            $sustainedRate = (float)$this->options['limiter_rps'];
            
            if (isset($this->options['limiter_burst']) && $this->options['limiter_burst'] !== null) {
                $burstCapacity = (int)$this->options['limiter_burst'];
                echo "Rate Limiter Config: {$sustainedRate} req/sec sustained, {$burstCapacity} burst capacity, {$timeWindow}s window\n";
            } else {
                $burstCapacity = max(10, (int)($sustainedRate * 10));
                echo "Rate Limiter Config: {$sustainedRate} req/sec sustained, {$burstCapacity} burst capacity (auto), {$timeWindow}s window\n";
            }
        } else {
            // Show that we're using scenario-based configuration
            echo "Rate Limiter Config: Using scenario-based configuration (varies by test)\n";
            if ($timeWindow !== 60) {
                echo "Rate Limiter Window: {$timeWindow}s (overridden)\n";
            }
        }
        
        // Show test load configuration if specified
        if (isset($this->options['target_rps']) && $this->options['target_rps'] !== null) {
            echo "Test Load Target: {$this->options['target_rps']} req/sec\n";
        }
    }

    private function displayScenarioRateLimiterConfig(array $config): void
    {
        // Show the effective rate limiter configuration for this specific scenario
        $timeWindow = $config['decay'];
        $burstCapacity = $config['max_attempts'];
        
        // Calculate sustained rate from the config
        // For scenarios, we typically have max_attempts over decay seconds
        $sustainedRate = $burstCapacity / $timeWindow;
        
        echo "Rate Limiter Config: {$sustainedRate} req/sec sustained, {$burstCapacity} burst capacity, {$timeWindow}s window\n";
        
        // Show test load info if available
        if (isset($config['target_rps'])) {
            echo "Test Load Target: {$config['target_rps']} req/sec\n";
        }
        echo "\n";
    }

    private function getMaxProcesses(): int
    {
        // Try to determine system limits
        $maxProcs = 50; // Default fallback
        
        if (function_exists('shell_exec')) {
            $ulimit = shell_exec('ulimit -u 2>/dev/null');
            if ($ulimit && is_numeric(trim($ulimit))) {
                $maxProcs = min(100, intval(trim($ulimit)) / 10);
            }
        }
        
        return $maxProcs;
    }

    private function calculateLatencyStatistics(array $latencyCounters): array
    {
        if (empty($latencyCounters)) {
            return [];
        }

        // Convert counter data back to a weighted array for calculations
        $allLatencies = [];
        $totalSum = 0;
        $totalCount = 0;

        foreach ($latencyCounters as $latency => $count) {
            $allLatencies[] = ['latency' => (float)$latency, 'count' => $count];
            $totalSum += (float)$latency * $count;
            $totalCount += $count;
        }

        // Sort by latency value
        usort($allLatencies, fn($a, $b) => $a['latency'] <=> $b['latency']);

        // Calculate basic statistics
        $stats = [
            'latency_avg' => $totalSum / $totalCount,
            'latency_min' => $allLatencies[0]['latency'],
            'latency_max' => $allLatencies[count($allLatencies) - 1]['latency']
        ];

        // Calculate percentiles using cumulative distribution
        $stats['latency_p50'] = $this->calculateWeightedPercentile($allLatencies, $totalCount, 50);
        $stats['latency_p95'] = $this->calculateWeightedPercentile($allLatencies, $totalCount, 95);
        $stats['latency_p99'] = $this->calculateWeightedPercentile($allLatencies, $totalCount, 99);

        return $stats;
    }

    private function calculateWeightedPercentile(array $sortedWeightedValues, int $totalCount, float $percentile): float
    {
        $targetCount = ($percentile / 100) * $totalCount;
        $cumulative = 0;

        foreach ($sortedWeightedValues as $item) {
            $cumulative += $item['count'];
            if ($cumulative >= $targetCount) {
                return $item['latency'];
            }
        }

        // Should never reach here, but return the max value as fallback
        return $sortedWeightedValues[count($sortedWeightedValues) - 1]['latency'];
    }

    private function calculatePercentile(array $sortedValues, float $percentile): float
    {
        $count = count($sortedValues);
        if ($count === 0) {
            return 0.0;
        }
        
        $index = ($percentile / 100) * ($count - 1);
        $lower = floor($index);
        $upper = ceil($index);
        
        if ($lower === $upper) {
            return $sortedValues[$lower];
        }
        
        $weight = $index - $lower;
        return $sortedValues[$lower] * (1 - $weight) + $sortedValues[$upper] * $weight;
    }
}

function showHelp(): void
{
    echo "Rate Limiter Stress Test\n";
    echo "========================\n\n";
    echo "Usage: php stress-test.php [OPTIONS]\n\n";
    echo "Options:\n";
    echo "  --help                 Show this help message\n";
    echo "  --algorithms=ALG       Algorithms to test: sliding,fixed,leaky,gcra,token,concurrency or combinations (default: all)\n";
    echo "  --scenarios=SCENARIO   Test scenarios: high,medium,low,burst,all or custom (default: all)\n";
    echo "  --duration=SECONDS     Duration of each test in seconds (default: 30)\n";
    echo "  --processes=NUM        Number of concurrent processes (default: 20)\n";
    echo "  --target-rps=NUM       Target requests per second (optional)\n";
    echo "  --redis-host=HOST      Redis host (default: 127.0.0.1)\n";
    echo "  --redis-port=PORT      Redis port (default: 6379)\n";
    echo "  --keys=NUM             Custom number of keys for custom scenario\n";
    echo "  --limiter-rps=NUM      Set rate limiter sustained rate (requests/sec) independent of test load\n";
    echo "  --limiter-burst=NUM    Set rate limiter burst capacity (default: 10x sustained rate)\n";
    echo "  --limiter-window=NUM   Set rate limiter window size for window-based algorithms (default: 60 seconds)\n";
    echo "  --concurrency-max=NUM  Set maximum concurrent requests for concurrency-aware algorithm\n";
    echo "  --concurrency-timeout=SEC  Set timeout for concurrency requests in seconds (default: 30)\n";
    echo "  --verbose              Enable verbose output\n";
    echo "  --no-clear             Don't clear Redis between tests\n";
    echo "  --max-speed            Performance mode: send requests as fast as possible (no throttling)\n";
    echo "  --latency-precision=N  Number of decimal places for latency rounding (default: 2)\n";
    echo "  --latency-sample=N     Sample rate for latency collection - collect every Nth measurement (default: 1 = all measurements)\n\n";
    echo "Examples:\n";
    echo "  php stress-test.php --help\n";
    echo "  php stress-test.php --algorithms=sliding,gcra,token --duration=10\n";
    echo "  php stress-test.php --scenarios=high,medium --processes=10\n";
    echo "  php stress-test.php --keys=100 --limiter-rps=50 --limiter-burst=25 --limiter-window=30\n";
    echo "  php stress-test.php --limiter-window=10 --algorithms=sliding,token --scenarios=high\n";
    echo "  php stress-test.php --target-rps=200 --limiter-rps=100 --limiter-burst=20 --scenarios=custom --keys=1\n";
    echo "  php stress-test.php --scenarios=burst --algorithms=fixed\n";
    echo "  php stress-test.php --max-speed --duration=5 --processes=4\n";
    echo "  php stress-test.php --latency-precision=5 --latency-sample=1 --algorithms=gcra\n";
    echo "  php stress-test.php --latency-sample=100 --max-speed --duration=10\n";
    echo "  php stress-test.php --algorithms=concurrency --concurrency-max=5 --limiter-rps=10 --duration=15\n";
    echo "  php stress-test.php --algorithms=concurrency,sliding --concurrency-max=10 --concurrency-timeout=60 --scenarios=high\n\n";
    echo "Scenarios:\n";
    echo "  high    - High contention (5 keys, 100 req/key)\n";
    echo "  medium  - Medium contention (50 keys, 50 req/key)\n";
    echo "  low     - Low contention (1000 keys, 10 req/key)\n";
    echo "  burst   - Single key burst test (1 key, 100 req/key)\n";
    echo "  all     - Run all predefined scenarios\n";
    echo "  custom  - Use custom parameters (requires --keys)\n\n";
    echo "Algorithms:\n";
    echo "  sliding     - Sliding window algorithm (precise, higher memory)\n";
    echo "  fixed       - Fixed window algorithm (efficient, allows burst)\n";
    echo "  leaky       - Leaky bucket algorithm (allows burst, enforces average rate)\n";
    echo "  gcra        - GCRA algorithm (memory efficient, smooth rate limiting)\n";
    echo "  token       - Token bucket algorithm (allows burst, gradual refill)\n";
    echo "  concurrency - Concurrency-aware sliding window (prevents request pileup)\n\n";
}

function parseArguments(): array
{
    $options = [];
    $args = $_SERVER['argv'];
    
    for ($i = 1; $i < count($args); $i++) {
        $arg = $args[$i];
        
        if ($arg === '--help' || $arg === '-h') {
            showHelp();
            exit(0);
        }
        
        if (strpos($arg, '=') !== false) {
            [$key, $value] = explode('=', $arg, 2);
            $key = ltrim($key, '-');
            
            switch ($key) {
                case 'algorithms':
                    $options['algorithms'] = explode(',', $value);
                    break;
                case 'scenarios':
                    $options['scenarios'] = explode(',', $value);
                    break;
                case 'duration':
                    $options['duration'] = (int)$value;
                    break;
                case 'processes':
                    $options['processes'] = (int)$value;
                    break;
                case 'target-rps':
                    $options['target_rps'] = (int)$value;
                    break;
                case 'redis-host':
                    $options['redis_host'] = $value;
                    break;
                case 'redis-port':
                    $options['redis_port'] = (int)$value;
                    break;
                case 'keys':
                    $options['custom_keys'] = (int)$value;
                    $options['scenarios'] = ['custom'];
                    break;
                case 'limiter-window':
                    $options['limiter_window'] = (int)$value;
                    break;
                case 'limiter-rps':
                    $options['limiter_rps'] = (float)$value;
                    break;
                case 'limiter-burst':
                    $options['limiter_burst'] = (int)$value;
                    break;
                case 'latency-precision':
                    $options['latency_precision'] = max(0, min(10, (int)$value)); // Clamp between 0-10
                    break;
                case 'latency-sample':
                    $options['latency_sample'] = max(1, (int)$value); // Minimum 1 (collect all)
                    break;
                case 'concurrency-max':
                    $options['concurrency_max'] = (int)$value;
                    $options['use_concurrency'] = true;
                    break;
                case 'concurrency-timeout':
                    $options['concurrency_timeout'] = (int)$value;
                    break;
            }
        } elseif ($arg === '--verbose') {
            $options['verbose'] = true;
        } elseif ($arg === '--no-clear') {
            $options['no_clear'] = true;
        } elseif ($arg === '--max-speed') {
            $options['max_speed'] = true;
        }
    }
    
    // Validate algorithms
    if (isset($options['algorithms'])) {
        $validAlgorithms = ['sliding', 'fixed', 'leaky', 'gcra', 'token', 'concurrency'];
        $options['algorithms'] = array_intersect($options['algorithms'], $validAlgorithms);
        if (empty($options['algorithms'])) {
            die("ERROR: Invalid algorithms. Valid options: sliding, fixed, leaky, gcra, token, concurrency\n");
        }
    }
    
    // Validate scenarios
    if (isset($options['scenarios'])) {
        $validScenarios = ['high', 'medium', 'low', 'burst', 'all', 'custom'];
        $options['scenarios'] = array_intersect($options['scenarios'], $validScenarios);
        if (empty($options['scenarios'])) {
            die("ERROR: Invalid scenarios. Valid options: high, medium, low, burst, all, custom\n");
        }
    }
    
    return $options;
}

// Check if required extensions are available
if (!extension_loaded('pcntl')) {
    die("ERROR: pcntl extension is required for multi-process testing\n");
}

if (!extension_loaded('redis') && !class_exists('Credis_Client')) {
    die("ERROR: Redis extension or Credis library is required\n");
}

// Parse command line arguments
$options = parseArguments();

// Verify Redis connection
try {
    $redis = new Credis_Client($options['redis_host'] ?? '127.0.0.1', $options['redis_port'] ?? 6379);
    $redis->ping();
    echo "✓ Redis connection established\n\n";
} catch (Exception $e) {
    die("ERROR: Could not connect to Redis: " . $e->getMessage() . "\n");
}

// Run the stress test
$runner = new StressTestRunner($options);
$runner->run();
