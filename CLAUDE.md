# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## System Overview

This is a GitHub webhook automation system that analyzes push events using Claude AI and posts summaries to Jira tickets. The system operates as a 3-stage pipeline:

1. **github.hook.php** - Receives GitHub webhooks and saves to `pending_webhooks/`
2. **claude.analyze.php** - Analyzes webhook data with Claude AI, saves to `pending_analysis/`
3. **jira.hook.php** - Posts analysis to Jira issues from commit messages or branch names

## Critical Commands

### Running Scripts Manually
```bash
# Process pending webhooks through Claude analysis
php claude.analyze.php

# Post analysis results to Jira
php jira.hook.php

# Alternative Shell script for Jira (if PHP curl not available)
./jira_integration.sh
```

### Testing Commands
```bash
# Test Jira API connection
php test_jira_connection.php

# Generate test webhook data
php test_webhook.php

# Test analysis functionality
php test_analyze.php

# Test GitHub Compare URL functionality
php test_compare_url.php

# Test YAML template parsing
php test_yaml.php

# Test Jira ticket pattern matching
php test_jira_pattern.php

# Test Jira markup formatting
php test_jira_format.php

# Test complex markdown conversion
php test_complex_markdown.php
```

### Docker Operations
```bash
# Build and run Docker container
docker build -f docker/web/Dockerfile -t webhook-processor .
docker run -d -p 8807:80 -v $(pwd):/var/www/html webhook-processor

# Access container
docker exec -it <container_id> bash

# View container logs
docker logs <container_id> -f

# Stop and remove container
docker stop <container_id> && docker rm <container_id>
```

### Crontab Setup (Required for Automation)
```bash
# Add to crontab -e:
* * * * * cd /var/www/html/webhookTest && php claude.analyze.php >> logs/cron_analyze.log 2>&1
*/2 * * * * cd /var/www/html/webhookTest && php jira.hook.php >> logs/cron_jira.log 2>&1
```

## Architecture & Data Flow

