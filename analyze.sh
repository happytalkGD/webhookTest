#!/bin/bash

# Claude Analysis Shell Script
# Processes webhook data files and generates analysis reports

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WEBHOOK_DATA_DIR="$SCRIPT_DIR/webhook_data"
ANALYSIS_DIR="$SCRIPT_DIR/analysis"
PROCESSED_DIR="$SCRIPT_DIR/processed_webhooks"
LOGS_DIR="$SCRIPT_DIR/logs"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "=== Claude Analysis Shell Script ==="
echo "Time: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

# Function to create directories with proper permissions
create_directory() {
    local dir=$1
    if [ ! -d "$dir" ]; then
        echo "Creating directory: $dir"
        mkdir -p "$dir"
        chmod 777 "$dir"
    fi
}

# Create necessary directories
create_directory "$ANALYSIS_DIR"
create_directory "$PROCESSED_DIR"
create_directory "$LOGS_DIR"

# Check if webhook_data directory exists
if [ ! -d "$WEBHOOK_DATA_DIR" ]; then
    echo -e "${RED}Error: webhook_data directory not found${NC}"
    echo "Run test_webhook.php first to create test data"
    exit 1
fi

# Find JSON files to process
echo "Checking for webhook files..."
files=($WEBHOOK_DATA_DIR/*.json)

if [ ! -e "${files[0]}" ]; then
    echo -e "${YELLOW}No webhook files to process${NC}"
    exit 0
fi

echo "Found ${#files[@]} webhook file(s) to process"
echo ""

# Process each file
for file in "${files[@]}"; do
    filename=$(basename "$file")
    echo "Processing: $filename"
    
    # Extract data from JSON file using jq or basic parsing
    if command -v jq &> /dev/null; then
        # Use jq if available
        event=$(jq -r '.event' "$file")
        delivery_id=$(jq -r '.delivery_id' "$file")
        repository=$(jq -r '.repository' "$file")
        branch=$(jq -r '.branch' "$file")
        pusher=$(jq -r '.pusher' "$file")
        commits_count=$(jq -r '.commits_count' "$file")
        timestamp=$(jq -r '.timestamp' "$file")
        
        # Extract commit details
        before_commit=$(jq -r '.payload.before' "$file" | cut -c1-7)
        after_commit=$(jq -r '.payload.after' "$file" | cut -c1-7)
        commit_messages=$(jq -r '.payload.commits[].message' "$file" 2>/dev/null)
    else
        # Basic parsing without jq
        event=$(grep -o '"event"[[:space:]]*:[[:space:]]*"[^"]*"' "$file" | cut -d'"' -f4)
        repository=$(grep -o '"repository"[[:space:]]*:[[:space:]]*"[^"]*"' "$file" | head -1 | cut -d'"' -f4)
        branch=$(grep -o '"branch"[[:space:]]*:[[:space:]]*"[^"]*"' "$file" | cut -d'"' -f4)
        pusher=$(grep -o '"pusher"[[:space:]]*:[[:space:]]*"[^"]*"' "$file" | cut -d'"' -f4)
        commits_count=$(grep -o '"commits_count"[[:space:]]*:[[:space:]]*[0-9]*' "$file" | grep -o '[0-9]*$')
        timestamp=$(grep -o '"timestamp"[[:space:]]*:[[:space:]]*"[^"]*"' "$file" | cut -d'"' -f4)
        
        echo "  Note: jq not installed, using basic parsing"
    fi
    
    # Only process push events
    if [ "$event" != "push" ]; then
        echo -e "  ${YELLOW}Skipping non-push event${NC}"
        continue
    fi
    
    # Generate analysis filename
    repo_clean=$(echo "$repository" | tr '/' '_')
    analysis_file="$ANALYSIS_DIR/$(date '+%Y-%m-%d_%H-%M-%S')_${repo_clean}_analysis.md"
    
    # Check if Claude is available
    if command -v claude &> /dev/null; then
        echo "  Running Claude analysis..."
        
        # Create prompt for Claude
        prompt="분석할 Git Push 이벤트:
Repository: $repository
Branch: $branch
Pusher: $pusher
Commits: $commits_count
Timestamp: $timestamp

다음 형식으로 한국어로 요약해주세요:
📌 **주요 변경사항**: (핵심 변경 내용)
📁 **영향받는 모듈**: (주요 파일/디렉토리)
🎯 **변경 목적**: (추측되는 목적)
🔍 **코드 리뷰 포인트**: (주의사항)"

        # Run Claude analysis
        analysis_result=$(echo "$prompt" | claude -p --permission-mode bypassPermissions 2>/dev/null)
        
        if [ $? -eq 0 ] && [ -n "$analysis_result" ]; then
            echo -e "  ${GREEN}✓ Claude analysis completed${NC}"
        else
            echo -e "  ${YELLOW}⚠ Claude analysis failed, using fallback${NC}"
            analysis_result="Mock analysis (Claude failed)"
        fi
    else
        echo -e "  ${YELLOW}Claude not available, generating mock analysis${NC}"
        
        # Generate mock analysis
        analysis_result="# Mock Analysis Report

📌 **주요 변경사항**: $commits_count개의 커밋이 포함된 Push 이벤트

📁 **영향받는 모듈**: 상세 분석 불가 (Claude 미설치)

🎯 **변경 목적**: 코드 변경 및 업데이트

🔍 **코드 리뷰 포인트**: Claude CLI 설치 필요

---
*Mock analysis generated at $timestamp*"
    fi
    
    # Create analysis report
    cat > "$analysis_file" << EOF
# Git Push Analysis Report

**Generated**: $(date '+%Y-%m-%d %H:%M:%S')
**Repository**: $repository
**Branch**: $branch
**Pusher**: $pusher
**Commits**: $commits_count
**Webhook File**: $filename

---

## Analysis Results

$analysis_result

---

*Analysis completed at $(date '+%Y-%m-%d %H:%M:%S')*
EOF

    if [ -f "$analysis_file" ]; then
        echo -e "  ${GREEN}✓ Report saved: $(basename "$analysis_file")${NC}"
        
        # Move processed file
        if mv "$file" "$PROCESSED_DIR/" 2>/dev/null; then
            echo -e "  ${GREEN}✓ Moved to processed directory${NC}"
        else
            echo -e "  ${YELLOW}⚠ Could not move file (permission issue?)${NC}"
        fi
    else
        echo -e "  ${RED}✗ Failed to save report${NC}"
    fi
    
    echo ""
done

# Summary
echo "=== Processing Complete ==="
echo ""

# Check results
if [ -d "$ANALYSIS_DIR" ]; then
    report_count=$(ls -1 "$ANALYSIS_DIR"/*.md 2>/dev/null | wc -l)
    echo "Analysis reports: $report_count file(s)"
fi

if [ -d "$PROCESSED_DIR" ]; then
    processed_count=$(ls -1 "$PROCESSED_DIR"/*.json 2>/dev/null | wc -l)
    echo "Processed webhooks: $processed_count file(s)"
fi

echo ""
echo "Directories:"
echo "  Analysis: $ANALYSIS_DIR"
echo "  Processed: $PROCESSED_DIR"
echo "  Logs: $LOGS_DIR"