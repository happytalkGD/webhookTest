<?php
/**
 * Claude Summaries Processor
 * Reads saved webhook data files and analyzes them with Claude
 */

// Include common library
require_once dirname(__FILE__) . '/common.lib.php';

// Include YAML parser if PHP YAML extension not available
if (!function_exists('yaml_parse_file')) {
    if (file_exists(dirname(__FILE__) . '/yaml_parser_v2.php')) {
        require_once dirname(__FILE__) . '/yaml_parser_v2.php';
    } else {
        require_once dirname(__FILE__) . '/yaml_parser.php';
    }
}

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
                    } else if (strpos($result['error'], 'No Jira ticket ID found') !== false) {
                        echo "  → No Jira ticket - skipping analysis\n";
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
 * Load YAML prompt template based on type
 */
function loadYamlPromptTemplate($type = 'normal') {
    $validTypes = ['normal', 'conflict', 'simplified'];
    if (!in_array($type, $validTypes)) {
        $type = 'normal';
    }
    
    $templateFile = dirname(__FILE__) . '/prompts_' . $type . '.yaml';
    if (!file_exists($templateFile)) {
        // Fallback to JSON template if YAML not found
        return loadJsonPromptTemplate();
    }
    
    // Check if yaml_parse_file function exists
    if (!function_exists('yaml_parse_file')) {
        // Try to use Symfony YAML if available
        if (file_exists(dirname(__FILE__) . '/vendor/autoload.php')) {
            require_once dirname(__FILE__) . '/vendor/autoload.php';
            if (class_exists('Symfony\Component\Yaml\Yaml')) {
                $yamlContent = file_get_contents($templateFile);
                return \Symfony\Component\Yaml\Yaml::parse($yamlContent);
            }
        }
        // Fallback to JSON if YAML parser not available
        echo "  ⚠️ YAML parser not available, using JSON template\n";
        return loadJsonPromptTemplate();
    }
    
    $template = yaml_parse_file($templateFile);
    if ($template === false) {
        throw new Exception("Invalid YAML in template file: " . $templateFile);
    }
    
    return $template;
}

/**
 * Load JSON prompt template (fallback)
 */
function loadJsonPromptTemplate() {
    $templateFile = dirname(__FILE__) . '/prompt_template.json';
    if (!file_exists($templateFile)) {
        throw new Exception("Prompt template file not found");
    }
    
    $templateJson = file_get_contents($templateFile);
    $template = json_decode($templateJson, true);
    
    if ($template === null) {
        throw new Exception("Invalid JSON in prompt template file");
    }
    
    return $template;
}

/**
 * Determine template type based on commit analysis
 */
function determineTemplateType($webhookData) {
    $payload = $webhookData['payload'];
    $totalCommits = count($payload['commits'] ?? []);
    
    // Check for merge commits with conflicts
    if (isset($payload['commits']) && is_array($payload['commits'])) {
        foreach ($payload['commits'] as $commit) {
            // Check for merge commit
            if (isset($commit['parents']) && is_array($commit['parents']) && count($commit['parents']) > 1) {
                // Check for conflict indicators
                $message = $commit['message'] ?? '';
                if (stripos($message, 'conflict') !== false || 
                    stripos($message, 'resolve') !== false || 
                    stripos($message, 'fixed merge') !== false) {
                    echo "  → Template type: conflict (merge with conflicts detected)\n";
                    return 'conflict';
                }
            }
            
            // Check message patterns for merge
            $message = $commit['message'] ?? '';
            if ((stripos($message, 'Merge pull request') !== false || 
                 stripos($message, 'Merge branch') !== false) &&
                (stripos($message, 'conflict') !== false || 
                 stripos($message, 'resolve') !== false)) {
                echo "  → Template type: conflict (merge conflict pattern detected)\n";
                return 'conflict';
            }
        }
    }
    
    // Use simplified for large commit sets
    if ($totalCommits > 15) {
        echo "  → Template type: simplified (large commit set: {$totalCommits} commits)\n";
        return 'simplified';
    }
    
    // Check prompt size estimate
    $estimatedSize = $totalCommits * 500; // Rough estimate
    if ($estimatedSize > 10000) {
        echo "  → Template type: simplified (estimated size too large)\n";
        return 'simplified';
    }
    
    echo "  → Template type: normal\n";
    return 'normal';
}

/**
 * Build prompt from YAML template
 */
function buildPromptFromYamlTemplate($template, $data, $type = 'normal') {
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
        '{before_commit}' => $data['before_commit'],
        '{after_commit}' => $data['after_commit'],
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
 * Build prompt from JSON template (legacy)
 */
function buildPromptFromTemplate($template, $data) {
    $prompt = $template['main_prompt']['intro'];
    
    // Add merge conflict warning if applicable
    if ($data['is_merge_with_conflict']) {
        $prompt .= $template['main_prompt']['merge_conflict_warning'];
    }
    
    // Basic info section
    $prompt .= $template['main_prompt']['basic_info']['header'];
    $prompt .= str_replace('{repository}', $data['repository'], $template['main_prompt']['basic_info']['repository']);
    $prompt .= str_replace('{branch}', $data['branch'], $template['main_prompt']['basic_info']['branch']);
    $prompt .= str_replace('{author}', $data['author'], $template['main_prompt']['basic_info']['author']);
    $prompt .= str_replace(
        ['{before_commit}', '{after_commit}'],
        [$data['before_commit'], $data['after_commit']],
        $template['main_prompt']['basic_info']['commit_range']
    );
    
    // Commit details section
    $settings = $template['settings'];
    $maxCommits = $settings['max_commits_detail'];
    $totalCommits = count($data['commits']);
    $commitsToShow = $data['commits'];
    
    $prompt .= $template['main_prompt']['commit_details']['header'];
    if ($totalCommits > $maxCommits) {
        $prompt .= str_replace(
            ['{total}', '{shown}'],
            [$totalCommits, $maxCommits],
            $template['main_prompt']['commit_details']['total_commits_note']
        );
        $commitsToShow = array_slice($data['commits'], 0, $maxCommits);
    }
    
    foreach ($commitsToShow as $idx => $commit) {
        // Truncate long commit messages
        $message = $commit['message'];
        if (strlen($message) > $settings['truncate_message_at']) {
            $message = substr($message, 0, $settings['truncate_message_at'] - 3) . '...';
        }
        
        $prompt .= str_replace(
            ['{idx}', '{commit_id}', '{message}', '{author}', '{added}', '{modified}', '{removed}'],
            [$idx + 1, substr($commit['id'], 0, $settings['truncate_commit_id_to']), $message, $commit['author'], $commit['added'], $commit['modified'], $commit['removed']],
            $template['main_prompt']['commit_details']['commit_format']
        );
        
        // Add file list
        if (!empty($commit['files'])) {
            $fileCount = count($commit['files']);
            if ($fileCount > $settings['max_files_per_commit']) {
                $prompt .= str_replace('{count}', $fileCount, $template['main_prompt']['commit_details']['files_many']);
            } else {
                $filesList = implode(', ', array_slice($commit['files'], 0, $settings['max_files_to_list']));
                $prompt .= str_replace('{files_list}', $filesList, $template['main_prompt']['commit_details']['files_list']);
                if ($fileCount > $settings['max_files_to_list']) {
                    $prompt .= str_replace('{count}', $fileCount - $settings['max_files_to_list'], $template['main_prompt']['commit_details']['files_more']);
                }
                $prompt .= "\n";
            }
        }
    }
    
    // Analysis task section
    $prompt .= $template['main_prompt']['analysis_task']['header'];
    if (!empty($data['compare_url'])) {
        $prompt .= str_replace('{url}', $data['compare_url'], $template['main_prompt']['analysis_task']['with_compare_url']);
    } else {
        $prompt .= str_replace('{repo_name}', $data['repo_name'], $template['main_prompt']['analysis_task']['without_compare_url']);
    }
    
    // Summary format section
    $prompt .= $template['main_prompt']['summary_format']['header'];
    $prompt .= $template['main_prompt']['summary_format']['main_changes'];
    $prompt .= $template['main_prompt']['summary_format']['affected_modules'];
    $prompt .= $template['main_prompt']['summary_format']['change_purpose'];
    
    // Add conflict resolution section if needed
    if ($data['is_merge_with_conflict']) {
        $prompt .= $template['main_prompt']['summary_format']['conflict_resolution'];
        $prompt .= $template['main_prompt']['summary_format']['conflict_check_items'];
    }
    
    // Add constraints
    $prompt .= $template['main_prompt']['constraints']['length'];
    $prompt .= $template['main_prompt']['constraints']['content_only'];
    $prompt .= $template['main_prompt']['constraints']['important_note'];
    
    return $prompt;
}

/**
 * Build simplified prompt from template
 */
function buildSimplifiedPromptFromTemplate($template, $data) {
    $prompt = $template['main_prompt']['simplified_prompt']['intro'];
    
    $prompt .= str_replace(
        ['{repository}', '{branch}', '{commit_count}', '{before_commit}', '{after_commit}'],
        [$data['repository'], $data['branch'], $data['commit_count'], $data['before_commit'], $data['after_commit']],
        $template['main_prompt']['simplified_prompt']['basic_info']
    );
    
    if (!empty($data['compare_url'])) {
        $prompt .= str_replace('{url}', $data['compare_url'], $template['main_prompt']['simplified_prompt']['compare_url']);
    }
    
    $prompt .= $template['main_prompt']['simplified_prompt']['summary_format'];
    $prompt .= $template['main_prompt']['simplified_prompt']['constraint'];
    
    return $prompt;
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
    
    // Check if any commit or branch has Jira ticket ID
    $hasJiraTicket = false;
    
    // Check branch name first
    if (extractJiraTicketId($branch)) {
        $hasJiraTicket = true;
        echo "  → Found Jira ticket in branch: " . extractJiraTicketId($branch) . "\n";
    }
    
    // Check commit messages if not found in branch
    if (!$hasJiraTicket && isset($payload['commits']) && is_array($payload['commits'])) {
        foreach ($payload['commits'] as $commit) {
            $message = $commit['message'] ?? '';
            if (extractJiraTicketId($message)) {
                $hasJiraTicket = true;
                echo "  → Found Jira ticket in commit: " . extractJiraTicketId($message) . "\n";
                break;
            }
        }
    }
    
    // Skip analysis if no Jira ticket found
    if (!$hasJiraTicket) {
        echo "  ⚠️ No Jira ticket ID found - skipping analysis\n";
        echo "    Branch: {$branch}\n";
        if (isset($payload['commits'][0])) {
            echo "    First commit: " . substr($payload['commits'][0]['message'] ?? '', 0, 50) . "...\n";
        }
        return [
            'success' => false,
            'error' => 'No Jira ticket ID found in branch or commits'
        ];
    }
    
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
    
    // Determine template type
    $templateType = determineTemplateType($webhookData);
    
    // Load YAML prompt template
    try {
        $template = loadYamlPromptTemplate($templateType);
    } catch (Exception $e) {
        echo "  ✗ Failed to load YAML template, trying JSON: " . $e->getMessage() . "\n";
        try {
            $template = loadJsonPromptTemplate();
            $templateType = 'json'; // Mark as JSON fallback
        } catch (Exception $e2) {
            echo "  ✗ Failed to load any template: " . $e2->getMessage() . "\n";
            return [
                'success' => false,
                'error' => 'Failed to load prompt template'
            ];
        }
    }
    
    // System prompt from template
    $systemPrompt = $template['system_prompt'] ?? 'You are a Git commit analyzer.';
    
    // Build compare URL if available
    $compareUrl = $payload['repository']['compare_url'] ?? '';
    $actualCompareUrl = '';
    if (!empty($compareUrl) && !empty($beforeCommit) && !empty($afterCommit)) {
        // Convert GitHub web URL to API URL
        // From: https://github.com/{owner}/{repo}/compare/{base}...{head}
        // To: https://api.github.com/repos/{owner}/{repo}/compare/{base}...{head}
        $actualCompareUrl = str_replace(
            ['{base}', '{head}'],
            [$beforeCommit, $afterCommit],
            $compareUrl
        );
        
        // Convert to API URL if it's a web URL
        if (strpos($actualCompareUrl, 'https://github.com/') === 0) {
            $actualCompareUrl = str_replace(
                'https://github.com/',
                'https://api.github.com/repos/',
                $actualCompareUrl
            );
        }
        
        // Validate the resulting URL
        $parsedUrl = parse_url($actualCompareUrl);
        if ($parsedUrl === false) {
            error_log("Invalid URL generated for compare API: " . $actualCompareUrl);
            $actualCompareUrl = '';
        } else {
            // Validate URL components
            if (!isset($parsedUrl['scheme']) || !isset($parsedUrl['host']) || !isset($parsedUrl['path'])) {
                error_log("Malformed URL - missing required components: " . $actualCompareUrl);
                $actualCompareUrl = '';
            } else {
                // Validate API path pattern
                $path = $parsedUrl['path'];
                
                // Check for double "repos/" insertion
                if (strpos($path, '/repos/repos/') !== false) {
                    error_log("Double 'repos/' detected in URL path: " . $path);
                    // Fix double repos
                    $path = str_replace('/repos/repos/', '/repos/', $path);
                    $actualCompareUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $path;
                }
                
                // Validate path pattern: /repos/{owner}/{repo}/compare/{base}...{head}
                if (!preg_match('#^/repos/([^/]+)/([^/]+)/compare/([^/]+)$#', $path, $matches)) {
                    error_log("Invalid API path pattern: " . $path);
                    $actualCompareUrl = '';
                } else {
                    // Extract and validate owner and repo
                    $owner = $matches[1];
                    $repo = $matches[2];
                    $compareRange = $matches[3];
                    
                    if (empty($owner) || empty($repo)) {
                        error_log("Empty owner or repo in URL: owner='$owner', repo='$repo'");
                        $actualCompareUrl = '';
                    } else if (!strpos($compareRange, '...')) {
                        error_log("Invalid compare range format (missing '...'): " . $compareRange);
                        $actualCompareUrl = '';
                    } else {
                        // Validate host
                        if ($parsedUrl['host'] !== 'api.github.com') {
                            error_log("Invalid API host: " . $parsedUrl['host'] . " (expected api.github.com)");
                            $actualCompareUrl = '';
                        } else {
                            // URL is valid
                            echo "  → GitHub Compare API URL validated: " . $actualCompareUrl . "\n";
                        }
                    }
                }
            }
        }
    }
    
    // Prepare data for template
    $promptData = [
        'is_merge_with_conflict' => $isMergeCommit && isset($hasConflict) && $hasConflict,
        'repository' => $payload['repository']['full_name'] ?? 'Unknown',
        'branch' => $branch,
        'author' => $payload['pusher']['name'] ?? 'Unknown',
        'before_commit' => substr($beforeCommit, 0, 7),
        'after_commit' => substr($afterCommit, 0, 7),
        'commits' => $commitInfo,
        'compare_url' => $actualCompareUrl,
        'repo_name' => $repoName,
        'commit_count' => count($commitInfo)
    ];
    
    // Build prompt using appropriate method
    if ($templateType !== 'json') {
        $prompt = buildPromptFromYamlTemplate($template, $promptData, $templateType);
    } else {
        // Fallback to JSON template builder
        $prompt = buildPromptFromTemplate($template, $promptData);
    }
    
    // Check prompt size to prevent Claude errors
    $promptLength = strlen($prompt);
    $maxPromptLength = 10000; // Default max length
    
    if ($templateType !== 'json' && isset($template['settings']['max_prompt_length'])) {
        $maxPromptLength = $template['settings']['max_prompt_length'];
    } elseif ($templateType === 'json' && isset($template['settings']['max_prompt_length'])) {
        $maxPromptLength = $template['settings']['max_prompt_length'];
    }
    
    if ($promptLength > $maxPromptLength) { // If prompt is too long
        echo "  ⚠️ Prompt too long ({$promptLength} bytes), switching to simplified version...\n";
        
        // Reload as simplified template
        try {
            $template = loadYamlPromptTemplate('simplified');
            $prompt = buildPromptFromYamlTemplate($template, $promptData, 'simplified');
        } catch (Exception $e) {
            // Fallback to JSON simplified
            $prompt = buildSimplifiedPromptFromTemplate($template, $promptData);
        }
    }
    
    // Save prompt to log file for debugging
    $promptLogDir = dirname(__FILE__) . '/logs/claude_prompts';
    if (!is_dir($promptLogDir)) {
        mkdir($promptLogDir, 0755, true);
    }
    
    $promptLogFile = $promptLogDir . '/' . date('Y-m-d_H-i-s') . '_' . 
                     str_replace('/', '_', $payload['repository']['full_name'] ?? 'unknown') . 
                     '_prompt.txt';
    
    // Save the full prompt to file
    $fullPromptContent = "=== SYSTEM PROMPT ===\n";
    $fullPromptContent .= $systemPrompt . "\n\n";
    $fullPromptContent .= "=== USER PROMPT ===\n";
    $fullPromptContent .= $prompt . "\n\n";
    $fullPromptContent .= "=== METADATA ===\n";
    $fullPromptContent .= "Repository: " . ($payload['repository']['full_name'] ?? 'unknown') . "\n";
    $fullPromptContent .= "Branch: " . $branch . "\n";
    $fullPromptContent .= "Commit Range: " . substr($beforeCommit, 0, 7) . " → " . substr($afterCommit, 0, 7) . "\n";
    $fullPromptContent .= "Prompt Size: " . strlen($prompt) . " bytes\n";
    $fullPromptContent .= "Timestamp: " . date('Y-m-d H:i:s') . "\n";
    
    file_put_contents($promptLogFile, $fullPromptContent);
    echo "  → Prompt saved to: logs/claude_prompts/" . basename($promptLogFile) . "\n";
    
    // Escape the system prompt for shell command
    $escapedSystemPrompt = str_replace("\n", "\\n", addslashes($systemPrompt));
    
    // Build Claude CLI command with heredoc
    $command = "claude -p --permission-mode bypassPermissions --input-format text --output-format text --append-system-prompt \"$escapedSystemPrompt\" << 'QUERY_PROMPT'\n";
    $command .= $prompt . "\n";
    $command .= "QUERY_PROMPT";
    
    // Log the command
    error_log("Executing Claude command for: " . ($payload['repository']['full_name'] ?? 'unknown'));
    error_log("Prompt size: " . strlen($prompt) . " bytes");
    error_log("Prompt log saved to: " . $promptLogFile);
    
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