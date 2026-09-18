import {useCallback, useEffect, useRef, useState} from "react";
import {AxiosError} from "axios";
import {t} from "@lingui/macro";
import {Attendee, AttendeeCheckIn, IdParam, PublicCheckIn} from "../types";
import {publicCheckInClient} from "../api/check-in.client";
import {isSsr} from "../utilites/helpers";

/**
 * The door scanner's local copy of a check-in list.
 *
 * At the door, the scan has to feel instant, and the venue's connection is the
 * least reliable part of the chain. So the whole attendee list is downloaded once
 * when the list opens and kept in memory: a scan resolves against it with no
 * request at all, and the check-in itself is queued and confirmed with the server
 * in the background, retried until it gets through.
 *
 * The roster is also persisted, so reopening the page (a crashed tab, a redeploy)
 * comes back instantly, and it is refreshed periodically to pick up sales and
 * check-ins made from another phone.
 */

const ROSTER_PAGE_SIZE = 250;
const ROSTER_REFRESH_MS = 60_000;
const QUEUE_RETRY_MS = 2_000;
const QUEUE_MAX_RETRY_MS = 30_000;
const QUEUE_REQUEST_TIMEOUT_MS = 8_000;
// Roomier than the queue's: a roster page carries 250 attendees, not 50 ids. What matters is that
// it ends. With no timeout at all, a dead socket leaves isLoading pinned to true, the interval
// below returns on its guard forever, and the roster silently stops updating for the rest of the
// night — while the status bar still reads "all check-ins synced".
const ROSTER_REQUEST_TIMEOUT_MS = 20_000;
// Kept well inside QUEUE_REQUEST_TIMEOUT_MS: the server writes one transaction
// per attendee, so the slice has to be small enough that the round trip always
// finishes, even on the venue's connection.
const QUEUE_BATCH_SIZE = 50;

export type PendingCheckIn = {
    publicId: string;
    action: 'check-in' | 'check-in-and-mark-order-as-paid';
    queuedAt: number;
    attempts: number;
};

type Snapshot = {
    savedAt: number;
    attendees: Attendee[];
};

type RosterState = {
    attendees: Attendee[];
    loadedAt: number | null;
    isLoading: boolean;
    loadError: boolean;
    pending: PendingCheckIn[];
    syncing: boolean;
};

const rosterKey = (shortId: IdParam) => `checkInRoster:${shortId}`;
const queueKey = (shortId: IdParam) => `checkInQueue:${shortId}`;

const readJson = <T, >(key: string): T | null => {
    if (isSsr()) return null;
    try {
        const raw = localStorage.getItem(key);
        return raw ? JSON.parse(raw) as T : null;
    } catch {
        return null;
    }
};

const writeJson = (key: string, value: unknown) => {
    if (isSsr()) return;
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage full or blocked: the roster still works from memory.
    }
};

// The optimistic placeholder has no server record behind it yet, and its id is
// the marker for that. Anything that needs the real check-in — undoing one, or
// rolling a refused one back — keys off this instead of guessing from a blank field.
const PENDING_ID_PREFIX = 'pending-';

export const isPendingCheckIn = (checkIn?: AttendeeCheckIn): boolean =>
    checkIn !== undefined && String(checkIn.id).startsWith(PENDING_ID_PREFIX);

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

