<?php
/**
 * Jira Integration Hook
 * Reads analysis files and posts comments to Jira issues based on branch names
 */

// Load environment variables from .env file if it exists
$envFile = dirname(__FILE__) . '/.env';
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
        }
    }
}

// Configuration - Get from environment or use defaults
define('JIRA_BASE_URL', getenv('JIRA_BASE_URL') ?: 'https://your-domain.atlassian.net');
define('JIRA_EMAIL', getenv('JIRA_EMAIL') ?: 'your-email@example.com');
define('JIRA_API_TOKEN', getenv('JIRA_API_TOKEN') ?: 'your-api-token');

// Directories
$analysisDir = dirname(__FILE__) . '/analysis';
$processedJiraDir = dirname(__FILE__) . '/processed_jira';
$logsDir = dirname(__FILE__) . '/logs';

// Create necessary directories
foreach ([$processedJiraDir, $logsDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

// Setup error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', $logsDir . '/jira_hook_errors.log');

/**
 * Extract Jira ticket ID from branch name
 * Supports patterns like: PROJ-123, ABC-456-feature, feature/XYZ-789
 */
function extractJiraTicketId($branchName) {
    // Common Jira ticket patterns
    $patterns = [
        '/([A-Z]{2,10}-\d+)/i',  // Standard JIRA format: PROJ-123
        '/^([A-Z]{2,10}-\d+)/',  // At the beginning
        '/\/([A-Z]{2,10}-\d+)/', // After slash (feature/PROJ-123)
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $branchName, $matches)) {
            return strtoupper($matches[1]);
        }
    }
    
    return null;
}

/**
 * Parse analysis markdown file
 */
function parseAnalysisFile($filepath) {
    if (!file_exists($filepath)) {
        return null;
    }
    
    $content = file_get_contents($filepath);
    $data = [];
    
    // Extract metadata using regex
    if (preg_match('/\*\*Generated\*\*:\s*(.+)/', $content, $matches)) {
        $data['generated'] = trim($matches[1]);
    }
    
    if (preg_match('/\*\*Repository\*\*:\s*(.+)/', $content, $matches)) {
        $data['repository'] = trim($matches[1]);
        // Clean up escaped characters
        $data['repository'] = str_replace('\\/', '/', $data['repository']);
    }
    
    if (preg_match('/\*\*Branch\*\*:\s*(.+)/', $content, $matches)) {
        $data['branch'] = trim($matches[1]);
    }
    
    if (preg_match('/\*\*Pusher\*\*:\s*(.+)/', $content, $matches)) {
        $data['pusher'] = trim($matches[1]);
    }
    
    if (preg_match('/\*\*Commits\*\*:\s*(.+)/', $content, $matches)) {
        $data['commits'] = trim($matches[1]);
    }
    
    // Extract commit messages from Commit Details section
    if (preg_match('/## Commit Details\s*\n(.+?)(?=\n---|\n## |$)/s', $content, $matches)) {
        $commitSection = $matches[1];
        // Find all commit messages (after ### number. message pattern)
        if (preg_match_all('/###\s*\d+\.\s*(.+)/', $commitSection, $commitMatches)) {
            $data['commit_messages'] = $commitMatches[1];
        }
    }
    
    // Extract analysis sections
    if (preg_match('/📌 \*\*주요 변경사항\*\*:\s*(.+?)(?=\n\n|📁)/s', $content, $matches)) {
        $data['main_changes'] = trim($matches[1]);
    }
    
    if (preg_match('/📁 \*\*영향받는 모듈\*\*:\s*(.+?)(?=\n\n|🎯)/s', $content, $matches)) {
        $data['affected_modules'] = trim($matches[1]);
    }
    
    if (preg_match('/🎯 \*\*변경 목적\*\*:\s*(.+?)(?=\n\n|🔍)/s', $content, $matches)) {
        $data['purpose'] = trim($matches[1]);
    }
    
    if (preg_match('/🔍 \*\*코드 리뷰 포인트\*\*:\s*(.+?)(?=\n---|\n\n---)/s', $content, $matches)) {
        $data['review_points'] = trim($matches[1]);
    }
    
    return $data;
}

/**
 * Create Jira comment content from analysis data
 */
function createJiraComment($analysisData) {
    $comment = "*🔄 GitHub Push Analysis Report*\n\n";
    
    $comment .= "Repository: {$analysisData['repository']}\n";
    $comment .= "Branch: {$analysisData['branch']}\n";
    $comment .= "Pusher: {$analysisData['pusher']}\n";
    $comment .= "Commits: {$analysisData['commits']}\n";
    $comment .= "Generated: {$analysisData['generated']}\n\n";
    
    $comment .= "----\n\n";
    
    if (isset($analysisData['main_changes'])) {
        $comment .= "*📌 주요 변경사항*\n";
        $comment .= "{$analysisData['main_changes']}\n\n";
    }
    
    if (isset($analysisData['affected_modules'])) {
        $comment .= "*📁 영향받는 모듈*\n";
        $comment .= "{$analysisData['affected_modules']}\n\n";
    }
    
    if (isset($analysisData['purpose'])) {
        $comment .= "*🎯 변경 목적*\n";
        $comment .= "{$analysisData['purpose']}\n\n";
    }
    
    if (isset($analysisData['review_points'])) {
        $comment .= "*🔍 코드 리뷰 포인트*\n";
        $comment .= "{$analysisData['review_points']}\n\n";
    }
    
    $comment .= "----\n";
    $comment .= "_This comment was automatically generated from GitHub push analysis_";
    
    return $comment;
}

/**
 * Send comment to Jira using REST API
 */
