import type {Attendee, AttendeeCheckIn, IdParam, PublicCheckIn} from "../types";

/**
 * The decisions behind the door scanner, with nothing around them.
 *
 * These used to live inside `useCheckInRoster`, tangled with refs, effects and
 * requests, which meant the only way to check them was to open the app, cut the
 * network and instrument the code with a temporary `setTimeout` to force a race.
 * Pulled out here they are plain functions — data in, data out — so each case a
 * scanner can hit at a door is an assertion instead of a rehearsal.
 *
 * Nothing in this file may reach for React, `localStorage` or a translation: the
 * types are imported with `import type` on purpose, because `../types` pulls in
 * `@lingui/core` through `locales.ts` and that import would follow into the tests.
 */

export const ROSTER_PAGE_SIZE = 250;
export const ROSTER_REFRESH_MS = 60_000;
const QUEUE_RETRY_MS = 2_000;
const QUEUE_MAX_RETRY_MS = 30_000;
export const QUEUE_REQUEST_TIMEOUT_MS = 8_000;
// Roomier than the queue's: a roster page carries 250 attendees, not 50 ids. What matters is that
// it ends. With no timeout at all, a dead socket leaves isLoading pinned to true, the refresh
// interval returns on its guard forever, and the roster silently stops updating for the rest of the
// night — while the status bar still reads "all check-ins synced".
export const ROSTER_REQUEST_TIMEOUT_MS = 20_000;
// Kept well inside QUEUE_REQUEST_TIMEOUT_MS: the server writes one transaction
// per attendee, so the slice has to be small enough that the round trip always
// finishes, even on the venue's connection.
export const QUEUE_BATCH_SIZE = 50;

export type PendingCheckIn = {
    publicId: string;
    action: 'check-in' | 'check-in-and-mark-order-as-paid';
    queuedAt: number;
    attempts: number;
    // Counted apart from `attempts` on purpose, and only when the server actually answered with an
    // error. At a door the normal way to fail is for the request never to leave at all, and those
    // failures must never move an entry towards being set aside: a five minute dead spot would
    // start pushing perfectly good check-ins to the back of the queue.
    serverErrors: number;
};

export type Snapshot = {
    savedAt: number;
    attendees: Attendee[];
};

export type CheckInOutcome =
    | { status: 'queued' }
    | { status: 'already-checked-in' }
    | { status: 'cancelled' }
    | { status: 'not-on-this-list' };

// A refusal that is about the request rather than about one person — the list expired, it was
// deleted — has nobody to name, so the attendee is optional.
export type RejectedCheckIn = {
    attendee?: Attendee;
    message: string;
};

// The optimistic placeholder has no server record behind it yet, and its id is
// the marker for that. Anything that needs the real check-in — undoing one, or
// rolling a refused one back — keys off this instead of guessing from a blank field.
export const PENDING_ID_PREFIX = 'pending-';

export const isPendingCheckIn = (checkIn?: AttendeeCheckIn): boolean =>
    checkIn !== undefined && String(checkIn.id).startsWith(PENDING_ID_PREFIX);

export const pendingPlaceholder = (attendee: Attendee): AttendeeCheckIn => ({
    id: `${PENDING_ID_PREFIX}${attendee.public_id}`,
    attendee_id: attendee.id as IdParam,
    check_in_list_id: '',
    short_id: '',
    order_id: attendee.order_id,
    checked_in_at: new Date().toISOString(),
});

// ---- The persisted roster --------------------------------------------------

export const ROSTER_KEY_PREFIX = 'checkInRoster:';
export const QUEUE_KEY_PREFIX = 'checkInQueue:';

/**
 * Which stored lists can be dropped from the device.
 *
 * The roster is the full attendance of an event — names, seats, order numbers — sitting in the
 * `localStorage` of a phone that is often borrowed and rarely wiped. It is worth keeping only while
 * the door is actually using it: once a list is done, or another one is opened, it is PII with no
 * purpose, and re-downloading it costs one request.
 *
 * A list with check-ins still queued is never touched, current or not. Those entries exist nowhere
 * else until the server confirms them, so dropping them would lose people who already walked in.
 */
export const shortIdsToPurge = (
    stored: { shortId: string; pendingCount: number }[],
    keep: { shortId: string; isFinished: boolean },
): string[] =>
    stored
        .filter(({shortId, pendingCount}) => pendingCount === 0
            && (shortId !== keep.shortId || keep.isFinished))
        .map(({shortId}) => shortId);

/**
 * `localStorage` is not trusted input: an old format, a half-written value or a hand-edited entry
 * all come back as valid JSON with the wrong shape. A snapshot that cannot be trusted is dropped
 * whole rather than patched — downloading the roster again costs one request, while a `savedAt`
 * that is not a number reaches arithmetic and `new Date()` and takes the screen down in the middle
 * of the door.
 */
