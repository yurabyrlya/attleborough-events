---
phase: 02-draft-model-review-queue-submissions
reviewed: 2026-10-17T21:55:00Z
depth: standard
diff_base: 8ca700e
files_reviewed: 83
files_reviewed_list:
  - .ai/guidelines/attleborough.md
  - .env.example
  - AGENTS.md
  - app/Enums/DraftKind.php
  - app/Enums/ExtractionMethod.php
  - app/Enums/QualityBand.php
  - app/Enums/RejectReason.php
  - app/Enums/ReviewStatus.php
  - app/Filament/Pages/Rules.php
  - app/Filament/Resources/EventDrafts/Actions/ReviewActions.php
  - app/Filament/Resources/EventDrafts/EventDraftResource.php
  - app/Filament/Resources/EventDrafts/Pages/EditEventDraft.php
  - app/Filament/Resources/EventDrafts/Pages/ListEventDrafts.php
  - app/Filament/Resources/EventDrafts/Pages/ReviewEventDraft.php
  - app/Filament/Resources/EventDrafts/Schemas/EventDraftForm.php
  - app/Filament/Resources/EventDrafts/Schemas/EventDraftInfolist.php
  - app/Filament/Resources/EventDrafts/Tables/EventDraftsTable.php
  - app/Filament/Resources/Events/EventResource.php
  - app/Filament/Resources/Events/Pages/CreateEvent.php
  - app/Filament/Resources/Events/Schemas/EventForm.php
  - app/Http/Controllers/SubmissionController.php
  - app/Http/Requests/StoreSubmissionRequest.php
  - app/Models/Category.php
  - app/Models/CategoryKeywordRule.php
  - app/Models/CategoryMapping.php
  - app/Models/Event.php
  - app/Models/EventDraft.php
  - app/Models/GeofenceTerm.php
  - app/Ops/HealthReport.php
  - app/Providers/AppServiceProvider.php
  - app/Publishing/Actions/ApproveDraft.php
  - app/Publishing/Actions/RejectDraft.php
  - app/Publishing/Actions/ReopenDraft.php
  - app/Publishing/Data/PublishableEventData.php
  - app/Review/CategoryAssigner.php
  - app/Review/Data/CategoryDecision.php
  - app/Review/Data/DraftData.php
  - app/Review/Data/QualityResult.php
  - app/Review/Data/VenueSuggestion.php
  - app/Review/DraftPublishability.php
  - app/Review/DraftWriter.php
  - app/Review/Exceptions/DraftNotPublishable.php
  - app/Review/Exceptions/InvalidReviewTransition.php
  - app/Review/Exceptions/RulesNotSaved.php
  - app/Review/QualityScorer.php
  - app/Review/ReferenceCode.php
  - app/Review/ReviewQueue.php
  - app/Review/SaveRules.php
  - app/Review/VenueMatcher.php
  - app/Submissions/Honeypot.php
  - app/Submissions/TurnstileVerifier.php
  - app/Support/EventPageData.php
  - app/Support/LondonFormatter.php
  - app/Support/OccurrenceCardData.php
  - config/review.php
  - config/services.php
  - database/factories/EventDraftFactory.php
  - database/migrations/2026_10_17_100200_create_event_drafts_table.php
  - database/migrations/2026_10_17_100300_add_contact_public_and_organiser_website_to_events.php
  - database/migrations/2026_10_17_100400_create_category_keyword_rules_table.php
  - database/migrations/2026_10_17_100410_create_category_mappings_table.php
  - database/migrations/2026_10_17_100420_create_geofence_terms_table.php
  - database/seeders/DatabaseSeeder.php
  - database/seeders/RulesSeeder.php
  - ops/probe-sources.sh
  - phpunit.xml
  - resources/css/app.css
  - resources/js/submit.js
  - resources/views/components/submit/honeypot.blade.php
  - resources/views/errors/429.blade.php
  - resources/views/events/show.blade.php
  - resources/views/filament/pages/rules.blade.php
  - resources/views/filament/resources/event-drafts/entries/safe-link.blade.php
  - resources/views/filament/resources/event-drafts/keyboard-help.blade.php
  - resources/views/filament/resources/event-drafts/pages/review-event-draft.blade.php
  - resources/views/filament/resources/event-drafts/raw-content.blade.php
  - resources/views/layouts/app.blade.php
  - resources/views/privacy.blade.php
  - resources/views/submit/create.blade.php
  - resources/views/submit/thanks.blade.php
  - routes/console.php
  - routes/web.php
  - vite.config.js
