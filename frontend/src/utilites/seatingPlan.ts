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

/** Free placement still needs a shape: pieces land on a grid and never sit on each other. */
export const PLAN_GRID = 20;

/** Breathing room between two pieces, so a plan never reads as one blurred block. */
const GUTTER = 24;

export const snapToGrid = (value: number) => Math.round(value / PLAN_GRID) * PLAN_GRID;

interface Rect { x: number; y: number; width: number; height: number }

const intersects = (a: Rect, b: Rect) =>
    a.x < b.x + b.width + GUTTER
    && a.x + a.width + GUTTER > b.x
    && a.y < b.y + b.height + GUTTER
    && a.y + a.height + GUTTER > b.y;

/** True when the piece would land on top of any of the others. */
export const collides = (moving: Rect, others: Rect[]) => others.some((other) => intersects(moving, other));

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

/**
 * Lays every section out in rows that fit the widest one, with the stage above them. Used to
 * rescue a plan whose pieces already sit on top of each other, which no amount of dragging
 * one piece at a time can undo comfortably.
 */
export const tidyPlan = (sections: SeatingSection[]) => {
    const GAP = 40;
    const widest = Math.max(...sections.map((section) => sectionSize(section).width), STAGE_SIZE.width);
    const perRow = Math.max(1, Math.floor(1200 / (widest + GAP)));

    let y = 0;
    let rowHeight = 0;

    const placed = sections.map((section, index) => {
        const column = index % perRow;

        if (column === 0 && index > 0) {
            y += rowHeight + GAP;
            rowHeight = 0;
        }

        rowHeight = Math.max(rowHeight, sectionSize(section).height);

        return {
            id: section.id,
            position_x: column * (widest + GAP),
            position_y: y,
        };
    });

    return {
        stage: {x: 0, y: -(STAGE_SIZE.height + GAP)},
        sections: placed,
    };
};
