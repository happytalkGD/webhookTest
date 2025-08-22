<?php
/**
 * Test Jira ticket ID extraction patterns
 */

function extractJiraTicketId($branchName) {
    // Common Jira ticket patterns
    $patterns = [
        '/\[([A-Z]+[0-9]+-\d+)\]/i',     // [P03-45] format with brackets
        '/\[([A-Z]+-\d+)\]/i',            // [PROJ-123] format with brackets  
        '/([A-Z]+[0-9]+-\d+)/i',          // P03-45, ABC1-234 mixed format
        '/([A-Z]{1,10}-\d+)/i',           // Standard JIRA format: PROJ-123
        '/^([A-Z]{1,10}-\d+)/',           // At the beginning
        '/\/([A-Z]{1,10}-\d+)/',          // After slash (feature/PROJ-123)
    ];
    
    foreach ($patterns as $index => $pattern) {
        if (preg_match($pattern, $branchName, $matches)) {
            echo "  → Matched by pattern #" . ($index + 1) . ": $pattern\n";
            return strtoupper($matches[1]);
        }
    }
    
    return null;
}

// Test cases
$testCases = [
    "[P03-45] .gitignore에 .env 및 processed_jira 추가",
    "[PROJ-123] Fix authentication bug",
    "P03-45 Update configuration",
    "PROJ-123: Add new feature",
    "feature/ABC-456",
    "bugfix/P03-45-login-issue",
    "[BLUMN-456] 로그인 API 보안 강화",
    "develop",
    "ABC1-234 Mixed format test",
];

echo "=== Testing Jira Ticket ID Extraction ===\n\n";

foreach ($testCases as $testCase) {
    echo "Test: \"$testCase\"\n";
    $result = extractJiraTicketId($testCase);
    if ($result) {
        echo "  ✓ Found: $result\n";
    } else {
        echo "  ✗ Not found\n";
    }
    echo "\n";
}

echo "=== Test Complete ===\n";