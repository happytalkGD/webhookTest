<?php
/**
 * Jira Integration Hook
 * Reads analysis files and posts comments to Jira issues based on branch names
 */

// 필요한 PHP 확장 모듈 확인
if (!extension_loaded('curl')) {
    echo "ERROR: PHP curl extension is not installed.\n";
    echo "To install on Ubuntu/Debian: sudo apt-get install php-curl\n";
    echo "To install on CentOS/RHEL: sudo yum install php-curl\n";
    echo "After installation, restart your web server or PHP service.\n\n";
    echo "Alternative: Use the jira_integration.sh script instead.\n";
    exit(1);
}

// 공통 라이브러리 포함
require_once dirname(__FILE__) . '/common.lib.php';

// 환경 초기화 (첫 실행 시 --init-dirs 옵션으로 디렉토리 상태 표시)
$showDirInit = isset($argv[1]) && $argv[1] === '--init-dirs';
$dirs = initializeEnvironment('jira_hook', $showDirInit);

// 설정 - 환경변수에서 가져오거나 기본값 사용
define('JIRA_BASE_URL', getConfig('JIRA_BASE_URL', 'https://your-domain.atlassian.net'));
define('JIRA_EMAIL', getConfig('JIRA_EMAIL', 'your-email@example.com'));
define('JIRA_API_TOKEN', getConfig('JIRA_API_TOKEN', 'your-api-token'));

// 디렉토리 설정
$analysisDir = $dirs['pending_analysis'];
$processedJiraDir = $dirs['processed_jira'];
$logsDir = $dirs['logs'];

// extractJiraTicketId 함수는 이제 common.lib.php에 있음

/**
 * 분석 마크다운 파일 파싱
 * 메타데이터와 분석 내용을 추출하여 구조화된 배열로 반환
 */
function parseAnalysisFile($filepath) {
    if (!file_exists($filepath)) {
        return null;
    }
    
    $content = file_get_contents($filepath);
    $data = [];
    
    // 정규표현식을 사용하여 메타데이터 추출
    if (preg_match('/\*\*Generated\*\*:\s*(.+)/', $content, $matches)) {
        $data['generated'] = trim($matches[1]);
    }
    
    if (preg_match('/\*\*Repository\*\*:\s*(.+)/', $content, $matches)) {
        $data['repository'] = trim($matches[1]);
        // 이스케이프된 문자 정리
        $data['repository'] = str_replace('\\/', '/', $data['repository']);
    }
    
    if (preg_match('/\*\*Branch\*\*:\s*(.+)/', $content, $matches)) {
        $data['branch'] = trim($matches[1]);
    }
    
    if (preg_match('/\*\*Pusher\*\*:\s*(.+)/', $content, $matches)) {
        $data['pusher'] = trim($matches[1]);
    }
    
    if (preg_match('/\*\*Commits\*\*:\s*(.+)/', $content, $matches)) {
        $data['commits'] = trim($matches[1]);
    }
    
    // 커밋 상세 섹션에서 커밋 메시지 추출
    if (preg_match('/## Commit Details\s*\n(.+?)(?=\n---|\n## |$)/s', $content, $matches)) {
        $commitSection = $matches[1];
        // 모든 커밋 메시지 찾기 (### 번호. 메시지 패턴 이후)
        if (preg_match_all('/###\s*\d+\.\s*(.+)/', $commitSection, $commitMatches)) {
            $data['commit_messages'] = $commitMatches[1];
        }
    }
    
    // 분석 섹션 추출
    // "## Claude AI Analysis" 또는 "## Analysis Results" 이후의 모든 분석 내용 추출
    // 파일 끝 또는 분석 푸터까지의 내용 검색
    if (preg_match('/##\s*(Claude AI Analysis|Analysis Results)\s*\n(.+?)(?=\n\*Analysis performed|\Z)/s', $content, $matches)) {
        // 캐처된 컨텐츠에 중간 --- 구분자가 포함될 수 있으므로 정리
        $analysisContent = trim($matches[2]);
        
        // 컨텐츠가 영어 텍스트로 시작하고 구분자가 있으면, 그 이후의 한국어 컨텐츠 추출
        if (strpos($analysisContent, 'Based on my analysis') === 0 && strpos($analysisContent, '---') !== false) {
            // 첫 번째 --- 구분자 이후의 컨텐츠 추출
            $parts = explode('---', $analysisContent, 2);
            if (count($parts) > 1) {
                $analysisContent = trim($parts[1]);
            }
        }
        
        $data['full_analysis'] = $analysisContent;
        
        // 하위 호환성을 위해 개별 섹션도 추출
        if (preg_match('/📌\s*\*\*주요 변경사항\*\*:\s*(.+?)(?=\n\n|📁|$)/s', $data['full_analysis'], $subMatches)) {
            $data['main_changes'] = trim($subMatches[1]);
        }
        
        if (preg_match('/📁\s*\*\*영향받는 모듈\*\*:\s*(.+?)(?=\n\n|🎯|$)/s', $data['full_analysis'], $subMatches)) {
            $data['affected_modules'] = trim($subMatches[1]);
        }
        
        if (preg_match('/🎯\s*\*\*변경 목적\*\*:\s*(.+?)(?=\n\n|🔍|$)/s', $data['full_analysis'], $subMatches)) {
            $data['purpose'] = trim($subMatches[1]);
        }
        
        if (preg_match('/🔍\s*\*\*코드 리뷰 포인트\*\*:\s*(.+?)$/s', $data['full_analysis'], $subMatches)) {
            $data['review_points'] = trim($subMatches[1]);
        }
    }
    
    return $data;
}

