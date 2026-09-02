import {t, Trans} from "@lingui/macro";
import {useMediaQuery} from "@mantine/hooks";
import {Seat, SeatingPlan, SeatingSection} from "../../../../../types.ts";
import {SeatingChart, SeatingLegend, SeatingStage} from "../../../../common/SeatingChart";

interface SeatingPanelProps {
    plan: SeatingPlan;
    sections: SeatingSection[];
    selectedSeatIdsForProduct: (productId: number) => number[];
    quantityForProduct: (productId: number) => number;
    onToggleSeat: (productId: number, seat: Seat) => void;
}

type Piece = { key: string, x: number, y: number, section?: SeatingSection };

export const SeatingPanel = ({
                                 plan,
                                 sections,
                                 selectedSeatIdsForProduct,
                                 quantityForProduct,
                                 onToggleSeat,
                             }: SeatingPanelProps) => {
    // A free canvas is unusable on a phone, so there the same coordinates are read as an
    // order instead — top to bottom, then left to right. One source of truth, two readings.
    const isNarrow = useMediaQuery('(max-width: 767px)');

    const productIds = [...new Set(sections.map((section) => Number(section.product_id)))];
    const needed = productIds.reduce((total, id) => total + quantityForProduct(id), 0);
    const chosen = productIds.reduce((total, id) => total + selectedSeatIdsForProduct(id).length, 0);

    if (needed === 0) {
        return null;
    }

    const pieces: Piece[] = [
        {key: 'stage', x: plan.stage_x, y: plan.stage_y},
        ...sections.map((section) => ({
            key: String(section.id),
            x: section.position_x ?? 0,
            y: section.position_y ?? 0,
            section,
        })),
    ];

    // Coordinates are stored around an origin and can be negative, so the whole plan is
    // shifted into view rather than clipped at the edges of the canvas.
    const offsetX = Math.min(...pieces.map((piece) => piece.x));
    const offsetY = Math.min(...pieces.map((piece) => piece.y));

    const renderSection = (section: SeatingSection) => {
        const productId = Number(section.product_id);
        const quantity = quantityForProduct(productId);

        return (
            <div className={'hi-seating-piece'} data-locked={quantity === 0 || undefined}>
                <h4 className={'hi-seating-piece-name'}>{section.name}</h4>
                <SeatingChart
                    rowCount={section.row_count}
                    seatsPerRow={section.seats_per_row}
                    aislePositions={section.aisle_positions}
                    seats={section.seats}
                    selectedSeatIds={selectedSeatIdsForProduct(productId)}
                    maxSelectable={quantity}
                    onToggleSeat={(seat) => onToggleSeat(productId, seat)}
                    showLegend={false}
                />
            </div>
        );
    };

    const ordered = [...pieces].sort((a, b) => a.y - b.y || a.x - b.x);

    return (
        <div className={'hi-seating-panel'}>
            <div className={'hi-seating-panel-header'}>
                <h3 className={'hi-seating-panel-title'}>{t`Choose your seats`}</h3>
                <span className={'hi-seating-panel-count'}>
                    {chosen === needed
                        ? t`All seats selected`
                        : <Trans>{chosen} of {needed} seats selected</Trans>}
                </span>
            </div>

            {isNarrow ? (
                <div className={'hi-seating-stack'}>
                    {ordered.map((piece) => (
                        <div key={piece.key}>
                            {piece.section ? renderSection(piece.section) : <SeatingStage/>}
                        </div>
                    ))}
                </div>
            ) : (
                <div className={'hi-seating-canvas'}>
                    {pieces.map((piece) => (
                        <div
                            key={piece.key}
                            className={'hi-seating-placed'}
                            style={{left: piece.x - offsetX, top: piece.y - offsetY}}
                        >
                            {piece.section ? renderSection(piece.section) : <SeatingStage/>}
                        </div>
                    ))}
                </div>
            )}

            <SeatingLegend/>
        </div>
    );
};
