# Check-In Rate Limiting

## Overview

The door scanner talks to five **public, unauthenticated** routes under `/public/check-in-lists/*`.
Their only secret is the check-in list's `short_id`, which is meant to be shared with door staff and
therefore leaks easily. They need a cap — but the cap is unusually easy to get wrong, because a
legitimate phone at a busy door generates far more traffic than it looks like it should.

This document records what is capped, what deliberately is not, and the point at which a real door
would start being refused. Read it before changing any of these numbers.

## What each route costs a real device

| Route | What triggers it | Requests per device |
|---|---|---|
| `GET /check-in-lists/{id}` | Page load, info modal, activation countdown | 1–3 per session |
| `GET .../attendees` | **Roster refresh: the full list, every 60s, in pages of 250** | `ceil(attendees / 250)` per minute |
| `GET .../attendees/{public_id}` | A scan for a ticket sold since the last refresh | rare |
| `POST .../check-ins` | The queue flush — with a live connection this is **one POST per scan** | 20–30/min while scanning |
| `DELETE .../check-ins/{short_id}` | Manual check-out | rare |

The roster is what dominates, and it scales with the size of the event:

| Event size | Roster pages/min | + scanning | Per device | 
|---|---|---|---|
| 2,000 attendees | 8 | ~25 | ~33 req/min |
| 5,000 attendees | 20 | ~25 | ~45 req/min |
| 10,000 attendees | 40 | ~25 | ~65 req/min |

Every device at one entrance shares the venue's NAT, so these add up against a single public IP.

## What is capped

**`POST` and `DELETE` only**, via the `check-in` limiter in `RouteServiceProvider`:

```php
Limit::perMinute(300)->by('check-in:' . $short_id . '|' . $ip)
```

Keyed by **list and origin together**, which is the part that matters:

- Keyed by IP alone, the staff of one door throttle each other off a single budget.
- Keyed by `short_id` alone, anyone holding the (shareable) link drains the door's budget for them.

300/min is sized off scanning speed, not event size: a person scans a ticket every 2–3 seconds, so
~30 POST/min per device, and the budget covers roughly **10 devices behind one NAT scanning flat
out**. Unlike the roster, this number does not grow with the size of the event.

## What is deliberately NOT capped

The three `GET` routes are **exempt from the global `api` limiter** (see the early return in
`RateLimiter::for('api', ...)`) and carry no limiter of their own.

This is the opposite of the obvious choice, and it is deliberate. Before the exemption, the global
cap of **180/min per bare IP** applied to them, which — using the table above — is what a door hits
with:

- **5 devices** at a 2,000-person event,
- **4 devices** at 5,000,
- **2 devices** at 10,000.

In other words the pre-existing global cap was already strangling large events, and adding a
tighter one would have made it worse. Measured, not estimated: 195 sequential POSTs against the dev
stack returned the first `429` on request #180.

The failure mode is also worse than it looks. A refused `POST` is retried by the scanner's queue
(`classifyFlushFailure` treats 429 as retryable) so no check-in is lost — but a refused roster `GET`
surfaces in the UI as *"Could not load the attendee list"*, which at a door reads as bad wifi rather
than as a cap, and sends staff chasing the wrong problem.

## When this will need revisiting

The write cap is stable against event growth. The exemption on reads is not a free pass — it is a
decision to accept unbounded reads on a `short_id`-guarded endpoint **because the client re-downloads
the entire roster every 60 seconds**. If that ever changes to an incremental refresh (only what
changed since the last `loadedAt`), read traffic drops by an order of magnitude and these routes can
and should be capped like the rest.

Until then, do not put a limiter on the roster `GET` without first working out the per-device
request rate at the largest event in production, multiplying by the number of devices on one
entrance's wifi, and leaving headroom for page reloads — each of which replays the whole roster in
one burst.
