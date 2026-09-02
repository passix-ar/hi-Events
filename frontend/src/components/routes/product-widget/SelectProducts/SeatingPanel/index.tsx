import {t, Trans} from "@lingui/macro";
import {Seat, SeatingSection} from "../../../../../types.ts";
import {SeatingChart, SeatingLegend, SeatingStage} from "../../../../common/SeatingChart";

interface SeatingPanelProps {
    sections: SeatingSection[];
    selectedSeatIdsForProduct: (productId: number) => number[];
    quantityForProduct: (productId: number) => number;
    onToggleSeat: (productId: number, seat: Seat) => void;
}

export const SeatingPanel = ({
                                 sections,
                                 selectedSeatIdsForProduct,
                                 quantityForProduct,
                                 onToggleSeat,
                             }: SeatingPanelProps) => {
    const productIds = [...new Set(sections.map((section) => Number(section.product_id)))];
    const needed = productIds.reduce((total, productId) => total + quantityForProduct(productId), 0);
    const chosen = productIds.reduce((total, productId) => total + selectedSeatIdsForProduct(productId).length, 0);

    // Nothing to choose yet: the map would just be decoration above the total.
    if (needed === 0) {
        return null;
    }

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

            <SeatingStage/>

            {sections.map((section) => {
                const productId = Number(section.product_id);
                const quantity = quantityForProduct(productId);

                return (
                    <div
                        className={'hi-seating-panel-section'}
                        key={section.id}
                        data-locked={quantity === 0 || undefined}
                    >
                        <h4 className={'hi-seating-panel-section-name'}>{section.name}</h4>
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
            })}

            <SeatingLegend/>
        </div>
    );
};