### Directory Structure & Purpose
- **pending_webhooks/** - Raw GitHub webhook JSON files awaiting analysis
- **pending_analysis/** - Markdown analysis files awaiting Jira posting  
- **processed_webhooks/** - Webhook files after successful analysis
- **processed_jira/** - Analysis files after successful Jira posting
- **locks/** - Lock files preventing duplicate script execution
- **logs/** - All error and success logs

### Core Components

**common.lib.php** - Shared utilities including:
- `loadEnvFile()` - Environment variable loading (.env file parsing)
- `LockManager` class - Prevents duplicate runs (5-minute timeout with auto-cleanup)
- `extractJiraTicketId()` - Matches patterns: `[P03-45]`, `PROJ-123`, `ABC1-234`
- `initializeEnvironment()` - Sets up directories and logging infrastructure
- `moveToProcessed()` - File queue management between processing stages
- `safeJsonDecode()` - Safe JSON parsing with error handling
- `setupErrorLogging()` - Centralized error logging configuration
- `validateCompareUrl()` - GitHub Compare API URL validation and correction

### Processing Flow
1. GitHub sends webhook to `github.hook.php` endpoint
2. Webhook data saved as JSON in `pending_webhooks/`
3. Cron runs `claude.analyze.php` every minute:
   - Reads webhook JSON files
   - Executes Claude CLI with git diff analysis
   - Generates Korean language analysis report
   - Saves markdown to `pending_analysis/`
4. Cron runs `jira.hook.php` every 2 minutes:
   - Extracts Jira ticket ID from commits/branch
   - Converts markdown to Jira markup format
   - Updates issue description (if empty) or adds comment

## Configuration Requirements

### .env File (REQUIRED)
```bash
# Core Jira Configuration
JIRA_BASE_URL=https://your-domain.atlassian.net
JIRA_EMAIL=your-email@example.com  
JIRA_API_TOKEN=your-api-token

# GitHub Webhook Configuration
WEBHOOK_SECRET=test123

# Optional: Additional Claude/GitHub settings
# CLAUDE_API_KEY=your-claude-api-key
# GITHUB_TOKEN=your-github-token
```

**Setup Steps:**
1. Copy `.env.example` to `.env`: `cp .env.example .env`
2. Generate Jira API token: https://id.atlassian.com/manage-profile/security/api-tokens
3. Set secure file permissions: `chmod 600 .env`

### GitHub Webhook Setup
- Payload URL: `https://your-domain.com/path/to/github.hook.php`
- Content type: `application/json`
- Secret: Must match `WEBHOOK_SECRET` in .env
- Events: Select "Just the push event"

### Directory Permissions
```bash
chmod 755 *.php *.sh
chmod 777 pending_* processed_* logs/
```

## Claude Analysis Configuration

The system uses Claude CLI with GitHub Compare API integration:
- **Command**: `claude -p --permission-mode bypassPermissions`
- **Analysis Source**: GitHub Compare API (not local source files)
- **Template System**: YAML/JSON-based prompts with variable substitution
- **Language**: Korean output by default (configurable via templates)
- **Format**: 📌 주요 변경사항, 📁 영향받는 모듈, 🎯 변경 목적

### Template Priority Order
1. **YAML templates** (`prompts_*.yaml`) - Primary
2. **Korean YAML** (`prompts_*.kr.yaml`) - Language variant
3. **JSON template** (`prompt_template.json`) - Fallback

### Key Architecture Features
- **GitHub Compare API**: Direct analysis of commit diffs via GitHub API
- **Error Detection**: Automatic Claude error pattern recognition
- **Lock Management**: Prevents concurrent execution conflicts
- **Jira Pre-validation**: Skips analysis if no Jira ticket ID found
- **Template Preview**: `preview_prompt.php` for testing prompts before use

## Jira Integration Details

### Ticket ID Extraction Priority
1. Commit messages (searches all commits)
2. Branch name (if not found in commits)

### Markdown to Jira Conversion
- Headers: `#` → `h1.`, `##` → `h2.`, up to `#####` → `h5.`
- Bold: `**text**` → `*text*`
- Code: `` `code` `` → `{{code}}`
- Code blocks: ` ```code``` ` → `{code}code{code}`
- Lists: `- item` → `* item`
- Emojis: 📌→(!), 📁→(i), 🎯→(/), 🔍→(?)

### Jira API Behavior
- Checks if issue description is empty
- Empty description: Updates description field
- Existing description: Adds as comment
- Uses Jira REST API v2 with Basic Auth

## Lock File Management

All scripts use lock files to prevent duplicate execution:
- Location: `locks/{script_name}.lock`
- Timeout: 5 minutes (stale lock auto-removal)
- Automatic cleanup on normal/abnormal exit

## Troubleshooting

### Common Issues
- **Lock file errors**: Delete stale lock in `locks/` directory
- **Jira 401/403**: Verify API token and permissions
- **No analysis output**: Check Claude CLI installation with `which claude`
- **Webhook not received**: Verify GitHub webhook secret matches .env

### Log Files
- `logs/webhook_errors.log` - GitHub webhook reception
- `logs/analysis_errors.log` - Claude analysis issues
- `logs/jira_hook_errors.log` - Jira API problems
- `logs/jira_success.log` - Successful Jira posts
- `logs/cron_*.log` - Cron execution logs

## Development Notes

### System Requirements
- **PHP**: 8.2+ with curl extension required
- **Claude CLI**: Must be installed and accessible (`which claude`)
- **YAML Support**: Native PHP extension or custom parser (`yaml_parser_v2.php`)
- **File Permissions**: Scripts require 755, directories need 777
- **CLI Only**: All scripts reject web requests (return 403)

### Architecture Patterns
- **Queue-based Processing**: Files move through `pending_*` → `processed_*` stages
- **Lock Management**: 5-minute timeout with stale lock auto-cleanup
- **Error Isolation**: Failed analysis moved to `error_analysis/` directory
- **Template System**: Dynamic prompt generation with variable substitution
- **API Integration**: Direct GitHub Compare API (no local repo dependency)

### File Movement Flow
```
GitHub Webhook → pending_webhooks/
     ↓ (claude.analyze.php)
pending_analysis/ → processed_webhooks/
     ↓ (jira.hook.php)  
processed_jira/ (success) OR error_analysis/ (failure)
```

### Debugging Utilities
- `move_analysis_files.sh` - Relocate analysis files between directories
- `preview_prompt.php` - Test prompt templates before deployment
- `test_*.php` - Comprehensive test suite (9 test scripts available)
- `--debug` mode - Dry-run Jira posting with output preview