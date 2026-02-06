# Cron and “Post now” behaviour

## 1. “Post now” – immediate or scheduler?

**“Post now” is added to the scheduler; it does not publish in the same request.**

When you choose **Post now** in the UI:

- The backend creates a `ScheduledPost` with `scheduled_at = now()` (UTC) and `status = scheduled`.
- The post is stored like any other scheduled post; the **cron job** is what actually publishes it.
- So there is a short delay (up to one cron interval, e.g. 1 minute) before it goes live.

There is no “publish in this HTTP request” path; everything goes through the same cron-driven flow for consistency and retries.

---

## 2. Is the cron job running?

**Laravel does not run the scheduler by default.** You must set up **system cron** on the server (or your local machine) to run the Artisan commands.

### Commands to run via cron

| What | Command | Suggested frequency |
|------|---------|---------------------|
| Publish scheduled posts (and “post now”) | `php artisan postiz:process-scheduled-posts` | Every minute |
| Repeat rules | `php artisan postiz:process-repeat-posts` | Every 5 minutes |
| Token refresh | `php artisan postiz:process-tokens` | Every 15–30 minutes |
| Notification digests | `php artisan postiz:process-digests` | Every 15 minutes |
| Autopost rules | `php artisan postiz:process-autopost` | Hourly |
| Missing posts | `php artisan postiz:process-missing-posts` | Every 5–15 minutes |
| Streak reminders | `php artisan postiz:process-streaks` | Daily |

### Example crontab (run from project root)

From the **Laravel app root** (e.g. `apps/backend-laravel` or your deployment path):

```bash
# Every minute: publish due scheduled posts (including “post now”)
* * * * * cd /path/to/backend-laravel && php artisan postiz:process-scheduled-posts >> /dev/null 2>&1

# Every 5 minutes: repeat rules
*/5 * * * * cd /path/to/backend-laravel && php artisan postiz:process-repeat-posts >> /dev/null 2>&1

# Every 15 minutes: token refresh, digests
*/15 * * * * cd /path/to/backend-laravel && php artisan postiz:process-tokens >> /dev/null 2>&1
*/15 * * * * cd /path/to/backend-laravel && php artisan postiz:process-digests >> /dev/null 2>&1
```

Replace `/path/to/backend-laravel` with the actual path. On shared hosting (e.g. Krystal cPanel), use the cron UI to add these with the same schedule.

---

## 3. Logs for debugging cron and errors

### Log file

- **Path:** `storage/logs/postiz.log` (daily rotation, 14 days by default).
- **Content:** Cron runs, post state transitions, publish attempts, and errors (when enabled).

### Enabling debug logging

- In `.env` set:
  - `POSTIZ_DEBUG=true`
- When `POSTIZ_DEBUG=true`:
  - Every cron run logs `cron.run.start` and `cron.run.end`.
  - Every publish attempt logs `post.publish.attempt`.
  - Every state change logs `post.transition` (e.g. scheduled → processing → published).
- When `POSTIZ_DEBUG=false`:
  - Only **errors** and critical events are logged (e.g. `post.publish.error`, transitions to `failed`).

So for debugging “post now” or cron issues, set `POSTIZ_DEBUG=true` and tail `storage/logs/postiz.log`. Each line is JSON for easier parsing.

### Other env (optional)

- `POSTIZ_CRON_BATCH_SIZE=10` – max posts processed per run (default 10).
- `POSTIZ_RETRY_BACKOFF_MINUTES=5` – delay before retrying a failed post.
