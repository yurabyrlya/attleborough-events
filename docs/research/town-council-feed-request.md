# Email to the Town Clerk: courtesy note and contingency request

**Status:** optional courtesy note, sent by the owner. It is not a prerequisite for crawling (D-26, revised 2026-10-17).

**Why send it:**

1. **Courtesy and terms.** The council's site advertises its own iCal feed (`<link rel="alternate" type="text/calendar">`), and robots.txt allows everything except `/wp-admin/`. Its terms of use (https://attleboroughtc.org.uk/terms-of-use), however, carry standard wording that site materials "may not be copied, reproduced, republished … or distributed". We only republish facts (title, date, time, place) with a link back, but an explicit "yes" from the clerk removes any doubt.
2. **Contingency.** From a residential network the site serves normally. From the corporate gateway and datacenter fetchers it answers with a bot-verification page (LiteSpeed reCAPTCHA). Production runs on a Hetzner VPS, which is a datacenter IP, so the same block may hit us. If it does, this email asks them to allowlist our crawler. We never bypass the check.

Send it from the project mailbox. Fill in the bracketed parts before sending: sign-off name, IP address once the VPS exists, launch date. Don't paste anything personal into this file; the repo is public.

---

**To:** the Town Clerk (address from the council website, filled in when sending)
**Subject:** What's On Attleborough: listing the Town Council's public events

Dear Clerk,

I'm writing about **What's On Attleborough**, a small, free, independent website that brings together public events in and around the town on one mobile-friendly calendar. Nothing appears on it until a person has checked it, every listing links back to the organiser's own page, and it carries no advertising and no tracking cookies.

The Town Council's events calendar is one of the most useful sources in the town: the Town Hall programme, council meetings, Remembrance services and community groups. Your website already publishes an iCal calendar feed, and we'd like to use it to list your **public** events (title, date, time, venue and a link back to your event page). We wouldn't copy images or long descriptions, and we would never show organisers' contact details. Private hires and room bookings can be excluded if you prefer.

Could you let us know:

1. **Whether you're happy for us to list your public events this way.** Your website's terms of use mention copying and republishing, so we'd rather ask than assume.
2. **If our automatic check is ever blocked by your site's bot protection,** whether your web provider could allowlist our crawler. It identifies itself honestly as `WhatsOnAttleborough/1.0 (+mailto:hello@whatsonattleborough.co.uk)`, visits at most once or twice a day, and follows your robots.txt. From [launch date] it will run from a fixed server address, [IP address], which I can confirm nearer the time. If you would rather give us a different calendar link or feed, that works just as well.

If you'd prefer we didn't list council events at all, just say so and we won't. We'll also happily correct or remove any listing on request.

Thank you for your time, and for all the work that goes into keeping the town's calendar up to date.

Kind regards,

[Your name]
What's On Attleborough
hello@whatsonattleborough.co.uk
