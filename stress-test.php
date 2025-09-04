<?php

require_once 'vendor/autoload.php';

use Cm\RateLimiter\RateLimiterFactory;
use Credis_Client;

class StressTestRunner
{
    private RateLimiterFactory $factory;
    private Credis_Client $redis;
    private array $options;
    
    public function __construct(array $options = [])
    {
        $this->options = array_merge([
            'algorithms' => ['sliding', 'fixed'],
            'scenarios' => ['all'],
            'duration' => 30,
            'processes' => 20,
            'redis_host' => '127.0.0.1',
            'redis_port' => 6379,
            'target_rps' => null,
            'custom_keys' => null,
            'custom_max_attempts' => null,
            'custom_decay' => null,
            'verbose' => false,
            'no_clear' => false
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
        echo "=== Rate Limiter Stress Test ===\n";
        echo "Testing algorithms: " . implode(', ', $this->options['algorithms']) . "\n";
        echo "PHP Version: " . PHP_VERSION . "\n";
        echo "Processes: " . $this->options['processes'] . "\n";
        echo "Duration: " . $this->options['duration'] . "s per test\n";
        echo "Redis: {$this->options['redis_host']}:{$this->options['redis_port']}\n\n";
        
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
                'max_attempts' => $this->options['custom_max_attempts'] ?? 10,
                'decay' => $this->options['custom_decay'] ?? 10,
                'target_rps' => $this->options['target_rps'] ?? 500
            ]];
        }
        
