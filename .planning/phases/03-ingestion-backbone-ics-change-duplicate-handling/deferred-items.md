# Phase 3 deferred items

Found while executing plans. Not fixed in the plan that found them.

## From 03-08

1. ~~**Cancelled override beyond the 90-day horizon.**~~ **Resolved in 03-09.** ApproveDraft now stores the draft's `cancelled_keys` in the new `events.cancelled_dates` jsonb column, and `OccurrenceGenerator::sync()` inserts a new row for one of those dates as Cancelled (existing rows still keep their status). Test: `tests/Feature/Publishing/CancelledDatesBeyondHorizonTest.php`. 03-10's ConfirmSourceCancellation should append its confirmed keys to `events.cancelled_dates` the same way. (Original note: ApproveDraft cancelled a series' `cancelled_keys` only for occurrences that already existed; a date further out was published as Scheduled when the nightly sync reached it, and change detection could not catch it because `content_hash` already matched the baseline.)
2. **Events edit form "Next dates" preview ignores `excluded_dates`.** `EventForm::nextDates()` builds an unsaved Event from form state without `excluded_dates`, so the preview lists an EXDATE'd date that the published series (and the public site) skip. Display only; the saved event is correct. Fix: pass the record's `excluded_dates` into the preview model.
