import React from 'react';
import {formatCurrency} from "../../../utilites/currency.ts";
import {Product, ProductPrice} from "../../../types.ts";
import {t} from "@lingui/macro";
import {Popover} from "@mantine/core";
import {IconInfoCircle} from "@tabler/icons-react";

interface CurrencyProps {
    price?: number | null | undefined;
    currency?: string;
    strikeThrough?: boolean;
    className?: string;
    freeLabel?: string | null;
}

export const Currency: React.FC<CurrencyProps> = ({
                                                      price,
                                                      currency = 'USD',
                                                      strikeThrough,
                                                      className,
                                                      freeLabel,
                                                  }) => {
    if (!price) {
        return freeLabel ? freeLabel : formatCurrency(0, currency);
    }

    let formattedPrice = <>{formatCurrency(price, currency)}</>;

    if (strikeThrough) {
        formattedPrice = <s>{formattedPrice}</s>;
    }

    return (
        <span className={className}>
            {formattedPrice}
        </span>
    );
};

interface ProductPriceProps {
    product: Product;
    price: ProductPrice;
    currency?: string;
    className?: string;
    freeLabel?: string | null;
    taxAndServiceFeeDisplayType?: 'INCLUSIVE' | 'EXCLUSIVE';
}

export const ProductPriceDisplay: React.FC<ProductPriceProps> = ({
                                                                   price,
                                                                   currency = 'USD',
                                                                   className,
                                                                   freeLabel,
                                                                   taxAndServiceFeeDisplayType = 'EXCLUSIVE',
                                                               }) => {
    const basePrice = price.price;
    const taxTotal = price.tax_total || 0;
    const platformFeeTotal = price.platform_fee_total || 0;
    const otherFeeTotal = Math.max((price.fee_total || 0) - platformFeeTotal, 0);
    const totalTaxAndFees = taxTotal + (price.fee_total || 0);
    const nonPlatformTaxAndFees = taxTotal + otherFeeTotal;

    const isInclusive = taxAndServiceFeeDisplayType === 'INCLUSIVE';
    const separatesServiceFee = isInclusive && platformFeeTotal > 0;
    const allInPrice = price.price_including_taxes_and_fees ?? (basePrice + totalTaxAndFees);
    const priceBeforeServiceFee = separatesServiceFee
        ? Math.max(allInPrice - platformFeeTotal, 0)
        : allInPrice;
    // When the buyer pays the platform service fee, show it separately in the order summary
    // instead of folding it into the ticket price.
    const displayPrice = isInclusive ? priceBeforeServiceFee : basePrice;
    const breakdownTotal = separatesServiceFee ? priceBeforeServiceFee : allInPrice;
    const noteTaxAndFees = separatesServiceFee ? nonPlatformTaxAndFees : totalTaxAndFees;

    if (basePrice === 0 && totalTaxAndFees === 0) {
        return <span className={className}>{freeLabel || t`Free`}</span>;
    }

    const breakdownRow = (label: string, value: number, strong = false) => (
        <div style={{display: 'flex', justifyContent: 'space-between', gap: 16, padding: '2px 0',
            fontWeight: strong ? 700 : 400}}>
            <span>{label}</span>
            <span style={{whiteSpace: 'nowrap'}}>{formatCurrency(value, currency)}</span>
        </div>
    );

    const noteLabel = isInclusive ? t`Taxes & fees included` : t`+ taxes & fees`;

    const note = noteTaxAndFees === 0 ? null : (
        <Popover width={260} withArrow position="bottom-start" shadow="md">
            <Popover.Target>
                <span style={{display: 'inline-flex', alignItems: 'center', gap: 4, cursor: 'pointer',
                    fontSize: 12, opacity: 0.75}}>
                    {noteLabel}
                    <IconInfoCircle size={14}/>
                </span>
            </Popover.Target>
            <Popover.Dropdown>
                <div style={{fontSize: 13, minWidth: 200}}>
                    {breakdownRow(t`Base price`, basePrice)}
                    {taxTotal > 0 && breakdownRow(t`Taxes`, taxTotal)}
                    {platformFeeTotal > 0 && !separatesServiceFee && breakdownRow(t`Service fee`, platformFeeTotal)}
                    {otherFeeTotal > 0 && breakdownRow(t`Fees`, otherFeeTotal)}
                    <div style={{borderTop: '1px solid var(--mantine-color-default-border)', marginTop: 6, paddingTop: 6}}>
                        {breakdownRow(t`Total`, breakdownTotal, true)}
                    </div>
                </div>
            </Popover.Dropdown>
        </Popover>
    );

    return (
        <div className={className}>
            <div>{formatCurrency(displayPrice, currency)}</div>
            {note && <div>{note}</div>}
        </div>
    );
};