        return [
            [
                'name' => 'High Contention - 5 Keys',
                'key' => 'high',
                'keys' => 5,
                'processes' => $this->options['processes'],
                'duration' => $this->options['duration'],
                'max_attempts' => 100,
                'decay' => 10,
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
            'errors' => 0,
            'total_requests' => 0,
            'duration' => 0,
            'rps' => 0,
            'success_rate' => 0,
            'block_rate' => 0,
            'error_rate' => 0
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
        foreach ($tempFiles as $i => $tempFile) {
            if (file_exists($tempFile)) {
                $data = json_decode(file_get_contents($tempFile), true);
                if ($data) {
                    $results['successful'] += $data['successful'];
                    $results['blocked'] += $data['blocked'];
                    $results['errors'] += $data['errors'];
                    $results['total_requests'] += $data['total_requests'];
                }
                unlink($tempFile);
            }
        }
        
        // Calculate metrics
        if ($results['total_requests'] > 0) {
            $results['rps'] = $results['total_requests'] / $results['duration'];
            $results['success_rate'] = ($results['successful'] / $results['total_requests']) * 100;
            $results['block_rate'] = ($results['blocked'] / $results['total_requests']) * 100;
            $results['error_rate'] = ($results['errors'] / $results['total_requests']) * 100;
        }
        
        return $results;
    }
    
    private function runWorkerProcess(string $algorithm, array $config, int $workerId, string $tempFile): void
    {
        // Create new Redis connection for this process
        $redis = new Credis_Client('127.0.0.1', 6379);
        $factory = new RateLimiterFactory($redis);
        
        $limiter = $algorithm === 'sliding' 
            ? $factory->createSlidingWindow()
            : $factory->createFixedWindow();
        
        $stats = [
            'successful' => 0,
            'blocked' => 0, 
            'errors' => 0,
            'total_requests' => 0
        ];
        
        $endTime = time() + $config['duration'];
        $requestDelay = $config['processes'] > 0 ? (1000000 / $config['target_rps']) * $config['processes'] : 1000;
        
        while (time() < $endTime) {
            // Select random key from available set
            $keyId = rand(1, $config['keys']);
            $key = "test_key_{$keyId}";
            
            try {
                $result = $limiter->attempt($key, $config['max_attempts'], $config['decay']);
                $stats['total_requests']++;
                
                if ($result->successful()) {
                    $stats['successful']++;
                } else {
                    $stats['blocked']++;
                }
                
            } catch (Exception $e) {
                $stats['errors']++;
                $stats['total_requests']++;
            }
            
            // Rate limiting to prevent overwhelming the system
            if ($requestDelay > 0) {
                usleep($requestDelay);
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
        $format = "%-20s |";
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
            'Error Rate %' => ['error_rate', '%.2f%%'],
            'Duration (s)' => ['duration', '%.2f'],
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
            $first = $algorithms[0];
            $second = $algorithms[1];
            
            if ($results[$first]['error_rate'] < $results[$second]['error_rate']) {
                echo "✓ {$first} had fewer errors\n";
            } elseif ($results[$second]['error_rate'] < $results[$first]['error_rate']) {
                echo "✓ {$second} had fewer errors\n";
            } else {
                echo "• Both algorithms had similar error rates\n";
            }
            
            if ($results[$first]['rps'] > $results[$second]['rps']) {
                echo "✓ {$first} achieved higher throughput\n";
            } elseif ($results[$second]['rps'] > $results[$first]['rps']) {
                echo "✓ {$second} achieved higher throughput\n";
            } else {
                echo "• Both algorithms achieved similar throughput\n";
            }
        }
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
}

function showHelp(): void
{
    echo "Rate Limiter Stress Test\n";
    echo "========================\n\n";
    echo "Usage: php stress-test.php [OPTIONS]\n\n";
    echo "Options:\n";
    echo "  --help                 Show this help message\n";
    echo "  --algorithms=ALG       Algorithms to test: sliding,fixed or both (default: sliding,fixed)\n";
    echo "  --scenarios=SCENARIO   Test scenarios: high,medium,low,burst,all or custom (default: all)\n";
    echo "  --duration=SECONDS     Duration of each test in seconds (default: 30)\n";
    echo "  --processes=NUM        Number of concurrent processes (default: 20)\n";
    echo "  --target-rps=NUM       Target requests per second (optional)\n";
    echo "  --redis-host=HOST      Redis host (default: 127.0.0.1)\n";
    echo "  --redis-port=PORT      Redis port (default: 6379)\n";
    echo "  --keys=NUM             Custom number of keys for custom scenario\n";
    echo "  --max-attempts=NUM     Custom max attempts for custom scenario\n";
    echo "  --decay=SECONDS        Custom decay time for custom scenario\n";
    echo "  --verbose              Enable verbose output\n";
    echo "  --no-clear             Don't clear Redis between tests\n\n";
    echo "Examples:\n";
    echo "  php stress-test.php --help\n";
    echo "  php stress-test.php --algorithms=sliding --duration=10\n";
    echo "  php stress-test.php --scenarios=high,medium --processes=10\n";
    echo "  php stress-test.php --keys=100 --max-attempts=50 --decay=30\n";
    echo "  php stress-test.php --scenarios=burst --algorithms=fixed\n\n";
    echo "Scenarios:\n";
    echo "  high    - High contention (5 keys, 100 req/key)\n";
    echo "  medium  - Medium contention (50 keys, 50 req/key)\n";
    echo "  low     - Low contention (1000 keys, 10 req/key)\n";
    echo "  burst   - Single key burst test (1 key, 100 req/key)\n";
    echo "  all     - Run all predefined scenarios\n";
    echo "  custom  - Use custom parameters (requires --keys)\n\n";
    echo "Algorithms:\n";
    echo "  sliding - Sliding window algorithm (precise, higher memory)\n";
    echo "  fixed   - Fixed window algorithm (efficient, allows burst)\n\n";
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
                case 'max-attempts':
                    $options['custom_max_attempts'] = (int)$value;
                    break;
                case 'decay':
                    $options['custom_decay'] = (int)$value;
                    break;
            }
        } elseif ($arg === '--verbose') {
            $options['verbose'] = true;
        } elseif ($arg === '--no-clear') {
            $options['no_clear'] = true;
        }
    }
    
    // Validate algorithms
    if (isset($options['algorithms'])) {
        $validAlgorithms = ['sliding', 'fixed'];
        $options['algorithms'] = array_intersect($options['algorithms'], $validAlgorithms);
        if (empty($options['algorithms'])) {
            die("ERROR: Invalid algorithms. Valid options: sliding, fixed\n");
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