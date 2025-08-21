<?php
/**
 * GitHub Webhook Handler
 * Receives GitHub webhook events and logs them
 */

// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/logs/webhook_errors.log');

// Set response header
header('Content-Type: application/json');

try {
    // Get headers - handle both Apache and nginx
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        // Fallback for nginx
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headerName = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($name, 5)))));
                $headers[$headerName] = $value;
            }
        }
    }
    
    // Get GitHub specific headers
    $event = isset($headers['X-GitHub-Event']) ? $headers['X-GitHub-Event'] : 
             (isset($_SERVER['HTTP_X_GITHUB_EVENT']) ? $_SERVER['HTTP_X_GITHUB_EVENT'] : '');
    $signature256 = isset($headers['X-Hub-Signature-256']) ? $headers['X-Hub-Signature-256'] : 
                    (isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '');
    $signature = isset($headers['X-Hub-Signature']) ? $headers['X-Hub-Signature'] : 
                 (isset($_SERVER['HTTP_X_HUB_SIGNATURE']) ? $_SERVER['HTTP_X_HUB_SIGNATURE'] : '');
    $delivery = isset($headers['X-GitHub-Delivery']) ? $headers['X-GitHub-Delivery'] : 
                (isset($_SERVER['HTTP_X_GITHUB_DELIVERY']) ? $_SERVER['HTTP_X_GITHUB_DELIVERY'] : '');
    
    // Get raw POST data
    $rawPayload = file_get_contents('php://input');
    
    // Webhook secret (configured in GitHub webhook settings)
    $webhookSecret = 'test123';
    
    // Verify signature - GitHub may send either SHA1 or SHA256
    if ($webhookSecret && ($signature256 || $signature)) {
        $verified = false;
        
        // Try SHA256 first (preferred)
        if ($signature256) {
            $expectedSignature256 = 'sha256=' . hash_hmac('sha256', $rawPayload, $webhookSecret);
            if (hash_equals($expectedSignature256, $signature256)) {
                $verified = true;
            }
        }
        
        // Fall back to SHA1 if SHA256 didn't verify
        if (!$verified && $signature) {
            $expectedSignature = 'sha1=' . hash_hmac('sha1', $rawPayload, $webhookSecret);
            if (hash_equals($expectedSignature, $signature)) {
                $verified = true;
            }
        }
        
        if (!$verified) {
            error_log("Signature verification failed. Event: $event, Delivery: $delivery");
            http_response_code(401);
            echo json_encode(['error' => 'Invalid signature']);
            exit;
        }
    }

    // Parse JSON payload
    $payload = json_decode($rawPayload, true);
    
    // Check if JSON parsing was successful
    if ($rawPayload && json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON parsing failed: " . json_last_error_msg() . " for payload: " . substr($rawPayload, 0, 1000));
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload: ' . json_last_error_msg()]);
        exit;
    }
    
    // Create log directory if it doesn't exist
    $logDir = dirname(__FILE__) . '/logs';
    if (!is_dir($logDir)) {
        if (!mkdir($logDir, 0777, true)) {
            error_log("Failed to create log directory: $logDir");
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create log directory']);
            exit;
        }
    }

// Prepare log data
$logData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'event' => $event,
    'delivery_id' => $delivery,
    'headers' => [
        'X-GitHub-Event' => $event,
        'X-GitHub-Delivery' => $delivery,
        'X-Hub-Signature-256' => $signature256,
        'X-Hub-Signature' => $signature,
        'User-Agent' => isset($headers['User-Agent']) ? $headers['User-Agent'] : ''
    ],
    'payload' => $payload
];

// Log file name with date
$logFile = $logDir . '/webhook_' . date('Y-m-d') . '.log';

// Write to log file
$logEntry = date('Y-m-d H:i:s') . ' | ' . $event . ' | ' . $delivery . PHP_EOL;
$logEntry .= '----------------------------------------' . PHP_EOL;
$logEntry .= json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
$logEntry .= '========================================' . PHP_EOL . PHP_EOL;

if (file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to write log']);
    exit;
}

// Handle specific events
$response = ['status' => 'received'];

