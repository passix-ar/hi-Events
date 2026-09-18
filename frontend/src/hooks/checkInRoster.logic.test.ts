import {describe, expect, it} from 'vitest';
import type {Attendee, PublicCheckIn} from '../types';
import {
    applyFlushResponse,
    backoffDelay,
    batchSizeFor,
    classifyFlushFailure,
    collectRefusals,
    decideCheckIn,
    mergeRoster,
    nextServerErrors,
    parseQueue,
    parseSnapshot,
    shouldRotate,
    type PendingCheckIn,
} from './checkInRoster.logic';

/**
 * Every case here is something that can happen at a door on a Saturday night. They are grouped by
 * what goes wrong when the logic is wrong, not by which function they call: someone walks in without
 * a ticket, someone who paid is turned away, a check-in is lost, or the door is never told.
 */

const attendee = (overrides: Partial<Attendee> = {}): Attendee => ({
    id: 1,
    product_id: 10,
    product_price_id: 100,
    order_id: 1000,
    status: 'ACTIVE',
    first_name: 'Ada',
    last_name: 'Lovelace',
    email: 'ada@example.test',
    public_id: 'A-AAA111',
    short_id: 'aaa111',
    ...overrides,
});

const serverCheckIn = (overrides: Partial<PublicCheckIn> = {}): PublicCheckIn => ({
    id: 5,
    short_id: 'chk5',
    check_in_list_id: 2,
    attendee_id: 1,
    checked_in_at: '2026-09-18T22:00:00Z',
    order_id: 1000,
    ...overrides,
});

const placeholder = (publicId: string) => ({
    id: `pending-${publicId}`,
    attendee_id: 1,
    check_in_list_id: '',
    short_id: '',
    order_id: 1000,
    checked_in_at: '2026-09-18T21:59:00Z',
});

const queued = (publicId: string): PendingCheckIn => ({
    publicId,
    action: 'check-in',
    queuedAt: 0,
    attempts: 0,
    serverErrors: 0,
});

// ---------------------------------------------------------------------------
// Someone walks in who should not, or someone who paid is turned away
// ---------------------------------------------------------------------------

describe('decideCheckIn', () => {
    it('lets a valid ticket through', () => {
        expect(decideCheckIn(attendee(), [10])).toEqual({status: 'queued'});
    });

    it('refuses someone already checked in', () => {
        expect(decideCheckIn(attendee({check_in: placeholder('A-AAA111')}), [10]))
            .toEqual({status: 'already-checked-in'});
    });

    it('refuses a cancelled ticket', () => {
        expect(decideCheckIn(attendee({status: 'CANCELLED'}), [10])).toEqual({status: 'cancelled'});
    });

    // The order matters and it is not obvious: a cancelled ticket that somehow already has a
    // check-in is reported as already checked in, because that is the more useful thing to say to
    // whoever is holding up the queue.
    it('reports an already checked in cancelled ticket as already checked in', () => {
        expect(decideCheckIn(attendee({status: 'CANCELLED', check_in: placeholder('A-AAA111')}), [10]))
            .toEqual({status: 'already-checked-in'});
    });

    it('refuses a ticket this list does not cover', () => {
        expect(decideCheckIn(attendee({product_id: 99}), [10, 11]))
            .toEqual({status: 'not-on-this-list'});
    });

    // With no products yet there is nothing to tell an outside ticket from a valid one, and
    // refusing everyone at the door would be far worse than letting the server decide.
    it('does not refuse anyone while the check-in list has not loaded', () => {
        expect(decideCheckIn(attendee({product_id: 99}), undefined)).toEqual({status: 'queued'});
        expect(decideCheckIn(attendee({product_id: 99}), [])).toEqual({status: 'queued'});
    });

    // The ids arrive as numbers from one endpoint and as strings from another. Comparing them
    // without normalising rejects every single ticket, and only at the door.
    it('matches a numeric product id against a string one', () => {
        expect(decideCheckIn(attendee({product_id: 7}), ['7'])).toEqual({status: 'queued'});
    });

    // Whether an unpaid order may enter depends on an event setting the scanner reads elsewhere.
    // This function deliberately does not decide it.
    it('leaves the unpaid decision to the caller', () => {
        expect(decideCheckIn(attendee({status: 'AWAITING_PAYMENT'}), [10])).toEqual({status: 'queued'});
    });
});

