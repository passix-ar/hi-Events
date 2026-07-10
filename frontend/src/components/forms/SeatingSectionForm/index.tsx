import {InputGroup} from "../../common/InputGroup";
import {NumberInput, TextInput} from "@mantine/core";
import {t} from "@lingui/macro";
import {UseFormReturnType} from "@mantine/form";
import {ProductCategory, SeatingSectionRequest} from "../../../types.ts";
import {CustomSelect, ItemProps} from "../../common/CustomSelect";
import {IconCheck, IconX} from "@tabler/icons-react";
import {ProductSelector} from "../../common/ProductSelector";
import {SeatingChart} from "../../common/SeatingChart";

interface SeatingSectionFormProps {
    form: UseFormReturnType<SeatingSectionRequest>;
    productsCategories: ProductCategory[];
}

export const SeatingSectionForm = ({form, productsCategories}: SeatingSectionFormProps) => {
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
                <SeatingChart
                    rowCount={rowCount}
                    seatsPerRow={seatsPerRow}
                    showLegend={false}
                />
            )}
        </>
    );
};
