# Phase 3 deferred items

Found while executing plans. Not fixed in the plan that found them.

## From 03-08

1. **Cancelled override beyond the 90-day horizon.** ApproveDraft cancels a series' `cancelled_keys` only for occurrences that already exist (inside `calendar.horizon_days`). A cancelled date further out is published as Scheduled when the nightly sync reaches it, and change detection will not catch it because `content_hash` already matches the baseline. Possible fix: have `OccurrenceGenerator::sync()` insert new rows as Cancelled when their key is in a stored list (e.g. an `events.cancelled_dates` jsonb or the linked item's accepted payload). Belongs with 03-09/03-10 (cancellation handling).
2. **Events edit form "Next dates" preview ignores `excluded_dates`.** `EventForm::nextDates()` builds an unsaved Event from form state without `excluded_dates`, so the preview lists an EXDATE'd date that the published series (and the public site) skip. Display only; the saved event is correct. Fix: pass the record's `excluded_dates` into the preview model.
