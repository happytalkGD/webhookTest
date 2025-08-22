#!/bin/bash

# Jira Integration Script - Shell alternative to jira.hook.php
# Reads analysis files and posts to Jira issues

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Load environment variables from .env file
if [ -f "$SCRIPT_DIR/.env" ]; then
    export $(grep -v '^#' "$SCRIPT_DIR/.env" | xargs -d '\n')
fi

# Configuration
JIRA_BASE_URL="${JIRA_BASE_URL:-https://your-domain.atlassian.net}"
JIRA_EMAIL="${JIRA_EMAIL:-your-email@example.com}"
JIRA_API_TOKEN="${JIRA_API_TOKEN:-your-api-token}"

# Directories
ANALYSIS_DIR="$SCRIPT_DIR/analysis"
PROCESSED_JIRA_DIR="$SCRIPT_DIR/processed_jira"
LOGS_DIR="$SCRIPT_DIR/logs"

# Create necessary directories
mkdir -p "$PROCESSED_JIRA_DIR" "$LOGS_DIR"

# Log file
LOG_FILE="$LOGS_DIR/jira_integration_$(date +%Y-%m-%d).log"

# Function to log messages
log_message() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

# Function to convert markdown to Jira format
markdown_to_jira() {
    local text="$1"
    
    # Clean up excessive line breaks first (3+ newlines to 2)
    text=$(echo "$text" | sed ':a;N;$!ba;s/\n\n\n\+/\n\n/g')
    
    # Headers - Process 4-level headers first, treating numbered h4 as bold items
    # Convert #### 1. **text** to *1. text* (bold numbered item)
    text=$(echo "$text" | sed 's/^#### \([0-9]\+\.\) \*\*\(.*\)\*\*$/*\1 \2*/g')
    text=$(echo "$text" | sed 's/^#### \(.*\)$/h4. \1/g')
    text=$(echo "$text" | sed 's/^### \(.*\)$/h3. \1/g')
    text=$(echo "$text" | sed 's/^## \(.*\)$/h2. \1/g')
    text=$(echo "$text" | sed 's/^# \(.*\)$/h1. \1/g')
    
    # Bold - **text** to *text*
    text=$(echo "$text" | sed 's/\*\*\([^*]*\)\*\*/\*\1\*/g')
    text=$(echo "$text" | sed 's/__\([^_]*\)__/\*\1\*/g')
    
    # Code blocks - ```code``` to {code}code{code}
    text=$(echo "$text" | sed ':a;N;$!ba;s/```\([^`]*\)```/{code}\1{code}/g')
    
    # Inline code - `code` to {{code}}
    text=$(echo "$text" | sed 's/`\([^`]*\)`/{{\1}}/g')
    
    # Lists - * or - to *
    text=$(echo "$text" | sed 's/^[*-] \(.*\)$/* \1/g')
    
    # Numbered lists - only with content
    text=$(echo "$text" | sed 's/^[0-9]\+\. \([^ ].*\)$/# \1/g')
    
    # Remove standalone list markers
    text=$(echo "$text" | sed 's/^[0-9]\+\.\s*$//g')  # Remove "1." on its own
    text=$(echo "$text" | sed 's/^[a-z]\.\s*$//g')     # Remove "a." on its own
    text=$(echo "$text" | sed 's/^i\+\.\s*$//g')       # Remove "i." on its own
    
    # Horizontal rules
    text=$(echo "$text" | sed 's/^---\+$/----/g')
    
    # Emojis to Jira icons
    text=$(echo "$text" | sed 's/📌/(!)/g')
    text=$(echo "$text" | sed 's/📁/(i)/g')
    text=$(echo "$text" | sed 's/🎯/(\/)/g')
    text=$(echo "$text" | sed 's/🔍/(?)/g')
    text=$(echo "$text" | sed 's/📊/(*)/g')
    text=$(echo "$text" | sed 's/🔄/(on)/g')
    text=$(echo "$text" | sed 's/✅/(\/)/g')
    text=$(echo "$text" | sed 's/❌/(x)/g')
    text=$(echo "$text" | sed 's/⚠️/(!)/g')
    
    # Final cleanup - remove excessive blank lines
    text=$(echo "$text" | sed ':a;N;$!ba;s/\n\n\n\+/\n\n/g')
    text=$(echo "$text" | sed '/^[[:space:]]*$/d' | sed ':a;N;$!ba;s/\n\n\n\+/\n\n/g')
    
    echo "$text"
}

# Function to extract Jira ticket ID
extract_ticket_id() {
    local content="$1"
    local ticket_id=""
    
    # Try different patterns
    # Pattern 1: [P03-45] format
    ticket_id=$(echo "$content" | grep -oE '\[[A-Z]+[0-9]+-[0-9]+\]' | head -1 | tr -d '[]')
    
    # Pattern 2: [PROJ-123] standard format
    if [ -z "$ticket_id" ]; then
        ticket_id=$(echo "$content" | grep -oE '\[[A-Z]+-[0-9]+\]' | head -1 | tr -d '[]')
    fi
    
    # Pattern 3: P03-45 without brackets
    if [ -z "$ticket_id" ]; then
        ticket_id=$(echo "$content" | grep -oE '[A-Z]+[0-9]+-[0-9]+' | head -1)
    fi
    
    # Pattern 4: PROJ-123 standard without brackets
    if [ -z "$ticket_id" ]; then
        ticket_id=$(echo "$content" | grep -oE '[A-Z]{1,10}-[0-9]+' | head -1)
    fi
    
    echo "$ticket_id"
}

