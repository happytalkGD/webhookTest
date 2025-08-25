<?php
/**
 * Common Library for Webhook Processing System
 * Shared functions and utilities used across multiple scripts
 */

/**
 * Load environment variables from .env file
 * @param string $envFile Path to .env file
 * @return array Loaded environment variables
 */
function loadEnvFile($envFile = null) {
    if ($envFile === null) {
        $envFile = dirname(__FILE__) . '/.env';
    }
    
    $loadedVars = [];
    
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Parse KEY=VALUE
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present
                $value = trim($value, '"\'');
                
                // Set as environment variable
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $loadedVars[$key] = $value;
            }
        }
    }
    
    return $loadedVars;
}

/**
 * Setup error logging
 * @param string $logFile Path to error log file
 */
function setupErrorLogging($logFile = null) {
    if ($logFile === null) {
        $logFile = dirname(__FILE__) . '/logs/errors.log';
    }
    
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', $logFile);
}

/**
 * Create directory if it doesn't exist
 * @param string|array $dirs Directory path(s) to create
 * @param int $permissions Directory permissions
 * @return bool Success status
 */
function ensureDirectoryExists($dirs, $permissions = 0777) {
    if (!is_array($dirs)) {
        $dirs = [$dirs];
    }
    
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            if (!mkdir($dir, $permissions, true)) {
                error_log("Failed to create directory: $dir");
                return false;
            }
        }
    }
    
    return true;
}

/**
 * Lock file management for preventing duplicate script runs
 */
class LockManager {
    private $lockFile;
    private $maxAge;
    private $scriptName;
    
    /**
     * @param string $scriptName Name of the script (used for lock file name)
     * @param int $maxAge Maximum age in seconds before lock is considered stale (default 5 minutes)
     */
    public function __construct($scriptName, $maxAge = 300) {
        $this->scriptName = $scriptName;
        $this->maxAge = $maxAge;
        $this->lockFile = dirname(__FILE__) . '/locks/' . $scriptName . '.lock';
        
        // Ensure lock directory exists
        $lockDir = dirname($this->lockFile);
        ensureDirectoryExists($lockDir);
    }
    
    /**
     * Acquire lock for script execution
     * @return bool True if lock acquired, false if another instance is running
     */
    public function acquireLock() {
        // Check if lock file exists and if it's stale
        if (file_exists($this->lockFile)) {
            $lockAge = time() - filemtime($this->lockFile);
            
            if ($lockAge < $this->maxAge) {
                echo "⚠️  Another instance is already running (lock age: {$lockAge} seconds)\n";
                echo "Lock file: {$this->lockFile}\n";
                echo "If you're sure no other instance is running, delete the lock file and try again.\n";
                return false;
            } else {
                echo "⚠️  Stale lock file found (age: {$lockAge} seconds), removing it...\n";
                unlink($this->lockFile);
            }
        }
        
        // Create lock file
        if (!touch($this->lockFile)) {
            echo "❌ Failed to create lock file\n";
            return false;
        }
        
        // Register shutdown function to remove lock file
        register_shutdown_function([$this, 'releaseLock']);
        
        // Handle signals for clean shutdown if available
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'signalHandler']);
            pcntl_signal(SIGTERM, [$this, 'signalHandler']);
        }
        
        return true;
    }
    
    /**
     * Release the lock
     */
    public function releaseLock() {
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
            echo "Lock file removed.\n";
        }
    }
    
    /**
     * Signal handler for clean shutdown
     */
    public function signalHandler($signal) {
        $this->releaseLock();
        exit(0);
    }
}

/**
 * Extract Jira ticket ID from text
 * @param string $text Text to search for Jira ticket ID
 * @return string|null Jira ticket ID or null if not found
 */
function extractJiraTicketId($text) {
    // Common Jira ticket patterns
    $patterns = [
        '/\[([A-Z]+[0-9]+-\d+)\]/i',     // [P03-45] format with brackets
        '/\[([A-Z]+-\d+)\]/i',            // [PROJ-123] format with brackets  
        '/([A-Z]+[0-9]+-\d+)/i',          // P03-45, ABC1-234 mixed format
        '/([A-Z]{1,10}-\d+)/i',           // Standard JIRA format: PROJ-123
        '/^([A-Z]{1,10}-\d+)/',           // At the beginning
        '/\/([A-Z]{1,10}-\d+)/',          // After slash (feature/PROJ-123)
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            return strtoupper($matches[1]);
        }
    }
    
    return null;
}

/**
 * Get configuration with fallback to default
 * @param string $key Configuration key
 * @param mixed $default Default value if not found
 * @return mixed Configuration value
 */