export const useCheckInRoster = (
    checkInListShortId: IdParam,
    enabled: boolean,
    allowedProductIds?: (number | string)[],
) => {
    const [state, setState] = useState<RosterState>(() => {
        const snapshot = readJson<Snapshot>(rosterKey(checkInListShortId));
        const queue = readJson<PendingCheckIn[]>(queueKey(checkInListShortId)) ?? [];
        return {
            attendees: snapshot?.attendees ?? [],
            loadedAt: snapshot?.savedAt ?? null,
            isLoading: false,
            loadError: false,
            pending: queue,
            syncing: false,
        };
    });

    const stateRef = useRef(state);
    stateRef.current = state;

    const patchAttendee = useCallback((publicId: string, patch: Partial<Attendee>) => {
        setState(prev => {
            const attendees = prev.attendees.map(a => a.public_id === publicId ? {...a, ...patch} : a);
            writeJson(rosterKey(checkInListShortId), {savedAt: prev.loadedAt ?? Date.now(), attendees});
            return {...prev, attendees};
        });
    }, [checkInListShortId]);

    // ---- Roster download -------------------------------------------------

    const refresh = useCallback(async () => {
        if (!enabled || stateRef.current.isLoading) return;
        setState(prev => ({...prev, isLoading: true, loadError: false}));

        try {
            const all: Attendee[] = [];
            let page = 1;
            // Pages are fetched sequentially: the server orders by id, so this is
            // consistent, and a 300-person event is two requests.
            for (; ;) {
                const response = await publicCheckInClient.getCheckInListAttendeesPage(
                    checkInListShortId, page, ROSTER_PAGE_SIZE, ROSTER_REQUEST_TIMEOUT_MS,
                );
                all.push(...response.data);
                // The server may cap the page size below what was asked for, so
                // the last page is the one shorter than what the server itself
                // says it serves per page.
                const serverPageSize = Number(response.meta?.per_page) || ROSTER_PAGE_SIZE;
                if (response.data.length === 0 || response.data.length < serverPageSize) break;
                page++;
            }

            setState(prev => {
                // Anything still queued locally is newer than what the server just
                // told us: keep those attendees marked as checked in.
                const pendingIds = new Set(prev.pending.map(p => p.publicId));
                const merged = all.map(a => {
                    if (!pendingIds.has(a.public_id) || a.check_in) return a;
                    const local = prev.attendees.find(p => p.public_id === a.public_id);
                    return local?.check_in ? {...a, check_in: local.check_in} : a;
                });
                const loadedAt = Date.now();
                writeJson(rosterKey(checkInListShortId), {savedAt: loadedAt, attendees: merged});
                return {...prev, attendees: merged, loadedAt, isLoading: false, loadError: false};
            });
        } catch {
            setState(prev => ({...prev, isLoading: false, loadError: prev.attendees.length === 0}));
        }
    }, [checkInListShortId, enabled]);

    useEffect(() => {
        if (!enabled) return;
        refresh();
        const interval = setInterval(refresh, ROSTER_REFRESH_MS);
        return () => clearInterval(interval);
    }, [enabled, refresh]);

    // ---- Check-in queue --------------------------------------------------

    const queueCheckIn = useCallback((attendee: Attendee, action: PendingCheckIn['action']): CheckInOutcome => {
        if (attendee.check_in) {
            return {status: 'already-checked-in'};
        }

        // The roster carries cancelled tickets — the server returns them so the
        // door can see them — and the check-in is optimistic, so the refusal has
        // to happen here. Otherwise the scanner goes green and the server's
        // rejection only lands seconds later, with the person already inside.
        if (attendee.status === 'CANCELLED') {
            return {status: 'cancelled'};
        }

        // The server refuses a ticket this list does not cover, and that refusal used to come back
        // as a 409 for the whole request, taking the queue — and everyone already through the door
        // — down with it. It is settled here, where it costs nothing and the door gets a useful
        // answer. With no list of products yet (the list has not loaded) there is nothing to tell
        // an outside ticket from a valid one, and refusing everything would be worse.
        if (allowedProductIds?.length
            && !allowedProductIds.some(id => String(id) === String(attendee.product_id))) {
            return {status: 'not-on-this-list'};
        }

        // Optimistic: the person is through the door now. The server confirms in
        // the background and replaces this placeholder with the real record.
        const placeholder: AttendeeCheckIn = {
            id: `${PENDING_ID_PREFIX}${attendee.public_id}`,
            attendee_id: attendee.id as IdParam,
            check_in_list_id: '',
            short_id: '',
            order_id: attendee.order_id,
            checked_in_at: new Date().toISOString(),
        };

        setState(prev => {
            // A ticket sold after the last refresh arrives through the network fallback and is not
            // in the roster yet. It has to be added, not just mapped over: rejections are resolved
            // against this array, so anyone missing from it gets their refusal dropped silently —
            // and they would never show up in the list on screen either.
            const known = prev.attendees.some(a => a.public_id === attendee.public_id);
            const roster = known ? prev.attendees : [...prev.attendees, attendee];
            const attendees = roster.map(a => a.public_id === attendee.public_id
                ? {...a, check_in: placeholder, status: action === 'check-in-and-mark-order-as-paid' ? 'ACTIVE' : a.status}
                : a);
            const pending = prev.pending.some(p => p.publicId === attendee.public_id)
                ? prev.pending
                : [...prev.pending, {publicId: attendee.public_id, action, queuedAt: Date.now(), attempts: 0}];
            writeJson(rosterKey(checkInListShortId), {savedAt: prev.loadedAt ?? Date.now(), attendees});
            writeJson(queueKey(checkInListShortId), pending);
            return {...prev, attendees, pending};
        });

        return {status: 'queued'};
    }, [checkInListShortId, allowedProductIds]);

    // Every refusal is a person who did not get through, so they all have to be
    // reported: keeping only the first would silently swallow the rest.
    const [rejected, setRejected] = useState<RejectedCheckIn[]>([]);

    // One flush at a time; each pass sends a bounded slice of the queue.
    const flushingRef = useRef(false);
    const retryTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const flush = useCallback(async () => {
        if (flushingRef.current) return;
        // A slice, not the whole queue: half an hour without signal leaves
        // hundreds queued, the server opens a transaction per attendee, and a
        // request that big never lands inside the timeout — so the client cuts,
        // retries the same oversized payload and never converges. Bounded slices
        // always land, and whatever is left triggers the next pass.
        const batch = stateRef.current.pending.slice(0, QUEUE_BATCH_SIZE);
        if (batch.length === 0) return;

        // Only this slice leaves the queue when the round trip ends. Anything
        // scanned while the request is in flight has to survive it: clearing the
        // whole queue would drop it unsent, leaving the person marked as through
        // the door and no record of it anywhere.
        const batchIds = new Set(batch.map(p => p.publicId));

        flushingRef.current = true;
        setState(prev => ({...prev, syncing: true}));

        try {
            const response = await publicCheckInClient.createCheckIns(
                checkInListShortId,
                batch.map(p => ({public_id: p.publicId, action: p.action})),
                QUEUE_REQUEST_TIMEOUT_MS,
            );

            const confirmed = new Map<string, PublicCheckIn>();
            (response.data ?? []).forEach((checkIn) => confirmed.set(String(checkIn.attendee_id), checkIn));
            const errors = response.errors ?? {};

            // An attendee the server confirmed is not a rejection, even when the
            // same response also carries "already checked in" for them: that is the
            // idempotent path after a lost response, and the person did go through.
            const confirmedPublicIds = new Set(
                stateRef.current.attendees
                    .filter(a => a.id !== undefined && confirmed.has(String(a.id)))
                    .map(a => a.public_id),
            );

            setState(prev => {
                const attendees = prev.attendees.map(a => {
                    const real = a.id !== undefined ? confirmed.get(String(a.id)) : undefined;
                    if (real) return {...a, check_in: real};
                    if (errors[a.public_id]) {
                        // The server refused it (cancelled ticket, unpaid order). Roll
                        // back the optimistic mark so the list tells the truth.
                        return {...a, check_in: undefined};
                    }
                    return a;
                });
                const pending = prev.pending.filter(p => !batchIds.has(p.publicId));
                writeJson(rosterKey(checkInListShortId), {savedAt: prev.loadedAt ?? Date.now(), attendees});
                writeJson(queueKey(checkInListShortId), pending);
                return {...prev, attendees, pending, syncing: false};
            });

            const refusals: RejectedCheckIn[] = Object.entries(errors)
                .filter(([publicId]) => !confirmedPublicIds.has(publicId))
                .map(([publicId, message]) => ({
                    // The attendee is normally in the roster — queueCheckIn puts them there even
                    // when they came from the network fallback — but a refresh in between can drop
                    // them again. The refusal is reported either way: dropping it would mean
                    // someone walked in on a check-in the server never accepted, with no trace.
                    attendee: stateRef.current.attendees.find(a => a.public_id === publicId),
                    message,
                }));

            if (refusals.length > 0) {
                setRejected(prev => [...prev, ...refusals]);
            }
        } catch (error) {
            // A definitive refusal (the list expired, was deleted, the request is
            // malformed) will never succeed on retry: drop the queue, undo the
            // optimistic marks and say so. Everything else — network down, a
            // timeout, a 5xx, a 429 — keeps the queue and backs off: the person
            // already went through; the record will follow.
            const status = error instanceof AxiosError ? error.response?.status : undefined;
            const isDefinitive = status !== undefined && status >= 400 && status < 500 && status !== 408 && status !== 429;

            if (isDefinitive) {
                const message = (error as AxiosError<{ message?: string }>).response?.data?.message
                    ?? 'The server refused these check-ins';
                setState(prev => {
                    const attendees = prev.attendees.map(a => batchIds.has(a.public_id) && isPendingCheckIn(a.check_in)
                        ? {...a, check_in: undefined}
                        : a);
                    const pending = prev.pending.filter(p => !batchIds.has(p.publicId));
                    writeJson(rosterKey(checkInListShortId), {savedAt: prev.loadedAt ?? Date.now(), attendees});
                    writeJson(queueKey(checkInListShortId), pending);
                    return {...prev, attendees, pending, syncing: false};
                });
                // One report, not one per person: this refusal is about the list (expired,
                // deleted), so it says nothing about any individual — naming the first of the
                // batch read as if it did. What the door needs is how many were lost.
                const lost = batch.length;
                setRejected(prev => [...prev, {
                    message: t`${message} — ${lost} check-in(s) could not be saved`,
                }]);
                flushingRef.current = false;
                return;
            }

            // The attempt counts against this batch only: a check-in queued while
            // it was failing should not inherit an already-stretched backoff.
            const attempts = batch[0].attempts + 1;
            setState(prev => ({
                ...prev,
                syncing: false,
                pending: prev.pending.map(p => batchIds.has(p.publicId) ? {...p, attempts} : p),
            }));
            const delay = Math.min(QUEUE_RETRY_MS * 2 ** Math.min(attempts, 4), QUEUE_MAX_RETRY_MS);
            retryTimerRef.current = setTimeout(() => {
                flushingRef.current = false;
                flush();
            }, delay);
            return;
        }

        flushingRef.current = false;
    }, [checkInListShortId]);

    // The single trigger for sending: whatever is left in the queue after a pass
    // re-runs this. Depending on the array's identity rather than its length also
    // catches the case where a batch leaves and the same number arrives behind it.
    useEffect(() => {
        if (state.pending.length > 0 && !flushingRef.current) flush();
    }, [state.pending, flush]);

    useEffect(() => {
        if (isSsr()) return;
        const onOnline = () => {
            if (retryTimerRef.current) clearTimeout(retryTimerRef.current);
            flushingRef.current = false;
            flush();
        };
        window.addEventListener('online', onOnline);
        return () => {
            window.removeEventListener('online', onOnline);
            if (retryTimerRef.current) clearTimeout(retryTimerRef.current);
        };
    }, [flush]);

    const findByPublicId = useCallback(
        (publicId: string) => stateRef.current.attendees.find(a => a.public_id === publicId),
        [],
    );

    return {
        attendees: state.attendees,
        loadedAt: state.loadedAt,
        isLoading: state.isLoading,
        loadError: state.loadError,
        pendingCount: state.pending.length,
        syncing: state.syncing,
        rejected,
        clearRejected: () => setRejected([]),
        refresh,
        findByPublicId,
        queueCheckIn,
        patchAttendee,
    };
};
