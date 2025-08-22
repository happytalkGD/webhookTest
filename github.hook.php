<?php
/**
 * GitHub Webhook Handler
 * Receives GitHub webhook events and logs them
 */

// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/logs/webhook_errors.log');

// Set response header
header('Content-Type: application/json');

try {
    // Get headers - handle both Apache and nginx
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        // Fallback for nginx
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headerName = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($name, 5)))));
                $headers[$headerName] = $value;
            }
        }
    }
    
    // Get GitHub specific headers
    $event = isset($headers['X-GitHub-Event']) ? $headers['X-GitHub-Event'] : 
             (isset($_SERVER['HTTP_X_GITHUB_EVENT']) ? $_SERVER['HTTP_X_GITHUB_EVENT'] : '');
    $signature256 = isset($headers['X-Hub-Signature-256']) ? $headers['X-Hub-Signature-256'] : 
                    (isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '');
    $signature = isset($headers['X-Hub-Signature']) ? $headers['X-Hub-Signature'] : 
                 (isset($_SERVER['HTTP_X_HUB_SIGNATURE']) ? $_SERVER['HTTP_X_HUB_SIGNATURE'] : '');
    $delivery = isset($headers['X-GitHub-Delivery']) ? $headers['X-GitHub-Delivery'] : 
                (isset($_SERVER['HTTP_X_GITHUB_DELIVERY']) ? $_SERVER['HTTP_X_GITHUB_DELIVERY'] : '');
    
    // Get raw POST data
    $rawPayload = file_get_contents('php://input');
    
    // Webhook secret (configured in GitHub webhook settings)
    $webhookSecret = 'test123';
    
    // Verify signature - GitHub may send either SHA1 or SHA256
    if ($webhookSecret && ($signature256 || $signature)) {
        $verified = false;
        
        // Try SHA256 first (preferred)
        if ($signature256) {
            $expectedSignature256 = 'sha256=' . hash_hmac('sha256', $rawPayload, $webhookSecret);
            if (hash_equals($expectedSignature256, $signature256)) {
                $verified = true;
            }
        }
        
        // Fall back to SHA1 if SHA256 didn't verify
        if (!$verified && $signature) {
            $expectedSignature = 'sha1=' . hash_hmac('sha1', $rawPayload, $webhookSecret);
            if (hash_equals($expectedSignature, $signature)) {
                $verified = true;
            }
        }
        
        if (!$verified) {
            error_log("Signature verification failed. Event: $event, Delivery: $delivery");
            http_response_code(401);
            echo json_encode(['error' => 'Invalid signature']);
            exit;
        }
    }

    // Parse JSON payload
    $payload = json_decode($rawPayload, true);
    
    // Check if JSON parsing was successful
    if ($rawPayload && json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON parsing failed: " . json_last_error_msg() . " for payload: " . substr($rawPayload, 0, 1000));
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload: ' . json_last_error_msg()]);
        exit;
    }
    
    // Create log directory if it doesn't exist
    $logDir = dirname(__FILE__) . '/logs';
    if (!is_dir($logDir)) {
        if (!mkdir($logDir, 0777, true)) {
            error_log("Failed to create log directory: $logDir");
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create log directory']);
            exit;
        }
    }

// Prepare log data
$logData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'event' => $event,
    'delivery_id' => $delivery,
    'headers' => [
        'X-GitHub-Event' => $event,
        'X-GitHub-Delivery' => $delivery,
        'X-Hub-Signature-256' => $signature256,
        'X-Hub-Signature' => $signature,
        'User-Agent' => isset($headers['User-Agent']) ? $headers['User-Agent'] : ''
    ],
    'payload' => $payload
];

// Log file name with date
$logFile = $logDir . '/webhook_' . date('Y-m-d') . '.log';

// Write to log file
$logEntry = date('Y-m-d H:i:s') . ' | ' . $event . ' | ' . $delivery . PHP_EOL;
$logEntry .= '----------------------------------------' . PHP_EOL;
$logEntry .= json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
$logEntry .= '========================================' . PHP_EOL . PHP_EOL;

if (file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to write log']);
    exit;
}

// Handle specific events
$response = ['status' => 'received'];