# Function to check if Jira issue exists and get description
check_jira_issue() {
    local ticket_id="$1"
    local url="${JIRA_BASE_URL}/rest/api/2/issue/${ticket_id}?fields=description"
    
    response=$(curl -s -w "\n%{http_code}" \
        -u "${JIRA_EMAIL}:${JIRA_API_TOKEN}" \
        -H "Accept: application/json" \
        "${url}")
    
    http_code=$(echo "$response" | tail -1)
    body=$(echo "$response" | head -n -1)
    
    if [ "$http_code" = "200" ]; then
        # Extract description (check if it's null or empty)
        description=$(echo "$body" | grep -o '"description":[^,}]*' | sed 's/"description"://;s/^"//;s/"$//')
        if [ "$description" = "null" ] || [ -z "$description" ]; then
            echo "EMPTY"
        else
            echo "EXISTS"
        fi
    else
        echo "ERROR"
    fi
}

# Function to update Jira issue description
update_jira_description() {
    local ticket_id="$1"
    local content="$2"
    local url="${JIRA_BASE_URL}/rest/api/2/issue/${ticket_id}"
    
    # Escape JSON special characters
    content=$(echo "$content" | sed 's/\\/\\\\/g' | sed 's/"/\\"/g' | sed ':a;N;$!ba;s/\n/\\n/g')
    
    response=$(curl -s -w "\n%{http_code}" \
        -u "${JIRA_EMAIL}:${JIRA_API_TOKEN}" \
        -X PUT \
        -H "Content-Type: application/json" \
        -d "{\"fields\":{\"description\":\"${content}\"}}" \
        "${url}")
    
    http_code=$(echo "$response" | tail -1)
    
    if [ "$http_code" = "204" ]; then
        echo "SUCCESS"
    else
        echo "FAILED: HTTP $http_code"
    fi
}

# Function to add Jira comment
add_jira_comment() {
    local ticket_id="$1"
    local content="$2"
    local url="${JIRA_BASE_URL}/rest/api/2/issue/${ticket_id}/comment"
    
    # Escape JSON special characters
    content=$(echo "$content" | sed 's/\\/\\\\/g' | sed 's/"/\\"/g' | sed ':a;N;$!ba;s/\n/\\n/g')
    
    response=$(curl -s -w "\n%{http_code}" \
        -u "${JIRA_EMAIL}:${JIRA_API_TOKEN}" \
        -X POST \
        -H "Content-Type: application/json" \
        -d "{\"body\":\"${content}\"}" \
        "${url}")
    
    http_code=$(echo "$response" | tail -1)
    
    if [ "$http_code" = "201" ]; then
        echo "SUCCESS"
    else
        echo "FAILED: HTTP $http_code"
    fi
}

# Main processing
log_message "Starting Jira integration process"

# Check if analysis directory exists
if [ ! -d "$ANALYSIS_DIR" ]; then
    log_message "Analysis directory not found: $ANALYSIS_DIR"
    exit 1
fi

# Process each analysis file
for analysis_file in "$ANALYSIS_DIR"/*.txt; do
    # Skip if no files found
    [ -f "$analysis_file" ] || continue
    
    filename=$(basename "$analysis_file")
    processed_marker="$PROCESSED_JIRA_DIR/$filename.processed"
    
    # Skip if already processed
    if [ -f "$processed_marker" ]; then
        continue
    fi
    
    log_message "Processing: $filename"
    
    # Read file content
    content=$(cat "$analysis_file")
    
    # Extract ticket ID from commits first, then branch
    ticket_id=""
    
    # First try to find in commit messages
    commit_section=$(echo "$content" | sed -n '/=== 커밋 내역 ===/,/=== /p')
    if [ -n "$commit_section" ]; then
        ticket_id=$(extract_ticket_id "$commit_section")
        if [ -n "$ticket_id" ]; then
            log_message "Found ticket ID in commit: $ticket_id"
        fi
    fi
    
    # If not found in commits, try branch name
    if [ -z "$ticket_id" ]; then
        branch_line=$(echo "$content" | grep "브랜치:")
        if [ -n "$branch_line" ]; then
            ticket_id=$(extract_ticket_id "$branch_line")
            if [ -n "$ticket_id" ]; then
                log_message "Found ticket ID in branch: $ticket_id"
            fi
        fi
    fi
    
    if [ -z "$ticket_id" ]; then
        log_message "No Jira ticket ID found in $filename"
        touch "$processed_marker"
        continue
    fi
    
    # Convert content to Jira format
    jira_content=$(markdown_to_jira "$content")
    
    # Check if issue exists and has description
    issue_status=$(check_jira_issue "$ticket_id")
    
    if [ "$issue_status" = "ERROR" ]; then
        log_message "Error checking issue $ticket_id or issue not found"
        touch "$processed_marker"
        continue
    fi
    
    # Update description or add comment based on status
    if [ "$issue_status" = "EMPTY" ]; then
        log_message "Updating description for $ticket_id (currently empty)"
        result=$(update_jira_description "$ticket_id" "$jira_content")
        log_message "Description update result: $result"
    else
        log_message "Adding comment to $ticket_id (description exists)"
        result=$(add_jira_comment "$ticket_id" "$jira_content")
        log_message "Comment add result: $result"
    fi
    
    # Mark as processed
    touch "$processed_marker"
    log_message "Marked as processed: $filename"
done

log_message "Jira integration process completed"