describe('mergeRoster: a confirmed check-in must never be erased by a refresh', () => {
    const protect = (...ids: string[]) => new Set(ids);

    it('takes the server’s version for anyone not protected', () => {
        const merged = mergeRoster({
            server: [attendee({check_in: serverCheckIn()})],
            local: [attendee()],
            protectedIds: protect(),
        });

        expect(merged[0].check_in).toEqual(serverCheckIn());
    });

    // The bug this whole mechanism exists for: the answer was taken before the person walked in.
    it('keeps a local check-in the server’s answer predates', () => {
        const merged = mergeRoster({
            server: [attendee()],
            local: [attendee({check_in: placeholder('A-AAA111')})],
            protectedIds: protect('A-AAA111'),
        });

        expect(merged[0].check_in).toEqual(placeholder('A-AAA111'));
    });

    it('replaces the optimistic placeholder once the server has the real record', () => {
        const merged = mergeRoster({
            server: [attendee({check_in: serverCheckIn()})],
            local: [attendee({check_in: placeholder('A-AAA111')})],
            protectedIds: protect('A-AAA111'),
        });

        expect(merged[0].check_in).toEqual(serverCheckIn());
    });

    // A refusal rolls the optimistic mark back to undefined before this runs. Protection must not
    // bring it back, or a cancelled ticket would show as checked in for the rest of the night.
    it('does not resurrect a check-in the server refused', () => {
        const merged = mergeRoster({
            server: [attendee()],
            local: [attendee({check_in: undefined})],
            protectedIds: protect('A-AAA111'),
        });

        expect(merged[0].check_in).toBeUndefined();
    });

    it('reflects a check-out made from the panel or another phone', () => {
        const merged = mergeRoster({
            server: [attendee()],
            local: [attendee({check_in: serverCheckIn()})],
            protectedIds: protect(),
        });

        expect(merged[0].check_in).toBeUndefined();
    });

    // The ticket sold after the last refresh, which reached the scanner through the network
    // fallback: the server's page does not have it yet and its check-in is still queued.
    it('keeps a protected attendee the server did not return', () => {
        const merged = mergeRoster({
            server: [],
            local: [attendee({public_id: 'A-LATE99', check_in: placeholder('A-LATE99')})],
            protectedIds: protect('A-LATE99'),
        });

        expect(merged).toHaveLength(1);
        expect(merged[0].public_id).toBe('A-LATE99');
    });

    it('drops an unprotected attendee the server no longer returns', () => {
        const merged = mergeRoster({
            server: [],
            local: [attendee({public_id: 'A-GONE01'})],
            protectedIds: protect(),
        });

        expect(merged).toEqual([]);
    });

    it('never lists a protected attendee twice', () => {
        const merged = mergeRoster({
            server: [attendee(), attendee({id: 2, public_id: 'A-BBB222'})],
            local: [attendee({check_in: placeholder('A-AAA111')})],
            protectedIds: protect('A-AAA111'),
        });

        expect(merged.map(a => a.public_id)).toEqual(['A-AAA111', 'A-BBB222']);
    });
});

describe('applyFlushResponse', () => {
    const batch = new Set(['A-AAA111']);

    it('swaps the placeholder for the real record the server returned', () => {
        const {attendees} = applyFlushResponse({
            attendees: [attendee({check_in: placeholder('A-AAA111')})],
            pending: [queued('A-AAA111')],
            batchIds: batch,
            response: {data: [serverCheckIn()]},
        });

        expect(attendees[0].check_in).toEqual(serverCheckIn());
    });

    it('rolls back the optimistic mark on a refusal', () => {
        const {attendees} = applyFlushResponse({
            attendees: [attendee({check_in: placeholder('A-AAA111')})],
            pending: [queued('A-AAA111')],
            batchIds: batch,
            response: {errors: {'A-AAA111': 'Ticket is cancelled'}},
        });

        expect(attendees[0].check_in).toBeUndefined();
    });

    // Someone scanned while the request was in the air. Clearing the whole queue would drop them
    // unsent: marked as through the door, with no record anywhere.
    it('only removes the slice that was sent', () => {
        const {pending} = applyFlushResponse({
            attendees: [],
            pending: [queued('A-AAA111'), queued('A-DURING')],
            batchIds: batch,
            response: {data: [serverCheckIn()]},
        });

        expect(pending.map(p => p.publicId)).toEqual(['A-DURING']);
    });

    it('survives a response with no data and no errors', () => {
        const {attendees, pending} = applyFlushResponse({
            attendees: [attendee({check_in: placeholder('A-AAA111')})],
            pending: [queued('A-AAA111')],
            batchIds: batch,
            response: {data: null, errors: null},
        });

        expect(attendees[0].check_in).toEqual(placeholder('A-AAA111'));
        expect(pending).toEqual([]);
    });

    // Check-ins are matched by internal id, and an attendee that reached the roster through the
    // network fallback may not carry one. Matching it by accident would mark the wrong person.
    it('does not match an attendee that has no id', () => {
        const {attendees} = applyFlushResponse({
            attendees: [attendee({id: undefined, public_id: 'A-NOID01'})],
            pending: [],
            batchIds: new Set(),
            response: {data: [serverCheckIn({attendee_id: 1})]},
        });

        expect(attendees[0].check_in).toBeUndefined();
    });
});

