import {InputGroup} from "../../common/InputGroup";
import {Input, NumberInput, TextInput} from "@mantine/core";
import {t} from "@lingui/macro";
import {UseFormReturnType} from "@mantine/form";
import {ProductCategory, Seat, SeatingSectionRequest} from "../../../types.ts";
import {CustomSelect, ItemProps} from "../../common/CustomSelect";
import {IconCheck, IconX} from "@tabler/icons-react";
import {ProductSelector} from "../../common/ProductSelector";
import {SeatingChart} from "../../common/SeatingChart";
import {rowLabelForIndex} from "../../../utilites/seats.ts";
import {useEffect} from "react";

interface SeatingSectionFormProps {
    form: UseFormReturnType<SeatingSectionRequest>;
    productsCategories: ProductCategory[];
    seats?: Seat[];
}

export const SeatingSectionForm = ({form, productsCategories, seats}: SeatingSectionFormProps) => {
    const statusOptions: ItemProps[] = [
        {
            icon: <IconCheck/>,
            label: t`Active`,
            value: 'ACTIVE',
            description: t`Seats in this section can be selected by ticket buyers`,
        },
        {
            icon: <IconX/>,
            label: t`Inactive`,
            value: 'INACTIVE',
            description: t`Hide this section from ticket buyers`,
        },
    ];

    const ticketCategories = productsCategories.map((category) => ({
        ...category,
        products: category.products?.filter((product) => product.product_type === 'TICKET'),
    }));

    const rowCount = Number(form.values.row_count) || 0;
    const seatsPerRow = Number(form.values.seats_per_row) || 0;
    const disabledSeats = form.values.disabled_seats ?? [];

    useEffect(() => {
        if (rowCount < 1 || seatsPerRow < 1 || disabledSeats.length === 0) {
            return;
        }
        const validLabels = new Set<string>();
        for (let rowIndex = 0; rowIndex < rowCount; rowIndex++) {
            const rowLabel = rowLabelForIndex(rowIndex);
            for (let seatNumber = 1; seatNumber <= seatsPerRow; seatNumber++) {
                validLabels.add(`${rowLabel}${seatNumber}`);
            }
        }
        const pruned = disabledSeats.filter((label) => validLabels.has(label));
        if (pruned.length !== disabledSeats.length) {
            form.setFieldValue('disabled_seats', pruned);
        }
    }, [rowCount, seatsPerRow]);

    const handleToggleBlocked = (label: string) => {
        form.setFieldValue(
            'disabled_seats',
            disabledSeats.includes(label)
                ? disabledSeats.filter((blocked) => blocked !== label)
                : [...disabledSeats, label],
        );
    };

    return (
        <>
            <TextInput
                {...form.getInputProps('name')}
                required
                label={t`Section name`}
                placeholder={t`Balcony`}
            />

            <ProductSelector
                label={t`Which product does this section sell seats for?`}
                placeholder={t`Select a product`}
                productCategories={ticketCategories as ProductCategory[]}
                form={form}
                productFieldName={'product_id'}
                multiSelect={false}
            />

            <InputGroup>
                <NumberInput
                    {...form.getInputProps('row_count')}
                    required
                    min={1}
                    max={100}
                    label={t`Number of rows`}
                    placeholder={'10'}
                />
                <NumberInput
                    {...form.getInputProps('seats_per_row')}
                    required
                    min={1}
                    max={100}
                    label={t`Seats per row`}
                    placeholder={'10'}
                />
            </InputGroup>

            <CustomSelect
                label={t`Status`}
                required
                form={form}
                name={'status'}
                optionList={statusOptions}
            />

            {rowCount > 0 && seatsPerRow > 0 && rowCount * seatsPerRow <= 2000 && (
                <Input.Wrapper
                    label={t`Venue shape`}
                    description={t`Click seats to block them (aisles, pillars, missing seats). Blocked seats are not sold and appear as gaps on the seat map.`}
                >
                    <SeatingChart
                        rowCount={rowCount}
                        seatsPerRow={seatsPerRow}
                        seats={seats}
                        showLegend={false}
                        editMode
                        blockedSeatLabels={disabledSeats}
                        onToggleBlocked={handleToggleBlocked}
                    />
                </Input.Wrapper>
            )}
        </>
    );
};