/**
 * 마크다운을 Jira 마크업으로 변환
 * GitHub 형식 마크다운을 Atlassian Jira 형식으로 변환
 */
function markdownToJira($text) {
    // Headers - Process 5-level headers first, then 4-level, treating numbered h4 as bold items
    // Convert ##### text to h5. text (5-level headers)
    $text = preg_replace('/^#####\s+(.+)$/m', 'h5. $1', $text);
    // Convert #### 1. **text** to *1. text* (bold numbered item)
    $text = preg_replace('/^####\s+(\d+\.)\s+\*\*(.+?)\*\*$/m', '*$1 $2*', $text);
    $text = preg_replace('/^####\s+(.+)$/m', 'h4. $1', $text);
    $text = preg_replace('/^###\s+(.+)$/m', 'h3. $1', $text);
    $text = preg_replace('/^##\s+(.+)$/m', 'h2. $1', $text);
    $text = preg_replace('/^#\s+(.+)$/m', 'h1. $1', $text);
    
    // Bold - **text** or __text__ to *text*
    $text = preg_replace('/\*\*(.+?)\*\*/', '*$1*', $text);
    $text = preg_replace('/__(.+?)__/', '*$1*', $text);
    
    // Italic - *text* or _text_ to _text_
    // Be careful not to affect already converted bold
    $text = preg_replace('/(?<!\*)\*(?!\*)([^*]+?)(?<!\*)\*(?!\*)/', '_$1_', $text);
    $text = preg_replace('/(?<!_)_(?!_)([^_]+?)(?<!_)_(?!_)/', '_$1_', $text);
    
    // Code blocks - ```code``` to {code}code{code}
    $text = preg_replace('/```([^`]+?)```/s', '{code}$1{code}', $text);
    
    // Inline code - `code` to {{code}}
    $text = preg_replace('/`([^`]+?)`/', '{{$1}}', $text);
    
    // Clean up excessive line breaks first
    $text = preg_replace('/\n{3,}/', "\n\n", $text);  // Replace 3+ newlines with 2
    
    // 이모지가 포함된 리스트
    $text = preg_replace('/^- 📌 (.+)$/m', '* (!) $1', $text);  // 중요
    $text = preg_replace('/^- 📁 (.+)$/m', '* (i) $1', $text);  // 정보
    $text = preg_replace('/^- 🎯 (.+)$/m', '* (/) $1', $text);  // 체크
    $text = preg_replace('/^- 🔍 (.+)$/m', '* (?) $1', $text);  // 질문
    
    // Regular lists - * or - to *
    $text = preg_replace('/^[\*\-]\s+(.+)$/m', '* $1', $text);
    
    // Remove standalone list markers FIRST before converting numbered lists
    $text = preg_replace('/^\d+\.\s*$/m', '', $text);  // Remove "1." etc on its own
    $text = preg_replace('/^[a-z]\.\s*$/m', '', $text);  // Remove "a." etc on its own
    $text = preg_replace('/^[ivx]+\.\s*$/m', '', $text);  // Remove "i.", "ii.", etc on its own
    
    // Now convert numbered lists - only with content
    $text = preg_replace('/^\d+\.\s+([^\s].+)$/m', '# $1', $text);
    
    // Links - [text](url) to [text|url]
    $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '[$1|$2]', $text);
    
    // Horizontal rules - --- to ----
    $text = preg_replace('/^---+$/m', '----', $text);
    
    // Blockquotes - > text to {quote}text{quote}
    $lines = explode("\n", $text);
    $inQuote = false;
    $result = [];
    
    foreach ($lines as $line) {
        if (strpos($line, '> ') === 0) {
            if (!$inQuote) {
                $result[] = '{quote}';
                $inQuote = true;
            }
            $result[] = substr($line, 2);
        } else {
            if ($inQuote) {
                $result[] = '{quote}';
                $inQuote = false;
            }
            $result[] = $line;
        }
    }
    if ($inQuote) {
        $result[] = '{quote}';
    }
    
    $text = implode("\n", $result);
    
    // Tables (simple conversion)
    $text = preg_replace('/\|(.+)\|/', '||$1||', $text);
    
    // Emojis in headers to Jira icons
    $text = str_replace('📌', '(!)', $text);
    $text = str_replace('📁', '(i)', $text);
    $text = str_replace('🎯', '(/)', $text);
    $text = str_replace('🔍', '(?)', $text);
    $text = str_replace('📊', '(*)', $text);
    $text = str_replace('🔄', '(on)', $text);
    $text = str_replace('✅', '(/)', $text);
    $text = str_replace('❌', '(x)', $text);
    $text = str_replace('⚠️', '(!)', $text);
    
    // Final cleanup - remove empty lines created by removing standalone list markers
    $text = preg_replace('/\n{3,}/', "\n\n", $text);  // Replace 3+ newlines with 2
    $text = preg_replace('/\n\s*\n/', "\n\n", $text); // Clean up lines with only whitespace
    
    return $text;
}

