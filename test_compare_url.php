<?php
/**
 * Test script for GitHub Compare URL prompt
 * This script demonstrates how Claude can use the GitHub Compare API
 */

// Include common library
require_once dirname(__FILE__) . '/common.lib.php';

// Sample webhook data with compare_url
$sampleWebhookData = [
    'event' => 'push',
    'delivery_id' => 'test-delivery-id',
    'timestamp' => date('Y-m-d H:i:s'),
    'payload' => [
        'ref' => 'refs/heads/master',
        'before' => '7257b3b23a649e1c6dae2dc0b8fb48621cf7b27c',
        'after' => '776758a64bb40423b36f1fd527335380bbae42ab',
        'repository' => [
            'name' => 'webhookTest',
            'full_name' => 'happytalkGD/webhookTest',
            'compare_url' => 'https://api.github.com/repos/happytalkGD/webhookTest/compare/{base}...{head}'
        ],
        'pusher' => [
            'name' => 'happytalkGD'
        ],
        'commits' => [
            [
                'id' => '776758a64bb40423b36f1fd527335380bbae42ab',
                'message' => 'P03-45 클라우드 분석 및 Jira 통합 개선',
                'author' => ['name' => 'happytalkGD'],
                'timestamp' => date('c'),
                'added' => [],
                'modified' => ['claude.analyze.php', '.gitignore', 'README.md'],
                'removed' => []
            ]
        ]
    ]
];

// Build the prompt as the updated claude.analyze.php would
$payload = $sampleWebhookData['payload'];
$branch = str_replace('refs/heads/', '', $payload['ref']);
$beforeCommit = $payload['before'];
$afterCommit = $payload['after'];

// Extract compare URL and build actual URL
$compareUrl = $payload['repository']['compare_url'];
$actualCompareUrl = str_replace(
    ['{base}', '{head}'],
    [$beforeCommit, $afterCommit],
    $compareUrl
);

echo "=== GitHub Compare URL Test ===\n\n";
echo "Repository: " . $payload['repository']['full_name'] . "\n";
echo "Branch: " . $branch . "\n";
echo "Commit Range: " . substr($beforeCommit, 0, 7) . " → " . substr($afterCommit, 0, 7) . "\n";
echo "\n";
echo "Compare URL Template: " . $compareUrl . "\n";
echo "Actual Compare URL: " . $actualCompareUrl . "\n";
echo "\n";

// Build the prompt that would be sent to Claude
$prompt = "다음 Git Push 이벤트의 변경사항을 분석하고 한국어로 요약해주세요.\n\n";
$prompt .= "=== 기본 정보 ===\n";
$prompt .= "저장소: " . $payload['repository']['full_name'] . "\n";
$prompt .= "브랜치: " . $branch . "\n";
$prompt .= "작성자: " . $payload['pusher']['name'] . "\n";
$prompt .= "커밋 범위: " . substr($beforeCommit, 0, 7) . " → " . substr($afterCommit, 0, 7) . "\n\n";

$prompt .= "=== 커밋 내역 ===\n";
foreach ($payload['commits'] as $idx => $commit) {
    $prompt .= ($idx + 1) . ". [" . substr($commit['id'], 0, 7) . "] " . $commit['message'] . "\n";
    $prompt .= "   작성자: " . $commit['author']['name'] . "\n";
    $modifiedCount = count($commit['modified']);
    $prompt .= "   변경: 수정 " . $modifiedCount . "개 파일\n";
    if (!empty($commit['modified'])) {
        $prompt .= "   파일: " . implode(', ', $commit['modified']) . "\n";
    }
}

$prompt .= "\n=== Git 분석 작업 ===\n";
$prompt .= "다음 GitHub Compare API URL을 사용하여 변경사항을 분석해주세요:\n";
$prompt .= "Compare URL: " . $actualCompareUrl . "\n";
$prompt .= "\n이 URL을 통해 두 커밋 간의 차이점을 직접 확인하고 분석해주세요.\n";
$prompt .= "URL에서 다음 정보를 확인할 수 있습니다:\n";
$prompt .= "- 변경된 파일 목록 (files)\n";
$prompt .= "- 각 파일의 patch (코드 diff)\n";
$prompt .= "- 추가/삭제된 줄 수 (additions/deletions)\n";
$prompt .= "- 파일 상태 (added/modified/removed)\n";

$prompt .= "\n=== 요약 형식 ===\n";
$prompt .= "다음 형식으로 요약해주세요:\n";
$prompt .= "📌 **주요 변경사항**: (1-2줄로 핵심 변경 내용)\n";
$prompt .= "📁 **영향받는 모듈**: (주요 파일/디렉토리)\n";
$prompt .= "🎯 **변경 목적**: (커밋 메시지와 코드 변경 기반 추측)\n";
$prompt .= "\n전체 300 줄 이내로 자세하게 작성해주세요.\n";
$prompt .= "\n작업된 내용 작성하고 제안 사항을 적는것은 금지 합니다.\n";
$prompt .= "\n중요: GitHub Compare API를 직접 호출하여 실제 코드 변경사항을 확인한 후 분석해주세요.";

echo "=== Generated Prompt for Claude ===\n";
echo $prompt . "\n\n";

echo "=== System Prompt ===\n";
echo "You are a Git commit analyzer. Analyze the provided Git repository changes and provide a detailed summary in Korean. You can fetch and analyze GitHub repository changes using the compare URL provided.\n\n";

echo "=== How to Use ===\n";
echo "1. This prompt instructs Claude to use the GitHub Compare API directly\n";
echo "2. Claude will fetch the actual diff from: " . $actualCompareUrl . "\n";
echo "3. The API response will contain:\n";
echo "   - Complete file diffs (patches)\n";
echo "   - Statistics about additions/deletions\n";
echo "   - File status information\n";
echo "4. Claude will analyze the actual code changes, not just commit messages\n";
echo "\n";

echo "=== Test Commands ===\n";
echo "To test with Claude CLI:\n";
echo "1. Save a test webhook JSON in pending_webhooks/\n";
echo "2. Run: php claude.analyze.php\n";
echo "\n";
echo "Or directly test the prompt with:\n";
$systemPrompt = "You are a Git commit analyzer. Analyze the provided Git repository changes and provide a detailed summary in Korean. You can fetch and analyze GitHub repository changes using the compare URL provided.";
$escapedSystemPrompt = str_replace("\n", "\\n", addslashes($systemPrompt));
echo "claude -p --permission-mode bypassPermissions --append-system-prompt \"$escapedSystemPrompt\" << 'EOF'\n";
echo $prompt . "\n";
echo "EOF\n";