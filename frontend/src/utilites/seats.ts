import {Seat} from "../types.ts";

export const rowLabelForIndex = (rowIndex: number): string => {
    let label = '';
    let n = rowIndex + 1;

    while (n > 0) {
        n--;
        label = String.fromCharCode(65 + (n % 26)) + label;
        n = Math.floor(n / 26);
    }

    return label;
};

/**
 * Deterministic seat ordering, identical to the backend's
 * (seating_section_id, LENGTH(row_label), row_label, seat_number). The backend assigns the
 * Nth seat of a product to that product's Nth attendee, so any UI pairing seats with
 * attendees must use this exact comparator.
 */
export const compareSeats = (a: Seat, b: Seat): number => {
    if (a.seating_section_id !== b.seating_section_id) {
        return a.seating_section_id - b.seating_section_id;
    }
    if (a.row_label.length !== b.row_label.length) {
        return a.row_label.length - b.row_label.length;
    }
    if (a.row_label !== b.row_label) {
        return a.row_label < b.row_label ? -1 : 1;
    }
    return a.seat_number - b.seat_number;
};

export const sortSeats = (seats: Seat[]): Seat[] => [...seats].sort(compareSeats);
