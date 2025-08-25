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
```

### Docker Operations
```bash
# Build and run Docker container
docker build -t webhook-processor .
docker run -d -p 8807:80 -v $(pwd):/var/www/html webhook-processor

# Access container
docker exec -it <container_id> bash
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
- `loadEnvFile()` - Environment variable loading
- `LockManager` class - Prevents duplicate runs (5-minute timeout)
- `extractJiraTicketId()` - Matches patterns: `[P03-45]`, `PROJ-123`, `ABC1-234`
- `initializeEnvironment()` - Sets up directories and logging
- `moveToProcessed()` - File queue management

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
JIRA_BASE_URL=https://your-domain.atlassian.net
JIRA_EMAIL=your-email@example.com  
JIRA_API_TOKEN=your-api-token
WEBHOOK_SECRET=test123
```

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

The system uses Claude CLI with specific parameters:
- Command: `claude -p --permission-mode bypassPermissions`
- Analyzes git diffs between commits
- Expects source code at `./source/{repoName}`
- Outputs Korean language summaries
- Format: 📌 주요 변경사항, 📁 영향받는 모듈, 🎯 변경 목적

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

- PHP 8.2+ required with curl extension
- Claude CLI must be installed and accessible
- System expects git repositories at `./source/` directory
- All scripts check for CLI execution (web access returns 403)
- Lock mechanism prevents cron overlap when analysis takes >1 minute
- Korean language output from Claude analysis is intentional