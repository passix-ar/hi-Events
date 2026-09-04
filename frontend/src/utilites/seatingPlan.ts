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

/** The title is drawn on one line above the grid and can be wider than it — "Platea
 *  izquierda" over three seats is nearly twice the grid. Left out of the box, a neighbour
 *  parks its seats under the text. Rough is fine: the gutter absorbs the error. */
const NAME_CHAR = 7.2;

export const STAGE_SIZE = {width: 220, height: 34};

/**
 * Coordinates are stored around an origin and are routinely negative — sitting above or left
 * of the stage is normal. The plan is drawn inside a fixed margin rather than shifted to its
 * own minimum: normalising on every render moved every other piece whenever one was dragged
 * past the current edge, so the whole room jumped while you moved one section.
 */
export const PLAN_MARGIN = 24;

/** Free placement still needs a shape: pieces land on a grid and never sit on each other. */
export const PLAN_GRID = 20;

/** Just enough that two pieces do not touch. Kept small on purpose: the plan is meant to be
 *  arranged freely, not to fight the organizer over a few pixels. */
const GUTTER = 12;

export const snapToGrid = (value: number) => Math.round(value / PLAN_GRID) * PLAN_GRID;

interface Rect { x: number; y: number; width: number; height: number }

const intersects = (a: Rect, b: Rect) =>
    a.x < b.x + b.width + GUTTER
    && a.x + a.width + GUTTER > b.x
    && a.y < b.y + b.height + GUTTER
    && a.y + a.height + GUTTER > b.y;

const collides = (moving: Rect, others: Rect[]) => others.some((other) => intersects(moving, other));

/**
 * Where a dragged piece actually lands. Refusing an overlapping drop looked reasonable until
 * a plan that already overlapped could not be untangled: every small move still collided, so
 * nothing could be dragged anywhere. A piece is always dropped, and pushed to the nearest
 * free spot when the one under the cursor is taken.
 */
export const freeSpotNear = (target: Rect, others: Rect[]) => {
    if (!collides(target, others)) {
        return {x: target.x, y: target.y};
    }

    for (let ring = 1; ring <= 40; ring++) {
        const step = ring * PLAN_GRID;

        for (const [dx, dy] of [[0, step], [step, 0], [0, -step], [-step, 0],
            [step, step], [-step, step], [step, -step], [-step, -step]]) {
            const candidate = {...target, x: Math.max(0, target.x + dx), y: Math.max(0, target.y + dy)};

            if (!collides(candidate, others)) {
                return {x: candidate.x, y: candidate.y};
            }
        }
    }

    return {x: target.x, y: target.y};
};

export const sectionSize = (section: SeatingSection) => {
    const seats = section.seats_per_row;
    const aisles = section.aisle_positions?.length ?? 0;

    const grid = ROW_LABEL * 2 + seats * SEAT + (seats - 1) * SEAT_GAP + aisles * AISLE;

    return {
        width: Math.max(grid, Math.ceil(section.name.length * NAME_CHAR)),
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
        ...(stage.visible === false ? [] : [{key: 'stage', x: Math.max(0, stage.x), y: Math.max(0, stage.y), ...STAGE_SIZE}]),
        ...sections.map((section) => ({
            key: String(section.id),
            x: Math.max(0, section.position_x ?? 0),
            y: Math.max(0, section.position_y ?? 0),
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

    // The stage sits on top of the plan, and the sections start below it.
    let y = STAGE_SIZE.height + GAP;
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
        stage: {x: 0, y: 0},
        sections: placed,
    };
};
