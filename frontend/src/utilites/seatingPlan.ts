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

/**
 * Coordinates are stored around an origin and are routinely negative — sitting above or left
 * of the stage is normal. The plan is drawn inside a fixed margin rather than shifted to its
 * own minimum: normalising on every render moved every other piece whenever one was dragged
 * past the current edge, so the whole room jumped while you moved one section.
 */
export const PLAN_MARGIN = 200;

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

/** Lays the stage and every section out inside the fixed margin. */
export const buildPlan = (
    sections: SeatingSection[],
    stage: { x: number, y: number, visible?: boolean },
) => {
    const pieces: PlanPiece[] = [
        ...(stage.visible === false ? [] : [{key: 'stage', x: stage.x, y: stage.y, ...STAGE_SIZE}]),
        ...sections.map((section) => ({
            key: String(section.id),
            x: section.position_x ?? 0,
            y: section.position_y ?? 0,
            section,
            ...sectionSize(section),
        })),
    ];

    if (pieces.length === 0) {
        return {pieces, width: 0, height: 0, offsetX: 0, offsetY: 0};
    }

    const placed = pieces.map((piece) => ({...piece, x: piece.x + PLAN_MARGIN, y: piece.y + PLAN_MARGIN}));

    return {
        pieces: placed,
        width: Math.max(...placed.map((piece) => piece.x + piece.width)) + PLAN_MARGIN,
        height: Math.max(...placed.map((piece) => piece.y + piece.height)) + PLAN_MARGIN,
    };
};
