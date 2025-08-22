<?php
/**
 * Test Jira format conversion
 */

function markdownToJira($text) {
    // Headers
    $text = preg_replace('/^### (.+)$/m', 'h3. $1', $text);
    $text = preg_replace('/^## (.+)$/m', 'h2. $1', $text);
    $text = preg_replace('/^# (.+)$/m', 'h1. $1', $text);
    
    // Bold - **text** or __text__ to *text*
    $text = preg_replace('/\*\*(.+?)\*\*/', '*$1*', $text);
    $text = preg_replace('/__(.+?)__/', '*$1*', $text);
    
    // Code blocks - ```code``` to {code}code{code}
    $text = preg_replace('/```([^`]+?)```/s', '{code}$1{code}', $text);
    
    // Inline code - `code` to {{code}}
    $text = preg_replace('/`([^`]+?)`/', '{{$1}}', $text);
    
    // Regular lists - * or - to *
    $text = preg_replace('/^[\*\-]\s+(.+)$/m', '* $1', $text);
    
    // Numbered lists
    $text = preg_replace('/^\d+\.\s+(.+)$/m', '# $1', $text);
    
    // Horizontal rules - --- to ----
    $text = preg_replace('/^---+$/m', '----', $text);
    
    // Emojis to Jira icons
    $text = str_replace('📌', '(!)', $text);
    $text = str_replace('📁', '(i)', $text);
    $text = str_replace('🎯', '(/)', $text);
    $text = str_replace('🔍', '(?)', $text);
    $text = str_replace('📊', '(*)', $text);
    $text = str_replace('🔄', '(on)', $text);
    
    return $text;
}

$testMarkdown = "# 🔄 GitHub Push Analysis Report

**Repository:** happytalkGD/webhookTest
**Branch:** master
**Commits:** 3

---

## 📊 Claude AI Analysis

📌 **주요 변경사항**: Jira 통합 기능을 추가하고 웹훅 분석 스크립트를 개선했습니다.

📁 **영향받는 모듈**:
- `jira.hook.php` (신규 추가)
- `analyze.sh` (수정)
- `.gitignore` (수정)

🎯 **변경 목적**: GitHub 변경사항을 자동으로 Jira에 기록

🔍 **코드 리뷰 포인트**:
- API 토큰 보안 확인
- 에러 처리 로직 검증

---

_This comment was automatically generated_";

echo "=== Original Markdown ===\n";
echo $testMarkdown . "\n\n";

echo "=== Converted to Jira Format ===\n";
echo markdownToJira($testMarkdown) . "\n";