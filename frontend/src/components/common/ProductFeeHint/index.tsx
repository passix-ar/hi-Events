import {t} from "@lingui/macro";
import {IdParam, TaxAndFee, TaxAndFeeCalculationType} from "../../../types.ts";
import {useGetPlatformFeePreview} from "../../../queries/useGetPlatformFeePreview.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import classes from "./ProductFeeHint.module.scss";

interface ProductFeeHintProps {
    eventId: IdParam;
    price: number;
    currency: string;
    passToBuyer: boolean;
    taxesAndFees?: TaxAndFee[];
}

// MercadoPago AR Checkout Pro ≈ 6.29% + 21% IVA (immediate accreditation).
// Estimate only, to show an approximate net payout — not used for charging.
export const MP_FEE_PERCENT = 6.29 * 1.21;

// Platform fee rates are price-independent, so one fixed-sample fetch is enough.
export const FEE_RATE_SAMPLE_PRICE = 1000;

export interface FeePreviewRates {
    percentage_fee: number;
    fixed_fee_converted: number;
}

export interface FeeBreakdown {
    taxesTotal: number;
    buyerPays: number;
    platformFee: number;
    mpFee: number;
    youReceive: number;
}

// Single source of truth for the estimated money flow shown in the form. Taxes and
// fees are added on top of the price (the price field is entered net of them) and the
// platform fee is charged on the resulting total — matching the backend, which applies
// the application fee on the order's total_gross. Values are intentionally left
// unrounded so the net⇄price field can invert them exactly.
export const computeFeeBreakdown = (
    price: number,
    fee: FeePreviewRates,
    passToBuyer: boolean,
    taxesAndFees: TaxAndFee[] = [],
): FeeBreakdown => {
    const platformRate = fee.percentage_fee / 100;
    const fixedFee = fee.fixed_fee_converted;
    const mpRate = MP_FEE_PERCENT / 100;

    const taxesTotal = taxesAndFees.reduce((sum, taxOrFee) => {
        const rate = Number(taxOrFee.rate) || 0;
        return sum + (taxOrFee.calculation_type === TaxAndFeeCalculationType.Percentage
            ? price * (rate / 100)
            : rate);
    }, 0);

    const base = price + taxesTotal;
    const platformFee = passToBuyer
        ? (fixedFee + base * platformRate) / (1 - platformRate)
        : fixedFee + base * platformRate;
    const buyerPays = passToBuyer ? base + platformFee : base;
    const mpFee = buyerPays * mpRate;
    const youReceive = buyerPays - platformFee - mpFee;

    return {taxesTotal, buyerPays, platformFee, mpFee, youReceive};
};

export const ProductFeeHint = ({eventId, price, currency, passToBuyer, taxesAndFees = []}: ProductFeeHintProps) => {
    const {data: fee} = useGetPlatformFeePreview(eventId, FEE_RATE_SAMPLE_PRICE);

    if (!fee || !(price > 0)) {
        return null;
    }

    const {taxesTotal, buyerPays, platformFee, mpFee, youReceive} = computeFeeBreakdown(price, fee, passToBuyer, taxesAndFees);
    const hasTaxes = taxesTotal > 0;

    return (
        <div className={classes.hint}>
            {hasTaxes && (
                <>
                    <div className={classes.row}>
                        <span className={classes.label}>{t`Base price`}</span>
                        <span className={classes.value}>{formatCurrency(price, currency)}</span>
                    </div>
                    <div className={classes.row}>
                        <span className={classes.label}>{t`Taxes & fees`}</span>
                        <span className={classes.value}>+{formatCurrency(taxesTotal, currency)}</span>
                    </div>
                </>
            )}
            <div className={`${classes.row} ${hasTaxes ? classes.subtotal : ''}`}>
                <span className={classes.label}>{t`Buyer pays`}</span>
                <span className={classes.value}>{formatCurrency(buyerPays, currency)}</span>
            </div>
            <div className={classes.row}>
                <span className={classes.label}>{t`Passix platform`}</span>
                <span className={classes.deduction}>−{formatCurrency(platformFee, currency)}</span>
            </div>
            <div className={classes.row}>
                <span className={classes.label}>{t`MercadoPago approx.`}</span>
                <span className={classes.deduction}>−{formatCurrency(mpFee, currency)}</span>
            </div>
            <div className={`${classes.row} ${classes.total}`}>
                <span className={classes.totalLabel}>{t`You receive`}</span>
                <span className={classes.totalValue}>{formatCurrency(youReceive, currency)}</span>
            </div>
        </div>
    );
};
