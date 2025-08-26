<?php
/**
 * Jira Integration Hook
 * Reads analysis files and posts comments to Jira issues based on branch names
 */

// Check for required PHP extensions
if (!extension_loaded('curl')) {
    echo "ERROR: PHP curl extension is not installed.\n";
    echo "To install on Ubuntu/Debian: sudo apt-get install php-curl\n";
    echo "To install on CentOS/RHEL: sudo yum install php-curl\n";
    echo "After installation, restart your web server or PHP service.\n\n";
    echo "Alternative: Use the jira_integration.sh script instead.\n";
    exit(1);
}

// Include common library
require_once dirname(__FILE__) . '/common.lib.php';

// Initialize environment
$dirs = initializeEnvironment('jira_hook');

// Configuration - Get from environment or use defaults
define('JIRA_BASE_URL', getConfig('JIRA_BASE_URL', 'https://your-domain.atlassian.net'));
define('JIRA_EMAIL', getConfig('JIRA_EMAIL', 'your-email@example.com'));
define('JIRA_API_TOKEN', getConfig('JIRA_API_TOKEN', 'your-api-token'));

// Directories
$analysisDir = $dirs['pending_analysis'];
$processedJiraDir = $dirs['processed_jira'];
$logsDir = $dirs['logs'];

// extractJiraTicketId function is now in common.lib.php

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
    // Extract all analysis content after "## Claude AI Analysis" or "## Analysis Results"
    if (preg_match('/##\s*(Claude AI Analysis|Analysis Results)\s*\n(.+?)(?=\n---|\Z)/s', $content, $matches)) {
        $data['full_analysis'] = trim($matches[2]);
        
        // Also extract individual sections for backward compatibility
        if (preg_match('/📌\s*\*\*주요 변경사항\*\*:\s*(.+?)(?=\n\n|📁|$)/s', $data['full_analysis'], $subMatches)) {
            $data['main_changes'] = trim($subMatches[1]);
        }
        
        if (preg_match('/📁\s*\*\*영향받는 모듈\*\*:\s*(.+?)(?=\n\n|🎯|$)/s', $data['full_analysis'], $subMatches)) {
            $data['affected_modules'] = trim($subMatches[1]);
        }
        
        if (preg_match('/🎯\s*\*\*변경 목적\*\*:\s*(.+?)(?=\n\n|🔍|$)/s', $data['full_analysis'], $subMatches)) {
            $data['purpose'] = trim($subMatches[1]);
        }
        
        if (preg_match('/🔍\s*\*\*코드 리뷰 포인트\*\*:\s*(.+?)$/s', $data['full_analysis'], $subMatches)) {
            $data['review_points'] = trim($subMatches[1]);
        }
    }
    
    return $data;
}

/**
 * Convert Markdown to Jira markup
 */
