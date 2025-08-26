# GitHub Webhook Automation System

GitHub 푸시 이벤트를 자동으로 분석하고 Jira 티켓에 댓글을 추가하는 자동화 시스템입니다.

## 시스템 구성

```
GitHub Push → github.hook.php → claude.analyze.php → jira.hook.php → Jira Issue
```

1. **github.hook.php**: GitHub 웹훅을 받아서 `pending_webhooks/`에 저장
2. **claude.analyze.php**: 웹훅 데이터를 Claude AI로 분석해서 `pending_analysis/`에 마크다운 파일 저장
   - GitHub Compare API를 통해 실제 코드 변경사항 분석
   - `compare_url`을 활용한 직접적인 diff 분석
3. **jira.hook.php**: 분석 파일을 읽어서 Jira 티켓에 댓글/설명 추가

## 디렉토리 구조

```
webhookTest/
├── github.hook.php          # GitHub 웹훅 핸들러
├── claude.analyze.php       # Claude AI 분석 스크립트 (GitHub API 연동)
├── jira.hook.php           # Jira 통합 스크립트 (PHP)
├── jira_integration.sh     # Jira 통합 스크립트 (Shell)
├── common.lib.php          # 공통 라이브러리
├── test_compare_url.php    # GitHub Compare API 테스트 스크립트
├── .env                    # 환경 설정 파일
├── pending_webhooks/       # 처리 대기 중인 웹훅 데이터
├── pending_analysis/       # 처리 대기 중인 분석 파일
├── processed_webhooks/     # 처리 완료된 웹훅 데이터
├── processed_jira/         # 처리 완료된 분석 파일
├── error_analysis/         # 오류가 포함된 분석 파일
└── logs/                   # 로그 파일들
```

## 환경 설정

### 1. .env 파일 생성

```bash
# Jira 설정
JIRA_BASE_URL=https://your-domain.atlassian.net
JIRA_EMAIL=your-email@example.com
JIRA_API_TOKEN=your-api-token
```

### 2. 권한 설정

```bash
chmod 755 *.php
chmod 755 *.sh
chmod 777 pending_* processed_* logs/
```

### 3. PHP 확장 모듈 확인

```bash
# curl 확장이 필요합니다
php -m | grep curl

# 설치가 필요한 경우:
# Ubuntu/Debian: sudo apt-get install php-curl
# CentOS/RHEL: sudo yum install php-curl
```

## Crontab 자동화 설정

### 권장 설정 (매분 실행)

```bash
# crontab 편집
crontab -e

# 다음 내용 추가:
# GitHub 웹훅 분석 (매분 실행)
* * * * * cd /home/gd/webhookTest && php claude.analyze.php >> logs/cron_analyze.log 2>&1

# Jira 통합 (매분 실행) - PHP 버전
* * * * * cd /home/gd/webhookTest && php jira.hook.php >> logs/cron_jira.log 2>&1

# 또는 Shell 스크립트 버전 사용
# * * * * * cd /home/gd/webhookTest && ./jira_integration.sh >> logs/cron_jira.log 2>&1
```

### 대안 설정 (5분마다 실행)

더 안정적인 운영을 위해 5분마다 실행하는 경우:

```bash
# 5분마다 실행
*/5 * * * * cd /home/gd/webhookTest && php claude.analyze.php >> logs/cron_analyze.log 2>&1
*/5 * * * * cd /home/gd/webhookTest && php jira.hook.php >> logs/cron_jira.log 2>&1
```

### 단계별 실행 (권장)

분석과 Jira 업로드를 시간차를 두고 실행:

```bash
# 분석: 매분 실행
* * * * * cd /var/www/html/webhookTest && php claude.analyze.php >> logs/cron_analyze.log 2>&1

# Jira 통합: 2분마다 실행 (분석 완료 후)
*/2 * * * * cd /var/www/html/webhookTest && php jira.hook.php >> logs/cron_jira.log 2>&1
```

## GitHub 웹훅 설정

### 1. GitHub 리포지토리 설정

1. 리포지토리 → Settings → Webhooks → Add webhook
2. Payload URL: `https://your-domain.com/path/to/github.hook.php`
3. Content type: `application/json`
4. Secret: `test123` (또는 github.hook.php에서 변경)
5. Events: `Just the push event` 선택

### 2. 웹훅 검증

```bash
# 웹훅 로그 확인
tail -f logs/webhook_$(date +%Y-%m-%d).log

# 요약 로그 확인
tail -f logs/summary.log
```

