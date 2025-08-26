<?php
/**
 * Prompt Template Preview Tool
 * Generates a preview of the prompt template with sample data
 * Supports both JSON and YAML templates
 */

// Include YAML parser if needed
if (!function_exists('yaml_parse_file')) {
    if (file_exists(dirname(__FILE__) . '/yaml_parser_v2.php')) {
        require_once dirname(__FILE__) . '/yaml_parser_v2.php';
    } elseif (file_exists(dirname(__FILE__) . '/yaml_parser.php')) {
        require_once dirname(__FILE__) . '/yaml_parser.php';
    }
}

// Sample data for preview
$sampleData = [
    'is_merge_with_conflict' => false,
    'repository' => 'example/test-repo',
    'branch' => 'main',
    'author' => '홍길동',
    'before_commit' => 'abc1234',
    'after_commit' => 'def5678',
    'commits' => [
        [
            'id' => 'def56789abcdef',
            'message' => 'P03-45 프롬프트 템플릿 기능 추가 및 로깅 개선',
            'author' => '홍길동',
            'added' => 2,
            'modified' => 3,
            'removed' => 1,
            'files' => ['prompt_template.json', 'claude.analyze.php', 'README.md'],
            'parents' => ['abc1234']  // Single parent (normal commit)
        ],
        [
            'id' => 'cde4567890abcd',
            'message' => 'P03-45 병합 충돌 감지 로직 수정',
            'author' => '김철수',
            'added' => 1,
            'modified' => 2,
            'removed' => 0,
            'files' => ['common.lib.php', 'jira.hook.php'],
            'parents' => ['abc1234']
        ]
    ],
    'compare_url' => 'https://api.github.com/repos/example/test-repo/compare/abc1234...def5678',
    'repo_name' => 'test-repo',
    'commit_count' => 2
];

// For conflict preview, add merge commit with multiple parents
$conflictData = array_merge($sampleData, [
    'commits' => [
        [
            'id' => 'merge123456789',
            'message' => 'Merge branch \'feature\' into main - resolved conflicts in config files',
            'author' => '박개발',
            'added' => 0,
            'modified' => 3,
            'removed' => 0,
            'files' => ['config.php', 'settings.json', 'database.yml'],
            'parents' => ['abc1234', 'xyz5678']  // Multiple parents (merge commit)
        ],
        ...$sampleData['commits']
    ]
]);

// Preview type selection
$previewType = $argv[1] ?? 'normal';
$validTypes = ['normal', 'conflict', 'simplified'];

if (!in_array($previewType, $validTypes)) {
    echo "Invalid preview type. Use one of: " . implode(', ', $validTypes) . "\n";
    $previewType = 'normal';
}

echo "===========================================\n";
echo "    PROMPT TEMPLATE PREVIEW GENERATOR\n";
echo "===========================================\n\n";

/**
 * Load YAML template
 */
function loadYamlTemplate($type) {
    $templateFile = dirname(__FILE__) . '/prompts_' . $type . '.yaml';
    
    if (!file_exists($templateFile)) {
        echo "Warning: YAML template not found: $templateFile\n";
        return null;
    }
    
    if (!function_exists('yaml_parse_file')) {
        echo "Warning: YAML parser not available\n";
        return null;
    }
    
    $template = yaml_parse_file($templateFile);
    if ($template === false) {
        echo "Error: Invalid YAML in template file\n";
        return null;
    }
    
    return $template;
}

/**
 * Generate YAML template preview
 */
