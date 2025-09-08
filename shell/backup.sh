#!/bin/bash

# 처리할 대상 폴더와 조건 정의
# format: "폴더|조건"
# 조건: "all" -> 모든 파일, "older_than_60" -> 60분 이상 지난 파일
TARGETS=(
  "/var/www/html/webhookTest/processed_webhooks|all"
  "/var/www/html/webhookTest/processed_jira|all"
  "/var/www/html/webhookTest/pending_analysis|older_than_60"
)

for target in "${TARGETS[@]}"; do
  IFS='|' read -r SRC CONDITION <<< "$target"
  BACKUP="$SRC/backup"

  # backup 폴더 생성
  mkdir -p "$BACKUP"

  if [ "$CONDITION" = "all" ]; then
    files=("$SRC"/*.json "$SRC"/*.md)
  elif [ "$CONDITION" = "older_than_60" ]; then
    files=()
    while IFS= read -r f; do
        files+=("$f")
    done < <(find "$SRC" -maxdepth 1 -type f \( -name "*.md" -o -name "*.json" \) -mmin +60)
  fi

  # 파일 이동
  for file in "${files[@]}"; do
    [ -e "$file" ] || continue  # 파일 없으면 skip

    # 수정 시간 기준 날짜
    file_date=$(date -d @"$(stat -c %Y "$file")" +"%Y-%m-%d")

    # 날짜별 백업 폴더 생성
    mkdir -p "$BACKUP/$file_date"

    # 파일 이동
    mv "$file" "$BACKUP/$file_date/"
  done
done