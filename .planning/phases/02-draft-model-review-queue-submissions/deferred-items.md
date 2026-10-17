# Phase 2: deferred items

Out-of-scope findings logged during execution (not fixed by the plan that found them).

## From 02-16 (browser UAT F11-F14)

| Area | Finding | Seen in |
|------|---------|---------|
| Admin, Review queue | After a bulk action (Reject selected, Approve selected) the tab badges ("Pending 3", "Rejected 0") and the sidebar "Review queue" badge keep the old counts until the page is reloaded. The table rows themselves update. Phase 1 fixed the same pattern for the System health badge (quick task 261017-gac). | `tests/Browser/ReviewQueueTest.php` F13 bulk reject (screenshot during development) |
