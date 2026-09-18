/**
 * The gate for the HID scanner, which feeds the page a stream of keypresses rather than a scan
 * event: anything the person at the door types lands here too. Only an attendee code goes through.
 */
export const isScannableBarcode = (barcode: string): boolean =>
    barcode.startsWith('A-') && barcode.length > 3;
