<?php
/**
 * Test directory initialization
 * This script tests the common directory initialization functionality
 */

// Include common library
require_once dirname(__FILE__) . '/common.lib.php';

echo "=== Directory Initialization Test ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Test 1: Initialize with verbose output
echo "Test 1: Initializing directories with verbose output...\n";
echo "------------------------------------------------\n";
$dirs = initializeEnvironment('test_script', true);

// Test 2: Display directory status
echo "\nTest 2: Display directory status...\n";
echo "------------------------------------------------\n";
displayDirectoryStatus();

// Test 3: Verify all directories exist
echo "Test 3: Verify all directories were created...\n";
echo "------------------------------------------------\n";
$allExists = true;
foreach ($dirs as $key => $path) {
    if ($key === 'base') continue;
    
    $exists = is_dir($path);
    $icon = $exists ? '✓' : '✗';
    echo "  {$icon} {$key}: {$path}\n";
    
    if (!$exists) {
        $allExists = false;
    }
}

echo "\n";
if ($allExists) {
    echo "✅ All directories successfully created!\n";
} else {
    echo "❌ Some directories were not created.\n";
}

// Test 4: Check permissions
echo "\nTest 4: Check directory permissions...\n";
echo "------------------------------------------------\n";
foreach ($dirs as $key => $path) {
    if ($key === 'base' || !is_dir($path)) continue;
    
    $perms = substr(sprintf('%o', fileperms($path)), -4);
    $isWritable = is_writable($path);
    $icon = $isWritable ? '✓' : '✗';
    echo "  {$icon} {$key}: {$perms} " . ($isWritable ? '(writable)' : '(not writable)') . "\n";
}

echo "\n=== Test Complete ===\n";