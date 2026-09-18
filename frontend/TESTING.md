# Frontend tests

```bash
yarn test          # run once
yarn test:watch    # re-run on change
```

Vitest, in `node` mode: no jsdom, no DOM globals, no browser. The whole suite is a few hundred
milliseconds, so it can run on every save.

## What is covered

The door scanner's decisions, which is where a mistake is expensive and invisible: somebody walks in
without a valid ticket, somebody who paid is turned away, a check-in that was made is lost, or the
person at the door is never told any of it happened.

| File | What it pins down |
|---|---|
| `src/hooks/checkInRoster.logic.test.ts` | Whether a scan is let through, whether a confirmed check-in survives a background refresh, what a batch's answer does to the queue, when a failure is worth retrying, and what a corrupt `localStorage` snapshot may not do |
| `src/components/common/CheckIn/SyncStatus/syncState.test.ts` | Which of the six things the status bar says, and in what order |
| `src/components/layouts/CheckIn/barcode.test.ts` | What the HID scanner is allowed to treat as an attendee code |

## Why the logic lives outside its hook

These used to be inside `useCheckInRoster` and `SyncStatus`, tangled with refs, effects and
requests. Testing them there would have meant jsdom, Testing Library, a mocked axios and fake timers
for every case — and three specific traps:

- `isSsr()` is `import.meta.env.SSR`, which is `true` under `environment: 'node'`. Every
  `localStorage` read would return `null` and the snapshot tests would pass without testing anything.
- The hook imports `t` from `@lingui/macro`, which needs the Babel transform just to load.
- `../types` reaches `@lingui/core` through `locales.ts`, so even the types have to be imported with
  `import type` to keep the test graph clean.

Pulled out into plain functions, none of that applies: the modules under test import nothing at
runtime. If a test ever needs a mock, a timer or a DOM, that is the signal that some logic is still
tangled with the UI and should come out too.

## What is not covered, on purpose

Rendering, Mantine components, icons and CSS; the toasts; the camera scanner; the translation
catalogues (`lingui extract` already reports those). A suite that tries to cover everything breaks
for reasons that are not bugs, and then nobody reads it.

Some things can only be checked in a browser and stay manual: the text actually on screen, the list
age ticking up while the page sits open, retry re-fetching both the roster and the check-in list, and
the absence of hydration warnings in the console.

## Adding a test

A test earns its place by failing when the behaviour breaks. Before trusting a new one, break the
line it covers on purpose and confirm it goes red — a test that stays green with the bug in place is
worse than no test, because it reads like coverage.
