# U-Mail: 90-second screenshot walkthrough

The accompanying MP4 is a captioned walkthrough of the repository's existing demo screenshots, followed by the current architecture and test results. It is not a recording of live interactions or a measured AI benchmark. No real mailbox data or credentials are added.

| Time | Visual | Narration / talking point |
| --- | --- | --- |
| 0–10s | Employee login | U-Mail is an internal mailbox platform built for UTICA Jendouba during my MajestEYE internship. Employees sign in through their own portal. |
| 10–25s | Inbox | The workspace organizes conversations, drafts and scheduled mail. Search and mailbox actions operate within the current employee's mailbox. |
| 25–40s | Thread reader | A thread combines reading, reply preparation and contextual U-Assist actions. Attachment downloads and previews check mailbox access. Administrators cannot inspect arbitrary employee mail. |
| 40–60s | Compose | U-Assist uses local Llama 3.2 to help prepare and review messages. Users review prepared drafts and confirm mailbox actions. Authorization is enforced in application code, including when model output references a conversation. |
| 60–75s | Architecture | Laravel serves the UI and checks access. MySQL stores application state and queued work; files remain private. Workers call local Ollama and the SMTP gateway. The scheduler handles due mail and retention. |
| 75–90s | Engineering evidence | The hardening branch removes recoverable password copies, verifies expiring single-use resets, and adds CI. The suite passes 134 tests with 1,083 assertions. This is a local and CI result, not a security certification. |

## Recording a live replacement

Use two synthetic employee accounts and a synthetic administrator in an isolated database. Capture sign-in, opening a seeded thread, requesting a summary, reviewing a draft, and demonstrating that a second account cannot open the same attachment. Use real Ollama output or label a model-unavailable state explicitly. Never record production inboxes, reset codes, environment variables, or credential screens.
