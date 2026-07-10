import {t} from "@lingui/macro";
import {Seat} from "../../../types.ts";
import {rowLabelForIndex} from "../../../utilites/seats.ts";
import classes from "./SeatingChart.module.scss";

interface SeatingChartProps {
    rowCount: number;
    seatsPerRow: number;
    seats?: Seat[];
    selectedSeatIds?: number[];
    maxSelectable?: number;
    onToggleSeat?: (seat: Seat) => void;
    showLegend?: boolean;
}

export const SeatingChart = ({
                                 rowCount,
                                 seatsPerRow,
                                 seats,
                                 selectedSeatIds = [],
                                 maxSelectable = 0,
                                 onToggleSeat,
                                 showLegend = true,
                             }: SeatingChartProps) => {
    const seatsByPosition = new Map<string, Seat>();
    seats?.forEach((seat) => seatsByPosition.set(`${seat.row_label}|${seat.seat_number}`, seat));

    const isInteractive = !!onToggleSeat;

    const getSeatState = (seat?: Seat): string => {
        if (!seat) {
            return 'PREVIEW';
        }
        if (selectedSeatIds.includes(seat.id)) {
            return 'SELECTED';
        }
        return seat.state;
    };

    const handleSeatClick = (seat?: Seat) => {
        if (!seat || !onToggleSeat) {
            return;
        }
        const isSelected = selectedSeatIds.includes(seat.id);
        if (!isSelected && (seat.state !== 'AVAILABLE' || selectedSeatIds.length >= maxSelectable)) {
            return;
        }
        onToggleSeat(seat);
    };

    return (
        <div className={classes.seatingChart}>
            <div className={classes.scrollWrapper}>
                <div className={classes.grid}>
                    {Array.from({length: rowCount}, (_, rowIndex) => {
                        const rowLabel = rowLabelForIndex(rowIndex);
                        return (
                            <div className={classes.row} key={rowLabel}>
                                <div className={classes.rowLabel}>{rowLabel}</div>
                                {Array.from({length: seatsPerRow}, (_, seatIndex) => {
                                    const seatNumber = seatIndex + 1;
                                    const seat = seatsByPosition.get(`${rowLabel}|${seatNumber}`);
                                    const state = getSeatState(seat);
                                    const isClickable = isInteractive && seat
                                        && (state === 'SELECTED' || (state === 'AVAILABLE' && selectedSeatIds.length < maxSelectable));

                                    return (
                                        <button
                                            key={seatNumber}
                                            type={'button'}
                                            className={classes.seat}
                                            data-state={state}
                                            data-clickable={isClickable || undefined}
                                            disabled={isInteractive && !isClickable}
                                            onClick={() => handleSeatClick(seat)}
                                            title={seat ? seat.label : `${rowLabel}${seatNumber}`}
                                            aria-label={seat ? seat.label : `${rowLabel}${seatNumber}`}
                                        >
                                            {seatNumber}
                                        </button>
                                    );
                                })}
                                <div className={classes.rowLabel}>{rowLabel}</div>
                            </div>
                        );
                    })}
                </div>
            </div>
            {showLegend && <SeatingLegend/>}
        </div>
    );
};

export const SeatingLegend = () => (
    <div className={classes.legend}>
        <span><i className={classes.swatch} data-state={'AVAILABLE'}/>{t`Available`}</span>
        <span><i className={classes.swatch} data-state={'SELECTED'}/>{t`Selected`}</span>
        <span><i className={classes.swatch} data-state={'HELD'}/>{t`Held`}</span>
        <span><i className={classes.swatch} data-state={'SOLD'}/>{t`Sold`}</span>
    </div>
);