describe('collectRefusals', () => {
    // The idempotent path: the first response was lost, the retry says "already checked in" and
    // also returns the record. The person did go through — telling the door they were refused
    // would send them to look for someone who is already inside.
    it('is silent about someone the same response also confirmed', () => {
        const refusals = collectRefusals({
            attendees: [attendee()],
            response: {
                data: [serverCheckIn()],
                errors: {'A-AAA111': 'Attendee Ada Lovelace is already checked in'},
            },
        });

        expect(refusals).toEqual([]);
    });

    // A refresh in between can drop them from the roster. Staying quiet here would mean someone
    // walked in on a check-in the server never accepted, with no trace at all.
    it('still reports a refusal for someone no longer in the roster', () => {
        const refusals = collectRefusals({
            attendees: [],
            response: {errors: {'A-GHOST1': 'Invalid attendee code'}},
        });

        expect(refusals).toEqual([{attendee: undefined, message: 'Invalid attendee code'}]);
    });

    it('reports every refusal, not just the first', () => {
        const refusals = collectRefusals({
            attendees: [attendee(), attendee({id: 2, public_id: 'A-BBB222'})],
            response: {errors: {'A-AAA111': 'Cancelled', 'A-BBB222': 'Unpaid order'}},
        });

        expect(refusals).toHaveLength(2);
        expect(refusals.map(r => r.attendee?.public_id)).toEqual(['A-AAA111', 'A-BBB222']);
    });
});

// ---------------------------------------------------------------------------
// A check-in that was made is lost
// ---------------------------------------------------------------------------

describe('classifyFlushFailure', () => {
    it.each([400, 403, 404, 409, 422])('treats %i as definitive', (status) => {
        expect(classifyFlushFailure(status)).toBe('definitive');
    });

    it.each([408, 429, 500, 502, 503])('retries after %i', (status) => {
        expect(classifyFlushFailure(status)).toBe('retry');
    });

    // The one that matters most. At a venue the normal way to fail is for the request never to
    // reach anyone, and axios leaves no status behind when that happens. Calling it definitive
    // would wipe a whole night's queue on a dropped signal.
    it('retries when the request never reached the server', () => {
        expect(classifyFlushFailure(undefined)).toBe('retry');
    });
});

describe('backoffDelay', () => {
    it('doubles from four seconds', () => {
        expect([1, 2, 3].map(backoffDelay)).toEqual([4_000, 8_000, 16_000]);
    });

    it('stops growing at thirty seconds', () => {
        expect(backoffDelay(4)).toBe(30_000);
        expect(backoffDelay(10)).toBe(30_000);
    });
});

describe('batchSizeFor and shouldRotate: one bad entry must not hold up the queue', () => {
    it('sends full batches until the server has refused a couple of times', () => {
        expect([0, 1].map(batchSizeFor)).toEqual([50, 50]);
    });

    it('narrows to ten, then to one, to find the entry the server will not take', () => {
        expect([2, 3].map(batchSizeFor)).toEqual([10, 10]);
        expect([4, 10].map(batchSizeFor)).toEqual([1, 1]);
    });

    it('only sets an entry aside once it has failed on its own several times', () => {
        expect(shouldRotate(5)).toBe(false);
        expect(shouldRotate(6)).toBe(true);
    });

    /**
     * The test this whole design exists for. `serverErrors` counts answers from the server and
     * nothing else, so a venue losing signal for minutes — the ordinary case, the one the offline
     * queue was built for — never moves a good check-in towards being set aside. If this goes green
     * while transport failures are being counted, the scanner has started pushing valid check-ins to
     * the back of the queue during a dead spot.
     */
    it('does nothing at all through a long stretch with no signal', () => {
        // Forty failed passes with the venue's connection down: none of them reached a server, so
        // none of them carries a status.
        const serverErrors = Array.from({length: 40})
            .reduce<number>(current => nextServerErrors(current, undefined), 0);

        expect(serverErrors).toBe(0);
        expect(batchSizeFor(serverErrors)).toBe(50);
        expect(shouldRotate(serverErrors)).toBe(false);
    });

    it('counts the failures the server did answer', () => {
        expect(nextServerErrors(0, 500)).toBe(1);
        expect(nextServerErrors(3, 503)).toBe(4);
    });
});