export const parseSnapshot = (raw: unknown): Snapshot | null => {
    const candidate = raw as Partial<Snapshot> | null;
    // Three checks, each doing something the others do not. `typeof` is what lets the value be
    // used as a number afterwards — without it the narrowing fails and `savedAt` stays
    // `number | undefined`. `Number.isFinite` rejects NaN and Infinity, and — unlike the global
    // `isFinite`, which coerces and so reads `null` as 0 — it rejects anything that is not a number
    // at all. The Date is checked on its own because 1e300 passes both and then throws out of
    // `toISOString()`, which is exactly where this value ends up.
    const savedAt = candidate?.savedAt;
    const isUsableDate = typeof savedAt === 'number'
        && Number.isFinite(savedAt)
        && !Number.isNaN(new Date(savedAt).getTime());

    if (!candidate || !Array.isArray(candidate.attendees) || !isUsableDate) {
        return null;
    }

    // Entries are checked one by one too, or the shape check above is decoration: an attendee
    // without a `public_id` takes the search box down the first time someone types in it.
    return {
        savedAt,
        attendees: candidate.attendees.filter(attendee => typeof attendee?.public_id === 'string'),
    };
};

/**
 * The queue gets the same treatment as the roster: it comes back from `localStorage`, so an old
 * format, a half-written value or a hand-edited entry all arrive as valid JSON with the wrong shape.
 * Entries are rebuilt field by field rather than trusted — a missing `serverErrors` on a queue
 * written by the previous version would otherwise reach arithmetic as `undefined` and turn the whole
 * count into NaN.
 */
export const parseQueue = (raw: unknown): PendingCheckIn[] => {
    if (!Array.isArray(raw)) return [];

    return raw
        .filter(entry => typeof entry?.publicId === 'string'
            && (entry.action === 'check-in' || entry.action === 'check-in-and-mark-order-as-paid'))
        .map(entry => ({
            publicId: entry.publicId,
            action: entry.action,
            queuedAt: Number.isFinite(entry.queuedAt) ? entry.queuedAt : Date.now(),
            attempts: Number.isFinite(entry.attempts) ? entry.attempts : 0,
            serverErrors: Number.isFinite(entry.serverErrors) ? entry.serverErrors : 0,
        }));
};

/**
 * Folds a freshly downloaded roster into the one on screen.
 *
 * `protectedIds` is everything that passed through the check-in queue at any point while the pages
 * were in flight. What the server just answered describes its state at the *start* of the fetch, so
 * for those people it is already out of date: applying it as-is erases a check-in the server itself
 * confirmed seconds ago, the person shows as not checked in, and scanning them again goes green.
 *
 * For everyone else the server wins, which is what makes a remote check-out show up here.
 */
export const mergeRoster = ({server, local, protectedIds}: {
    server: Attendee[];
    local: Attendee[];
    protectedIds: Set<string>;
}): Attendee[] => {
    const merged = server.map(attendee => {
        if (!protectedIds.has(attendee.public_id) || attendee.check_in) return attendee;
        const mine = local.find(candidate => candidate.public_id === attendee.public_id);
        // A refused check-in was already rolled back to undefined, so the server's record wins
        // here — this never resurrects one.
        return mine?.check_in ? {...attendee, check_in: mine.check_in} : attendee;
    });

    // A protected attendee the server did not return at all: the one that arrived through the
    // network fallback. Dropping them here would take them off the list while their check-in is
    // still queued.
    const returnedIds = new Set(server.map(attendee => attendee.public_id));

    return [
        ...merged,
        ...local.filter(attendee => !returnedIds.has(attendee.public_id) && protectedIds.has(attendee.public_id)),
    ];
};

// ---- The scan --------------------------------------------------------------

/**
 * Whether this person walks in, decided entirely on the phone. The check-in itself is optimistic,
 * so every refusal that can be settled locally has to be settled here: once it is queued the
 * scanner has already gone green and the person is inside.
 */
export const decideCheckIn = (
    attendee: Attendee,
    allowedProductIds?: (number | string)[],
): CheckInOutcome => {
    if (attendee.check_in) {
        return {status: 'already-checked-in'};
    }

    // The roster carries cancelled tickets — the server returns them so the door can see them.
    if (attendee.status === 'CANCELLED') {
        return {status: 'cancelled'};
    }

    // The server refuses a ticket this list does not cover, and that refusal used to come back as a
    // 409 for the whole request, taking the queue — and everyone already through the door — down
    // with it. It is settled here, where it costs nothing and the door gets a useful answer. With no
    // list of products yet (the list has not loaded) there is nothing to tell an outside ticket from
    // a valid one, and refusing everything would be worse.
    if (allowedProductIds?.length
        && !allowedProductIds.some(id => String(id) === String(attendee.product_id))) {
        return {status: 'not-on-this-list'};
    }

    return {status: 'queued'};
};

// ---- The queue -------------------------------------------------------------

export type FlushResponse = {
    data?: PublicCheckIn[] | null;
    errors?: Record<string, string> | null;
};

const indexConfirmed = (response: FlushResponse): Map<string, PublicCheckIn> => {
    const confirmed = new Map<string, PublicCheckIn>();
    (response.data ?? []).forEach(checkIn => confirmed.set(String(checkIn.attendee_id), checkIn));
    return confirmed;
};

