<?php
/**
 * Test Script for Claude Analysis
 * Tests the Claude analysis functionality separately
 */

echo "=== Claude Analysis Test Script ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Check if claude.analyze.php exists
$analyzeScript = dirname(__FILE__) . '/claude.analyze.php';
if (!file_exists($analyzeScript)) {
    echo "ERROR: claude.analyze.php not found at: $analyzeScript\n";
    exit(1);
}

// Check webhook_data directory
$webhookDataDir = dirname(__FILE__) . '/webhook_data';
if (!is_dir($webhookDataDir)) {
    echo "ERROR: webhook_data directory not found\n";
    echo "Run test_webhook.php first to create test data\n";
    exit(1);
}

// List available files to process
echo "Checking webhook_data directory...\n";
$files = glob($webhookDataDir . '/*.json');

if (empty($files)) {
    echo "No webhook files found to process.\n";
    echo "Run test_webhook.php first to create test data\n";
    exit(1);
}

echo "Found " . count($files) . " webhook file(s):\n";
foreach ($files as $file) {
    $size = filesize($file);
    echo "  - " . basename($file) . " ({$size} bytes)\n";
}
echo "\n";

// Run the Claude analysis
echo "Running Claude analysis processor...\n";
echo "Command: php $analyzeScript\n";
echo "----------------------------------------\n\n";

$output = [];
$returnCode = 0;
exec('php ' . $analyzeScript . ' 2>&1', $output, $returnCode);

// Display output
foreach ($output as $line) {
    echo $line . "\n";
}

echo "\n----------------------------------------\n";
echo "Analysis complete. Return code: $returnCode\n\n";

// Check results
echo "Checking results...\n\n";

// Check analysis directory
$analysisDir = dirname(__FILE__) . '/analysis';
if (is_dir($analysisDir)) {
    echo "Analysis directory:\n";
    $analysisFiles = glob($analysisDir . '/*.md');
    if (!empty($analysisFiles)) {
        echo "  Found " . count($analysisFiles) . " analysis report(s):\n";
        // Show last 3 files
        $recentFiles = array_slice($analysisFiles, -3);
        foreach ($recentFiles as $file) {
            $size = filesize($file);
            $mtime = date('Y-m-d H:i:s', filemtime($file));
            echo "  - " . basename($file) . " ({$size} bytes, modified: {$mtime})\n";
        }
        
        // Show preview of latest file
        if (!empty($recentFiles)) {
            $latestFile = end($recentFiles);
            echo "\n  Preview of latest report (" . basename($latestFile) . "):\n";
            echo "  ----------------------------------------\n";
            $content = file_get_contents($latestFile);
            $lines = explode("\n", $content);
            $preview = array_slice($lines, 0, 20);
            foreach ($preview as $line) {
                echo "  " . $line . "\n";
            }
            if (count($lines) > 20) {
                echo "  ... (" . (count($lines) - 20) . " more lines)\n";
            }
            echo "  ----------------------------------------\n";
        }
    } else {
        echo "  No analysis reports found\n";
    }
} else {
    echo "Analysis directory not found\n";
}

echo "\n";

// Check processed directory
$processedDir = dirname(__FILE__) . '/processed_webhooks';
if (is_dir($processedDir)) {
    echo "Processed webhooks directory:\n";
    $processedFiles = glob($processedDir . '/*.json');
    echo "  Found " . count($processedFiles) . " processed file(s)\n";
    if (!empty($processedFiles)) {
        // Show last 3 files
        $recentProcessed = array_slice($processedFiles, -3);
        foreach ($recentProcessed as $file) {
            $size = filesize($file);
            echo "  - " . basename($file) . " ({$size} bytes)\n";
        }
    }
} else {
    echo "Processed webhooks directory not found\n";
}

echo "\n";

// Check logs
$logsDir = dirname(__FILE__) . '/logs';
if (is_dir($logsDir)) {
    echo "Recent log entries:\n";
    $errorLog = $logsDir . '/analysis_errors.log';
    if (file_exists($errorLog)) {
        $logContent = shell_exec("tail -5 '$errorLog' 2>/dev/null");
        if ($logContent) {
            echo "  From analysis_errors.log:\n";
            $lines = explode("\n", trim($logContent));
            foreach ($lines as $line) {
                if (!empty($line)) {
                    echo "  " . $line . "\n";
                }
            }
        }
    } else {
        echo "  No error log found\n";
    }
}

echo "\n=== Test Complete ===\n\n";

// Provide summary
if ($returnCode === 0) {
    echo "✓ Analysis executed successfully\n";
} else {
    echo "✗ Analysis failed with return code: $returnCode\n";
}

echo "\nTips:\n";
echo "- Check /analysis/ directory for generated reports\n";
echo "- Check /processed_webhooks/ for processed files\n";
echo "- Check /logs/analysis_errors.log for any errors\n";
echo "- Run 'php test_webhook.php' to create more test data\n";