# AI / Copilot logs

## Where AI logs are written

| Log | Path | Contents |
|-----|------|----------|
| **Copilot / AI only** | `apps/backend-laravel/storage/logs/copilot.log` | OpenAI request failures, persist errors, agent errors, threadList errors. Daily rotation (see `config/logging.php`). |
| **All Laravel** | `apps/backend-laravel/storage/logs/laravel.log` | Default app log; Copilot errors are also written to the `copilot` channel above so you can tail just AI. |

To watch AI-related logs only:

```bash
# From project root
tail -f apps/backend-laravel/storage/logs/copilot.log
```

On Windows (PowerShell):

```powershell
Get-Content apps\backend-laravel\storage\logs\copilot.log -Wait
```

## When we log

- **OpenAI request failed**: `CopilotKitAgentService::callOpenAi()` catches cURL/API errors (e.g. SSL, rate limit) and logs to `copilot` channel.
- **Copilot persist messages failed**: Saving thread/messages to DB failed (e.g. constraint error).
- **Copilot agent error**: Unhandled exception in `CopilotController::agent()` (e.g. bad GraphQL body).
- **Copilot threadList failed**: Loading messages for a thread failed.

All of these also go to the default log (e.g. `laravel.log`) if the default channel is `stack` and includes `single`/`daily`.
