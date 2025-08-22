<?php
/**
 * Test complex markdown conversion
 */

function markdownToJira($text) {
    // Clean up excessive line breaks first
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    
    // Headers - Process 4-level headers first, treating numbered h4 as bold items
    // Convert #### 1. **text** to *1. text* (bold numbered item)
    $text = preg_replace('/^####\s+(\d+\.)\s+\*\*(.+?)\*\*$/m', '*$1 $2*', $text);
    $text = preg_replace('/^####\s+(.+)$/m', 'h4. $1', $text);
    $text = preg_replace('/^###\s+(.+)$/m', 'h3. $1', $text);
    $text = preg_replace('/^##\s+(.+)$/m', 'h2. $1', $text);
    $text = preg_replace('/^#\s+(.+)$/m', 'h1. $1', $text);
    
    // Bold - **text** or __text__ to *text*
    $text = preg_replace('/\*\*(.+?)\*\*/', '*$1*', $text);
    $text = preg_replace('/__(.+?)__/', '*$1*', $text);
    
    // Code blocks - ```code``` to {code}code{code}
    $text = preg_replace('/```([^`]+?)```/s', '{code}$1{code}', $text);
    
    // Inline code - `code` to {{code}}
    $text = preg_replace('/`([^`]+?)`/', '{{$1}}', $text);
    
    // Regular lists - * or - to *
    $text = preg_replace('/^[\*\-]\s+(.+)$/m', '* $1', $text);
    
    // Remove standalone list markers FIRST before converting numbered lists
    $text = preg_replace('/^\d+\.\s*$/m', '', $text);  // Remove "1." etc on its own
    $text = preg_replace('/^[a-z]\.\s*$/m', '', $text);  // Remove "a." etc on its own  
    $text = preg_replace('/^[ivx]+\.\s*$/m', '', $text);  // Remove "i.", "ii.", etc on its own
    
    // Now convert numbered lists - only with content
    $text = preg_replace('/^\d+\.\s+([^\s].+)$/m', '# $1', $text);
    
    // Horizontal rules - --- to ----
    $text = preg_replace('/^---+$/m', '----', $text);
    
    // Emojis to Jira icons
    $text = str_replace('📌', '(!)', $text);
    $text = str_replace('📁', '(i)', $text);
    $text = str_replace('🎯', '(/)', $text);
    $text = str_replace('🔍', '(?)', $text);
    $text = str_replace('📊', '(*)', $text);
    $text = str_replace('🔄', '(on)', $text);
    
    // Final cleanup
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    $text = preg_replace('/\n\s*\n/', "\n\n", $text);
    
    return $text;
}

$testMarkdown = "### 🔍 **코드 리뷰 포인트**

#### 1. **PHP curl 확장 체크 (jira.hook.php:7-15)**
설명 내용입니다.

#### 2. **Markdown 변환 개선 (test_jira_format.php:28-34)**
또 다른 설명입니다.";

echo "=== Original Markdown ===\n";
echo $testMarkdown . "\n\n";

echo "=== Converted to Jira Format ===\n";
echo markdownToJira($testMarkdown) . "\n";