## 수동 실행 및 테스트

### 개별 스크립트 실행

```bash
# 웹훅 분석 실행
php claude.analyze.php

# GitHub Compare API 테스트
php test_compare_url.php

# Jira 통합 실행 (PHP)
php jira.hook.php

# Jira 통합 실행 (Shell)
./jira_integration.sh
```

### 로그 확인

```bash
# 분석 로그
tail -f logs/analysis_errors.log

# Jira 로그
tail -f logs/jira_hook_errors.log
tail -f logs/jira_success.log

# Cron 로그
tail -f logs/cron_analyze.log
tail -f logs/cron_jira.log
```

## 지원되는 Jira 티켓 패턴

- `[P03-45]` - 대괄호 포함 형식
- `[PROJ-123]` - 표준 Jira 형식
- `P03-45` - 대괄호 없는 형식
- `PROJ-123` - 표준 형식

티켓 ID는 다음 순서로 검색됩니다:
1. 커밋 메시지
2. 브랜치 이름

## Markdown 지원 형식

### 헤더 변환
- `# Header` → `h1. Header`
- `## Header` → `h2. Header`
- `### Header` → `h3. Header`
- `#### Header` → `h4. Header`
- `##### Header` → `h5. Header` (새로 추가)

### 기타 형식
- `**bold**` → `*bold*`
- `` `code` `` → `{{code}}`
- `- list` → `* list`
- `[link](url)` → `[link|url]`

## 문제 해결

### 일반적인 문제

1. **웹훅이 받아지지 않는 경우**
   ```bash
   # 웹 서버 로그 확인
   tail -f /var/log/apache2/error.log
   # 또는
   tail -f /var/log/nginx/error.log
   ```

2. **분석이 실행되지 않는 경우**
   ```bash
   # Claude CLI 설치 확인
   which claude
   
   # 권한 확인
   ls -la pending_webhooks/
   
   # GitHub Compare API 테스트
   php test_compare_url.php
   ```

3. **Jira 연결 실패**
   ```bash
   # .env 파일 확인
   cat .env
   
   # 네트워크 연결 테스트
   curl -u "email:token" https://your-domain.atlassian.net/rest/api/2/myself
   ```

4. **"Execution error" 감지**
   - Claude 분석 중 오류 발생 시 `error_analysis/` 디렉토리로 이동
   - Jira로 발송되지 않고 개발자 검토 필요
   - 로그 확인: `tail -f logs/jira_errors.log`

### 디버깅 모드

```bash
# PHP 에러 표시 활성화 (개발 시에만)
sed -i 's/ini_set('\''display_errors'\'', 0)/ini_set('\''display_errors'\'', 1)/' *.php
```

## Claude AI 분석 기능

### GitHub Compare API 연동

claude.analyze.php는 이제 GitHub Compare API를 직접 활용하여 더 정확한 코드 분석을 제공합니다:

1. **Compare URL 자동 생성**
   - 웹훅 데이터에서 `compare_url` 템플릿 추출
   - `{base}`와 `{head}`를 실제 커밋 해시로 치환
   - 예: `https://api.github.com/repos/owner/repo/compare/abc123...def456`

2. **직접적인 코드 Diff 분석**
   - Claude가 GitHub API를 호출하여 실제 변경사항 확인
   - 파일별 patch (diff) 정보 분석
   - 추가/삭제된 줄 수 및 코드 내용 파악

3. **분석 품질 향상**
   - 로컬 저장소 의존성 제거
   - 실시간 GitHub 데이터 활용
   - 더 정확한 코드 변경 의도 파악

### 프롬프트 템플릿 시스템

claude.analyze.php는 JSON 또는 YAML 기반 템플릿 시스템을 사용하여 프롬프트를 관리합니다:

#### 지원 형식 및 파일 위치

템플릿은 JSON 또는 YAML 형식으로 제공할 수 있습니다:
- **JSON 형식**: `prompt_template.json` (폴백 옵션)
- **YAML 형식**: `prompts_normal.yaml`, `prompts_conflict.yaml`, `prompts_simplified.yaml`
- **파일 위치**: 프로젝트 루트 디렉토리 (`/webhookTest/`)

> **참고**: YAML 파일이 있으면 JSON보다 우선적으로 사용됩니다. 한국어 버전은 `.kr.yaml` 확장자를 사용합니다.

#### 템플릿 파일 구조