function generateYamlPreview($template, $data, $type) {
    // Select prompt based on URL availability
    $promptKey = !empty($data['compare_url']) ? 'user_prompt' : 'user_prompt_no_url';
    $prompt = $template[$promptKey] ?? '';
    
    // Build commits detail string
    $commitsDetail = '';
    
    if ($type !== 'simplified') {
        $commitTemplate = $template['commit_template'] ?? '';
        $maxCommits = 10;
        $commitsToShow = array_slice($data['commits'], 0, $maxCommits);
        
        foreach ($commitsToShow as $idx => $commit) {
            $message = $commit['message'];
            if (strlen($message) > 200) {
                $message = substr($message, 0, 197) . '...';
            }
            
            $filesList = '';
            if (!empty($commit['files'])) {
                $fileCount = count($commit['files']);
                if ($fileCount > 10) {
                    $filesList = "총 {$fileCount}개 파일 변경됨";
                } else {
                    $filesList = implode(', ', array_slice($commit['files'], 0, 5));
                    if ($fileCount > 5) {
                        $filesList .= " 외 " . ($fileCount - 5) . "개";
                    }
                }
            }
            
            $commitStr = str_replace(
                ['{idx}', '{commit_id}', '{message}', '{author}', '{added}', '{modified}', '{removed}', '{files}'],
                [$idx + 1, substr($commit['id'], 0, 7), $message, $commit['author'], 
                 $commit['added'], $commit['modified'], $commit['removed'], $filesList],
                $commitTemplate
            );
            
            // Add merge info for conflict template
            if ($type === 'conflict' && isset($commit['parents']) && count($commit['parents']) > 1) {
                $commitStr = str_replace('{merge_info}', '병합 커밋 (부모: ' . count($commit['parents']) . '개)', $commitStr);
            } else {
                $commitStr = str_replace('{merge_info}', '', $commitStr);
            }
            
            $commitsDetail .= $commitStr;
        }
    } else {
        // Simplified version - just main commits
        $mainCommitTemplate = $template['main_commit_template'] ?? '';
        $maxCommits = 5;
        $commitsToShow = array_slice($data['commits'], 0, $maxCommits);
        
        foreach ($commitsToShow as $commit) {
            $message = $commit['message'];
            if (strlen($message) > 50) {
                $message = substr($message, 0, 47) . '...';
            }
            
            $totalFiles = $commit['added'] + $commit['modified'] + $commit['removed'];
            
            $commitsDetail .= str_replace(
                ['{commit_id}', '{message}', '{total_files}'],
                [substr($commit['id'], 0, 7), $message, $totalFiles],
                $mainCommitTemplate
            );
        }
    }
    
    // Replace all variables in the prompt
    $replacements = [
        '{repository}' => $data['repository'],
        '{branch}' => $data['branch'],
        '{author}' => $data['author'],
        '{before_commit}' => substr($data['before_commit'], 0, 7),
        '{after_commit}' => substr($data['after_commit'], 0, 7),
        '{total}' => count($data['commits']),
        '{shown}' => min(count($data['commits']), $type === 'simplified' ? 5 : 10),
        '{commit_count}' => $data['commit_count'],
        '{commits_detail}' => trim($commitsDetail),
        '{main_commits}' => trim($commitsDetail),
        '{url}' => $data['compare_url'],
        '{repo_name}' => $data['repo_name']
    ];
    
    foreach ($replacements as $key => $value) {
        $prompt = str_replace($key, $value, $prompt);
    }
    
    return $prompt;
}

/**
 * Show statistics
 */
function showStatistics($prompt, $type) {
    echo "\n==========================================\n";
    echo "PROMPT STATISTICS ($type):\n";
    echo "==========================================\n";
    
    $lines = explode("\n", $prompt);
    $nonEmptyLines = array_filter($lines, function($line) { return trim($line) !== ''; });
    
    echo "Total Lines: " . count($lines) . "\n";
    echo "Non-empty Lines: " . count($nonEmptyLines) . "\n";
    echo "Total Characters: " . strlen($prompt) . "\n";
    echo "Total Bytes: " . strlen($prompt) . "\n";
    
    // Check against limits
    $limits = [
        'normal' => ['min' => 30, 'max' => 100],
        'conflict' => ['min' => 30, 'max' => 100],
        'simplified' => ['min' => 0, 'max' => 50]
    ];
    
    if (isset($limits[$type])) {
        $lineCount = count($nonEmptyLines);
        $limit = $limits[$type];
        if ($lineCount < $limit['min']) {
            echo "⚠️  Warning: Too short (minimum {$limit['min']} lines)\n";
        } elseif ($lineCount > $limit['max']) {
            echo "⚠️  Warning: Too long (maximum {$limit['max']} lines)\n";
        } else {
            echo "✅ Length is within limits ({$limit['min']}-{$limit['max']} lines)\n";
        }
    }
}