findings:
  critical: 2
  warning: 8
  info: 7
  total: 17
status: issues_found
---

# Phase 2: Code Review Report

**Reviewed:** 2026-10-17T21:55:00Z
**Depth:** standard (with targeted cross-file tracing into `EventTiming`, Filament `Select`/`HasState`/`Callout`/`HasGlobalSearch`, and Laravel `DatabaseSessionHandler`)
**Files Reviewed:** 83
**Status:** issues_found

## Summary

I reviewed the Phase 2 draft model, review queue, public submission form, Rules screen and supporting views against AGENTS.md / `.ai/guidelines/attleborough.md` and the decisions D-01..D-26 in 02-CONTEXT.md.

Most of the core holds up. The draft→event approval is atomic: the draft row is locked, the status transition is asserted on the fresh row, `PublishEvent` runs as a savepoint, and the partial unique index `event_drafts_one_new_per_event` backs this up. A double approve is refused at three layers. `PublishEvent` is still the only creator of events, and the arch test enforces this with `toOnlyBeUsedIn`. Every organiser string reaches the admin and public pages through escaped output: I checked that Filament's `Callout` heading and description go through `e()`. Links are filtered to http/https. Global search is gated through `canAccess()`, which `canGloballySearch()` really does consult. The SSRF surface in the Turnstile verifier is a constant URL.

Two problems should block shipping:

