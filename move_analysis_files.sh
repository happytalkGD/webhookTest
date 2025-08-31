#!/bin/bash

# Script to move misplaced analysis files to the correct directory
# This script finds analysis_*.md files in the root directory and moves them to pending_analysis/

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$SCRIPT_DIR"
PENDING_ANALYSIS_DIR="$ROOT_DIR/pending_analysis"

# Create pending_analysis directory if it doesn't exist
mkdir -p "$PENDING_ANALYSIS_DIR"

# Counter for moved files
moved_count=0

echo "=== Analysis File Mover ==="
echo "Time: $(date '+%Y-%m-%d %H:%M:%S')"
echo "Checking for misplaced analysis files in: $ROOT_DIR"
echo ""

# Find all analysis_*.md files in root directory
for file in "$ROOT_DIR"/analysis_*.md; do
    if [ -f "$file" ]; then
        filename=$(basename "$file")
        
        # Check if file already exists in pending_analysis
        if [ -f "$PENDING_ANALYSIS_DIR/$filename" ]; then
            echo "⚠️  File already exists in pending_analysis: $filename"
            echo "   Renaming with timestamp..."
            timestamp=$(date +%s)
            new_filename="${filename%.md}_${timestamp}.md"
            mv "$file" "$PENDING_ANALYSIS_DIR/$new_filename"
            echo "   ✓ Moved to: $new_filename"
        else
            # Move the file
            mv "$file" "$PENDING_ANALYSIS_DIR/"
            echo "✓ Moved: $filename → pending_analysis/"
        fi
        
        ((moved_count++))
    fi
done

# Summary
echo ""
echo "=== Summary ==="
if [ $moved_count -eq 0 ]; then
    echo "No misplaced analysis files found."
else
    echo "Moved $moved_count file(s) to pending_analysis directory."
fi

echo ""
echo "Current pending_analysis files:"
ls -la "$PENDING_ANALYSIS_DIR"/*.md 2>/dev/null | wc -l | xargs echo "Total files:"

# Optional: Add this script to crontab for automatic monitoring
echo ""
echo "To run this automatically every minute, add to crontab:"
echo "* * * * * $SCRIPT_DIR/move_analysis_files.sh >> $SCRIPT_DIR/logs/file_mover.log 2>&1"