// Main execution
$dataToUse = $sampleData;

// Adjust data based on preview type
if ($previewType === 'conflict') {
    $dataToUse = $conflictData;
    echo "Preview Type: CONFLICT (merge with conflicts)\n";
} elseif ($previewType === 'simplified') {
    // Add more commits for simplified preview
    for ($i = 3; $i <= 20; $i++) {
        $dataToUse['commits'][] = [
            'id' => 'commit' . str_pad($i, 7, '0'),
            'message' => "Additional commit message #$i for testing",
            'author' => 'Developer ' . $i,
            'added' => rand(0, 5),
            'modified' => rand(0, 10),
            'removed' => rand(0, 3),
            'files' => ["file$i.php", "test$i.js"],
            'parents' => ['parent' . $i]
        ];
    }
    $dataToUse['commit_count'] = count($dataToUse['commits']);
    echo "Preview Type: SIMPLIFIED (large commit set)\n";
} else {
    echo "Preview Type: NORMAL\n";
}

echo "-------------------------------------------\n\n";

// Try to load and preview YAML template
$yamlTemplate = loadYamlTemplate($previewType);

if ($yamlTemplate) {
    echo "=== YAML TEMPLATE PREVIEW ===\n";
    echo "Template File: prompts_{$previewType}.yaml\n";
    echo "==========================================\n";
    
    $prompt = generateYamlPreview($yamlTemplate, $dataToUse, $previewType);
    echo $prompt;
    
    showStatistics($prompt, $previewType);
} else {
    echo "Error: Could not load YAML template for type: $previewType\n";
    echo "Make sure the file 'prompts_{$previewType}.yaml' exists and is valid.\n";
}

// Template variables reference
echo "\n==========================================\n";
echo "TEMPLATE VARIABLES:\n";
echo "==========================================\n";
$variables = [
    '{repository}' => '전체 저장소 이름 (owner/repo)',
    '{branch}' => '브랜치 이름',
    '{author}' => '커밋 작성자',
    '{before_commit}' => '이전 커밋 해시 (7자)',
    '{after_commit}' => '이후 커밋 해시 (7자)',
    '{total}' => '전체 커밋 수',
    '{shown}' => '표시된 커밋 수',
    '{commit_count}' => '전체 커밋 개수',
    '{commits_detail}' => '커밋 상세 정보 (normal/conflict)',
    '{main_commits}' => '주요 커밋 목록 (simplified)',
    '{url}' => 'GitHub Compare API URL',
    '{repo_name}' => '저장소 이름 (owner 제외)',
    '{idx}' => '커밋 인덱스',
    '{commit_id}' => '커밋 ID (7자)',
    '{message}' => '커밋 메시지',
    '{added}' => '추가된 파일 수',
    '{modified}' => '수정된 파일 수', 
    '{removed}' => '삭제된 파일 수',
    '{files}' => '파일 목록',
    '{total_files}' => '총 파일 수 (simplified)',
    '{merge_info}' => '병합 정보 (conflict only)'
];

foreach ($variables as $var => $desc) {
    echo sprintf("  %-20s : %s\n", $var, $desc);
}

echo "\n==========================================\n";
echo "Usage: php preview_prompt.php [normal|conflict|simplified]\n";
echo "==========================================\n";