# U-Mail architecture

This diagram describes the implementation on `main` after the security changes. It is a Laravel monolith with a separate queue worker and scheduler.

```mermaid
flowchart LR
    browser["Employee or administrator browser"] -->|"HTTPS"| gateway["Web server / Caddy pilot"]
    gateway -->|"Requests"| app["Laravel app: Blade UI, session auth, MFA, authorization"]
    app -->|"Mail, accounts, sessions, audit, agent runs and queued jobs"| db[("MySQL; SQLite for tests")]
    app -->|"Authorized uploads and downloads"| files["Private attachment storage"]
    worker["Laravel queue worker"] -->|"Claim jobs and persist results"| db
    app -.->|"Enqueue through database"| worker
    worker -->|"U-Assist context and generation"| ollama["Host-local Ollama / Llama 3.2"]
    app -->|"Inline compose generation"| ollama
    worker -->|"Queued external mail"| smtp["SMTP provider / local Mailpit"]
    worker -->|"Read attachments for delivery"| files
    app -->|"Account notifications"| smtp
    scheduler["Laravel scheduler: every minute"] -->|"Scheduled mail, trash and audit retention"| db
    scheduler -.->|"Dispatch due deliveries"| worker
```

## Trust boundaries

- Every mailbox, attachment and agent-run request is scoped to the signed-in user. Administrator status does not grant access to other employees' messages.
- U-Assist receives authorized mailbox context. Model output is untrusted; prepared actions are checked again when the user confirms them. Draft creation also validates thread and parent-message ownership.
- Files live outside the public web root. Controllers authorize downloads and previews before reading them.
- Passwords are one-way hashes. Reset codes are hashed, expire after 15 minutes, and are consumed once; resets revoke sessions. The former credential registry is removed by a migration.
- Ollama is host-local in the coworker pilot. Remote browsers access Laravel, not the Ollama port. SMTP is a separate integration; local Mailpit captures development mail.

## Trade-off

A Laravel monolith and database-backed queue simplify deployment, transactional mailbox updates, and debugging. The cost is shared database load and less independent scaling than dedicated messaging infrastructure. Separate workers keep long-running mail delivery and AI requests out of ordinary HTTP requests; inline compose remains synchronous and can wait on model latency.

## Source map

The diagram follows `routes/web.php`, `MailService`, `AgentRunController`, `UmailAgentService`, `LocalAgentEngineService`, `ProcessAgentRun`, `DeliverExternalMessage`, `config/queue.php`, and `routes/console.php`. A screenshot walkthrough does not establish production delivery, model accuracy, or throughput.