##### JSON 형식 (prompt_template.json)
1. **기본 프롬프트 섹션**
   - `system_prompt`: Claude AI 시스템 프롬프트
   - `main_prompt`: 메인 프롬프트 구조
   - `simplified_prompt`: 큰 커밋용 간소화 프롬프트
   - `display_format`: 시각적 표시용 배열 형식

##### YAML 형식 (prompts_*.yaml)
1. **기본 프롬프트 섹션**
   - `system_prompt`: Claude AI 시스템 프롬프트 (JSON의 system_prompt와 동일)
   - `user_prompt`: 사용자 프롬프트 (JSON의 main_prompt에 해당)
   - `user_prompt_no_url`: URL 없을 때 대체 프롬프트
   - `commit_template`: 커밋 상세 템플릿
   - `variables`: 변수 설명 섹션

2. **분석 프로파일**
   - `default`: 기본 분석
   - `detailed`: 상세 코드 리뷰
   - `security`: 보안 중점 분석
   - `performance`: 성능 영향 분석
   - `english`: 영어 분석

3. **설정 및 제약**
   - `max_commits_detail`: 상세 표시할 최대 커밋 수
   - `max_prompt_length`: 프롬프트 최대 길이
   - `truncate_message_at`: 커밋 메시지 최대 길이

#### 템플릿 변수

| 변수 | 설명 |
|------|------|
| `{repository}` | 전체 저장소 이름 (owner/repo) |
| `{branch}` | 브랜치 이름 |
| `{author}` | 커밋 작성자 |
| `{before_commit}` | 이전 커밋 해시 (7자) |
| `{after_commit}` | 이후 커밋 해시 (7자) |
| `{commit_id}` | 개별 커밋 ID |
| `{message}` | 커밋 메시지 |
| `{url}` | GitHub Compare URL |

### 프롬프트 미리보기 도구

`preview_prompt.php`를 사용하여 템플릿 수정 후 실제 생성될 프롬프트를 미리 확인할 수 있습니다:

```bash
# 일반 프롬프트 미리보기
php preview_prompt.php normal

# 충돌 있는 병합 커밋 프롬프트
php preview_prompt.php conflict

# 간소화된 프롬프트 (큰 커밋용)
php preview_prompt.php simplified
```

미리보기 도구는 다음 정보를 제공합니다:
- 실제 생성될 프롬프트 전체 내용
- 프롬프트 통계 (줄 수, 문자 수, 바이트)
- 사용된 템플릿 변수 목록

### 프롬프트 로깅

모든 Claude AI 프롬프트는 디버깅을 위해 자동으로 로깅됩니다:

- 위치: `logs/claude_prompts/`
- 파일명: `YYYY-MM-DD_HH-ii-ss_저장소명_prompt.txt`
- 내용: System Prompt, User Prompt, 메타데이터

```bash
# 최근 프롬프트 로그 확인
ls -lt logs/claude_prompts/ | head -5

# 특정 프롬프트 내용 확인
cat logs/claude_prompts/2024-01-01_12-00-00_example_repo_prompt.txt
```

### 테스트 방법

```bash
# Compare URL 프롬프트 테스트
php test_compare_url.php

# 프롬프트 미리보기
php preview_prompt.php normal

# 실제 웹훅 데이터로 분석 실행
php claude.analyze.php
```

## 보안 고려사항

1. **.env 파일 권한**: `chmod 600 .env`
2. **웹훅 시크릿**: GitHub에서 설정한 시크릿과 github.hook.php의 시크릿 일치 확인
3. **Jira API 토큰**: 최소 권한 원칙 적용
4. **로그 파일**: 민감한 정보 로깅 방지

## 모니터링

### 상태 확인 스크립트

```bash
#!/bin/bash
# status_check.sh

echo "=== Webhook Automation Status ==="
echo "Pending webhooks: $(ls -1 pending_webhooks/*.json 2>/dev/null | wc -l)"
echo "Pending analysis: $(ls -1 pending_analysis/*.md 2>/dev/null | wc -l)"
echo "Recent webhook: $(ls -1t logs/webhook_*.log 2>/dev/null | head -1 | xargs tail -1)"
echo "Last cron run:"
echo "  Analysis: $(tail -1 logs/cron_analyze.log 2>/dev/null)"
echo "  Jira: $(tail -1 logs/cron_jira.log 2>/dev/null)"
```

사용법: `chmod +x status_check.sh && ./status_check.sh`