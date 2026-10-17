---
phase: 02-draft-model-review-queue-submissions
fixed_at: 2026-10-17T22:05:00Z
review_path: .planning/phases/02-draft-model-review-queue-submissions/02-REVIEW.md
iteration: 1
findings_in_scope: 2
fixed: 2
skipped: 0
status: all_fixed
---

# Phase 02: Code Review Fix Report

**Fixed at:** 2026-10-17T22:05:00Z
**Source review:** .planning/phases/02-draft-model-review-queue-submissions/02-REVIEW.md
**Iteration:** 1
**Scope:** owner decision, CR-01 and CR-02 only. WR-01..WR-08 and IN-01..IN-07 are deferred (see 02-REVIEW-DISPOSITION.md) and were not touched.

**Summary:**
- Findings in scope: 2
- Fixed: 2
- Skipped: 0

## Fixed Issues

### CR-01: Privacy notice vs sessions (IP/user agent stored, plaintext flashed input)

**Files modified:** `app/Support/PrivacyDatabaseSessionHandler.php` (new), `app/Providers/AppServiceProvider.php`, `config/session.php`, `.env.example`, `database/migrations/2026_10_17_215438_clear_sessions_stored_with_ip_addresses.php` (new), `resources/views/privacy.blade.php`, `tests/Feature/Public/PrivacyPageTest.php`, `tests/Feature/Submissions/SessionPrivacyTest.php` (new)
**Commit:** 2355016
**Applied fix:**
- `PrivacyDatabaseSessionHandler` extends `Illuminate\Session\DatabaseSessionHandler` and overrides `addRequestInformation()` so `ip_address` and `user_agent` are never written. (Checked against vendor Laravel v13.37.0: `getDefaultPayload()` → `addRequestInformation()`; `SessionManager::callCustomCreator()` still wraps custom handlers in `buildSession()`, so encryption applies.)
- `Session::extend('database', …)` in `AppServiceProvider` replaces the built-in driver itself rather than adding a new driver name. That way production or local `.env` files that still say `SESSION_DRIVER=database` can't fall back to Laravel's handler. `config/session.php` and `.env.example` keep `database`.
- `session.encrypt` now defaults to `true`. `.env.example` sets `SESSION_ENCRYPT=true`.
- Data-only migration deletes the existing `sessions` rows. They hold IPs and user agents, and may hold plaintext flashed form input. Once encryption is on they can't be read anyway (`EncryptedStore` falls back to an empty session). Effect: visitors get a fresh session and the admin signs in again. `down()` does nothing. No schema change.
- `/privacy` now says: "The session doesn't record your IP address or browser. If something in the form needs correcting, what you typed is kept in your session, encrypted, so you don't have to type it again. A session ends after two hours without activity." The rate-limit paragraph ("held in that count for up to 24 hours") is unchanged and is now accurate.
- Tests: a GET through the web stack with the database driver leaves `sessions.ip_address` and `user_agent` null and contains neither value. A failed `/submit` carrying an email leaves a payload with no plaintext email and no `_old_input`, and the payload decrypts back to the input. I checked that both tests fail when the handler is not registered.

### CR-02: Same-day end time equal to start time published as a 24-hour event

**Files modified:** `app/Http/Requests/StoreSubmissionRequest.php`, `app/Review/DraftPublishability.php`, `app/Publishing/Data/PublishableEventData.php`, `tests/Feature/Submissions/SubmitValidationTest.php`, `tests/Feature/Review/ApproveDraftTest.php`, `tests/Feature/Filament/BulkReviewTest.php`, `tests/Unit/Publishing/PublishableEventDataTest.php`
**Commit:** 2ff83f7
**Applied fix:**
- `StoreSubmissionRequest`: an `end_time` closure rule refuses an end time equal to the start time when there is no end date or the end date is the start date. It uses the event form's wording, "The end time must be different from the start time."
- `DraftPublishability`: a new field-level blocker `end_time_same_as_start` with the same message, used by single approve, the A key and the Approve button. It is also a specific bulk skip key, "end time same as start time", placed after `repeats_unset` and before `blocked`.
- `PublishableEventData::assertValid()`: the same invariant, so non-Filament paths are covered. New `sameClockTime()` helper treats HH:MM and HH:MM:SS as equal. The constructor comment that lists the mirrored rules is updated.
- Equal times are still allowed when the end date is a later day, and an end earlier than the start (overnight) still works.
- Tests: submission refused (no end date, and end date on the start date), with multi-day and overnight cases still accepted. Single approve is refused with exactly this blocker. Bulk keys and skip wording are covered, plus an end-to-end bulk-approve notification "1 skipped: end time same as start time". The DTO rejects both shapes and accepts the multi-day case.

## Verification

All checks ran in the isolated worktree (`.github/worktrees/rf-02-…`, with vendor copied in and `.env` copied). It was then fast-forwarded into `main` in the main checkout and removed.

- Targeted tests: CR-01 10/10 passed. CR-02 175/175 passed (SubmitValidation, ApproveDraft, BulkReview, PublishableEventData, ApproveAndNext, EditEventDraft).
- Full `./vendor/bin/pest --testsuite=Unit,Feature`: 1005 passed, 5178 assertions. No "relation does not exist" flakes.
- `vendor/bin/pint --dirty --format agent`: passed. `vendor/bin/phpstan analyse --memory-limit=1G`: 0 errors.
- The main checkout's `.env` now has `SESSION_ENCRYPT=true` for local parity. It is not committed.
- The new migration is still **Pending** on the dev database. Run `php artisan migrate`; it will sign the admin out once.

---

_Fixed: 2026-10-17T22:05:00Z_
_Fixer: the agent (gsd-code-fixer)_
_Iteration: 1_
