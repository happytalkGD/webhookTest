<?php
/**
 * Claude Summaries Processor
 * Reads saved webhook data files and analyzes them with Claude
 */

// Include common library
require_once dirname(__FILE__) . '/common.lib.php';

// Initialize environment
$dirs = initializeEnvironment('claude_analyze');

// Configuration
$webhookDataDir = $dirs['pending_webhooks'];
$analysisDir = $dirs['pending_analysis'];
$processedDir = $dirs['processed_webhooks'];

/**
 * Main processing function
 */
function processWebhookFiles() {
    global $webhookDataDir, $processedDir;
    
    // Get all JSON files in webhook_data directory
    $files = glob($webhookDataDir . '/*.json');
    
    if (empty($files)) {
        echo "No webhook files to process.\n";
        return;
    }
    
    echo "Found " . count($files) . " webhook file(s) to process.\n\n";
    
    foreach ($files as $file) {
        echo "Processing: " . basename($file) . "\n";
        
        try {
            // Read and decode JSON file
            $jsonContent = file_get_contents($file);
            $webhookData = safeJsonDecode($jsonContent);
            
            if ($webhookData === null) {
                throw new Exception("Invalid JSON in file");
            }
            
            // Process based on event type
            if ($webhookData['event'] === 'push' && isset($webhookData['payload'])) {
                $result = analyzePushEvent($webhookData);
                
                if ($result['success']) {
                    echo "  ✓ Analysis completed successfully\n";
                    echo "  → Saved to: " . $result['file'] . "\n";
                    
                    // Move processed file to processed directory
                    moveToProcessed($file, $processedDir);
                } else {
                    echo "  ✗ Analysis failed: " . $result['error'] . "\n";
                }
            } else {
                echo "  → Skipping non-push event or invalid data\n";
            }
            
        } catch (Exception $e) {
            echo "  ✗ Error: " . $e->getMessage() . "\n";
            error_log("Error processing file {$file}: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    echo "Processing complete.\n";
}

/**
 * Analyze push event with Claude
 */
function analyzePushEvent($webhookData) {
    global $analysisDir;
    
    $payload = $webhookData['payload'];
    
    // Extract repository information
    $branch = str_replace('refs/heads/', '', $payload['ref'] ?? 'main');
    $repoName = $payload['repository']['name'] ?? 'repo';
    $beforeCommit = $payload['before'] ?? '';
    $afterCommit = $payload['after'] ?? '';
    
    // Prepare commit information
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
    
    // Build the prompt
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
    $prompt .= "1. cd ./source/{$repoName}\n";
    $prompt .= "2. git diff " . substr($beforeCommit, 0, 7) . ".." . substr($afterCommit, 0, 7) . "\n";
    $prompt .= "3. git log --oneline " . substr($beforeCommit, 0, 7) . ".." . substr($afterCommit, 0, 7) . "\n";
    $prompt .= "4. 변경된 파일들의 주요 코드 변경사항을 분석해주세요.\n";
    
    $prompt .= "\n=== 요약 형식 ===\n";
    $prompt .= "다음 형식으로 요약해주세요:\n";
    $prompt .= "📌 **주요 변경사항**: (1-2줄로 핵심 변경 내용)\n";
    $prompt .= "📁 **영향받는 모듈**: (주요 파일/디렉토리)\n";
    $prompt .= "🎯 **변경 목적**: (커밋 메시지와 코드 변경 기반 추측)\n";
    $prompt .= "\n전체 300 줄 이내로 자세하게 작성해주세요.\n";
    $prompt .= "\n작업된 내용 작성하고 추가 사항을 적는것은 금지 합니다.";
    
    // Escape the system prompt for shell command
    $escapedSystemPrompt = str_replace("\n", "\\n", addslashes($systemPrompt));
    
    // Build Claude CLI command with heredoc
    $command = "claude -p --permission-mode bypassPermissions --input-format text --output-format text --append-system-prompt \"$escapedSystemPrompt\" << 'QUERY_PROMPT'\n";
    $command .= $prompt . "\n";
    $command .= "QUERY_PROMPT";
    
    // Log the command
    error_log("Executing Claude command for: " . ($payload['repository']['full_name'] ?? 'unknown'));
    
    // Execute Claude command
    $output = [];
    $returnCode = 0;
    exec($command . " 2>&1", $output, $returnCode);
    
    // Log execution result
    error_log("Claude return code: " . $returnCode . ", Output lines: " . count($output));
    
    if ($returnCode !== 0 || empty($output)) {
        return [
            'success' => false,
            'error' => 'Claude analysis failed or returned empty result'
        ];
    }
    
    $analysisResult = implode("\n", $output);
    
    // Create analysis report file
    $timestamp = date('Y-m-d_H-i-s');
    $shortBefore = substr($beforeCommit, 0, 7);
    $shortAfter = substr($afterCommit, 0, 7);
    $repoNameClean = str_replace('/', '_', $payload['repository']['full_name'] ?? 'unknown');
    $filename = "{$timestamp}_{$repoNameClean}_{$shortBefore}_to_{$shortAfter}.md";
    $analysisFile = $analysisDir . '/' . $filename;
    
    // Prepare file content with metadata
    $fileContent = "# Git Push Analysis Report\n\n";
    $fileContent .= "**Generated**: " . date('Y-m-d H:i:s') . "\n";
    $fileContent .= "**Repository**: " . ($payload['repository']['full_name'] ?? 'Unknown') . "\n";
    $fileContent .= "**Branch**: {$branch}\n";
    $fileContent .= "**Pusher**: " . ($payload['pusher']['name'] ?? 'Unknown') . "\n";
    $fileContent .= "**Commit Range**: {$shortBefore} → {$shortAfter}\n";
    $fileContent .= "**Total Commits**: " . count($commitInfo) . "\n";
    $fileContent .= "**Webhook Delivery ID**: " . ($webhookData['delivery_id'] ?? 'N/A') . "\n\n";
    $fileContent .= "---\n\n";
    
    // Add commit details
    $fileContent .= "## Commit Details\n\n";
    foreach ($commitInfo as $idx => $commit) {
        $fileContent .= "### " . ($idx + 1) . ". " . $commit['message'] . "\n";
        $fileContent .= "- **Commit Hash**: `" . substr($commit['id'], 0, 7) . "`\n";
        $fileContent .= "- **Author**: " . $commit['author'] . "\n";
        $fileContent .= "- **Timestamp**: " . $commit['timestamp'] . "\n";
        $fileContent .= "- **Changes**: Added " . $commit['added'] . ", Modified " . $commit['modified'] . ", Removed " . $commit['removed'] . " files\n";
        if (!empty($commit['files'])) {
            $fileContent .= "- **Files**:\n";
            foreach ($commit['files'] as $file) {
                $fileContent .= "  - " . $file . "\n";
            }
        }
        $fileContent .= "\n";
    }
    
    // Add Claude analysis
    $fileContent .= "---\n\n";
    $fileContent .= "## Claude AI Analysis\n\n";
    $fileContent .= $analysisResult . "\n\n";
    $fileContent .= "---\n\n";
    $fileContent .= "*Analysis performed by Claude AI at " . date('Y-m-d H:i:s') . "*\n";
    
    // Save analysis file
    if (!file_put_contents($analysisFile, $fileContent)) {
        return [
            'success' => false,
            'error' => 'Failed to save analysis file'
        ];
    }
    
    // Update summary index
    $summaryFile = $analysisDir . '/index.txt';
    $summaryEntry = date('Y-m-d H:i:s') . " | ";
    $summaryEntry .= $repoNameClean . " | ";
    $summaryEntry .= $branch . " | ";
    $summaryEntry .= $shortBefore . " → " . $shortAfter . " | ";
    $summaryEntry .= "File: " . $filename . "\n";
    file_put_contents($summaryFile, $summaryEntry, FILE_APPEND | LOCK_EX);
    
    return [
        'success' => true,
        'file' => $filename
    ];
}

// Check if script is run from command line
if (isCliMode()) {
    echo "=== Claude Summaries Processor ===\n";
    echo "Starting at: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Create lock manager
    $lockManager = new LockManager('claude_analyze');
    
    // Try to acquire lock
    if (!$lockManager->acquireLock()) {
        exit(0);
    }
    
    processWebhookFiles();
    
    echo "\nFinished at: " . date('Y-m-d H:i:s') . "\n";
} else {
    // If accessed via web, return JSON response
    jsonResponse([
        'error' => 'This script must be run from command line',
        'usage' => 'php claude.analyze.php'
    ], 403);
}