# 프롬프트 템플릿 가독성 버전

이 문서는 `prompt_template.json`의 가독성 개선 버전입니다.

---

## 시스템 프롬프트

```
You are a Git commit analyzer. Analyze the provided Git repository changes 
and provide a detailed summary in Korean. You can fetch and analyze GitHub 
repository changes using the compare URL provided.
```

---

## 메인 프롬프트 구조

### 1. 인트로
```
다음 Git Push 이벤트의 변경사항을 분석하고 한국어로 요약해주세요.
```

### 2. 병합 충돌 경고 (해당 시)
```
⚠️ **주의: 이것은 충돌이 있었던 병합 커밋입니다**
충돌 해결 부분에 특별히 주목하여 분석해주세요.
```

### 3. 기본 정보
```
=== 기본 정보 ===
저장소: {repository}
브랜치: {branch}
작성자: {author}
커밋 범위: {before_commit} → {after_commit}
```

### 4. 커밋 내역
```
=== 커밋 내역 ===
총 {total}개 커밋 중 최근 {shown}개만 표시

1. [{commit_id}] {message}
   작성자: {author}
   변경: 추가 {added}개, 수정 {modified}개, 삭제 {removed}개 파일
   파일: {files}
```

### 5. Git 분석 작업

#### Compare URL이 있는 경우:
```
=== Git 분석 작업 ===
GitHub Compare API: {url}

API를 호출하여 실제 코드 변경사항(files, patches, stats)을 
가져온 후, 다음 관점에서 분석해주세요:
- 변경된 파일들의 목적과 역할
- 코드 변경의 일관성과 품질
- 모듈 간 의존성 변화
- 주요 로직 변경사항
```

#### Compare URL이 없는 경우:
```
=== Git 분석 작업 ===
source 폴더로 이동하여 git 으로 변경된 내용을 분석 해주세요:
source >  cd ./source/{repo_name}
```

### 6. 요약 형식
```
=== 요약 형식 ===
다음 형식으로 요약해주세요:

📌 **주요 변경사항**
📁 **영향받는 모듈**
🎯 **변경 목적**
🔍 **기술적 세부사항**
```

### 7. 충돌 해결 내역 (병합 충돌 시)
```
⚠️ **충돌 해결 내역**: (충돌이 발생했던 파일과 해결 방법)

충돌 분석 시 다음 사항을 확인해주세요:
- 어떤 파일에서 충돌이 발생했는지
- 충돌이 어떻게 해결되었는지 (어느 브랜치의 코드를 선택했는지)
- 충돌 해결 과정에서 추가/수정된 코드가 있는지
```

### 8. 제약 사항
```
문서 길이는 30-100 Line 사이로 작성해주세요. 
너무 짧거나 길지 않게 균형을 맞춰주세요.

실제 작업된 내용만 작성하고, 추가 제안이나 개선 사항은 작성하지 마세요.

중요: 제공된 정보와 커밋 메시지를 기반으로 정확하고 구체적인 분석을 제공해주세요.

분석 깊이: 단순 나열이 아닌 변경의 의도와 영향을 파악하여 작성해주세요.
```

---

## 간소화된 프롬프트 (큰 커밋용)

```
다음 Git Push 이벤트의 변경사항을 분석하고 한국어로 요약해주세요.

=== 기본 정보 ===
저장소: {repository}
브랜치: {branch}
커밋 수: {commit_count}개
커밋 범위: {before_commit} → {after_commit}

GitHub Compare URL: {url}

위 URL을 사용하여 변경사항을 직접 확인하고 다음 형식으로 요약해주세요:

📌 **주요 변경사항**: (핵심 변경 내용)
📁 **영향받는 모듈**: (주요 파일/디렉토리)
🎯 **변경 목적**: (추측)

50줄 이내로 매우 간결하게 작성해주세요.
```

---

## 설정값

| 설정 | 값 | 설명 |
|------|-----|------|
| max_commits_detail | 10 | 상세히 표시할 최대 커밋 수 |
| max_prompt_length | 10000 | 프롬프트 최대 길이 (바이트) |
| max_files_per_commit | 10 | 커밋당 표시할 최대 파일 수 |
| max_files_to_list | 5 | 리스트로 표시할 최대 파일 수 |
| truncate_message_at | 200 | 커밋 메시지 최대 길이 |
| truncate_commit_id_to | 7 | 커밋 ID 표시 길이 |

---

## 분석 프로파일

### 1. 기본 분석 (default)
- **언어**: 한국어
- **출력 길이**: 100줄
- **중점**: 변경사항, 영향받는 모듈, 변경 목적

### 2. 상세 분석 (detailed)
- **언어**: 한국어
- **출력 길이**: 200줄
- **중점**: 변경사항, 영향받는 모듈, 변경 목적, 코드 품질, 잠재적 문제점
- **추가 지시**:
  - 코드 변경의 품질과 일관성을 평가해주세요
  - 잠재적인 버그나 성능 문제가 있는지 확인해주세요

### 3. 보안 분석 (security)
- **언어**: 한국어
- **출력 길이**: 150줄
- **중점**: 보안 영향, 취약점, 권한 변경, 민감 데이터
- **추가 지시**:
  - 보안상 위험이 될 수 있는 변경사항이 있는지 확인해주세요
  - 비밀번호, API 키, 토큰 등 민감한 정보가 노출되었는지 검사해주세요
  - 권한 관련 변경사항이 있는지 확인해주세요

### 4. 성능 분석 (performance)
- **언어**: 한국어
- **출력 길이**: 100줄
- **중점**: 성능 영향, 최적화, 리소스 사용, 알고리즘 효율성
- **추가 지시**:
  - 성능에 영향을 줄 수 있는 변경사항을 식별해주세요
  - 비효율적인 알고리즘이나 리소스 사용이 있는지 확인해주세요

### 5. 영어 분석 (english)
- **언어**: 영어
- **출력 길이**: 100줄
- **중점**: changes, affected modules, purpose
- **시스템 프롬프트**: 영어 버전 사용
- **요약 형식**:
  - 📌 **Main Changes**: (1-2 lines of core changes)
  - 📁 **Affected Modules**: (main files/directories)
  - 🎯 **Change Purpose**: (based on commit messages and code changes)

---

## 템플릿 변수 참조

| 변수 | 설명 | 예시 |
|------|------|------|
| `{repository}` | 전체 저장소 이름 | example/test-repo |
| `{branch}` | 브랜치 이름 | main |
| `{author}` | 커밋 작성자 | 홍길동 |
| `{before_commit}` | 이전 커밋 해시 (7자) | abc1234 |
| `{after_commit}` | 이후 커밋 해시 (7자) | def5678 |
| `{total}` | 전체 커밋 수 | 10 |
| `{shown}` | 표시된 커밋 수 | 5 |
| `{idx}` | 커밋 인덱스 | 1, 2, 3... |
| `{commit_id}` | 커밋 ID (7자) | def5678 |
| `{message}` | 커밋 메시지 | 프롬프트 템플릿 기능 추가 |
| `{added}` | 추가된 파일 수 | 2 |
| `{modified}` | 수정된 파일 수 | 3 |
| `{removed}` | 삭제된 파일 수 | 1 |
| `{files}` | 파일 목록 | file1.php, file2.js |
| `{files_list}` | 쉼표로 구분된 파일 목록 | file1, file2, file3 |
| `{count}` | 일반적인 개수 | 5 |
| `{url}` | GitHub compare URL | https://api.github.com/... |
| `{repo_name}` | 저장소 이름 (owner 제외) | test-repo |
| `{commit_count}` | 전체 커밋 개수 | 10 |