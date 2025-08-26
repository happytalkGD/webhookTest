<?php
// Test YAML parsing
require_once 'yaml_parser.php';

$yaml = file_get_contents('prompts_normal.yaml');
echo "YAML file size: " . strlen($yaml) . " bytes\n\n";

// Find user_prompt content
$start = strpos($yaml, "user_prompt: |");
$end = strpos($yaml, "# 대체 버전");
if ($start !== false && $end !== false) {
    $content = substr($yaml, $start + 14, $end - $start - 14);
    echo "user_prompt expected length: " . strlen(trim($content)) . " bytes\n";
}

// Parse with our parser
$parsed = yaml_parse_file('prompts_normal.yaml');
echo "\nParsed keys: " . implode(', ', array_keys($parsed)) . "\n";
echo "\nuser_prompt parsed length: " . strlen($parsed['user_prompt'] ?? '') . " bytes\n";

if (isset($parsed['user_prompt'])) {
    echo "\nFirst 500 chars of parsed user_prompt:\n";
    echo "---\n";
    echo substr($parsed['user_prompt'], 0, 500);
    echo "\n---\n";
}