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

The roster is what dominates, and it scales with the size of the event. **Our events run in the
hundreds**; the larger rows are here because the numbers below are chosen against them, not because
we see them:

| Event size | Roster pages/min | + scanning | Per device |
|---|---|---|---|
| **300 attendees (our scale)** | **2** | ~25 | **~27 req/min** |
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

## What caps the reads

The three `GET` routes take **600/min per IP**, set in the early return of
`RateLimiter::for('api', ...)` rather than inheriting the global cap:

```php
Limit::perMinute(600)->by($request->ip())
```

They were briefly exempt altogether. That was sized against a 10,000-person event and was the wrong
trade for what we actually run: these routes are unauthenticated, guarded only by a `short_id` that
is meant to be handed around, and they return the whole roster with names, order and seat. Leaving
them uncapped meant anyone holding the link could pull it as fast as they liked.

600 is generous at our scale and still refuses a scrape. Where it would start refusing a real door,
counting only the roster GETs:

| Event size | Roster GET/min per device | Devices before 600/min |
|---|---|---|
| **300 attendees (our scale)** | 2 | **~240** |
| 2,000 attendees | 8 | 75 |
| 5,000 attendees | 20 | 30 |
| 10,000 attendees | 40 | **15** |

So the cap is invisible at the events we run and would only bite at a scale we do not operate at —
and even there, only with more than fifteen phones on one entrance's wifi. Note that a page reload
replays the whole roster in one burst, so a round of reloads at a large event spends a chunk of the
minute's budget at once.

The global cap this replaced was **180/min per bare IP**, which the same table puts at 5 devices for
a 2,000-person event and 2 at 10,000 — already too tight. Measured, not estimated: 195 sequential
requests against the dev stack returned the first `429` on request #180.

The failure mode is worth knowing. A refused `POST` is retried by the scanner's queue
(`classifyFlushFailure` treats 429 as retryable) so no check-in is lost — but a refused roster `GET`
surfaces in the UI as *"Could not load the attendee list"*, which at a door reads as bad wifi rather
than as a cap, and sends staff chasing the wrong problem.

## When this will need revisiting

The write cap is stable against event growth. The read cap is not: it is spent almost entirely by
the roster refresh, **which re-downloads the entire list every 60 seconds**, so its headroom shrinks
as events get bigger. The table above is the thing to re-check, not the number itself.

If the refresh ever becomes incremental — only what changed since the last `loadedAt` — read traffic
drops by an order of magnitude and 600 stops being a constraint at any size we would plausibly run.
That is the change worth making before raising this number.

Until then, do not lower it without first working out the per-device request rate at the largest
event in production, multiplying by the number of devices on one entrance's wifi, and leaving
headroom for page reloads — each of which replays the whole roster in one burst.