function getConfig($key, $default = null) {
    $value = getenv($key);
    return $value !== false ? $value : $default;
}

/**
 * Log entry to file with timestamp
 * @param string $logFile Log file path
 * @param string $message Message to log
 * @param string $level Log level (INFO, ERROR, WARNING, SUCCESS)
 */
function logToFile($logFile, $message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "{$timestamp} | {$level} | {$message}\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Format file size in human readable format
 * @param int $bytes File size in bytes
 * @return string Formatted file size
 */
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * Safe JSON encode with error handling
 * @param mixed $data Data to encode
 * @param int $options JSON encode options
 * @return string|false JSON string or false on error
 */
function safeJsonEncode($data, $options = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) {
    $json = json_encode($data, $options);
    
    if ($json === false) {
        error_log("JSON encoding failed: " . json_last_error_msg());
        return false;
    }
    
    return $json;
}

/**
 * Safe JSON decode with error handling
 * @param string $json JSON string to decode
 * @param bool $assoc Return associative array
 * @return mixed|null Decoded data or null on error
 */
function safeJsonDecode($json, $assoc = true) {
    $data = json_decode($json, $assoc);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decoding failed: " . json_last_error_msg());
        return null;
    }
    
    return $data;
}

/**
 * Display progress message with formatting
 * @param string $message Message to display
 * @param string $type Type of message (info, success, error, warning)
 * @param int $indent Indentation level
 */
function displayMessage($message, $type = 'info', $indent = 0) {
    $prefix = str_repeat('  ', $indent);
    
    switch ($type) {
        case 'success':
            echo "{$prefix}✅ {$message}\n";
            break;
        case 'error':
            echo "{$prefix}❌ {$message}\n";
            break;
        case 'warning':
            echo "{$prefix}⚠️  {$message}\n";
            break;
        case 'process':
            echo "{$prefix}🔄 {$message}\n";
            break;
        case 'info':
        default:
            echo "{$prefix}ℹ️  {$message}\n";
            break;
    }
}

/**
 * Check if script is running from CLI
 * @return bool True if running from CLI
 */
function isCliMode() {
    return php_sapi_name() === 'cli';
}

/**
 * Return JSON response for web access
 * @param array $data Response data
 * @param int $httpCode HTTP response code
 */
function jsonResponse($data, $httpCode = 200) {
    header('Content-Type: application/json');
    http_response_code($httpCode);
    echo safeJsonEncode($data);
    exit;
}

/**
 * Get directory paths configuration
 * @return array Directory paths
 */
function getDirectoryPaths() {
    $baseDir = dirname(__FILE__);
    
    return [
        'base' => $baseDir,
        'logs' => $baseDir . '/logs',
        'locks' => $baseDir . '/locks',
        'pending_webhooks' => $baseDir . '/pending_webhooks',
        'pending_analysis' => $baseDir . '/pending_analysis',
        'processed_webhooks' => $baseDir . '/processed_webhooks',
        'processed_jira' => $baseDir . '/processed_jira',
        'error_analysis' => $baseDir . '/error_analysis',
    ];
}

/**
 * Initialize common environment
 * @param string $scriptName Name of the script
 * @return array Configuration and paths
 */
function initializeEnvironment($scriptName) {
    // Load environment variables
    loadEnvFile();
    
    // Setup error logging
    $dirs = getDirectoryPaths();
    ensureDirectoryExists($dirs['logs']);
    setupErrorLogging($dirs['logs'] . '/' . $scriptName . '_errors.log');
    
    // Ensure all necessary directories exist
    ensureDirectoryExists([
        $dirs['locks'],
        $dirs['pending_webhooks'],
        $dirs['pending_analysis'],
        $dirs['processed_webhooks'],
        $dirs['processed_jira'],
        $dirs['error_analysis']
    ]);
    
    return $dirs;
}

/**
 * Move file to processed directory
 * @param string $sourceFile Source file path
 * @param string $processedDir Processed directory path
 * @return bool Success status
 */
function moveToProcessed($sourceFile, $processedDir) {
    $filename = basename($sourceFile);
    $processedFile = $processedDir . '/' . $filename;
    
    if (rename($sourceFile, $processedFile)) {
        displayMessage("Moved to processed directory", 'success', 1);
        return true;
    } else {
        // If move fails, try to delete the original file
        if (unlink($sourceFile)) {
            displayMessage("Original file deleted", 'info', 1);
            return true;
        }
        displayMessage("Failed to move or delete file", 'error', 1);
        return false;
    }
}

// Export constants for configuration
define('DEFAULT_LOCK_TIMEOUT', 300); // 5 minutes
define('DEFAULT_PERMISSIONS', 0777);