<?php
/**
 * Test Script for GitHub Webhook Processing
 * Simulates a GitHub push event without needing actual GitHub webhook
 */

// This script only tests webhook data saving
// Use test_analyze.php to test Claude analysis

// Test payload data (from actual GitHub webhook)
$testPayload = [
    "ref" => "refs/heads/master",
    "before" => "27bbccbae592b3965c89ee76e6d796f54698d7e2",
    "after" => "2847bc4f4ac9ee3fca1673ad632667f19353ac06",
    "repository" => [
        "id" => 1041887954,
        "node_id" => "R_kgDOPhny0g",
        "name" => "webhookTest",
        "full_name" => "happytalkGD/webhookTest",
        "private" => false,
        "owner" => [
            "name" => "happytalkGD",
            "email" => "87687912+happytalkGD@users.noreply.github.com",
            "login" => "happytalkGD",
            "id" => 87687912,
        ],
        "html_url" => "https://github.com/happytalkGD/webhookTest",
        "description" => "webhookTest",
        "clone_url" => "https://github.com/happytalkGD/webhookTest.git",
        "default_branch" => "main",
    ],
    "pusher" => [
        "name" => "happytalkGD",
        "email" => "87687912+happytalkGD@users.noreply.github.com"
    ],
    "commits" => [
        [
            "id" => "2847bc4f4ac9ee3fca1673ad632667f19353ac06",
            "tree_id" => "dcc9f9a2696bc0b7fac2b14e7b240f14df553704",
            "distinct" => true,
            "message" => "pull_request 이벤트 주석 수정: 불필요한 공백 제거",
            "timestamp" => "2025-08-21T18:25:14+09:00",
            "url" => "https://github.com/happytalkGD/webhookTest/commit/2847bc4f4ac9ee3fca1673ad632667f19353ac06",
            "author" => [
                "name" => "gd",
                "email" => "gd@blumn.ai"
            ],
            "committer" => [
                "name" => "gd",
                "email" => "gd@blumn.ai"
            ],
            "added" => [],
            "removed" => [],
            "modified" => ["github.hook.php"]
        ]
    ],
    "head_commit" => [
        "id" => "2847bc4f4ac9ee3fca1673ad632667f19353ac06",
        "message" => "pull_request 이벤트 주석 수정: 불필요한 공백 제거",
        "timestamp" => "2025-08-21T18:25:14+09:00",
        "author" => [
            "name" => "gd",
            "email" => "gd@blumn.ai"
        ],
        "added" => [],
        "removed" => [],
        "modified" => ["github.hook.php"]
    ]
];

// Test headers
$testHeaders = [
    'X-GitHub-Event' => 'push',
    'X-GitHub-Delivery' => 'test-' . uniqid(),
    'X-Hub-Signature' => 'sha1=test_signature',
    'X-Hub-Signature-256' => 'sha256=test_signature_256'
];

echo "=== GitHub Webhook Test Script ===\n";
echo "Purpose: Test webhook data saving\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

echo "Testing webhook data saving...\n\n";
    
    // Simulate the saving logic from github.hook.php
    $webhookDataDir = dirname(__FILE__) . '/webhook_data';
    
    // Check/create directory
    if (!is_dir($webhookDataDir)) {
        echo "Creating webhook_data directory: $webhookDataDir\n";
        if (!mkdir($webhookDataDir, 0777, true)) {
            echo "ERROR: Failed to create directory\n";
            exit(1);
        }
        chmod($webhookDataDir, 0777);
    } else {
        echo "Directory exists: $webhookDataDir\n";
    }
    
    // Create test webhook data
    $delivery = $testHeaders['X-GitHub-Delivery'];
    $safeDelivery = preg_replace('/[^a-zA-Z0-9_-]/', '', $delivery);
    $webhookFile = $webhookDataDir . '/push_' . date('Y-m-d_H-i-s') . '_' . $safeDelivery . '.json';
    
    $webhookData = [
        'event' => 'push',
        'delivery_id' => $delivery,
        'timestamp' => date('Y-m-d H:i:s'),
        'repository' => $testPayload['repository']['full_name'] ?? 'unknown',
        'branch' => str_replace('refs/heads/', '', $testPayload['ref'] ?? ''),
        'pusher' => $testPayload['pusher']['name'] ?? 'unknown',
        'commits_count' => count($testPayload['commits'] ?? []),
        'payload' => $testPayload
    ];
    
    echo "Saving webhook data to: $webhookFile\n";
    
    $jsonContent = json_encode($webhookData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if ($jsonContent === false) {
        echo "ERROR: Failed to encode JSON: " . json_last_error_msg() . "\n";
        exit(1);
    }
    
    $writeResult = file_put_contents($webhookFile, $jsonContent);
    
    if ($writeResult !== false) {
        echo "SUCCESS: File saved ($writeResult bytes)\n";
        echo "File: " . basename($webhookFile) . "\n\n";
        
        // Verify file exists
        if (file_exists($webhookFile)) {
            echo "VERIFIED: File exists\n";
            echo "Contents preview:\n";
            echo "---\n";
            $preview = json_decode(file_get_contents($webhookFile), true);
            echo "Event: " . $preview['event'] . "\n";
            echo "Repository: " . $preview['repository'] . "\n";
            echo "Branch: " . $preview['branch'] . "\n";
            echo "Pusher: " . $preview['pusher'] . "\n";
            echo "Commits: " . $preview['commits_count'] . "\n";
            echo "---\n\n";
        } else {
            echo "WARNING: File not found after writing\n";
        }
        
        // Also test Docker container if needed
        echo "Checking Docker container (if applicable):\n";
        $dockerCheck = shell_exec("docker exec docker-web-1 ls -la /var/www/html/webhook_data/ 2>&1 || echo 'Docker not available'");
        echo $dockerCheck . "\n";
        
    } else {
        echo "ERROR: Failed to save file\n";
        echo "Check permissions for: $webhookDataDir\n";
        $perms = substr(sprintf('%o', fileperms($webhookDataDir)), -4);
        echo "Current directory permissions: $perms\n";
    }

echo "\n=== Test Complete ===\n";

// Provide next steps
echo "\nNext steps:\n";
echo "1. Check the webhook_data directory for the saved file\n";
echo "2. Run 'php test_analyze.php' to test Claude analysis\n";
echo "3. Or run 'php claude.analyze.php' directly\n";

echo "\nUsage:\n";
echo "  php test_webhook.php     # Create test webhook data\n";
echo "  php test_analyze.php     # Test Claude analysis\n";