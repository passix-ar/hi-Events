import {useCallback, useEffect, useRef, useState} from "react";
import {AxiosError} from "axios";
import {Attendee, AttendeeCheckIn, IdParam} from "../types";
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

export type CheckInOutcome =
    | { status: 'queued' }
    | { status: 'already-checked-in' };

export const useCheckInRoster = (checkInListShortId: IdParam, enabled: boolean) => {
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
                const response = await publicCheckInClient.getCheckInListAttendeesPage(checkInListShortId, page, ROSTER_PAGE_SIZE);
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

        // Optimistic: the person is through the door now. The server confirms in
        // the background and replaces this placeholder with the real record.
        const placeholder: AttendeeCheckIn = {
            id: `pending-${attendee.public_id}`,
            attendee_id: attendee.id as IdParam,
            check_in_list_id: '',
            product_id: attendee.product_id,
            event_id: '',
            short_id: '',
            order_id: attendee.order_id,
            created_at: new Date().toISOString(),
        };

        setState(prev => {
            const attendees = prev.attendees.map(a => a.public_id === attendee.public_id
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
    }, [checkInListShortId]);

    const [rejected, setRejected] = useState<{ attendee: Attendee, message: string } | null>(null);

    // One flush at a time; each pass sends everything queued in a single request.
    const flushingRef = useRef(false);
    const retryTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const flush = useCallback(async () => {
        if (flushingRef.current) return;
        const batch = stateRef.current.pending;
        if (batch.length === 0) return;

        flushingRef.current = true;
        setState(prev => ({...prev, syncing: true}));

        try {
            const response = await publicCheckInClient.createCheckIns(
                checkInListShortId,
                batch.map(p => ({public_id: p.publicId, action: p.action})),
                QUEUE_REQUEST_TIMEOUT_MS,
            );

            const confirmed = new Map<string, AttendeeCheckIn>();
            (response.data ?? []).forEach((checkIn) => confirmed.set(String(checkIn.attendee_id), checkIn as unknown as AttendeeCheckIn));
            const errors = response.errors ?? {};

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
                writeJson(rosterKey(checkInListShortId), {savedAt: prev.loadedAt ?? Date.now(), attendees});
                writeJson(queueKey(checkInListShortId), []);
                return {...prev, attendees, pending: [], syncing: false};
            });

            const firstError = Object.entries(errors)[0];
            if (firstError) {
                const attendee = stateRef.current.attendees.find(a => a.public_id === firstError[0]);
                if (attendee) setRejected({attendee, message: firstError[1]});
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
                const queuedIds = new Set(batch.map(p => p.publicId));
                setState(prev => {
                    const attendees = prev.attendees.map(a => queuedIds.has(a.public_id) && a.check_in?.short_id === ''
                        ? {...a, check_in: undefined}
                        : a);
                    writeJson(rosterKey(checkInListShortId), {savedAt: prev.loadedAt ?? Date.now(), attendees});
                    writeJson(queueKey(checkInListShortId), []);
                    return {...prev, attendees, pending: [], syncing: false};
                });
                const first = stateRef.current.attendees.find(a => a.public_id === batch[0].publicId);
                if (first) setRejected({attendee: first, message});
                flushingRef.current = false;
                return;
            }

            const attempts = batch[0].attempts + 1;
            setState(prev => ({
                ...prev,
                syncing: false,
                pending: prev.pending.map(p => ({...p, attempts})),
            }));
            const delay = Math.min(QUEUE_RETRY_MS * 2 ** Math.min(attempts, 4), QUEUE_MAX_RETRY_MS);
            retryTimerRef.current = setTimeout(() => {
                flushingRef.current = false;
                flush();
            }, delay);
            return;
        }

        flushingRef.current = false;
        if (stateRef.current.pending.length > 0) flush();
    }, [checkInListShortId]);

    // Flush whenever something is queued, and when the connection comes back.
    useEffect(() => {
        if (state.pending.length > 0 && !flushingRef.current) flush();
    }, [state.pending.length, flush]);

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
        clearRejected: () => setRejected(null),
        refresh,
        findByPublicId,
        queueCheckIn,
        patchAttendee,
    };
};