function markdownToJira($text) {
    // Headers - Process 5-level headers first, then 4-level, treating numbered h4 as bold items
    // Convert ##### text to h5. text (5-level headers)
    $text = preg_replace('/^#####\s+(.+)$/m', 'h5. $1', $text);
    // Convert #### 1. **text** to *1. text* (bold numbered item)
    $text = preg_replace('/^####\s+(\d+\.)\s+\*\*(.+?)\*\*$/m', '*$1 $2*', $text);
    $text = preg_replace('/^####\s+(.+)$/m', 'h4. $1', $text);
    $text = preg_replace('/^###\s+(.+)$/m', 'h3. $1', $text);
    $text = preg_replace('/^##\s+(.+)$/m', 'h2. $1', $text);
    $text = preg_replace('/^#\s+(.+)$/m', 'h1. $1', $text);
    
    // Bold - **text** or __text__ to *text*
    $text = preg_replace('/\*\*(.+?)\*\*/', '*$1*', $text);
    $text = preg_replace('/__(.+?)__/', '*$1*', $text);
    
    // Italic - *text* or _text_ to _text_
    // Be careful not to affect already converted bold
    $text = preg_replace('/(?<!\*)\*(?!\*)([^*]+?)(?<!\*)\*(?!\*)/', '_$1_', $text);
    $text = preg_replace('/(?<!_)_(?!_)([^_]+?)(?<!_)_(?!_)/', '_$1_', $text);
    
    // Code blocks - ```code``` to {code}code{code}
    $text = preg_replace('/```([^`]+?)```/s', '{code}$1{code}', $text);
    
    // Inline code - `code` to {{code}}
    $text = preg_replace('/`([^`]+?)`/', '{{$1}}', $text);
    
    // Clean up excessive line breaks first
    $text = preg_replace('/\n{3,}/', "\n\n", $text);  // Replace 3+ newlines with 2
    
    // Lists with emoji
    $text = preg_replace('/^- 📌 (.+)$/m', '* (!) $1', $text);  // Important
    $text = preg_replace('/^- 📁 (.+)$/m', '* (i) $1', $text);  // Info
    $text = preg_replace('/^- 🎯 (.+)$/m', '* (/) $1', $text);  // Check
    $text = preg_replace('/^- 🔍 (.+)$/m', '* (?) $1', $text);  // Question
    
    // Regular lists - * or - to *
    $text = preg_replace('/^[\*\-]\s+(.+)$/m', '* $1', $text);
    
    // Remove standalone list markers FIRST before converting numbered lists
    $text = preg_replace('/^\d+\.\s*$/m', '', $text);  // Remove "1." etc on its own
    $text = preg_replace('/^[a-z]\.\s*$/m', '', $text);  // Remove "a." etc on its own
    $text = preg_replace('/^[ivx]+\.\s*$/m', '', $text);  // Remove "i.", "ii.", etc on its own
    
    // Now convert numbered lists - only with content
    $text = preg_replace('/^\d+\.\s+([^\s].+)$/m', '# $1', $text);
    
    // Links - [text](url) to [text|url]
    $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '[$1|$2]', $text);
    
    // Horizontal rules - --- to ----
    $text = preg_replace('/^---+$/m', '----', $text);
    
    // Blockquotes - > text to {quote}text{quote}
    $lines = explode("\n", $text);
    $inQuote = false;
    $result = [];
    
    foreach ($lines as $line) {
        if (strpos($line, '> ') === 0) {
            if (!$inQuote) {
                $result[] = '{quote}';
                $inQuote = true;
            }
            $result[] = substr($line, 2);
        } else {
            if ($inQuote) {
                $result[] = '{quote}';
                $inQuote = false;
            }
            $result[] = $line;
        }
    }
    if ($inQuote) {
        $result[] = '{quote}';
    }
    
    $text = implode("\n", $result);
    
    // Tables (simple conversion)
    $text = preg_replace('/\|(.+)\|/', '||$1||', $text);
    
    // Emojis in headers to Jira icons
    $text = str_replace('📌', '(!)', $text);
    $text = str_replace('📁', '(i)', $text);
    $text = str_replace('🎯', '(/)', $text);
    $text = str_replace('🔍', '(?)', $text);
    $text = str_replace('📊', '(*)', $text);
    $text = str_replace('🔄', '(on)', $text);
    $text = str_replace('✅', '(/)', $text);
    $text = str_replace('❌', '(x)', $text);
    $text = str_replace('⚠️', '(!)', $text);
    
    // Final cleanup - remove empty lines created by removing standalone list markers
    $text = preg_replace('/\n{3,}/', "\n\n", $text);  // Replace 3+ newlines with 2
    $text = preg_replace('/\n\s*\n/', "\n\n", $text); // Clean up lines with only whitespace
    
    return $text;
}

/**
 * Create Jira comment content from analysis data
 */
function createJiraComment($analysisData) {
    // Build comment in Markdown first
    $markdown = "{$analysisData['repository']}:{$analysisData['branch']} / {$analysisData['pusher']} {$analysisData['generated']}\n\n";
    $markdown .= "---\n\n";
    
    // Use full analysis if available
    if (isset($analysisData['full_analysis']) && !empty($analysisData['full_analysis'])) {
        $markdown .= $analysisData['full_analysis'] . "\n\n";
    } else {
        // Fallback to individual sections
        if (isset($analysisData['main_changes'])) {
            $markdown .= "## 📌 주요 변경사항\n";
            $markdown .= "{$analysisData['main_changes']}\n\n";
        }
        
        if (isset($analysisData['affected_modules'])) {
            $markdown .= "## 📁 영향받는 모듈\n";
            $markdown .= "{$analysisData['affected_modules']}\n\n";
        }
        
        if (isset($analysisData['purpose'])) {
            $markdown .= "## 🎯 변경 목적\n";
            $markdown .= "{$analysisData['purpose']}\n\n";
        }
        
        if (isset($analysisData['review_points'])) {
            $markdown .= "## 🔍 코드 리뷰 포인트\n";
            $markdown .= "{$analysisData['review_points']}\n\n";
        }
    }
    
    // Convert Markdown to Jira format
    return markdownToJira($markdown);
}