switch ($event) {
    case 'push':
        // Handle push event
        $response['message'] = 'Push event received';
        if (isset($payload['ref'])) {
            $response['branch'] = str_replace('refs/heads/', '', $payload['ref']);
        }
        if (isset($payload['commits'])) {
            $response['commits_count'] = count($payload['commits']);
            
            // Analyze commits with Claude if available
            $aiSummary = analyzePushWithClaude($payload);
            if ($aiSummary) {
                $response['ai_summary'] = $aiSummary;
                $response['analysis_status'] = 'completed';
                
                // Note: Detailed analysis is saved in the analysis directory
                // Log a simple reference in the main log
                $aiLogFile = $logDir . '/ai_summaries_' . date('Y-m-d') . '.log';
                $aiLogEntry = date('Y-m-d H:i:s') . ' | ' . $delivery . PHP_EOL;
                $aiLogEntry .= 'Repository: ' . $payload['repository']['full_name'] . PHP_EOL;
                $aiLogEntry .= 'Branch: ' . $response['branch'] . PHP_EOL;
                $aiLogEntry .= 'Commits: ' . $response['commits_count'] . PHP_EOL;
                $aiLogEntry .= 'Analysis: Detailed analysis saved in /analysis/ directory' . PHP_EOL;
                $aiLogEntry .= '----------------------------------------' . PHP_EOL . PHP_EOL;
                file_put_contents($aiLogFile, $aiLogEntry, FILE_APPEND | LOCK_EX);
            } else {
                $response['analysis_status'] = 'fallback';
            }
        }
        break;
        
    case 'pull_request':
        // Handle pull request event
        $response['message'] = 'Pull request event received';
        if (isset($payload['action'])) {
            $response['action'] = $payload['action'];
        }
        break;
        
    case 'ping':
        // GitHub sends a ping event when webhook is first set up
        $response['message'] = 'Pong! Webhook is configured correctly';
        break;
        
    default:
        $response['message'] = 'Event received: ' . $event;
        break;
}

    // Optional: Log summary to separate file for quick monitoring
    $summaryFile = $logDir . '/summary.log';
    $summaryEntry = date('Y-m-d H:i:s') . ' | ' . $event . ' | ' . $delivery;
    if (isset($response['branch'])) {
        $summaryEntry .= ' | Branch: ' . $response['branch'];
    }
    if (isset($response['commits_count'])) {
        $summaryEntry .= ' | Commits: ' . $response['commits_count'];
    }
    $summaryEntry .= PHP_EOL;
    file_put_contents($summaryFile, $summaryEntry, FILE_APPEND | LOCK_EX);
    
    // Return success response
    http_response_code(200);
    echo json_encode($response);
    
} catch (Exception $e) {
    // Log the error
    error_log("Webhook handler error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}

/**
 * Analyze push event with Claude
 * @param array $payload GitHub push event payload
 * @return string|null AI summary or null if failed
 */
function analyzePushWithClaude($payload) {
    try {
        // Get repository info and branch
        $branch = str_replace('refs/heads/', '', $payload['ref'] ?? 'main');
        $repoName = $payload['repository']['name'] ?? 'repo';
        $beforeCommit = $payload['before'] ?? '';
        $afterCommit = $payload['after'] ?? '';
        
        // Prepare commit information for Claude
        $commitInfo = [];
        if (isset($payload['commits']) && is_array($payload['commits'])) {
            foreach ($payload['commits'] as $commit) {
                $commitInfo[] = [
                    'id' => $commit['id'] ?? '',
                    'message' => $commit['message'] ?? '',
                    'author' => $commit['author']['name'] ?? 'Unknown',
                    'timestamp' => $commit['timestamp'] ?? '',
                    'added' => count($commit['added'] ?? []),
                    'modified' => count($commit['modified'] ?? []),
                    'removed' => count($commit['removed'] ?? []),
                    'files' => array_merge(
                        $commit['added'] ?? [],
                        $commit['modified'] ?? [],
                        $commit['removed'] ?? []
                    )
                ];
            }
        }
        
        // System prompt for Claude
        $systemPrompt = "You are a Git commit analyzer. Analyze the provided Git repository changes and provide a detailed summary in Korean. You have access to a git repository located at ./source directory.";
        
        // Prepare the prompt for Claude
        $prompt = "다음 Git Push 이벤트의 변경사항을 분석하고 한국어로 요약해주세요.\n\n";
        $prompt .= "=== 기본 정보 ===\n";
        $prompt .= "저장소: " . ($payload['repository']['full_name'] ?? 'Unknown') . "\n";
        $prompt .= "브랜치: " . $branch . "\n";
        $prompt .= "작성자: " . ($payload['pusher']['name'] ?? 'Unknown') . "\n";
        $prompt .= "커밋 범위: " . substr($beforeCommit, 0, 7) . " → " . substr($afterCommit, 0, 7) . "\n\n";
        
        $prompt .= "=== 커밋 내역 ===\n";
        foreach ($commitInfo as $idx => $commit) {
            $prompt .= ($idx + 1) . ". [" . substr($commit['id'], 0, 7) . "] " . $commit['message'] . "\n";
            $prompt .= "   작성자: " . $commit['author'] . "\n";
            $prompt .= "   변경: 추가 " . $commit['added'] . "개, 수정 " . $commit['modified'] . "개, 삭제 " . $commit['removed'] . "개 파일\n";
            if (!empty($commit['files'])) {
                $prompt .= "   파일: " . implode(', ', array_slice($commit['files'], 0, 5));
                if (count($commit['files']) > 5) {
                    $prompt .= " 외 " . (count($commit['files']) - 5) . "개";
                }
                $prompt .= "\n";
            }
        }
        
        $prompt .= "\n=== Git 분석 작업 ===\n";
        $prompt .= "source 폴더로 이동하여 다음 git 명령을 실행하고 분석해주세요:\n";
        $prompt .= "1. cd ./source\n";
        $prompt .= "2. git diff " . substr($beforeCommit, 0, 7) . ".." . substr($afterCommit, 0, 7) . "\n";
        $prompt .= "3. git log --oneline " . substr($beforeCommit, 0, 7) . ".." . substr($afterCommit, 0, 7) . "\n";
        $prompt .= "4. 변경된 파일들의 주요 코드 변경사항을 분석해주세요.\n";
        
        $prompt .= "\n=== 요약 형식 ===\n";
        $prompt .= "다음 형식으로 요약해주세요:\n";
        $prompt .= "📌 **주요 변경사항**: (1-2줄로 핵심 변경 내용)\n";
        $prompt .= "📁 **영향받는 모듈**: (주요 파일/디렉토리)\n";
        $prompt .= "🎯 **변경 목적**: (커밋 메시지와 코드 변경 기반 추측)\n";
        $prompt .= "🔍 **코드 리뷰 포인트**: (주의해서 봐야 할 부분)\n";
        $prompt .= "\n전체 1000 줄 이내로 자세하게 작성해주세요.";
        
        // Escape the system prompt for shell command
        $escapedSystemPrompt = str_replace("\n", "\\n", addslashes($systemPrompt));
        
        // Build Claude CLI command with heredoc
        $command = "claude -p --permission-mode bypassPermissions --input-format text --output-format text --append-system-prompt \"$escapedSystemPrompt\" << 'QUERY_PROMPT'\n";
        $command .= $prompt . "\n";
        $command .= "QUERY_PROMPT";
        
        // Log the command being executed
        error_log("Executing Claude command: " . $command);
        
        $output = [];
        $returnCode = 0;
        exec($command . " 2>&1", $output, $returnCode);
        
        // Log the return code and output status
        error_log("Claude command return code: " . $returnCode);
        error_log("Claude output lines: " . count($output));
        
        if ($returnCode === 0 && !empty($output)) {
            $analysisResult = implode("\n", $output);
            
            // Save Claude analysis result to file
            $analysisDir = dirname(__FILE__) . '/analysis';
            if (!is_dir($analysisDir)) {
                mkdir($analysisDir, 0777, true);
            }
            
            // Create filename with timestamp and commit range
            $timestamp = date('Y-m-d_H-i-s');
            $shortBefore = substr($beforeCommit, 0, 7);
            $shortAfter = substr($afterCommit, 0, 7);
            $repoName = str_replace('/', '_', $payload['repository']['full_name'] ?? 'unknown');
            $filename = "{$timestamp}_{$repoName}_{$shortBefore}_to_{$shortAfter}.md";
            $analysisFile = $analysisDir . '/' . $filename;
            
            // Prepare content with metadata
            $fileContent = "# Git Push Analysis Report\n\n";
            $fileContent .= "**Generated**: " . date('Y-m-d H:i:s') . "\n";
            $fileContent .= "**Repository**: " . ($payload['repository']['full_name'] ?? 'Unknown') . "\n";
            $fileContent .= "**Branch**: {$branch}\n";
            $fileContent .= "**Pusher**: " . ($payload['pusher']['name'] ?? 'Unknown') . "\n";
            $fileContent .= "**Commit Range**: {$shortBefore} → {$shortAfter}\n";
            $fileContent .= "**Total Commits**: " . count($commitInfo) . "\n\n";
            $fileContent .= "---\n\n";
            $fileContent .= "## Commit Details\n\n";
            
            foreach ($commitInfo as $idx => $commit) {
                $fileContent .= "### " . ($idx + 1) . ". " . $commit['message'] . "\n";
                $fileContent .= "- **ID**: " . substr($commit['id'], 0, 7) . "\n";
                $fileContent .= "- **Author**: " . $commit['author'] . "\n";
                $fileContent .= "- **Changes**: Added " . $commit['added'] . ", Modified " . $commit['modified'] . ", Removed " . $commit['removed'] . " files\n";
                if (!empty($commit['files'])) {
                    $fileContent .= "- **Files**: " . implode(', ', $commit['files']) . "\n";
                }
                $fileContent .= "\n";
            }
            
            $fileContent .= "---\n\n";
            $fileContent .= "## Claude AI Analysis\n\n";
            $fileContent .= $analysisResult . "\n\n";
            $fileContent .= "---\n\n";
            $fileContent .= "*Analysis performed by Claude AI*\n";
            
            // Write to file
            if (file_put_contents($analysisFile, $fileContent)) {
                error_log("Claude analysis saved to: " . $analysisFile);
                
                // Also update the summary file with analysis file reference
                $summaryFile = dirname(__FILE__) . '/analysis/summary.txt';
                $summaryEntry = date('Y-m-d H:i:s') . " | " . $repoName . " | " . $branch . " | ";
                $summaryEntry .= $shortBefore . " → " . $shortAfter . " | ";
                $summaryEntry .= "File: " . $filename . "\n";
                file_put_contents($summaryFile, $summaryEntry, FILE_APPEND | LOCK_EX);
            } else {
                error_log("Failed to save Claude analysis to file: " . $analysisFile);
            }
            
            return $analysisResult;
        }
        
        // Fallback to simple summary if Claude is not available
        return generateSimpleSummary($payload, $commitInfo);
        
    } catch (Exception $e) {
        error_log("Claude analysis failed: " . $e->getMessage());
        return generateSimpleSummary($payload, $commitInfo ?? []);
    }
}


/**
 * Generate a simple summary without AI
 * @param array $payload GitHub push event payload
 * @param array $commitInfo Processed commit information
 * @return string Simple summary
 */
function generateSimpleSummary($payload, $commitInfo) {
    $summary = "📝 커밋 요약\n";
    $summary .= "저장소: " . ($payload['repository']['full_name'] ?? 'Unknown') . "\n";
    $summary .= "브랜치: " . str_replace('refs/heads/', '', $payload['ref'] ?? '') . "\n";
    $summary .= "커밋 수: " . count($commitInfo) . "개\n";
    
    if (!empty($commitInfo)) {
        $totalAdded = 0;
        $totalModified = 0;
        $totalRemoved = 0;
        $messages = [];
        
        foreach ($commitInfo as $commit) {
            $totalAdded += $commit['added'];
            $totalModified += $commit['modified'];
            $totalRemoved += $commit['removed'];
            $messages[] = "- " . $commit['message'];
        }
        
        $summary .= "변경 통계: 추가 {$totalAdded}개, 수정 {$totalModified}개, 삭제 {$totalRemoved}개 파일\n";
        $summary .= "커밋 메시지:\n" . implode("\n", array_slice($messages, 0, 3));
        
        if (count($messages) > 3) {
            $summary .= "\n... 외 " . (count($messages) - 3) . "개 커밋";
        }
    }
    
    return $summary;
}