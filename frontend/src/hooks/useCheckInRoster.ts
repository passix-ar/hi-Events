import {useCallback, useEffect, useRef, useState} from "react";
import {AxiosError} from "axios";
import {t} from "@lingui/macro";
import {Attendee, IdParam} from "../types";
import {publicCheckInClient} from "../api/check-in.client";
import {isSsr} from "../utilites/helpers";
import {
    applyFlushResponse,
    backoffDelay,
    classifyFlushFailure,
    collectRefusals,
    decideCheckIn,
    isPendingCheckIn,
    mergeRoster,
    parseSnapshot,
    pendingPlaceholder,
    QUEUE_BATCH_SIZE,
    QUEUE_REQUEST_TIMEOUT_MS,
    ROSTER_PAGE_SIZE,
    ROSTER_REFRESH_MS,
    ROSTER_REQUEST_TIMEOUT_MS,
    type PendingCheckIn,
    type RejectedCheckIn,
    type Snapshot,
} from "./checkInRoster.logic";

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
 *
 * What this file holds is the wiring — storage, timers, requests, React state. Every decision it
 * makes lives in `checkInRoster.logic.ts`, where it can be tested without a browser.
 */

// Re-exported so the scanner keeps importing its vocabulary from one place.
export {isPendingCheckIn, ROSTER_REFRESH_MS} from "./checkInRoster.logic";
export type {CheckInOutcome, PendingCheckIn, RejectedCheckIn} from "./checkInRoster.logic";

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

const readSnapshot = (shortId: IdParam): Snapshot | null =>
    parseSnapshot(readJson<unknown>(rosterKey(shortId)));

const readQueue = (shortId: IdParam): PendingCheckIn[] => {
    const raw = readJson<PendingCheckIn[]>(queueKey(shortId));
    return Array.isArray(raw) ? raw : [];
};

export const useCheckInRoster = (
    checkInListShortId: IdParam,
    enabled: boolean,
    allowedProductIds?: (number | string)[],
) => {
    const [state, setState] = useState<RosterState>(() => {
        const snapshot = readSnapshot(checkInListShortId);
        const queue = readQueue(checkInListShortId);
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

    // A ref rather than the state flag: `stateRef` only catches up on the next render, so two calls
    // landing in the same tick — the interval firing while someone taps retry — would both get
    // through. That already meant a duplicated fetch; it would also let the second one clear the
    // protection the first one depends on below.
    const isRefreshingRef = useRef(false);
    // Everything that entered the queue while a fetch was in flight. Reset on each refresh, so it
    // cleans itself up without a map to purge or timestamps to compare.
    const touchedDuringFetchRef = useRef<Set<string>>(new Set());

    const refresh = useCallback(async () => {
        if (!enabled || isRefreshingRef.current) return;
        isRefreshingRef.current = true;
        touchedDuringFetchRef.current = new Set();
        // Taken before the first page goes out: what the server is about to tell us describes its
        // state at THIS moment, so anything queued now is newer than that answer.
        const queuedWhenFetchStarted = new Set(stateRef.current.pending.map(p => p.publicId));
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
                // Anything that passed through the queue at any point during the fetch is newer
                // than the answer we just got. Checking `pending` alone is not enough: the flush
                // can confirm a check-in while the pages are still in flight, which takes it out of
                // the queue before we get here.
                const protectedIds = new Set([
                    ...queuedWhenFetchStarted,
                    ...touchedDuringFetchRef.current,
                    ...prev.pending.map(p => p.publicId),
                ]);
                const attendees = mergeRoster({server: all, local: prev.attendees, protectedIds});
                const loadedAt = Date.now();
                writeJson(rosterKey(checkInListShortId), {savedAt: loadedAt, attendees});
                return {...prev, attendees, loadedAt, isLoading: false, loadError: false};
            });
        } catch {
            // Flagged even with a roster already loaded. It used to be raised only when there was
            // nothing to show, so a refresh failing behind a full list was invisible and the status
            // bar kept saying everything was synced.
            setState(prev => ({...prev, isLoading: false, loadError: true}));
        } finally {
            isRefreshingRef.current = false;
        }
    }, [checkInListShortId, enabled]);

    useEffect(() => {
        if (!enabled) return;
        refresh();
        const interval = setInterval(refresh, ROSTER_REFRESH_MS);
        return () => clearInterval(interval);
    }, [enabled, refresh]);

    // ---- Check-in queue --------------------------------------------------

    const queueCheckIn = useCallback((attendee: Attendee, action: PendingCheckIn['action']) => {
        const outcome = decideCheckIn(attendee, allowedProductIds);

        if (outcome.status !== 'queued') {
            return outcome;
        }

        // Optimistic: the person is through the door now. The server confirms in
        // the background and replaces this placeholder with the real record.
        const placeholder = pendingPlaceholder(attendee);

        // If a refresh is in flight, its answer predates this scan: flag it so the merge does not
        // overwrite it with a snapshot taken before the person walked in.
        touchedDuringFetchRef.current.add(attendee.public_id);

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

        return outcome;
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

        const batchIds = new Set(batch.map(p => p.publicId));

        flushingRef.current = true;
        setState(prev => ({...prev, syncing: true}));

        try {
            const response = await publicCheckInClient.createCheckIns(
                checkInListShortId,
                batch.map(p => ({public_id: p.publicId, action: p.action})),
                QUEUE_REQUEST_TIMEOUT_MS,
            );

            setState(prev => {
                const {attendees, pending} = applyFlushResponse({
                    attendees: prev.attendees,
                    pending: prev.pending,
                    batchIds,
                    response,
                });
                writeJson(rosterKey(checkInListShortId), {savedAt: prev.loadedAt ?? Date.now(), attendees});
                writeJson(queueKey(checkInListShortId), pending);
                return {...prev, attendees, pending, syncing: false};
            });

            const refusals = collectRefusals({attendees: stateRef.current.attendees, response});

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

            if (classifyFlushFailure(status) === 'definitive') {
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
            retryTimerRef.current = setTimeout(() => {
                flushingRef.current = false;
                flush();
            }, backoffDelay(attempts));
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
