import {describe, expect, it} from 'vitest';
import {isScannableBarcode} from './barcode';

/**
 * The HID scanner feeds the page a stream of keypresses, so anything typed at the door lands in the
 * same handler. Only an attendee code may reach a check-in.
 */
describe('isScannableBarcode', () => {
    it('accepts an attendee code', () => {
        expect(isScannableBarcode('A-XK29PQZ')).toBe(true);
    });

    it('rejects the prefix on its own', () => {
        expect(isScannableBarcode('A-')).toBe(false);
    });

    it('rejects anything without the prefix', () => {
        expect(isScannableBarcode('1234')).toBe(false);
    });

    it('rejects an empty string', () => {
        expect(isScannableBarcode('')).toBe(false);
    });
});