/**
 * What a batch's answer does to the list on screen and to the queue behind it.
 *
 * `attendees` and `pending` are the state as it was when the request went out, not after: anything
 * scanned while it was in flight falls outside `batchIds` and has to survive untouched. Clearing the
 * whole queue instead would drop it unsent, leaving the person marked as through the door and no
 * record of it anywhere.
 */
export const applyFlushResponse = ({attendees, pending, batchIds, response}: {
    attendees: Attendee[];
    pending: PendingCheckIn[];
    batchIds: Set<string>;
    response: FlushResponse;
}): { attendees: Attendee[]; pending: PendingCheckIn[] } => {
    const confirmed = indexConfirmed(response);
    const errors = response.errors ?? {};

    return {
        attendees: attendees.map(attendee => {
            const real = attendee.id !== undefined ? confirmed.get(String(attendee.id)) : undefined;
            if (real) return {...attendee, check_in: real};
            if (errors[attendee.public_id]) {
                // The server refused it (cancelled ticket, unpaid order). Roll back the optimistic
                // mark so the list tells the truth.
                return {...attendee, check_in: undefined};
            }
            return attendee;
        }),
        pending: pending.filter(entry => !batchIds.has(entry.publicId)),
    };
};

/**
 * Who has to be told they did not get in. Every refusal is a person standing at the door, so none of
 * them is dropped — not even one whose attendee is no longer in the roster, because that silence is
 * exactly someone walking in on a check-in the server never accepted.
 */
export const collectRefusals = ({attendees, response}: {
    attendees: Attendee[];
    response: FlushResponse;
}): RejectedCheckIn[] => {
    const confirmed = indexConfirmed(response);
    const errors = response.errors ?? {};

    // An attendee the server confirmed is not a rejection, even when the same response also carries
    // "already checked in" for them: that is the idempotent path after a lost response, and the
    // person did go through.
    const confirmedPublicIds = new Set(
        attendees
            .filter(attendee => attendee.id !== undefined && confirmed.has(String(attendee.id)))
            .map(attendee => attendee.public_id),
    );

    return Object.entries(errors)
        .filter(([publicId]) => !confirmedPublicIds.has(publicId))
        .map(([publicId, message]) => ({
            // The attendee is normally in the roster — queueing one puts them there even when they
            // came from the network fallback — but a refresh in between can drop them again.
            attendee: attendees.find(candidate => candidate.public_id === publicId),
            message,
        }));
};

/**
 * A definitive refusal (the list expired, was deleted, the request is malformed) will never succeed
 * on retry: the queue is dropped and the door is told. Everything else — network down, a timeout, a
 * 5xx, a 429 — keeps the queue and backs off, because the person already went through and the
 * record has to follow.
 *
 * `undefined` is the case that matters most: axios leaves no `response` when the request never
 * reached anyone, which at a venue is the normal way to fail. Calling that definitive would erase a
 * whole night's queue on a dropped signal.
 */
export const classifyFlushFailure = (status?: number): 'definitive' | 'retry' =>
    status !== undefined && status >= 400 && status < 500 && status !== 408 && status !== 429
        ? 'definitive'
        : 'retry';

// Doubling from 4s, capped at 30s. The attempt count belongs to the batch that failed, not to the
// queue: a check-in scanned while it was failing should not inherit an already-stretched backoff.
export const backoffDelay = (attempts: number): number =>
    Math.min(QUEUE_RETRY_MS * 2 ** Math.min(attempts, 4), QUEUE_MAX_RETRY_MS);

/**
 * Whether this failure counts towards setting an entry aside.
 *
 * Only an answer from the server does. A request that never reached anyone carries no status, and at
 * a door that is the ordinary way to fail — counting it here would push perfectly good check-ins
 * towards the back of the queue over nothing worse than a dead spot.
 */
export const nextServerErrors = (current: number, status?: number): number =>
    status === undefined ? current : current + 1;

/**
 * How many to send after repeated errors *from the server*.
 *
 * A 500 says nothing about which attendee caused it, so the only way to find the one entry the
 * server cannot stomach is to keep halving the batch until it is alone. Until that happens, one bad
 * entry holds up everyone queued behind it — which is how a single unlucky scan used to stop a
 * door's whole night of check-ins from ever being recorded.
 */
export const batchSizeFor = (serverErrors: number): number =>
    serverErrors >= 4 ? 1 : serverErrors >= 2 ? 10 : QUEUE_BATCH_SIZE;

/**
 * Whether an entry that has been failing on its own should go to the back of the queue.
 *
 * Shrinking finds it but does not get it out of the way: the queue is sent head first, so the same
 * entry keeps being picked and everyone behind it keeps waiting. Moving it to the end lets the rest
 * through while it goes on being retried, which is why nothing is ever thrown away here.
 */
export const shouldRotate = (serverErrors: number): boolean => serverErrors >= 6;
