import {useMemo} from "react";
import {t, Trans} from "@lingui/macro";
import {SeatingSection} from "../../../types.ts";
import classes from './SeatMapPreview.module.scss';

interface SeatMapPreviewProps {
    sections: SeatingSection[];
    /** Where the designer put the stage; undefined while loading. */
    stage?: { stage_x: number; stage_y: number; stage_visible: boolean } | null;
    /** The section that was just created: it drops in row by row. */
    highlightId?: number | null;
}

// One colour per ticket type, in the order sections appear.
const TICKET_COLOURS = ['#d6ff3d', '#4dabf7', '#ff6fb5', '#ffd43b', '#2dd4bf', '#a78bfa', '#ff8c42'];

// The designer's pixel geometry (utilites/seatingPlan.ts): drawn 1:1 in the
// SVG so the plan here is the plan the panel and the public page show.
const SEAT = 22;
const SEAT_GAP = 3;
const ROW_GAP = 4;
const ROW_LABEL = 20;
const AISLE = 14;
const NAME_LINE = 26;
const NAME_CHAR = 7.2;
const STAGE = {width: 220, height: 34};
const GAP = 40;

const rowLetter = (row: number) => String.fromCharCode(65 + (row % 26));

/**
 * A tilted, isometric-looking plan of the seat map drawn from the real
 * sections at their real canvas positions: stage, one block per section,
 * aisles as gaps, seats coloured by the ticket that sells them. Pure SVG + CSS.
 */
export const SeatMapPreview = ({sections, stage, highlightId}: SeatMapPreviewProps) => {
    const layout = useMemo(() => {
        const ordered = [...sections].sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
        const colourByProduct = new Map<number, string>();
        ordered.forEach(section => {
            if (!colourByProduct.has(section.product_id)) {
                colourByProduct.set(section.product_id, TICKET_COLOURS[colourByProduct.size % TICKET_COLOURS.length]);
            }
        });

        // Older sections may all sit at (0,0): stack those like the designer does on creation.
        let fallbackY = STAGE.height + GAP;
        const blocks = ordered.map(section => {
            const aisles = new Set((section.aisle_positions ?? []).map(Number));
            const seatX: number[] = [];
            let x = ROW_LABEL;
            for (let seat = 1; seat <= section.seats_per_row; seat++) {
                seatX.push(x);
                x += SEAT + SEAT_GAP + (aisles.has(seat) ? AISLE : 0);
            }
            const gridWidth = x - SEAT_GAP + ROW_LABEL;
            const width = Math.max(gridWidth, Math.ceil(section.name.length * NAME_CHAR));
            const height = NAME_LINE + section.row_count * SEAT + (section.row_count - 1) * ROW_GAP;
            const positioned = (section.position_x ?? 0) !== 0 || (section.position_y ?? 0) !== 0;
            const left = Math.max(0, section.position_x ?? 0);
            const top = positioned ? Math.max(0, section.position_y ?? 0) : fallbackY;
            if (!positioned) {
                fallbackY += height + GAP;
            }
            return {section, seatX, width, height, left, top, colour: colourByProduct.get(section.product_id) ?? TICKET_COLOURS[0]};
        });

        const stageX = Math.max(0, stage?.stage_x ?? 0);
        const stageY = Math.max(0, stage?.stage_y ?? 0);
        const width = Math.max(STAGE.width + stageX, ...blocks.map(b => b.left + b.width));
        const height = Math.max(STAGE.height + stageY, ...blocks.map(b => b.top + b.height));

        return {width, height, stageX, stageY, blocks, colourByProduct};
    }, [sections, stage]);
    if (sections.length === 0) {
        return (
            <div className={classes.empty}>
                <div className={classes.emptyStage}><Trans>Stage</Trans></div>
                <p><Trans>No sections yet. Describe the room to the assistant: "una platea de 20 filas de 30".</Trans></p>
            </div>
        );
    }

    const totalSeats = sections.reduce((sum, section) => sum + section.row_count * section.seats_per_row, 0);
    const padding = 24;

    return (
        <div className={classes.scene}>
            <div className={classes.floor}>
                <svg
                    className={classes.plan}
                    viewBox={`${-padding} ${-padding} ${layout.width + padding * 2} ${layout.height + padding * 2}`}
                    role="img"
                    aria-label={t`Seat map preview`}
                >
                    <defs>
                        <linearGradient id="assistant-stage" x1="0" x2="1">
                            <stop offset="0" stopColor="#2a2a33"/>
                            <stop offset="0.5" stopColor="#3b3b46"/>
                            <stop offset="1" stopColor="#2a2a33"/>
                        </linearGradient>
                    </defs>

                    {stage?.stage_visible !== false && (
                        <g className={classes.stage} transform={`translate(${layout.stageX} ${layout.stageY})`}>
                            <rect x={0} y={0} width={STAGE.width} height={STAGE.height} rx={6} fill="url(#assistant-stage)"/>
                            <text x={STAGE.width / 2} y={STAGE.height / 2 + 4} textAnchor="middle" className={classes.stageLabel}>
                                {t`STAGE`}
                            </text>
                        </g>
                    )}

                    {layout.blocks.map(block => {
                        const isNew = block.section.id === highlightId;
                        return (
                            <g key={block.section.id} transform={`translate(${block.left} ${block.top})`} className={isNew ? classes.sectionNew : classes.section}>
                                <rect
                                    x={-8} y={-6} width={block.width + 16} height={block.height + 12} rx={10}
                                    className={classes.sectionFloor}
                                />
                                <text x={0} y={NAME_LINE - 9} className={classes.sectionLabel}>
                                    {block.section.name} · {block.section.row_count}×{block.section.seats_per_row}
                                </text>
                                {Array.from({length: block.section.row_count}).map((_, row) => (
                                    <g key={row} transform={`translate(0 ${NAME_LINE + row * (SEAT + ROW_GAP)})`}>
                                        <g className={classes.row} style={isNew ? {animationDelay: `${row * 45}ms`} : undefined}>
                                            <text x={ROW_LABEL / 2} y={SEAT / 2 + 4} textAnchor="middle" className={classes.rowLabel}>{rowLetter(row)}</text>
                                            {block.seatX.map((x, seat) => (
                                                <rect
                                                    key={seat}
                                                    x={x} y={0} width={SEAT} height={SEAT} rx={5}
                                                    fill={block.colour}
                                                    className={classes.seat}
                                                />
                                            ))}
                                        </g>
                                    </g>
                                ))}
                            </g>
                        );
                    })}
                </svg>
            </div>

            <div className={classes.legend}>
                {[...layout.colourByProduct.entries()].map(([productId, colour]) => {
                    const section = sections.find(s => s.product_id === productId);
                    const seats = sections.filter(s => s.product_id === productId).reduce((sum, s) => sum + s.row_count * s.seats_per_row, 0);
                    return (
                        <span key={productId} className={classes.legendItem}>
                            <i style={{background: colour}}/>
                            {section?.product?.title ?? t`Ticket`} · {seats}
                        </span>
                    );
                })}
                <span className={classes.legendTotal}>{t`${totalSeats} seats`}</span>
            </div>
        </div>
    );
};
