import {t, Trans} from "@lingui/macro";
import {Seat, SeatingPlan, SeatingSection} from "../../../../../types.ts";
import {SeatingLegend} from "../../../../common/SeatingChart";
import {SeatingRoom} from "../../../../common/SeatingRoom";

interface SeatingPanelProps {
    plan: SeatingPlan;
    sections: SeatingSection[];
    selectedSeatIdsForProduct: (productId: number) => number[];
    quantityForProduct: (productId: number) => number;
    onToggleSeat: (productId: number, seat: Seat) => void;
}

export const SeatingPanel = ({
                                 plan,
                                 sections,
                                 selectedSeatIdsForProduct,
                                 quantityForProduct,
                                 onToggleSeat,
                             }: SeatingPanelProps) => {
    const productIds = [...new Set(sections.map((section) => Number(section.product_id)))];
    const needed = productIds.reduce((total, id) => total + quantityForProduct(id), 0);
    const chosen = productIds.reduce((total, id) => total + selectedSeatIdsForProduct(id).length, 0);

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

            <SeatingRoom
                sections={sections}
                stage={{x: plan.stage_x, y: plan.stage_y}}
                selectedSeatIds={(section) => selectedSeatIdsForProduct(Number(section.product_id))}
                maxSelectable={(section) => quantityForProduct(Number(section.product_id))}
                onToggleSeat={(section, seat) => onToggleSeat(Number(section.product_id), seat)}
            />

            <SeatingLegend/>
        </div>
    );
};
