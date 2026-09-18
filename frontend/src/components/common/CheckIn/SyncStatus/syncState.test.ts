import {describe, expect, it} from 'vitest';
import {pickSyncState, STALE_AFTER_MS, type SyncStateInput} from './syncState';

/**
 * The status bar is the only thing at the door that can say something is wrong. What it says is
 * decided by the order of these branches, and the order is the part that is easy to get wrong.
 */

const NOW = 1_758_000_000_000;

const input = (overrides: Partial<SyncStateInput> = {}): SyncStateInput => ({
    online: true,
    pendingCount: 0,
    loadedAt: NOW,
    isLoading: false,
    loadError: false,
    mounted: true,
    now: NOW,
    ...overrides,
});

describe('pickSyncState', () => {
    it('shows the blocking error when there is no list at all', () => {
        expect(pickSyncState(input({loadError: true, loadedAt: null, pendingCount: 3, online: false})))
            .toEqual({kind: 'error'});
    });

    it('says how many check-ins are waiting when offline', () => {
        expect(pickSyncState(input({online: false, pendingCount: 4})))
            .toEqual({kind: 'offline', pendingCount: 4});
    });

    it('reassures that scanning still works when offline with nothing queued', () => {
        expect(pickSyncState(input({online: false})))
            .toEqual({kind: 'offline', pendingCount: 0});
    });

    // Pending check-ins already tell the door the network is not keeping up. The age of the list
    // is the quieter problem and it would only add noise here.
    it('prefers the pending count over the list being old', () => {
        expect(pickSyncState(input({pendingCount: 2, loadedAt: NOW - STALE_AFTER_MS - 1})))
            .toEqual({kind: 'syncing', pendingCount: 2});
    });

    it('warns when the list is older than three refresh cycles', () => {
        const loadedAt = NOW - STALE_AFTER_MS - 1;
        expect(pickSyncState(input({loadedAt}))).toEqual({kind: 'stale', loadedAt});
    });

    // The scanner route renders on the server, where there is no localStorage and no roster date.
    // Reading the clock before mounting gives the server and the client different text, and React
    // throws away the whole tree over it.
    it('never warns about the age before the component has mounted', () => {
        expect(pickSyncState(input({mounted: false, loadedAt: NOW - STALE_AFTER_MS - 1})))
            .toEqual({kind: 'ok'});
    });

    it('treats exactly three cycles as still fresh, and one millisecond more as old', () => {
        expect(pickSyncState(input({loadedAt: NOW - STALE_AFTER_MS}))).toEqual({kind: 'ok'});
        expect(pickSyncState(input({loadedAt: NOW - STALE_AFTER_MS - 1})).kind).toBe('stale');
    });

    it('says it is loading only while there is nothing to show yet', () => {
        expect(pickSyncState(input({isLoading: true, loadedAt: null}))).toEqual({kind: 'loading'});
        expect(pickSyncState(input({isLoading: true}))).toEqual({kind: 'ok'});
    });
});
