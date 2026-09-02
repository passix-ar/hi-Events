import {ReactNode} from "react";
import {useMediaQuery} from "@mantine/hooks";
import {Seat, SeatingSection} from "../../../types.ts";
import {SeatingChart, SeatingStage} from "../SeatingChart";
import {buildPlan} from "../../../utilites/seatingPlan.ts";
import classes from './SeatingRoom.module.scss';

interface SeatingRoomProps {
    sections: SeatingSection[];
    stage: { x: number, y: number, visible?: boolean };
    selectedSeatIds?: (section: SeatingSection) => number[];
    maxSelectable?: (section: SeatingSection) => number;
    onToggleSeat?: (section: SeatingSection, seat: Seat) => void;
    /** Wraps each piece where the organizer needs to grab it. */
    wrapPiece?: (key: string, node: ReactNode) => ReactNode;
    /** Drawn over a section, for the organizer's controls. */
    sectionOverlay?: (section: SeatingSection) => ReactNode;
}

export const SeatingRoom = ({
                                sections,
                                stage,
                                selectedSeatIds,
                                maxSelectable,
                                onToggleSeat,
                                wrapPiece,
                                sectionOverlay,
                            }: SeatingRoomProps) => {
    // A plan is drawn where it was placed on a wide screen. On a phone the same coordinates
    // are read as an order instead, top to bottom then left to right: dragging and pinching
    // a plan on a small screen is worse than a list, and the widget lives in an iframe.
    const isNarrow = useMediaQuery('(max-width: 767px)');
    const {pieces, width, height} = buildPlan(sections, stage);

    const renderPiece = (section?: SeatingSection) => {
        if (!section) {
            return <SeatingStage/>;
        }

        const locked = maxSelectable ? maxSelectable(section) === 0 : false;

        return (
            <div className={classes.section} data-locked={locked || undefined}>
                <div className={classes.name}>{section.name}</div>
                <SeatingChart
                    rowCount={section.row_count}
                    seatsPerRow={section.seats_per_row}
                    aislePositions={section.aisle_positions}
                    seats={section.seats}
                    selectedSeatIds={selectedSeatIds?.(section) ?? []}
                    maxSelectable={maxSelectable?.(section) ?? 0}
                    onToggleSeat={onToggleSeat ? (seat) => onToggleSeat(section, seat) : undefined}
                    showLegend={false}
                />
                {sectionOverlay?.(section)}
            </div>
        );
    };

    if (isNarrow) {
        return (
            <div className={classes.stack}>
                {[...pieces].sort((a, b) => a.y - b.y || a.x - b.x).map((piece) => (
                    <div key={piece.key}>{renderPiece(piece.section)}</div>
                ))}
            </div>
        );
    }

    return (
        <div className={classes.scroller}>
            <div className={classes.plan} style={{width, height}}>
                {pieces.map((piece) => {
                    const node = (
                        <div className={classes.piece} style={{left: piece.x, top: piece.y}}>
                            {renderPiece(piece.section)}
                        </div>
                    );

                    return <div key={piece.key}>{wrapPiece ? wrapPiece(piece.key, node) : node}</div>;
                })}
            </div>
        </div>
    );
};