describe('parseQueue: the queue comes back from localStorage too', () => {
    const entry = {publicId: 'A-AAA111', action: 'check-in', queuedAt: 5, attempts: 1, serverErrors: 2};

    it('keeps a well formed queue', () => {
        expect(parseQueue([entry])).toEqual([entry]);
    });

    // A queue written by the previous version has no serverErrors at all. Left undefined it reaches
    // arithmetic and turns the count into NaN, which compares false against every threshold.
    it('fills in a missing serverErrors instead of letting NaN through', () => {
        const [restored] = parseQueue([{publicId: 'A-AAA111', action: 'check-in', queuedAt: 5, attempts: 1}]);

        expect(restored.serverErrors).toBe(0);
        expect(Number.isNaN(restored.serverErrors + 1)).toBe(false);
    });

    it.each([
        ['it is not an array', 'nope'],
        ['it is null', null],
    ])('returns an empty queue when %s', (_label, raw) => {
        expect(parseQueue(raw)).toEqual([]);
    });

    it('drops entries with no public id or an action the server would refuse', () => {
        expect(parseQueue([
            entry,
            {action: 'check-in'},
            {publicId: 'A-BBB222', action: 'delete-everything'},
        ])).toEqual([entry]);
    });

    it('replaces counters that are not numbers rather than trusting them', () => {
        const [restored] = parseQueue([{...entry, attempts: '9', serverErrors: null}]);

        expect(restored.attempts).toBe(0);
        expect(restored.serverErrors).toBe(0);
    });
});

describe('parseSnapshot: localStorage is not trusted input', () => {
    const valid = {savedAt: 1_758_000_000_000, attendees: [attendee()]};

    it('accepts a well formed snapshot', () => {
        expect(parseSnapshot(valid)).toEqual(valid);
    });

    // It passes Number.isFinite and then throws out of toISOString(), which is exactly where this
    // value ends up — a blank screen in the middle of the door.
    it('rejects a date too large to format', () => {
        expect(parseSnapshot({...valid, savedAt: 1e300})).toBeNull();
    });

    it('rejects a savedAt that is not a number', () => {
        expect(parseSnapshot({...valid, savedAt: 'not a number'})).toBeNull();
    });

    // new Date(null) is the Unix epoch rather than an error, so a typeof check is the only thing
    // that catches this one.
    it('rejects a null savedAt', () => {
        expect(parseSnapshot({...valid, savedAt: null})).toBeNull();
    });

    it('rejects a snapshot with no savedAt', () => {
        expect(parseSnapshot({attendees: []})).toBeNull();
    });

    it.each([Infinity, -Infinity, NaN])('rejects savedAt = %s', (savedAt) => {
        expect(parseSnapshot({...valid, savedAt})).toBeNull();
    });

    it.each([
        ['attendees is not an array', {savedAt: 1, attendees: 'nope'}],
        ['the snapshot is null', null],
        ['the snapshot is a string', 'garbage'],
        ['the snapshot is a number', 42],
    ])('rejects it when %s', (_label, raw) => {
        expect(parseSnapshot(raw)).toBeNull();
    });

    // Checking the shape without checking the entries is decoration: one attendee without a
    // public_id takes the search box down the first time someone types in it.
    it('drops unusable entries and keeps the rest', () => {
        const parsed = parseSnapshot({
            savedAt: valid.savedAt,
            attendees: [attendee(), null, {no: 'public_id'}, {public_id: 42}],
        });

        expect(parsed?.attendees).toHaveLength(1);
        expect(parsed?.attendees[0].public_id).toBe('A-AAA111');
    });
});
