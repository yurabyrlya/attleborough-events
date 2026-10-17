# API Coverage — Phase 1 outbound link-outs and browser APIs

> Full coverage by default. Opt-outs are explicit, reasoned decisions.
>
> Phase 1 makes no server-side calls to any third-party service (tests run with
> `Http::preventStrayRequests()`). Its only external surface is URLs the visitor's
> browser follows (Google Calendar template, WhatsApp, Facebook, email) and browser
> APIs used as progressive enhancement (Web Share, Clipboard). Real external
> integrations (source crawlers) arrive in Phase 3 and get their own matrix.

| capability | decision | reason |
|---|---|---|
| iCalendar .ics download per date (EVT-06, OccurrenceIcsController) | INTEGRATE | |
| Google Calendar template link per date (EVT-06, GoogleCalendarLink) | INTEGRATE | |
| WhatsApp share link via wa.me (EVT-07, ShareLinks) | INTEGRATE | |
| Facebook share link via sharer.php (EVT-07, ShareLinks) | INTEGRATE | |
| Email share via mailto: (EVT-07, ShareLinks) | INTEGRATE | |
| Web Share API navigator.share (EVT-07, share.js) | INTEGRATE | |
| Clipboard API copy link (EVT-07, share.js) | INTEGRATE | |
| Open Graph and Twitter card meta tags (EVT-02) | INTEGRATE | |
| Outlook.com or Office 365 calendar deep link | OPT-OUT | not needed: the .ics download imports into Outlook; spec lists only .ics and Google |
| webcal:// subscription feed for a whole series or site | OPT-OUT | explicitly out of scope for Phase 1: per-date add-to-calendar only |
| X / Twitter, Messenger, Nextdoor share intents | OPT-OUT | not needed: spec lists WhatsApp, Facebook and email; native share sheet covers the rest on phones |
| Facebook/Meta SDK or any third-party share script | OPT-OUT | explicitly out of scope: project rule forbids third-party scripts on public pages |
| Google Calendar API (server-side insert) | OPT-OUT | explicitly out of scope: no accounts, OAuth or server-side calls; template link is enough |