/**
 * Get Jira issue details
 */
function getJiraIssue($ticketId) {
    $url = JIRA_BASE_URL . "/rest/api/2/issue/{$ticketId}?fields=description,summary";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode(JIRA_EMAIL . ':' . JIRA_API_TOKEN),
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error || $httpCode !== 200) {
        return null;
    }
    
    return json_decode($response, true);
}

/**
 * Update Jira issue description
 */
function updateJiraDescription($ticketId, $description) {
    $url = JIRA_BASE_URL . "/rest/api/2/issue/{$ticketId}";
    
    echo "  Updating issue description...\n";
    
    $data = [
        'fields' => [
            'description' => $description
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode(JIRA_EMAIL . ':' . JIRA_API_TOKEN),
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo "  ❌ CURL Error: {$error}\n";
        return false;
    }
    
    if ($httpCode === 204 || $httpCode === 200) {
        echo "  ✅ Description updated successfully\n";
        return true;
    } else {
        echo "  ❌ Failed to update description (HTTP {$httpCode})\n";
        return false;
    }
}

/**
 * Send comment to Jira using REST API
 */
function sendJiraComment($ticketId, $comment) {
    // Try API v2 first (more compatible)
    $url = JIRA_BASE_URL . "/rest/api/2/issue/{$ticketId}/comment";
    
    // Log the API call details
    echo "  API URL: {$url}\n";
    echo "  Using credentials: " . JIRA_EMAIL . "\n";
    echo "  API Token: " . (strlen(JIRA_API_TOKEN) > 10 ? substr(JIRA_API_TOKEN, 0, 4) . '...' . substr(JIRA_API_TOKEN, -4) : '***') . "\n";
    
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_VERBOSE, false); // Set to true for more debug info
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $curlInfo = curl_getinfo($ch);
    curl_close($ch);
    
    echo "  HTTP Response Code: {$httpCode}\n";
    echo "  Response Time: " . round($curlInfo['total_time'], 2) . "s\n";
    
    if ($error) {
        echo "  ❌ CURL Error: {$error}\n";
        error_log("CURL Error for ticket {$ticketId}: " . $error);
        return ['success' => false, 'error' => $error];
    }
    
    if ($httpCode >= 200 && $httpCode < 300) {
        echo "  ✅ Success! Comment posted to Jira\n";
        $responseData = json_decode($response, true);
        if (isset($responseData['id'])) {
            echo "  Comment ID: " . $responseData['id'] . "\n";
        }
        return ['success' => true, 'response' => $responseData, 'http_code' => $httpCode];
    } else {
        echo "  ❌ Failed with HTTP {$httpCode}\n";
        
        // Parse error response
        $errorData = json_decode($response, true);
        if ($errorData) {
            if (isset($errorData['errorMessages'])) {
                echo "  Error: " . implode(', ', $errorData['errorMessages']) . "\n";
            }
            if (isset($errorData['errors'])) {
                foreach ($errorData['errors'] as $field => $error) {
                    echo "  Error in {$field}: {$error}\n";
                }
            }
        } else {
            echo "  Raw response: " . substr($response, 0, 200) . "\n";
        }
        
        error_log("Jira API Error for ticket {$ticketId}: HTTP {$httpCode} - " . $response);
        return ['success' => false, 'error' => "HTTP {$httpCode}", 'response' => $response, 'http_code' => $httpCode];
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
    
    // Check for execution errors in the analysis
    // Look for actual Claude execution errors (not just the string in code analysis)
    $fileContent = file_get_contents($filepath);
    // Check for Claude CLI error patterns - more specific than just "Execution error"
    $isClaudeError = false;
    if (preg_match('/^Execution error:.*$/m', $fileContent) ||
        preg_match('/^Error executing Claude command.*$/m', $fileContent) ||
        preg_match('/^Claude return code: [^0].*$/m', $fileContent) ||
        preg_match('/^Claude analysis failed.*$/m', $fileContent)) {
        $isClaudeError = true;
    }
    
    if ($isClaudeError) {
        echo "  ❌ CLAUDE EXECUTION ERROR DETECTED in analysis file!\n";
        echo "  ⚠️  Analysis contains Claude execution errors - skipping Jira posting\n";
        echo "  📝 Please check the analysis file for errors:\n";
        echo "     " . basename($filepath) . "\n";
        
        // Move to error directory instead of processed
        global $logsDir;
        $errorDir = dirname($filepath) . '/../error_analysis';
        if (!is_dir($errorDir)) {
            mkdir($errorDir, 0777, true);
        }
        
        $errorFile = $errorDir . '/' . basename($filepath);
        if (rename($filepath, $errorFile)) {
            echo "  📁 Moved to error directory for review\n";
        }
        
        // Log the error
        $logEntry = date('Y-m-d H:i:s') . " | ERROR | Claude execution error in analysis | " . basename($filepath) . "\n";
        file_put_contents($logsDir . '/jira_errors.log', $logEntry, FILE_APPEND);
        
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
    
    // Create content for Jira
    $content = createJiraComment($analysisData);
    
    // Debug: Show what content will be sent
    echo "  === Content to send ===\n";
    echo "  " . str_replace("\n", "\n  ", substr($content, 0, 500)) . "\n";
    if (strlen($content) > 500) {
        echo "  ... (+" . (strlen($content) - 500) . " more characters)\n";
    }
    echo "  === End of content preview ===\n";
    
    // Check if issue exists and get its details
    echo "  Checking Jira issue details...\n";
    $issue = getJiraIssue($ticketId);
    
    if (!$issue) {
        echo "  ❌ Could not retrieve issue details. The issue might not exist.\n";
        return false;
    }
    
    // Check if description is empty
    $currentDescription = $issue['fields']['description'] ?? '';
    $isDescriptionEmpty = empty(trim($currentDescription));
    
    if ($isDescriptionEmpty) {
        echo "  📝 Description is empty. Updating description instead of adding comment.\n";
        $result = updateJiraDescription($ticketId, $content);
        
        if ($result) {
            echo "  ✅ Description updated successfully for {$ticketId}\n";
            $actionType = 'description_updated';
        } else {
            echo "  ❌ Failed to update description\n";
            return false;
        }
    } else {
        echo "  💬 Description exists. Adding as comment.\n";
        echo "  Current description length: " . strlen($currentDescription) . " characters\n";
        
        // Send as comment
        echo "  Sending comment to Jira...\n";
        $result = sendJiraComment($ticketId, $content);
        
        if ($result['success']) {
            echo "  ✅ Comment posted successfully to {$ticketId}\n";
            $actionType = 'comment_added';
        } else {
            echo "  ❌ Failed to post comment: {$result['error']}\n";
            return false;
        }
    }
    
    // Move processed file
    global $processedJiraDir, $logsDir;
    moveToProcessed($filepath, $processedJiraDir);
    
    // Log success
    $logEntry = date('Y-m-d H:i:s') . " | SUCCESS | {$ticketId} | {$filename} | {$actionType}\n";
    file_put_contents($logsDir . '/jira_success.log', $logEntry, FILE_APPEND);
    
    return true;
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
if (isCliMode()) {
    echo "=== Jira Integration Hook ===\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Create lock manager
    $lockManager = new LockManager('jira_hook');
    
    // Try to acquire lock
    if (!$lockManager->acquireLock()) {
        exit(0);
    }
    
    // Check configuration
    if (JIRA_BASE_URL === 'https://your-domain.atlassian.net') {
        displayMessage("WARNING: Jira configuration not set!", 'warning');
        echo "Please update the following constants in .env file:\n";
        echo "  - JIRA_BASE_URL\n";
        echo "  - JIRA_EMAIL\n";
        echo "  - JIRA_API_TOKEN\n\n";
        echo "Continuing in test mode...\n\n";
    }
    
    processAnalysisFiles();
    
    echo "\nFinished at: " . date('Y-m-d H:i:s') . "\n";
} else {
    // If accessed via web, return JSON response
    jsonResponse([
        'error' => 'This script must be run from command line',
        'usage' => 'php jira.hook.php'
    ], 403);
}