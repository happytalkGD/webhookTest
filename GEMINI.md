# GEMINI Project Analysis

## Project Overview

This project is a **GitHub Webhook Automation System** designed to analyze GitHub push events, generate summaries using a Large Language Model, and post those summaries to associated Jira tickets.

The system is built primarily with **PHP** and **shell scripts**. It operates as a pipeline, where different scripts are responsible for specific stages of the process:

1.  **Webhook Reception:** A PHP script (`github.hook.php`) receives a webhook payload from GitHub for every `push` event.
2.  **Analysis:** A core PHP script (`claude.analyze.php`) processes the webhook. It constructs a detailed prompt, including commit messages and a GitHub Compare URL, and uses a command-line AI tool (`claude`) to generate an analysis of the code changes.
3.  **Jira Integration:** Another PHP script (`jira.hook.php`) takes the generated analysis, formats it for Jira's markup language, and posts it as a comment to the relevant Jira ticket. It can also update the ticket's description if it's empty.

The system is designed to be run via **cron jobs**, ensuring continuous and automated operation.

### Key Technologies

*   **Backend:** PHP
*   **Scripting:** Shell Script (`.sh`)
*   **Data Formats:** JSON, YAML
*   **CI/CD & Automation:** GitHub Webhooks, Cron
*   **Integrations:** Jira API, GitHub API, Claude (via CLI)

### Architectural Highlights

*   **Pipeline Architecture:** The system uses a series of directories (`pending_webhooks`, `pending_analysis`, `processed_webhooks`, `processed_jira`, `error_analysis`) to manage the state of each webhook through the processing pipeline. This makes the system resilient and easy to debug.
*   **Modular Design:** Core logic is separated into distinct scripts for handling webhooks, analysis, and Jira integration. Shared functionality is centralized in a `common.lib.php` file.
*   **Configuration-driven:** The system uses a `.env` file for sensitive credentials (like Jira API keys) and YAML/JSON files for AI prompt templating.
*   **Robustness:** The system includes features like a locking mechanism to prevent concurrent script execution, error detection for the AI analysis, and a debug mode for safe testing.

## Building and Running

This project does not have a traditional build process. It is run directly using the PHP interpreter.

### Prerequisites

*   PHP with the `curl` extension.
*   A command-line tool named `claude` must be available in the system's PATH.
*   A `.env` file with the necessary Jira and GitHub credentials. An example is provided in `.env.example`.

### Running Manually

You can run the core scripts manually for testing or debugging purposes.

**1. Analyze pending webhooks:**

```bash
php claude.analyze.php
```

**2. Post analysis to Jira:**

```bash
php jira.hook.php
```

**3. Run Jira integration in debug mode (does not post to Jira):**

```bash
php jira.hook.php --debug
```

### Running with Automation (Cron)

The system is intended to be automated using `crontab`. The following is a recommended setup to run the analysis and Jira integration scripts every minute.

```bash
# Analyze new webhooks every minute
* * * * * cd /home/gd/webhookTest && php claude.analyze.php >> logs/cron_analyze.log 2>&1

# Post analysis to Jira every minute
* * * * * cd /home/gd/webhookTest && php jira.hook.php >> logs/cron_jira.log 2>&1
```

## Development Conventions

*   **Error Handling:** Errors are logged to files in the `logs/` directory. The Jira integration script also moves failed analysis files to the `error_analysis/` directory for manual inspection.
*   **Jira Ticket Identification:** The system identifies Jira tickets by looking for specific patterns (e.g., `PROJ-123`) in commit messages or the branch name. Commits without a ticket ID are skipped.
*   **Prompt Engineering:** AI prompts are managed through a sophisticated templating system using YAML (`prompts_*.yaml`) and JSON (`prompt_template.json`). This allows for different prompt strategies based on the context (e.g., normal push, merge with conflicts, large number of commits).
*   **Testing:** The project includes numerous `test_*.php` scripts, suggesting a practice of creating individual test files for different components and functionalities.
*   **Code Style:** The PHP code is procedural, with functionality organized into functions. A `common.lib.php` file is used for shared utilities, and a `LockManager` class is used for managing concurrency.
