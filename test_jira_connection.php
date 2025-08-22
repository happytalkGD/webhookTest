<?php
/**
 * Test Jira API Connection
 */

// Load environment variables
$envFile = dirname(__FILE__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value, '"\''));
    }
}

$JIRA_BASE_URL = getenv('JIRA_BASE_URL') ?: 'https://blumnai.atlassian.net';
$JIRA_EMAIL = getenv('JIRA_EMAIL') ?: 'gd@blumn.ai';
$JIRA_API_TOKEN = getenv('JIRA_API_TOKEN') ?: 'your-api-token';

echo "=== Jira Connection Test ===\n\n";
echo "Configuration:\n";
echo "  Base URL: $JIRA_BASE_URL\n";
echo "  Email: $JIRA_EMAIL\n";
echo "  Token: " . (strlen($JIRA_API_TOKEN) > 10 ? substr($JIRA_API_TOKEN, 0, 4) . '...' . substr($JIRA_API_TOKEN, -4) : '***') . "\n\n";

// Test 1: Get current user info
echo "Test 1: Getting current user info...\n";
$url = $JIRA_BASE_URL . "/rest/api/2/myself";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Basic ' . base64_encode($JIRA_EMAIL . ':' . $JIRA_API_TOKEN),
    'Content-Type: application/json',
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "  HTTP Code: $httpCode\n";

if ($error) {
    echo "  ❌ CURL Error: $error\n";
} elseif ($httpCode == 200) {
    $data = json_decode($response, true);
    echo "  ✅ Success! Connected as: " . ($data['displayName'] ?? 'Unknown') . "\n";
    echo "  Email: " . ($data['emailAddress'] ?? 'Unknown') . "\n";
} else {
    echo "  ❌ Failed with HTTP $httpCode\n";
    $errorData = json_decode($response, true);
    if ($errorData) {
        echo "  Error: " . json_encode($errorData, JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "  Response: " . substr($response, 0, 200) . "\n";
    }
}

echo "\n";

// Test 2: Check if issue exists
$testTicket = 'P03-45';
echo "Test 2: Checking if issue $testTicket exists...\n";
$url = $JIRA_BASE_URL . "/rest/api/2/issue/{$testTicket}?fields=summary,status";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Basic ' . base64_encode($JIRA_EMAIL . ':' . $JIRA_API_TOKEN),
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "  HTTP Code: $httpCode\n";

if ($error) {
    echo "  ❌ CURL Error: $error\n";
} elseif ($httpCode == 200) {
    $data = json_decode($response, true);
    echo "  ✅ Issue found!\n";
    echo "  Summary: " . ($data['fields']['summary'] ?? 'Unknown') . "\n";
    echo "  Status: " . ($data['fields']['status']['name'] ?? 'Unknown') . "\n";
} elseif ($httpCode == 404) {
    echo "  ⚠️  Issue $testTicket not found\n";
} else {
    echo "  ❌ Failed with HTTP $httpCode\n";
    $errorData = json_decode($response, true);
    if ($errorData) {
        echo "  Error: " . json_encode($errorData, JSON_PRETTY_PRINT) . "\n";
    }
}

echo "\n=== Test Complete ===\n";