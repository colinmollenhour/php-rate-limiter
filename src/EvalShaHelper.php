<?php

namespace Cm\RateLimiter;

use Credis_Client;

trait EvalShaHelper
{
    /**
     * Execute a Lua script using EVALSHA with fallback to SCRIPT LOAD + EVALSHA
     * 
     * @param Credis_Client $redis Redis client instance
     * @param string $script The Lua script content
     * @param string $sha1 The SHA1 hash of the script
     * @param array $keys Redis keys for the script
     * @param array $args Arguments for the script
     * @return mixed The result of the script execution
     */
    protected function evalSha(Credis_Client $redis, string $script, string $sha1, array $keys = [], array $args = []): mixed
    {
        try {
            // Try EVALSHA first (optimistic approach)
            $result = $redis->evalSha($sha1, $keys, $args);
            
            // If EVALSHA returns null, it might mean the script isn't cached
            // Some Redis clients return null instead of throwing NOSCRIPT error
            if ($result === null) {
                // Load the script and try again
                $loadedSha = $redis->script('LOAD', $script);
                
                // Verify the SHA1 matches what we expect
                if ($loadedSha !== $sha1) {
                    throw new \RuntimeException("Script SHA1 mismatch. Expected: {$sha1}, Got: {$loadedSha}");
                }
                
                // Now execute with EVALSHA
                $result = $redis->evalSha($sha1, $keys, $args);
            }
            
            return $result;
        } catch (\Exception $e) {
            // Only handle NOSCRIPT errors - script not cached on Redis server
            // All other errors should be rethrown as they indicate real problems
            if (!$this->isNoScriptError($e)) {
                throw $e;
            }
            
            // Script not cached, load it and try again
            $loadedSha = $redis->script('LOAD', $script);
            
            // Verify the SHA1 matches what we expect
            if ($loadedSha !== $sha1) {
                throw new \RuntimeException("Script SHA1 mismatch. Expected: {$sha1}, Got: {$loadedSha}");
            }
            
            // Now execute with EVALSHA
            $result = $redis->evalSha($sha1, $keys, $args);
            return $result;
        }
    }
    
    /**
     * Check if the exception is a NOSCRIPT error from Redis
     *
     * @param \Exception $e The exception to check
     * @return bool True if this is a NOSCRIPT error
     */
    private function isNoScriptError(\Exception $e): bool
    {
        $message = $e->getMessage();
        
        // Redis returns "NOSCRIPT No matching script. Please use EVAL." for missing scripts
        return strpos($message, 'NOSCRIPT') !== false ||
               strpos($message, 'No matching script') !== false;
    }
}