function sendJiraComment($ticketId, $comment) {
    $url = JIRA_BASE_URL . "/rest/api/3/issue/{$ticketId}/comment";
    
    // Use simple format for better compatibility
    $data = [
        'body' => $comment
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode(JIRA_EMAIL . ':' . JIRA_API_TOKEN),
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("CURL Error for ticket {$ticketId}: " . $error);
        return ['success' => false, 'error' => $error];
    }
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'response' => json_decode($response, true)];
    } else {
        error_log("Jira API Error for ticket {$ticketId}: HTTP {$httpCode} - " . $response);
        return ['success' => false, 'error' => "HTTP {$httpCode}", 'response' => $response];
    }
}

/**
 * Process a single analysis file
 */
function processAnalysisFile($filepath) {
    $filename = basename($filepath);
    echo "Processing: {$filename}\n";
    
    // Parse the analysis file
    $analysisData = parseAnalysisFile($filepath);
    
    if (!$analysisData || !isset($analysisData['branch'])) {
        echo "  ⚠️  Could not parse analysis file or branch not found\n";
        return false;
    }
    
    echo "  Branch: {$analysisData['branch']}\n";
    
    // Try to extract Jira ticket ID from commit messages first
    $ticketId = null;
    
    if (isset($analysisData['commit_messages']) && is_array($analysisData['commit_messages'])) {
        echo "  Checking commit messages for Jira ticket ID...\n";
        foreach ($analysisData['commit_messages'] as $commitMessage) {
            $ticketId = extractJiraTicketId($commitMessage);
            if ($ticketId) {
                echo "  Found in commit: \"{$commitMessage}\"\n";
                break;
            }
        }
    }
    
    // If not found in commits, try branch name
    if (!$ticketId) {
        echo "  Checking branch name for Jira ticket ID...\n";
        $ticketId = extractJiraTicketId($analysisData['branch']);
        if ($ticketId) {
            echo "  Found in branch: {$analysisData['branch']}\n";
        }
    }
    
    if (!$ticketId) {
        echo "  ℹ️  No Jira ticket ID found in commit messages or branch name\n";
        return false;
    }
    
    echo "  Jira Ticket: {$ticketId}\n";
    
    // Check if Jira credentials are configured
    if (JIRA_BASE_URL === 'https://your-domain.atlassian.net' || 
        JIRA_EMAIL === 'your-email@example.com' || 
        JIRA_API_TOKEN === 'your-api-token') {
        echo "  ⚠️  Jira credentials not configured. Please update the configuration.\n";
        
        // Save what would be sent for testing
        global $processedJiraDir;
        $testFile = $processedJiraDir . '/test_' . $ticketId . '_' . date('Y-m-d_H-i-s') . '.txt';
        $comment = createJiraComment($analysisData);
        file_put_contents($testFile, "Ticket: {$ticketId}\n\n" . $comment);
        echo "  💾 Test comment saved to: " . basename($testFile) . "\n";
        
        return false;
    }
    
    // Create Jira comment
    $comment = createJiraComment($analysisData);
    
    // Send to Jira
    echo "  Sending comment to Jira...\n";
    $result = sendJiraComment($ticketId, $comment);
    
    if ($result['success']) {
        echo "  ✅ Comment posted successfully to {$ticketId}\n";
        
        // Move processed file
        global $processedJiraDir, $logsDir;
        $processedFile = $processedJiraDir . '/' . $filename;
        if (rename($filepath, $processedFile)) {
            echo "  📁 Moved to processed directory\n";
        }
        
        // Log success
        $logEntry = date('Y-m-d H:i:s') . " | SUCCESS | {$ticketId} | {$filename}\n";
        file_put_contents($logsDir . '/jira_success.log', $logEntry, FILE_APPEND);
        
        return true;
    } else {
        echo "  ❌ Failed to post comment: {$result['error']}\n";
        
        // Log failure
        global $logsDir;
        $logEntry = date('Y-m-d H:i:s') . " | FAILED | {$ticketId} | {$filename} | {$result['error']}\n";
        file_put_contents($logsDir . '/jira_failures.log', $logEntry, FILE_APPEND);
        
        return false;
    }
}

/**
 * Main processing function
 */
function processAnalysisFiles() {
    global $analysisDir;
    
    // Get all markdown files in analysis directory
    $files = glob($analysisDir . '/*.md');
    
    if (empty($files)) {
        echo "No analysis files to process.\n";
        return;
    }
    
    echo "Found " . count($files) . " analysis file(s)\n\n";
    
    $processed = 0;
    $failed = 0;
    
    foreach ($files as $file) {
        if (processAnalysisFile($file)) {
            $processed++;
        } else {
            $failed++;
        }
        echo "\n";
    }
    
    echo "=== Summary ===\n";
    echo "Processed: {$processed}\n";
    echo "Failed/Skipped: {$failed}\n";
}

// Check if script is run from command line
if (php_sapi_name() === 'cli') {
    echo "=== Jira Integration Hook ===\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Check configuration
    if (JIRA_BASE_URL === 'https://your-domain.atlassian.net') {
        echo "⚠️  WARNING: Jira configuration not set!\n";
        echo "Please update the following constants in this file:\n";
        echo "  - JIRA_BASE_URL\n";
        echo "  - JIRA_EMAIL\n";
        echo "  - JIRA_API_TOKEN\n\n";
        echo "Continuing in test mode...\n\n";
    }
    
    processAnalysisFiles();
    
    echo "\nFinished at: " . date('Y-m-d H:i:s') . "\n";
} else {
    // If accessed via web, return JSON response
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'This script must be run from command line',
        'usage' => 'php jira.hook.php'
    ]);
}