/**
 * 분석 데이터로부터 Jira 댓글 내용 생성
 * 분석 데이터를 Jira 형식으로 포맷팅하고 오류 검사 수행
 */
function createJiraComment($analysisData) {
    // Check for Claude error patterns in the analysis content
    $fullContent = isset($analysisData['full_analysis']) ? $analysisData['full_analysis'] : '';
    
    if (checkIgnoreText($fullContent)) {
        echo "  ❌ Claude error detected in analysis content\n";
        return false;
    }

    // Build comment in Markdown first
    $markdown = "{$analysisData['repository']}:{$analysisData['branch']} / {$analysisData['pusher']} {$analysisData['generated']}\n\n";
    $markdown .= "---\n\n";
    
    // Use full analysis if available
    if (isset($analysisData['full_analysis']) && !empty($analysisData['full_analysis'])) {
        $markdown .= $analysisData['full_analysis'] . "\n\n";
    } else {
        // Fallback to individual sections
        if (isset($analysisData['main_changes'])) {
            $markdown .= "## 📌 주요 변경사항\n";
            $markdown .= "{$analysisData['main_changes']}\n\n";
        }
        
        if (isset($analysisData['affected_modules'])) {
            $markdown .= "## 📁 영향받는 모듈\n";
            $markdown .= "{$analysisData['affected_modules']}\n\n";
        }
        
        if (isset($analysisData['purpose'])) {
            $markdown .= "## 🎯 변경 목적\n";
            $markdown .= "{$analysisData['purpose']}\n\n";
        }
        
        if (isset($analysisData['review_points'])) {
            $markdown .= "## 🔍 코드 리뷰 포인트\n";
            $markdown .= "{$analysisData['review_points']}\n\n";
        }
    }
    
    // Convert Markdown to Jira format
    return markdownToJira($markdown);
}

/**
 * 공통 Jira API 호출 함수
 * 모든 Jira API 호출을 처리하는 통합 함수
 * 
 * @param string $endpoint API 엔드포인트 (예: /rest/api/2/issue/{ticketId})
 * @param string $method HTTP 메소드 (GET, POST, PUT)
 * @param array|null $data 전송할 데이터 (POST/PUT인 경우)
 * @param bool $verbose 디버그 출력 여부
 * @return array ['success' => bool, 'response' => mixed, 'http_code' => int, 'error' => string]
 */
