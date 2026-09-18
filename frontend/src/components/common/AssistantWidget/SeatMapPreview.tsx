import {useMemo} from "react";
import {t, Trans} from "@lingui/macro";
import {SeatingSection} from "../../../types.ts";
import classes from './SeatMapPreview.module.scss';

interface SeatMapPreviewProps {
    sections: SeatingSection[];
    /** The section that was just created: it drops in row by row. */
    highlightId?: number | null;
}

// One colour per ticket type, in the order sections appear.
const TICKET_COLOURS = ['#d6ff3d', '#4dabf7', '#ff6fb5', '#ffd43b', '#2dd4bf', '#a78bfa', '#ff8c42'];

const SEAT = 10;      // seat box
const GAP = 3;        // between seats
const AISLE = 12;     // extra gap after an aisle position
const ROW_GAP = 4;
const SECTION_GAP = 34;
const STAGE_HEIGHT = 26;
const LABEL_HEIGHT = 18;

/**
 * A tilted, isometric-looking plan of the seat map drawn from the real
 * sections: stage on top, one block per section, aisles as gaps, seats
 * coloured by the ticket that sells them. Pure SVG + CSS; nothing to load.
 */
export const SeatMapPreview = ({sections, highlightId}: SeatMapPreviewProps) => {
    const layout = useMemo(() => {
        const ordered = [...sections].sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
        const colourByProduct = new Map<number, string>();
        ordered.forEach(section => {
            if (!colourByProduct.has(section.product_id)) {
                colourByProduct.set(section.product_id, TICKET_COLOURS[colourByProduct.size % TICKET_COLOURS.length]);
            }
        });

        const blocks = ordered.map(section => {
            const aisles = new Set((section.aisle_positions ?? []).map(Number));
            const seatX: number[] = [];
            let x = 0;
            for (let seat = 1; seat <= section.seats_per_row; seat++) {
                seatX.push(x);
                x += SEAT + GAP + (aisles.has(seat) ? AISLE : 0);
            }
            const width = x - GAP;
            const height = section.row_count * (SEAT + ROW_GAP) - ROW_GAP;
            return {section, seatX, width, height, colour: colourByProduct.get(section.product_id) ?? TICKET_COLOURS[0]};
        });

        const width = Math.max(240, ...blocks.map(b => b.width));
        let y = STAGE_HEIGHT + SECTION_GAP;
        const placed = blocks.map(block => {
            const top = y;
            y += LABEL_HEIGHT + block.height + SECTION_GAP;
            return {...block, top, left: (width - block.width) / 2};
        });

        return {width, height: y, blocks: placed, colourByProduct};
    }, [sections]);

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

                    <g className={classes.stage}>
                        <rect x={layout.width * 0.15} y={0} width={layout.width * 0.7} height={STAGE_HEIGHT} rx={6} fill="url(#assistant-stage)"/>
                        <text x={layout.width / 2} y={STAGE_HEIGHT / 2 + 4} textAnchor="middle" className={classes.stageLabel}>
                            {t`STAGE`}
                        </text>
                    </g>

                    {layout.blocks.map(block => {
                        const isNew = block.section.id === highlightId;
                        return (
                            <g key={block.section.id} transform={`translate(${block.left} ${block.top})`} className={isNew ? classes.sectionNew : classes.section}>
                                <text x={block.width / 2} y={LABEL_HEIGHT - 6} textAnchor="middle" className={classes.sectionLabel}>
                                    {block.section.name} · {block.section.row_count}×{block.section.seats_per_row}
                                </text>
                                <rect
                                    x={-6} y={LABEL_HEIGHT - 4} width={block.width + 12} height={block.height + 10} rx={8}
                                    className={classes.sectionFloor}
                                />
                                {Array.from({length: block.section.row_count}).map((_, row) => (
                                    <g
                                        key={row}
                                        transform={`translate(0 ${LABEL_HEIGHT + row * (SEAT + ROW_GAP)})`}
                                        className={classes.row}
                                        style={isNew ? {animationDelay: `${row * 45}ms`} : undefined}
                                    >
                                        {block.seatX.map((x, seat) => (
                                            <rect
                                                key={seat}
                                                x={x} y={0} width={SEAT} height={SEAT} rx={2.5}
                                                fill={block.colour}
                                                className={classes.seat}
                                            />
                                        ))}
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
