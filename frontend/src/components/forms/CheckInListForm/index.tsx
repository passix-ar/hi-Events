import {Alert, Textarea, TextInput} from "@mantine/core";
import {t} from "@lingui/macro";
import {UseFormReturnType} from "@mantine/form";
import {CheckInList, CheckInListRequest, ProductCategory, ProductType} from "../../../types.ts";
import {InputGroup} from "../../common/InputGroup";
import {ProductSelector} from "../../common/ProductSelector";
import {useEffect, useMemo} from "react";
import {IconAlertTriangle, IconInfoCircle} from "@tabler/icons-react";

interface CheckInListFormProps {
    form: UseFormReturnType<CheckInListRequest>;
    productCategories: ProductCategory[];
    existingLists?: CheckInList[];
}

export const CheckInListForm = ({form, productCategories, existingLists}: CheckInListFormProps) => {
    // One door, two lists with the same tickets = the same ticket gets in twice
    // (each list only knows its own check-ins). Flag it before the list exists.
    const selectedIds = (form.values.product_ids ?? []).map(String);
    const overlappingList = existingLists?.find(list =>
        list.products?.some(product => selectedIds.includes(String(product.id)))
    );

    const tickets = useMemo(() => {
        return productCategories
            .flatMap(category => category.products || [])
            .filter(product => product.product_type === ProductType.Ticket);
    }, [productCategories]);

    useEffect(() => {
        if (tickets.length === 1 && (!form.values.product_ids || form.values.product_ids.length === 0)) {
            form.setFieldValue('product_ids', [String(tickets[0].id)]);
        }
    }, [tickets]);

    return (
        <>
            <Alert mb={20} icon={<IconInfoCircle size={16}/>} color="blue" variant="light">
                {t`Check-in lists let you control entry across days, areas, or ticket types. You can share a secure check-in link with staff — no account required.`}
            </Alert>

            <TextInput
                {...form.getInputProps('name')}
                required
                label={t`Name`}
                placeholder={t`VIP check-in list`}
            />

            {overlappingList && (
                <Alert mb={20} icon={<IconAlertTriangle size={16}/>} color="orange" variant="light">
                    {t`"${overlappingList.name}" already covers some of these tickets. If both lists are for the same door, open that one on every phone instead: separate lists do not see each other's check-ins, so the same ticket could get in twice.`}
                </Alert>
            )}

            <ProductSelector
                label={t`Which tickets should be associated with this check-in list?`}
                placeholder={t`Select tickets`}
                productCategories={productCategories}
                form={form}
                productFieldName="product_ids"
                includedProductTypes={[ProductType.Ticket]}
            />

            <Textarea
                {...form.getInputProps('description')}
                label={t`Description for check-in staff`}
                placeholder={t`Add a description for this check-in list`}
                description={t`Visible to check-in staff only. Helps identify this list during check-in.`}
                minRows={2}
            />

            <InputGroup>
                <TextInput
                    {...form.getInputProps('activates_at')}
                    type="datetime-local"
                    label={t`Activation date`}
                    description={t`When check-in opens`}
                />
                <TextInput
                    {...form.getInputProps('expires_at')}
                    type="datetime-local"
                    label={t`Expiration date`}
                    description={t`When check-in closes`}
                />
            </InputGroup>
        </>
    );
}
