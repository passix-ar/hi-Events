import {t, Trans} from "@lingui/macro";
import {Button, Modal} from "@mantine/core";
import {useMediaQuery} from "@mantine/hooks";
import {Seat, SeatingSection} from "../../../../../types.ts";
import {SeatingChart, SeatingLegend} from "../../../../common/SeatingChart";

interface SeatPickerModalProps {
    opened: boolean;
    onClose: () => void;
    productTitle: string;
    sections: SeatingSection[];
    selectedSeatIds: number[];
    maxSelectable: number;
    onChange: (seatIds: number[]) => void;
}

export const SeatPickerModal = ({
                                    opened,
                                    onClose,
                                    productTitle,
                                    sections,
                                    selectedSeatIds,
                                    maxSelectable,
                                    onChange,
                                }: SeatPickerModalProps) => {
    const isMobile = useMediaQuery('(max-width: 767px)');

    const handleToggleSeat = (seat: Seat) => {
        if (selectedSeatIds.includes(seat.id)) {
            onChange(selectedSeatIds.filter((id) => id !== seat.id));
            return;
        }
        if (selectedSeatIds.length >= maxSelectable) {
            return;
        }
        onChange([...selectedSeatIds, seat.id]);
    };

    return (
        <Modal
            opened={opened}
            onClose={onClose}
            title={<b>{t`Choose your seats`} — {productTitle}</b>}
            size={'auto'}
            fullScreen={isMobile}
            centered
        >
            <div style={{marginBottom: 12, fontSize: '0.9rem'}}>
                <Trans>{selectedSeatIds.length} of {maxSelectable} seats selected</Trans>
            </div>

            {sections.map((section) => (
                <div key={section.id} style={{marginBottom: 20}}>
                    <h4 style={{margin: '0 0 8px'}}>{section.name}</h4>
                    <SeatingChart
                        rowCount={section.row_count}
                        seatsPerRow={section.seats_per_row}
                        seats={section.seats}
                        selectedSeatIds={selectedSeatIds}
                        maxSelectable={maxSelectable}
                        onToggleSeat={handleToggleSeat}
                        showLegend={false}
                    />
                </div>
            ))}

            <SeatingLegend/>

            <Button
                fullWidth
                mt={'md'}
                disabled={selectedSeatIds.length !== maxSelectable}
                onClick={onClose}
            >
                {selectedSeatIds.length === maxSelectable
                    ? t`Confirm seats`
                    : t`Select ${maxSelectable - selectedSeatIds.length} more seat(s)`}
            </Button>
        </Modal>
    );
};