function executeJiraApiCall($endpoint, $method = 'GET', $data = null, $verbose = false) {
    $url = JIRA_BASE_URL . $endpoint;
    
    if ($verbose) {
        echo "  API URL: {$url}\n";
        echo "  Method: {$method}\n";
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode(JIRA_EMAIL . ':' . JIRA_API_TOKEN),
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    // 메소드별 설정
    switch($method) {
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
            break;
        case 'PUT':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            break;
        // GET은 기본값이므로 추가 설정 불필요
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $curlInfo = curl_getinfo($ch);
    curl_close($ch);
    
    if ($verbose) {
        echo "  HTTP Response Code: {$httpCode}\n";
        echo "  Response Time: " . round($curlInfo['total_time'], 2) . "s\n";
    }
    
    // 에러 처리
    if ($error) {
        if ($verbose) {
            echo "  ❌ CURL Error: {$error}\n";
        }
        return ['success' => false, 'error' => $error, 'http_code' => 0];
    }
    
    // 성공 응답 (2xx)
    if ($httpCode >= 200 && $httpCode < 300) {
        $responseData = null;
        if (!empty($response)) {
            $responseData = json_decode($response, true);
        }
        return ['success' => true, 'response' => $responseData, 'http_code' => $httpCode];
    }
    
    // 실패 응답
    $errorMessage = "HTTP {$httpCode}";
    if (!empty($response)) {
        $errorData = json_decode($response, true);
        if ($errorData) {
            if (isset($errorData['errorMessages'])) {
                $errorMessage .= ": " . implode(', ', $errorData['errorMessages']);
            }
            if (isset($errorData['errors'])) {
                $errorDetails = [];
                foreach ($errorData['errors'] as $field => $error) {
                    $errorDetails[] = "{$field}: {$error}";
                }
                if (!empty($errorDetails)) {
                    $errorMessage .= " (" . implode(', ', $errorDetails) . ")";
                }
            }
        }
    }
    
    return ['success' => false, 'error' => $errorMessage, 'response' => $response, 'http_code' => $httpCode];
}

/**
 * 텍스트에 무시해야 할 문구가 포함되어 있는지 확인
 * Claude 실행 오류 등을 감지하여 Jira 전송을 방지
 */
function checkIgnoreText($text) {
    $ignoreTexts = [
        'Execution error',
        'Error executing Claude command',
        'Claude return code',
        'Claude analysis failed',
        'Claude token limit exceeded',
        'Content too long for Claude analysis',
    ];

    foreach ($ignoreTexts as $ignoreText) {
        if (strpos($text, $ignoreText) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Jira 이슈 상세 정보 조회
 * 이슈의 설명(description)과 요약(summary) 필드를 가져옴
 */
function getJiraIssue($ticketId) {
    $endpoint = "/rest/api/2/issue/{$ticketId}?fields=description,summary";
    $result = executeJiraApiCall($endpoint, 'GET');
    
    if (!$result['success']) {
        return null;
    }
    
    return $result['response'];
}

/**
 * Jira 이슈 설명(description) 업데이트
 * 이슈의 설명 필드가 비어있을 때 분석 내용으로 채움
 */
function updateJiraDescription($ticketId, $description) {
    echo "  이슈 설명 업데이트 중...\n";
    
    $endpoint = "/rest/api/2/issue/{$ticketId}";
    $data = [
        'fields' => [
            'description' => $description
        ]
    ];
    
    $result = executeJiraApiCall($endpoint, 'PUT', $data);
    
    if ($result['success']) {
        echo "  ✅ 설명이 성공적으로 업데이트되었습니다\n";
        return true;
    } else {
        echo "  ❌ 설명 업데이트 실패: {$result['error']}\n";
        return false;
    }
}

/**
 * Jira에 댓글 전송
 * REST API v2를 사용하여 이슈에 댓글 추가
 */
function sendJiraComment($ticketId, $comment) {
    echo "  Jira에 댓글 전송 중...\n";
    echo "  사용 중인 자격 증명: " . JIRA_EMAIL . "\n";
    echo "  API 토큰: " . (strlen(JIRA_API_TOKEN) > 10 ? substr(JIRA_API_TOKEN, 0, 4) . '...' . substr(JIRA_API_TOKEN, -4) : '***') . "\n";
    
    $endpoint = "/rest/api/2/issue/{$ticketId}/comment";
    $data = ['body' => $comment];
    
    $result = executeJiraApiCall($endpoint, 'POST', $data, true);
    
    if ($result['success']) {
        echo "  ✅ 성공! Jira에 댓글이 게시되었습니다\n";
        if (isset($result['response']['id'])) {
            echo "  댓글 ID: " . $result['response']['id'] . "\n";
        }
        return $result;
    } else {
        echo "  ❌ 실패: {$result['error']}\n";
        error_log("Jira API Error for ticket {$ticketId}: " . $result['error']);
        return $result;
    }
}

/**
 * 단일 분석 파일 처리
 * 분석 파일을 읽어 Jira 티켓 ID를 추출하고 해당 이슈에 게시
 */
function processAnalysisFile($filepath, $debugMode = false) {
    $filename = basename($filepath);
    echo "Processing: {$filename}\n";
    
    if ($debugMode) {
        echo "  🔍 DEBUG MODE - Will not send to Jira\n";
    }
    
    // Parse the analysis file
    $analysisData = parseAnalysisFile($filepath);
    
    if (!$analysisData || !isset($analysisData['branch'])) {
        echo "  ⚠️  Could not parse analysis file or branch not found\n";
        return false;
    }
    
    // 분석 결과에 실행 오류가 있는지 확인
    // 코드 분석의 문자열이 아닌 실제 Claude 실행 오류 확인
    $fileContent = file_get_contents($filepath);
    // Claude CLI 오류 패턴 확인 - 단순 "Execution error"보다 구체적으로

    if (checkIgnoreText($fileContent)) {
        echo "  ❌ CLAUDE EXECUTION ERROR DETECTED in analysis file!\n";
        echo "  ⚠️  Analysis contains Claude execution errors - skipping Jira posting\n";
        echo "  📝 Please check the analysis file for errors:\n";
        echo "     " . basename($filepath) . "\n";
        
        // processed 대신 error 디렉토리로 이동
        global $logsDir;
        $errorDir = dirname($filepath) . '/../error_analysis';
        if (!is_dir($errorDir)) {
            mkdir($errorDir, 0777, true);
        }
        
        $errorFile = $errorDir . '/' . basename($filepath);
        if (rename($filepath, $errorFile)) {
            echo "  📁 Moved to error directory for review\n";
        }
        
        // 오류 로그 기록
        $logEntry = date('Y-m-d H:i:s') . " | ERROR | Claude execution error in analysis | " . basename($filepath) . "\n";
        file_put_contents($logsDir . '/jira_errors.log', $logEntry, FILE_APPEND);
        
        return false;
    }
    
    echo "  Branch: {$analysisData['branch']}\n";
    
    // 먼저 커밋 메시지에서 Jira 티켓 ID 추출 시도
    $ticketId = null;
    
    if (isset($analysisData['commit_messages']) && is_array($analysisData['commit_messages'])) {
        echo "  Checking commit messages for Jira ticket ID...\n";
        
        foreach ($analysisData['commit_messages'] as $commitMessage) {
            $ticketId = extractJiraTicketId($commitMessage);
            if ($ticketId) {
                echo "  Found in commit: \"{$commitMessage}\"\n";
                break;
            }
        }
    }
    
    // 커밋에서 찾지 못했으면 브랜치 이름에서 시도
    if (!$ticketId) {
        echo "  Checking branch name for Jira ticket ID...\n";
        $ticketId = extractJiraTicketId($analysisData['branch']);
        if ($ticketId) {
            echo "  Found in branch: {$analysisData['branch']}\n";
        }
    }
    
    if (!$ticketId) {
        echo "  ℹ️  No Jira ticket ID found in commit messages or branch name\n";
        return false;
    }
    
    echo "  Jira Ticket: {$ticketId}\n";
    
    // Jira 자격 증명이 설정되었는지 확인 (디버그 모드에서는 건너뛰기)
    if (!$debugMode && (JIRA_BASE_URL === 'https://your-domain.atlassian.net' || 
        JIRA_EMAIL === 'your-email@example.com' || 
        JIRA_API_TOKEN === 'your-api-token')) {
        echo "  ⚠️  Jira credentials not configured. Please update the configuration.\n";
        
        // 테스트를 위해 전송될 내용 저장
        global $processedJiraDir;
        $testFile = $processedJiraDir . '/test_' . $ticketId . '_' . date('Y-m-d_H-i-s') . '.txt';
        $comment = createJiraComment($analysisData);
        file_put_contents($testFile, "Ticket: {$ticketId}\n\n" . $comment);
        echo "  💾 Test comment saved to: " . basename($testFile) . "\n";
        
        return false;
    }
    
    // Jira용 컨텐츠 생성
    $content = createJiraComment($analysisData);
    
    // 컨텐츠 생성 실패 확인 (Claude 오류 감지)
    if ($content === false) {
        echo "  ⚠️ Claude error detected in analysis content - skipping Jira posting\n";
        
        // error 디렉토리로 이동
        global $logsDir;
        $errorDir = dirname($filepath) . '/../error_analysis';
        if (!is_dir($errorDir)) {
            mkdir($errorDir, 0777, true);
        }
        
        $errorFile = $errorDir . '/' . basename($filepath);
        if (rename($filepath, $errorFile)) {
            echo "  📁 Moved to error directory for review\n";
        }
        
        // 오류 로그 기록
        $logEntry = date('Y-m-d H:i:s') . " | ERROR | Claude error in content | " . basename($filepath) . "\n";
        file_put_contents($logsDir . '/jira_errors.log', $logEntry, FILE_APPEND);
        
        return false;
    }
    
    // 컨텐츠 표시
    echo "  === Content " . ($debugMode ? "(DEBUG MODE - NOT SENT)" : "to send") . " ===\n";
    echo "  " . str_replace("\n", "\n  ", substr($content, 0, 500)) . "\n";
    if (strlen($content) > 500) {
        echo "  ... (+" . (strlen($content) - 500) . " more characters)\n";
    }
    echo "  === End of content preview ===\n";
    
    // 디버그 모드에서는 실제 Jira API 호출 건너뛰기
    if ($debugMode) {
        echo "  📝 DEBUG: Skipping Jira API calls\n";
        echo "  📋 Full content length: " . strlen($content) . " characters\n";
        
        // 디버그 출력을 파일로 저장
        global $processedJiraDir, $logsDir;
        $debugFile = $processedJiraDir . '/debug_' . $ticketId . '_' . date('Y-m-d_H-i-s') . '.txt';
        file_put_contents($debugFile, "=== DEBUG OUTPUT ===\n");
        file_put_contents($debugFile, "Ticket: {$ticketId}\n", FILE_APPEND);
        file_put_contents($debugFile, "Repository: {$analysisData['repository']}\n", FILE_APPEND);
        file_put_contents($debugFile, "Branch: {$analysisData['branch']}\n", FILE_APPEND);
        file_put_contents($debugFile, "Pusher: {$analysisData['pusher']}\n", FILE_APPEND);
        file_put_contents($debugFile, "Generated: {$analysisData['generated']}\n\n", FILE_APPEND);
        file_put_contents($debugFile, "=== JIRA CONTENT ===\n", FILE_APPEND);
        file_put_contents($debugFile, $content, FILE_APPEND);
        echo "  💾 Debug output saved to: " . basename($debugFile) . "\n";
        
        // processed 디렉토리로 이동
        moveToProcessed($filepath, $processedJiraDir);
        
        // 디버그로 로그 기록
        $logEntry = date('Y-m-d H:i:s') . " | DEBUG | {$ticketId} | {$filename} | debug_mode\n";
        file_put_contents($logsDir . '/jira_success.log', $logEntry, FILE_APPEND);
        
        return true;
    }
    
    // 이슈 존재 여부 확인 및 상세 정보 조회
    echo "  Jira 이슈 상세 정보 확인 중...\n";
    $issue = getJiraIssue($ticketId);
    
    if (!$issue) {
        echo "  ❌ Could not retrieve issue details. The issue might not exist.\n";
        return false;
    }
    
    // 설명(description) 필드가 비어있는지 확인
    $currentDescription = $issue['fields']['description'] ?? '';
    $isDescriptionEmpty = empty(trim($currentDescription));
    
    if ($isDescriptionEmpty) {
        echo "  📝 설명이 비어있습니다. 댓글 대신 설명을 업데이트합니다.\n";
        $result = updateJiraDescription($ticketId, $content);
        
        if ($result) {
            echo "  ✅ Description updated successfully for {$ticketId}\n";
            $actionType = 'description_updated';
        } else {
            echo "  ❌ Failed to update description\n";
            return false;
        }
    } else {
        echo "  💬 설명이 존재합니다. 댓글로 추가합니다.\n";
        echo "  현재 설명 길이: " . strlen($currentDescription) . " 문자\n";
        
        // 댓글로 전송
        echo "  Jira에 댓글 전송 중...\n";
        $result = sendJiraComment($ticketId, $content);
        
        if ($result['success']) {
            echo "  ✅ Comment posted successfully to {$ticketId}\n";
            $actionType = 'comment_added';
        } else {
            echo "  ❌ Failed to post comment: {$result['error']}\n";
            return false;
        }
    }
    
    // 처리된 파일 이동
    global $processedJiraDir, $logsDir;
    moveToProcessed($filepath, $processedJiraDir);
    
    // 성공 로그 기록
    $logEntry = date('Y-m-d H:i:s') . " | SUCCESS | {$ticketId} | {$filename} | {$actionType}\n";
    file_put_contents($logsDir . '/jira_success.log', $logEntry, FILE_APPEND);
    
    return true;
}

/**
 * 메인 처리 함수
 * pending_analysis 디렉토리의 모든 분석 파일을 처리
 */
function processAnalysisFiles($debugMode = false) {
    global $analysisDir;
    
    // 분석 디렉토리의 모든 마크다운 파일 가져오기
    $files = glob($analysisDir . '/*.md');
    
    if (empty($files)) {
        echo "No analysis files to process.\n";
        return;
    }
    
    echo "Found " . count($files) . " analysis file(s)\n\n";
    
    $processed = 0;
    $failed = 0;
    
    foreach ($files as $file) {
        if (processAnalysisFile($file, $debugMode)) {
            $processed++;
        } else {
            $failed++;
        }
        echo "\n";
    }
    
    echo "=== Summary ===\n";
    echo "Processed: {$processed}\n";
    echo "Failed/Skipped: {$failed}\n";
}

// 스크립트가 커맨드 라인에서 실행되었는지 확인
if (isCliMode()) {
    // 디버그 모드 확인
    $debugMode = false;
    if (isset($argv[1]) && ($argv[1] === '--debug' || $argv[1] === '-d')) {
        $debugMode = true;
        echo "=== Jira Integration Hook (DEBUG MODE) ===\n";
    } else {
        echo "=== Jira Integration Hook ===\n";
    }
    echo "Time: " . date('Y-m-d H:i:s') . "\n\n";
    
    // 잠금 관리자 생성
    $lockManager = new LockManager('jira_hook');
    
    // 잠금 획듍 시도
    if (!$lockManager->acquireLock()) {
        exit(0);
    }
    
    // 설정 확인 (디버그 모드에서는 경고 건너뛰기)
    if (!$debugMode && JIRA_BASE_URL === 'https://your-domain.atlassian.net') {
        displayMessage("WARNING: Jira configuration not set!", 'warning');
        echo "Please update the following constants in .env file:\n";
        echo "  - JIRA_BASE_URL\n";
        echo "  - JIRA_EMAIL\n";
        echo "  - JIRA_API_TOKEN\n\n";
        echo "Continuing in test mode...\n\n";
    }
    
    if ($debugMode) {
        echo "🔍 DEBUG MODE ACTIVE\n";
        echo "  - Content will be displayed but NOT sent to Jira\n";
        echo "  - Files will be moved to processed_jira directory\n";
        echo "  - Debug output will be saved to processed_jira/debug_*.txt\n\n";
    }
    
    processAnalysisFiles($debugMode);
    
    echo "\nFinished at: " . date('Y-m-d H:i:s') . "\n";
} else {
    // 웹으로 접근한 경우 JSON 응답 반환
    jsonResponse([
        'error' => 'This script must be run from command line',
        'usage' => 'php jira.hook.php'
    ], 403);
}