switch ($event) {
    case 'push':
        // Handle push event
        $response['message'] = 'Push event received';
        if (isset($payload['ref'])) {
            $response['branch'] = str_replace('refs/heads/', '', $payload['ref']);
        }
        if (isset($payload['commits'])) {
            $response['commits_count'] = count($payload['commits']);
            
            // Save webhook data for later analysis
            $webhookDataDir = dirname(__FILE__) . '/webhook_data';
            
            // Debug: Check directory creation
            if (!is_dir($webhookDataDir)) {
                error_log("Creating webhook_data directory: " . $webhookDataDir);
                if (!mkdir($webhookDataDir, 0777, true)) {
                    error_log("ERROR: Failed to create directory: " . $webhookDataDir);
                    $response['data_saved'] = false;
                    $response['error'] = 'Failed to create webhook_data directory';
                    break;
                }
                chmod($webhookDataDir, 0777);
            }
            
            // Create filename with timestamp and delivery ID (sanitize delivery ID)
            $safeDelivery = preg_replace('/[^a-zA-Z0-9_-]/', '', $delivery);
            $webhookFile = $webhookDataDir . '/push_' . date('Y-m-d_H-i-s') . '_' . $safeDelivery . '.json';
            
            error_log("Attempting to save webhook file: " . $webhookFile);
            
            $webhookData = [
                'event' => 'push',
                'delivery_id' => $delivery,
                'timestamp' => date('Y-m-d H:i:s'),
                'repository' => $payload['repository']['full_name'] ?? 'unknown',
                'branch' => str_replace('refs/heads/', '', $payload['ref'] ?? ''),
                'pusher' => $payload['pusher']['name'] ?? 'unknown',
                'commits_count' => count($payload['commits'] ?? []),
                'payload' => $payload
            ];
            
            $jsonContent = json_encode($webhookData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
            if ($jsonContent === false) {
                error_log("ERROR: Failed to encode JSON: " . json_last_error_msg());
                $response['data_saved'] = false;
                $response['error'] = 'Failed to encode webhook data as JSON';
            } else {
                $writeResult = file_put_contents($webhookFile, $jsonContent);
                
                if ($writeResult !== false) {
                    $response['data_saved'] = true;
                    $response['data_file'] = basename($webhookFile);
                    $response['file_size'] = $writeResult;
                    error_log("SUCCESS: Push event data saved to: " . $webhookFile . " (Size: " . $writeResult . " bytes)");
                    
                    // Verify file exists
                    if (file_exists($webhookFile)) {
                        error_log("VERIFIED: File exists at " . $webhookFile);
                    } else {
                        error_log("WARNING: File not found after writing: " . $webhookFile);
                    }
                } else {
                    $response['data_saved'] = false;
                    $response['error'] = 'Failed to write webhook data to file';
                    error_log("ERROR: Failed to save push event data to: " . $webhookFile);
                    error_log("ERROR: Check permissions for directory: " . $webhookDataDir);
                }
            }
        }
        break;
        
    case 'pull_request':
        // Handle pull request event  
        $response['message'] = 'Pull request event received';
        if (isset($payload['action'])) {
            $response['action'] = $payload['action'];
        }
        break;
        
    case 'ping':
        // GitHub sends a ping event when webhook is first set up
        $response['message'] = 'Pong! Webhook is configured correctly';
        break;
        
    default:
        $response['message'] = 'Event received: ' . $event;
        break;
}

    // Optional: Log summary to separate file for quick monitoring
    $summaryFile = $logDir . '/summary.log';
    $summaryEntry = date('Y-m-d H:i:s') . ' | ' . $event . ' | ' . $delivery;
    if (isset($response['branch'])) {
        $summaryEntry .= ' | Branch: ' . $response['branch'];
    }
    if (isset($response['commits_count'])) {
        $summaryEntry .= ' | Commits: ' . $response['commits_count'];
    }
    $summaryEntry .= PHP_EOL;
    file_put_contents($summaryFile, $summaryEntry, FILE_APPEND | LOCK_EX);
    
    // Return success response
    http_response_code(200);
    echo json_encode($response);
    
} catch (Exception $e) {
    // Log the error
    error_log("Webhook handler error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}


