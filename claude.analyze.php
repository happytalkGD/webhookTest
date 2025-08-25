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
                    // Check if it's a merge commit skip (not an actual error)
                    if (strpos($result['error'], 'Merge commit') !== false) {
                        echo "  → Merge commit detected - skipping analysis\n";
                        // Move to processed directory since it was intentionally skipped
                        moveToProcessed($file, $processedDir);
                    } else {
                        echo "  ✗ Analysis failed: " . $result['error'] . "\n";
                    }
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
    
    // Check if this is a merge commit
    $isMergeCommit = false;
    $mergeCommitInfo = null;
    
    if (isset($payload['commits']) && is_array($payload['commits'])) {
        foreach ($payload['commits'] as $commit) {
            // First check if commit has parents info (not always in webhook)
            if (isset($commit['parents']) && is_array($commit['parents']) && count($commit['parents']) > 1) {
                $isMergeCommit = true;
                $mergeCommitInfo = $commit;
                echo "  → Detected merge commit (multiple parents)\n";
                break;
            }
            
            // Fallback to message-based detection
            $message = $commit['message'] ?? '';
            // Check for common merge commit patterns
            if (stripos($message, 'Merge pull request') !== false || 
                stripos($message, 'Merge branch') !== false ||
                stripos($message, 'Merge remote-tracking branch') !== false ||
                preg_match('/^Merge [a-f0-9]{7,40} into [a-f0-9]{7,40}/', $message) || // Git default merge message
                preg_match('/^Merge commit \'[a-f0-9]{7,40}\'/', $message)) { // Another Git pattern
                $isMergeCommit = true;
                $mergeCommitInfo = $commit;
                echo "  → Detected merge commit (message pattern)\n";
                break;
            }
        }
    }
    
    // Handle merge commits - check if it had conflicts
    if ($isMergeCommit) {
        // Check if this merge had conflicts by looking at the commit message
        $hasConflict = false;
        $message = $mergeCommitInfo['message'] ?? '';
        
        // Common conflict resolution indicators in commit messages
        if (stripos($message, 'conflict') !== false ||
            stripos($message, 'resolve') !== false ||
            stripos($message, 'fixed merge') !== false) {
            $hasConflict = true;
            echo "  → Merge commit appears to have had conflicts\n";
        }
        
        // If no conflict indicators found, skip the merge commit
        if (!$hasConflict) {
            echo "  → Skipping merge commit (no conflicts detected)\n";
            return [
                'success' => false,
                'error' => 'Merge commit without conflicts - skipping analysis'
            ];
        }
        
        echo "  → Analyzing merge commit with potential conflicts\n";
        // Continue to analyze merge commits that had conflicts
    }
    
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
    $systemPrompt = "You are a Git commit analyzer. Analyze the provided Git repository changes and provide a detailed summary in Korean. You can fetch and analyze GitHub repository changes using the compare URL provided.";
    
    // Build the prompt
    $prompt = "다음 Git Push 이벤트의 변경사항을 분석하고 한국어로 요약해주세요.\n\n";
    
    // Add special note if this is a merge commit with conflicts
    if ($isMergeCommit && isset($hasConflict) && $hasConflict) {
        $prompt .= "⚠️ **주의: 이것은 충돌이 있었던 병합 커밋입니다**\n";
        $prompt .= "충돌 해결 부분에 특별히 주목하여 분석해주세요.\n\n";
    }
    
    $prompt .= "=== 기본 정보 ===\n";
    $prompt .= "저장소: " . ($payload['repository']['full_name'] ?? 'Unknown') . "\n";
    $prompt .= "브랜치: " . $branch . "\n";
    $prompt .= "작성자: " . ($payload['pusher']['name'] ?? 'Unknown') . "\n";
    $prompt .= "커밋 범위: " . substr($beforeCommit, 0, 7) . " → " . substr($afterCommit, 0, 7) . "\n\n";
    
    // Limit commit details to prevent prompt overflow
    $maxCommits = 10; // Maximum number of commits to show in detail
    $totalCommits = count($commitInfo);
    
    $prompt .= "=== 커밋 내역 ===\n";
    if ($totalCommits > $maxCommits) {
        $prompt .= "총 {$totalCommits}개 커밋 중 최근 {$maxCommits}개만 표시\n\n";
        $commitInfo = array_slice($commitInfo, 0, $maxCommits);
    }
    
    foreach ($commitInfo as $idx => $commit) {
        // Truncate long commit messages
        $message = $commit['message'];
        if (strlen($message) > 200) {
            $message = substr($message, 0, 197) . '...';
        }
        
        $prompt .= ($idx + 1) . ". [" . substr($commit['id'], 0, 7) . "] " . $message . "\n";
        $prompt .= "   작성자: " . $commit['author'] . "\n";
        $prompt .= "   변경: 추가 " . $commit['added'] . "개, 수정 " . $commit['modified'] . "개, 삭제 " . $commit['removed'] . "개 파일\n";
        
        // Limit file list to prevent prompt overflow
        if (!empty($commit['files'])) {
            $fileCount = count($commit['files']);
            if ($fileCount > 10) {
                // For many files, just show summary
                $prompt .= "   파일: 총 {$fileCount}개 파일 변경됨\n";
            } else {
                $prompt .= "   파일: " . implode(', ', array_slice($commit['files'], 0, 5));
                if ($fileCount > 5) {
                    $prompt .= " 외 " . ($fileCount - 5) . "개";
                }
                $prompt .= "\n";
            }
        }
    }
    
    // Extract compare URL from webhook payload
    $compareUrl = $payload['repository']['compare_url'] ?? '';
    if (!empty($compareUrl) && !empty($beforeCommit) && !empty($afterCommit)) {
        // Build actual compare URL
        $actualCompareUrl = str_replace(
            ['{base}', '{head}'],
            [$beforeCommit, $afterCommit],
            $compareUrl
        );
        
        $prompt .= "\n=== Git 분석 작업 ===\n";
        $prompt .= "다음 GitHub Compare API URL을 사용하여 변경사항을 분석해주세요:\n";
        $prompt .= "Compare URL: " . $actualCompareUrl . "\n";
        $prompt .= "\n이 URL을 통해 두 커밋 간의 차이점을 직접 확인하고 분석해주세요.\n";
        $prompt .= "URL에서 다음 정보를 확인할 수 있습니다:\n";
        $prompt .= "- 변경된 파일 목록 (files)\n";
        $prompt .= "- 각 파일의 patch (코드 diff)\n";
        $prompt .= "- 추가/삭제된 줄 수 (additions/deletions)\n";
        $prompt .= "- 파일 상태 (added/modified/removed)\n";
    } else {
        $prompt .= "\n=== Git 분석 작업 ===\n";
        $prompt .= "source 폴더로 이동하여 git 으로 변경된 내용을 분석 해주세요:\n";
        $prompt .= "source >  cd ./source/{$repoName}\n";
    }
    $prompt .= "\n=== 요약 형식 ===\n";
    $prompt .= "다음 형식으로 요약해주세요:\n";
    $prompt .= "📌 **주요 변경사항**: (1-2줄로 핵심 변경 내용)\n";
    $prompt .= "📁 **영향받는 모듈**: (주요 파일/디렉토리)\n";
    $prompt .= "🎯 **변경 목적**: (커밋 메시지와 코드 변경 기반 추측)\n";
    
    // Add conflict resolution analysis section for merge commits with conflicts
    if ($isMergeCommit && isset($hasConflict) && $hasConflict) {
        $prompt .= "⚠️ **충돌 해결 내역**: (충돌이 발생했던 파일과 해결 방법)\n";
        $prompt .= "\n충돌 분석 시 다음 사항을 확인해주세요:\n";
        $prompt .= "- 어떤 파일에서 충돌이 발생했는지\n";
        $prompt .= "- 충돌이 어떻게 해결되었는지 (어느 브랜치의 코드를 선택했는지)\n";
        $prompt .= "- 충돌 해결 과정에서 추가/수정된 코드가 있는지\n";
    }
    
    $prompt .= "\n문서 길이는 전체 100 Line 이내로 간결하게 작성해주세요.\n";
    $prompt .= "\n작업된 내용만 작성하고 제안 사항을 적는것은 금지 합니다.\n";
    $prompt .= "\n중요: GitHub Compare API를 직접 호출하여 실제 코드 변경사항을 확인한 후 분석해주세요.";
    
    // Check prompt size to prevent Claude errors
    $promptLength = strlen($prompt);
    if ($promptLength > 10000) { // If prompt is too long (over 10KB)
        echo "  ⚠️ Prompt too long ({$promptLength} bytes), trimming...\n";
        
        // Simplify the prompt for large commits
        $prompt = "다음 Git Push 이벤트의 변경사항을 분석하고 한국어로 요약해주세요.\n\n";
        $prompt .= "=== 기본 정보 ===\n";
        $prompt .= "저장소: " . ($payload['repository']['full_name'] ?? 'Unknown') . "\n";
        $prompt .= "브랜치: " . $branch . "\n";
        $prompt .= "커밋 수: " . $totalCommits . "개\n";
        $prompt .= "커밋 범위: " . substr($beforeCommit, 0, 7) . " → " . substr($afterCommit, 0, 7) . "\n\n";
        
        if (!empty($compareUrl) && !empty($beforeCommit) && !empty($afterCommit)) {
            $actualCompareUrl = str_replace(
                ['{base}', '{head}'],
                [$beforeCommit, $afterCommit],
                $compareUrl
            );
            $prompt .= "GitHub Compare URL: " . $actualCompareUrl . "\n\n";
            $prompt .= "위 URL을 사용하여 변경사항을 직접 확인하고 다음 형식으로 요약해주세요:\n";
        }
        
        $prompt .= "📌 **주요 변경사항**: (핵심 변경 내용)\n";
        $prompt .= "📁 **영향받는 모듈**: (주요 파일/디렉토리)\n";
        $prompt .= "🎯 **변경 목적**: (추측)\n";
        $prompt .= "\n50줄 이내로 매우 간결하게 작성해주세요.";
    }
    
    // Escape the system prompt for shell command
    $escapedSystemPrompt = str_replace("\n", "\\n", addslashes($systemPrompt));
    
    // Build Claude CLI command with heredoc
    $command = "claude -p --permission-mode bypassPermissions --input-format text --output-format text --append-system-prompt \"$escapedSystemPrompt\" << 'QUERY_PROMPT'\n";
    $command .= $prompt . "\n";
    $command .= "QUERY_PROMPT";
    
    // Log the command
    error_log("Executing Claude command for: " . ($payload['repository']['full_name'] ?? 'unknown'));
    error_log("Prompt size: " . strlen($prompt) . " bytes");
    
    // Execute Claude command
    $output = [];
    $returnCode = 0;
    exec($command . " 2>&1", $output, $returnCode);
    
    // Log execution result
    error_log("Claude return code: " . $returnCode . ", Output lines: " . count($output));
    
    if ($returnCode !== 0) {
        // Check if output contains error message
        $outputStr = implode("\n", $output);
        if (stripos($outputStr, 'token limit') !== false || 
            stripos($outputStr, 'context length') !== false ||
            stripos($outputStr, 'too long') !== false) {
            echo "  ⚠️ Claude token limit exceeded, content too long\n";
            return [
                'success' => false,
                'error' => 'Content too long for Claude analysis'
            ];
        }
        
        echo "  ⚠️ Claude analysis failed with code: {$returnCode}\n";
        return [
            'success' => false,
            'error' => 'Claude analysis failed with code: ' . $returnCode
        ];
    }
    
    if (empty($output)) {
        return [
            'success' => false,
            'error' => 'Claude returned empty result'
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