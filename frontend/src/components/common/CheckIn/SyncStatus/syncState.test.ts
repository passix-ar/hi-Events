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
    stuckCount: 0,
    loadedAt: NOW,
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

    // A different problem from a slow connection, and it needs a different answer. Inside "Syncing
    // N" it reads as the network being slow and the number simply never comes down.
    it('says so when check-ins could not be saved, ahead of the plain pending count', () => {
        expect(pickSyncState(input({stuckCount: 2, pendingCount: 40})))
            .toEqual({kind: 'stuck', stuckCount: 2});
    });

    // Offline first: with no connection there is nothing to act on, and the count is going nowhere
    // for a reason the door already knows about.
    it('still leads with being offline', () => {
        expect(pickSyncState(input({online: false, stuckCount: 2, pendingCount: 40})).kind)
            .toBe('offline');
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
        expect(pickSyncState(input({loadedAt: null}))).toEqual({kind: 'loading'});
        expect(pickSyncState(input())).toEqual({kind: 'ok'});
    });

    // The case that shipped: the server renders this, and on the server there is no localStorage,
    // so there is no roster date to read and the refresh cycle has not started either. It went out
    // as "all check-ins synced" over an empty list.
    it('never claims to be synced before a roster has ever arrived', () => {
        expect(pickSyncState(input({loadedAt: null}))).toEqual({kind: 'loading'});
        expect(pickSyncState(input({loadedAt: null, mounted: false}))).toEqual({kind: 'loading'});
    });

    // Without a roster there is still a worse thing to say than "loading", and those branches run
    // first. Pinned because the fix above sits right underneath them.
    it('still reports the connection and the queue ahead of loading', () => {
        expect(pickSyncState(input({loadedAt: null, online: false, pendingCount: 2})))
            .toEqual({kind: 'offline', pendingCount: 2});
        expect(pickSyncState(input({loadedAt: null, pendingCount: 2})))
            .toEqual({kind: 'syncing', pendingCount: 2});
        expect(pickSyncState(input({loadedAt: null, stuckCount: 1})))
            .toEqual({kind: 'stuck', stuckCount: 1});
        expect(pickSyncState(input({loadedAt: null, loadError: true})))
            .toEqual({kind: 'error'});
    });
});
