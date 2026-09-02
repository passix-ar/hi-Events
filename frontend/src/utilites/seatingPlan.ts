import {SeatingSection} from "../types.ts";

/**
 * Absolutely positioned pieces give their container no size, so the plan has to say how big
 * it is or the browser clips it instead of scrolling. These mirror SeatingChart.module.scss:
 * change a seat there and change it here.
 */
const SEAT = 22;
const SEAT_GAP = 3;
const ROW_GAP = 4;
const ROW_LABEL = 20;
const AISLE = 14;
const NAME_LINE = 26;

export const STAGE_SIZE = {width: 220, height: 34};

export const sectionSize = (section: SeatingSection) => {
    const seats = section.seats_per_row;
    const aisles = section.aisle_positions?.length ?? 0;

    return {
        width: ROW_LABEL * 2 + seats * SEAT + (seats - 1) * SEAT_GAP + aisles * AISLE,
        height: NAME_LINE + section.row_count * SEAT + (section.row_count - 1) * ROW_GAP,
    };
};

export interface PlanPiece {
    key: string;
    x: number;
    y: number;
    width: number;
    height: number;
    section?: SeatingSection;
}

/**
 * Lays the stage and every section out from a common origin, shifted so the top-left of the
 * plan sits at 0,0 — coordinates are stored around an origin and are routinely negative.
 */
export const buildPlan = (sections: SeatingSection[], stage: { x: number, y: number }) => {
    const pieces: PlanPiece[] = [
        {key: 'stage', x: stage.x, y: stage.y, ...STAGE_SIZE},
        ...sections.map((section) => ({
            key: String(section.id),
            x: section.position_x ?? 0,
            y: section.position_y ?? 0,
            section,
            ...sectionSize(section),
        })),
    ];

    const offsetX = Math.min(...pieces.map((piece) => piece.x));
    const offsetY = Math.min(...pieces.map((piece) => piece.y));

    const placed = pieces.map((piece) => ({...piece, x: piece.x - offsetX, y: piece.y - offsetY}));

    return {
        pieces: placed,
        width: Math.max(...placed.map((piece) => piece.x + piece.width)),
        height: Math.max(...placed.map((piece) => piece.y + piece.height)),
        offsetX,
        offsetY,
    };
};