1. **The privacy notice is wrong about IP addresses.** `/privacy` says the IP is "held in that count for up to 24 hours and is not stored with your submission". In fact the database session driver writes `ip_address` and `user_agent` to `sessions` for every visitor. It also writes the full submission (including the organiser's email) unencrypted into the session payload whenever the form fails validation.
2. **The public form accepts a same-day end time equal to the start time.** The approval path never re-checks the admin form's "end must differ from start" rule. `EventTiming` then publishes the event as a 24-hour occurrence, and bulk approve will take it without the admin opening the draft.

The warnings cover:

- The rate limiter counts failed POSTs, and behind Cloudflare it is effectively one shared bucket for everyone on the same edge.
- The Turnstile hostname check fails open in production.
- Rules saves can silently delete rows created after the page loaded.
- A suggested venue silently becomes a confirmed venue on any save, defeating D-21.
- Past-dated drafts and absurdly long end dates are publishable.
- Personal data in pending drafts has no retention limit.

## Critical Issues

### CR-01: The privacy notice says IPs are only kept in the rate-limit count, but every session row stores the IP and user agent, and failed submissions put the organiser's details in plaintext session payloads

**Files:**
- `resources/views/privacy.blade.php:38-39`
- `config/session.php:21`
- `.env.example:31-33`
- (framework) `vendor/laravel/framework/src/Illuminate/Session/DatabaseSessionHandler.php:254-255`

**Issue:** The interim privacy notice (D-04) says: *"The IP address is held in that count for up to 24 hours and is not stored with your submission."* It also says the session cookie exists only "so the form can be sent securely". In reality:

- `SESSION_DRIVER=database` (the default and `.env.example`, required by the "no Redis" rule) makes `DatabaseSessionHandler` write `ip_address` and `user_agent` into `sessions` for every visitor of every public page, not only people using the form.
- Those rows live for the session lifetime, and after that until the 2/100 GC lottery removes them.
- When validation fails, Laravel's redirect-back flashes all the input (organiser name, email, website, description) into the session payload. `$dontFlash` only covers password fields, and with `SESSION_ENCRYPT=false` that payload is stored as plaintext in the database.

The review brief requires "no IPs stored". D-03/D-04 are marked costly to reverse because the notice is a published promise to organisers. As shipped, the notice is factually wrong. The `RateLimitTest` "stores no IP address with the draft" only looks at `event_drafts`, so it misses this.

**Fix:** Make the code match the notice, or change the notice. Ideally do both:

```php
// app/Session/PrivacyDatabaseSessionHandler.php
final class PrivacyDatabaseSessionHandler extends \Illuminate\Session\DatabaseSessionHandler
{
    protected function addRequestInformation(&$payload)
    {
        return $this; // never persist ip_address / user_agent
    }
}
// AppServiceProvider::boot()
Session::extend('database', fn ($app) => new PrivacyDatabaseSessionHandler(
    $app['db']->connection(config('session.connection')), config('session.table'),
    config('session.lifetime'), $app,
));
```

Also set `SESSION_ENCRYPT=true` in `.env.example` and production so flashed submission input isn't stored as plaintext. Add a feature test that POSTs an invalid submission and asserts `sessions.ip_address IS NULL`.

If you keep IP logging instead, rewrite the "Spam protection" paragraph to disclose session-table IP and user-agent storage and how long it is kept.

### CR-02: A same-day end time equal to the start time is accepted and published as a 24-hour event, and the admin form's rule is bypassed on approve and bulk approve

**Files:**
- `app/Http/Requests/StoreSubmissionRequest.php:121-123`
- `app/Review/DraftPublishability.php:122-171`
- `app/Publishing/Data/PublishableEventData.php:53-60, 73-122`
- (consumer) `app/Calendar/EventTiming.php:64-66`
- (the rule that is not mirrored) `app/Filament/Resources/Events/Schemas/EventForm.php:134`

**Issue:** `StoreSubmissionRequest` validates `end_time` only as `nullable|date_format:H:i`, so `start_time=19:00, end_time=19:00`, no end date, is stored on the draft. Nothing between the draft and the event checks it:

- `DraftPublishability::blockers()` doesn't.
- `PublishableEventData::assertValid()` doesn't, even though its comment says it "Mirrors the form's rules".

`EventTiming::forDate()` treats `end <= start` as overnight and adds a day. The event is therefore published as 19:00 to 19:00 the next day. It stays on the public calendar for 24 hours, and its `.ics` and Google Calendar link say the same.

The admin only hits the "end time must be different" rule if they open the edit form. The review page **A** key, the Approve button and **bulk approve** all skip it. The draft scores High (no deductions), so bulk approve takes it without anyone looking. No test covers it: `SubmitValidationTest` has no case where end equals start.

**Fix:** Refuse it in all three places, so the domain invariant really does mirror the form:

```php
// StoreSubmissionRequest::rules()
'end_time' => ['exclude_if:all_day,true', 'nullable', 'date_format:H:i', function ($attr, $value, $fail) {
    $sameDay = blank($this->input('end_date')) || $this->input('end_date') === $this->input('start_date');
    if ($sameDay && $value === $this->input('start_time')) {
        $fail('The end time must be different from the start time.');
    }
}],

// PublishableEventData::assertValid()
if ($this->startTime !== null && $this->endTime !== null
    && ($this->endDate === null || $this->endDate === $this->startDate)
    && substr($this->startTime, 0, 5) === substr($this->endTime, 0, 5)) {
    throw new InvalidArgumentException('The end time must be different from the start time.');
}
```

Add a `DraftPublishability` blocker test and a bulk-approve skip test for this draft.

## Warnings

### WR-01: The rate limiter counts every POST, including validation failures, so a real organiser can be locked out after a few corrections, and the 429 page misstates the window

**Files:**
- `routes/web.php:23-25`
- `app/Providers/AppServiceProvider.php:47-50`
- `config/review.php:47-49`
- `resources/views/errors/429.blade.php:4`

**Issue:** `throttle:submissions` hits the limiter on every request, before validation. `RateLimitTest` confirms that five *empty* POSTs use up the hourly allowance. The config comment says these are "Accepted submissions per visitor", which is not what the code does.

The form is long and refuses on several spam checks ("That was quick!", Turnstile retry, stale token), so an organiser who gets a few errors wrong is blocked for an hour. They then see "Too many submissions from here today" even though only the hourly limit tripped.

**Fix:** Count only accepted submissions. One way is to drop the route middleware and call `RateLimiter::attempt()` / `hit()` in `SubmissionController::store()` after `validated()` succeeds. Alternatively, keep a generous route-level ceiling for abuse (for example 30/hour) and add a separate success-only counter at 5/hour and 10/day. Make the 429 copy neutral ("Too many submissions from here. Please try again later") and fix the config comment.

### WR-02: Behind Cloudflare/Caddy the rate limit and Turnstile `remoteip` see the proxy address, so one spammer can block every organiser

**Files:**
- `app/Providers/AppServiceProvider.php:41-50`
- `app/Http/Requests/StoreSubmissionRequest.php:233-236`
- `bootstrap/app.php` (no `trustProxies`)

**Issue:** Cloudflare is already in the stack (D-17), and `bootstrap/app.php` configures no trusted proxies. `$request->ip()` is therefore a Cloudflare edge address, shared by every visitor routed through it. The 5/hour and 10/day "per IP" limit becomes a shared bucket: anyone posting five junk forms an hour stops everyone else on that edge from submitting. That is a cheap denial of service against SUB-01.

The same wrong IP is sent to Cloudflare as `remoteip`, which weakens Turnstile's own scoring. STATE.md defers trusted proxies to Phase 5, but the limiter ships now and the docblock acknowledges the problem.

**Fix:** Before `/submit` goes live, configure `->withMiddleware(fn (Middleware $m) => $m->trustProxies(at: [/* Cloudflare + Caddy ranges */], headers: Request::HEADER_X_FORWARDED_FOR | ...))`. Alternatively, key the limiter on `CF-Connecting-IP` only when the request comes from a trusted Cloudflare range. Add a test that two different `X-Forwarded-For` clients behind a trusted proxy get separate buckets.

### WR-03: The Turnstile hostname check fails open in production, and the health check doesn't notice

**Files:**
- `app/Submissions/TurnstileVerifier.php:77-81`
- `config/services.php:43`
- `app/Ops/HealthReport.php:86-99`
- `app/Http/Requests/StoreSubmissionRequest.php:72-82`

**Issue:** D-17 says the verifier "checks `success`, `action`, `hostname`; fails closed". In practice:

- When `TURNSTILE_HOSTNAMES` is empty, the hostname check is skipped completely, including in production.
- `formIsResting()` and `HealthReport::spamCheck()` only require the two keys, so a production install with no hostnames is reported as OK.
- The env value is split on `,` without trimming, so `a.com, b.com` stores `" b.com"`, which can never match. That fails closed, but the cause isn't obvious.
- Copying `.env.example` (Cloudflare's always-pass test keys) into production also passes the health check while every real submission is refused.

**Fix:**

```php
'hostnames' => array_values(array_filter(array_map('trim', explode(',', (string) env('TURNSTILE_HOSTNAMES', ''))))),
```

In `TurnstileVerifier`, `if ($hostnames === [] && app()->isProduction()) return ['hostname-not-configured'];`. Make `formIsResting()` and `spamCheck()` require hostnames in production, and flag the known Cloudflare test site key (`1x00000000000000000000AA`) as Failed.

### WR-04: Submission end dates have no upper bound, so a single "event" can stay on the calendar for decades

**Files:**
- `app/Http/Requests/StoreSubmissionRequest.php:115-122`
- `app/Publishing/Data/PublishableEventData.php:107-110`

**Issue:** `start_date` is limited to 12 months ahead, but `end_date` only has to be on or after the start date. A submission running 2026-11-01 to 2099-12-31 (timed or all-day) passes validation. It passes every blocker and scores High, so bulk approve publishes it. The result is one occurrence that stays visible on every day's listing until 2099 (`expires_at` is the end). The admin form has no limit either.

**Fix:** Add `'before_or_equal:'.$latest->toDateString()` (or a maximum span such as start + 31 days) to `end_date`. Mirror a maximum span in `PublishableEventData::assertValid()` and add a bulk skip key for long spans.

### WR-05: The Rules screen saves the whole page state, so rules or categories created after the page loaded are silently deleted

**Files:**
- `app/Filament/Pages/Rules.php:209-230`
- `app/Review/SaveRules.php:103-105, 403, 514`

**Issue:** `SaveRules` treats the submitted lists as the complete truth:

- Any stored category whose id is missing from the list counts as "removed", and is deleted if nothing uses it.
- Keyword rules and mappings are cleared with `whereKeyNot($keptIds)->delete()`.

If the admin has Rules open in two tabs, or `db:seed` / RulesSeeder runs on deploy while the page is open, saving the older page deletes the rows that appeared in the meantime. There is no warning, and the "Rules saved" notification hides it. This data loss is not reported as a refusal.

**Fix:** Carry an optimistic-concurrency token. For example, put `max(updated_at)` and the row count of each table in a hidden field on fill, compare them inside the locked transaction, and throw `RulesNotSaved(['The rules changed since you opened this page. Reload and try again.'])` if they differ. Alternatively, only delete ids that were present when the page was filled (keep a hidden list of loaded ids).

### WR-06: The suggested venue (pg_trgm ≥ 0.3) is pre-filled as if confirmed, so any save turns a guess into a confirmed venue and bulk approve then accepts it

**Files:**
- `app/Filament/Resources/EventDrafts/Pages/EditEventDraft.php:99-101`
- `app/Filament/Resources/EventDrafts/Schemas/EventDraftForm.php:52-68`
- `config/review.php:53`

**Issue:** `mutateFormDataBeforeFill()` puts `VenueMatcher::suggest()` into `venue_id`, and the picker shows it like any other selection. There is no "Suggested, please confirm" hint and no visual difference.

If the admin opens the edit page to fix something unrelated (a typo in the title) and clicks **Save**, the low-confidence suggestion (0.3 similarity) becomes the draft's confirmed `venue_id`. From then on bulk approve counts the venue as resolved. That defeats D-21 ("a suggested match alone makes bulk approve skip the draft") and D-05's explicit confirm step.

**Fix:** Don't pre-fill on the edit form. Show the suggestion as a one-click "Use suggested venue: X" action, or as helper text on the select. Alternatively, only pre-fill exact (name/alias/postcode) matches and require an explicit confirm for similarity matches.

### WR-07: Drafts whose event has already happened can be approved and bulk approved, creating events with no visible dates

**Files:**
- `app/Review/DraftPublishability.php:67-98, 122-171`
- `app/Publishing/Actions/ApproveDraft.php:54-66`

**Issue:** Nothing refuses a draft whose start date (or last end) is already in the past. A submission that sits in the queue past its date still scores High, so **Approve selected** publishes it. The result is a "Published" toast, a new `events` row and occurrences, but every occurrence has already expired. The event is invisible to residents, yet it counts as published, keeps the draft's personal data indefinitely as "approved", and pollutes slug space. D-11 even has a "Past/expired" reject preset, which suggests the system should steer the admin there.

**Fix:** Add a `past` blocker in `blockerMessages()`, comparing the London local date: `CarbonImmutable::now('Europe/London')->toDateString()` against `end_date ?? start_date` (for a series, use the rule's last instance or `UNTIL`). Give it the message "This event has already happened. Reject it as 'Past or expired'." Add a matching bulk skip key and a test frozen on a DST boundary date.

### WR-08: Pending drafts, and the organiser email in `raw_payload`, are kept forever, which the privacy notice doesn't cover

**Files:**
- `app/Models/EventDraft.php:185-202`
- `resources/views/privacy.blade.php:41-46`
- `app/Review/Data/DraftData.php:162`

**Issue:** `prunable()` only matches rejected drafts. A submission the admin never reviews (`pending_review`) keeps the organiser's name, email and full `raw_payload` indefinitely. The privacy notice gives retention only for approved and rejected submissions.

`raw_payload` also duplicates `organiser_email` even when `contact_public` is false. That copy outlives any later correction of `organiser_contact`, so a data-subject request to correct the email leaves the old value in `raw_payload`.

**Fix:** Either prune stale pending drafts too, for example pending and `start_date < today - 90 days` (or `created_at < now - 180 days` for undated drafts), or state the pending retention in the notice. Leave contact fields out of `raw_payload` (add `organiser_email` to `NOT_IN_RAW_PAYLOAD`, since it is already stored in `organiser_contact`), or clear them when the admin edits the contact.

## Info

### IN-01: `ReviewEventDraft::$shownStatus` is a client-writable Livewire property

**File:** `app/Filament/Resources/EventDrafts/Pages/ReviewEventDraft.php:35`
**Issue:** `public string $shownStatus` controls which actions are visible and can be changed from the client. The domain actions re-check the locked row, so this is cosmetic only, but it is an unnecessary trust in client state.
**Fix:** Add `#[\Livewire\Attributes\Locked]` to the property.

### IN-02: The category "Icon" field is never shown anywhere, and the helper text is wrong

**Files:** `app/Filament/Pages/Rules.php:85, 103-106`, `app/Review/SaveRules.php:177-182, 212-214, 280, 293`, `resources/views/components/category-icon.blade.php:9-10`
**Issue:** Public pages pick icons from a fixed slug allow-list, so `categories.icon` is never read. A new category shows the "other" sparkles icon, not the "generic icon" (`heroicon-o-tag`) the helper text promises. The field also can't be cleared, because blank keeps the old value (`$row['icon'] ?? $category->icon`).
**Fix:** Remove the Icon column from the Rules screen and from SaveRules validation, or actually render `icon`. Fix the helper text either way.

### IN-03: The timing token is spent before the draft is written, so a write failure loses the organiser's form

**Files:** `app/Http/Requests/StoreSubmissionRequest.php:244-246`, `app/Http/Controllers/SubmissionController.php:57`, `app/Review/ReferenceCode.php:22-29`, `app/Review/DraftWriter.php:61-68`
**Issue:** `Honeypot::markUsed()` runs during validation. If `DraftWriter::create()` then throws, the organiser gets a 500, their input is gone and the token is burned. This can happen on a reference unique clash (the check-then-insert has no retry), or when a Rules save deletes the picked category between the existence check and the insert: the FK insert waits on `SaveRules`' `FOR UPDATE` lock, then fails.
**Fix:** Mark the token used after a successful write (in the controller, inside the draft transaction). Retry `ReferenceCode` on a unique violation.

### IN-04: After a reopen, rejecting again overwrites the earlier note

**Files:** `app/Publishing/Actions/ReopenDraft.php:17-19, 39-44`, `app/Publishing/Actions/RejectDraft.php:47-53`
**Issue:** Reopen promises that "the rejection note is kept". The next reject writes `review_notes = $note`, which may be null, and so silently drops the earlier note.
**Fix:** Append to the existing note, or keep the old note when the new one is blank.

### IN-05: The keyboard **E** shortcut's edit URL is captured once and goes stale after Reopen

**File:** `resources/views/filament/resources/event-drafts/pages/review-event-draft.blade.php:14, 36-39`
**Issue:** `editUrl` is set when Alpine `x-data` is initialised. After **Reopen** on the review page, the status becomes pending, but `editUrl` is still null until the page is reloaded, so **E** does nothing.
**Fix:** Read it through `$wire` (for example `$wire.call('editUrl')`), or redirect to the review page after reopen.

### IN-06: The project rules source file has drifted from AGENTS.md

**Files:** `.ai/guidelines/attleborough.md:23-25, 44`, `AGENTS.md:24-25, 44-45`
**Issue:** The guidelines file lacks the WR-10 `UpdatePublishedEvent` rule and the `AdminGate` panel-access wording that AGENTS.md has. If AGENTS.md is regenerated from the guidelines (Boost), those rules disappear. Phase 2 also added no rule naming `DraftWriter` as the only writer of `event_drafts` or `ApproveDraft` as the only publication path, although both are load-bearing.
**Fix:** Sync the two files and add the Phase 2 write rules.

### IN-07: `ops/probe-sources.sh` follows same-host https→http downgrades and doesn't cap chunked bodies

**File:** `ops/probe-sources.sh:115-116, 257-264`
**Issue:** A redirect to `http://same-host/...` is followed, because only the host is compared. `--max-filesize` doesn't apply when there is no Content-Length.
**Fix:** Refuse scheme changes in the redirect loop. Add `--max-time` together with a byte cap via `head -c "$MAX_BYTES"`, or `--proto =https`.

---

_Reviewed: 2026-10-17T21:55:00Z_
_Reviewer: the agent (gsd-code-reviewer)_
_Depth: standard_
