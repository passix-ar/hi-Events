import {t} from "@lingui/macro";
import {TaxAndFee, TaxAndFeeCalculationType} from "../../../types.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import {computePlatformFee} from "../../../utilites/platformFee.ts";
import classes from "./ProductFeeHint.module.scss";

interface ProductFeeHintProps {
    price: number;
    currency: string;
    taxesAndFees?: TaxAndFee[];
    // Platform ("service") fee context, from the account configuration and the event's
    // Platform Fees setting. When provided, the breakdown shows what the buyer pays and what
    // the organizer receives after the platform fee.
    platformFeePercentage?: number;
    platformFeeFixed?: number;
    passPlatformFeeToBuyer?: boolean;
}

// Seller-facing breakdown shown under the price field. Shows the base price, the taxes/fees
// the organizer adds on top, what the buyer ends up paying, and what the organizer receives
// once the platform fee is applied (added on top when passed to the buyer, or deducted from
// the payout when absorbed).
const computeTaxesTotal = (price: number, taxesAndFees: TaxAndFee[]): number =>
    taxesAndFees.reduce((sum, taxOrFee) => {
        const rate = Number(taxOrFee.rate) || 0;
        return sum + (taxOrFee.calculation_type === TaxAndFeeCalculationType.Percentage
            ? price * (rate / 100)
            : rate);
    }, 0);

export const ProductFeeHint = ({
                                   price,
                                   currency,
                                   taxesAndFees = [],
                                   platformFeePercentage = 0,
                                   platformFeeFixed = 0,
                                   passPlatformFeeToBuyer = false,
                               }: ProductFeeHintProps) => {
    if (!(price > 0)) {
        return null;
    }

    const taxesTotal = computeTaxesTotal(price, taxesAndFees);
    // Buyer-facing amount before the platform fee (matches the backend's fee base).
    const preFeeAmount = price + taxesTotal;
    const platformFee = computePlatformFee(preFeeAmount, platformFeePercentage, platformFeeFixed, passPlatformFeeToBuyer);

    const buyerPays = passPlatformFeeToBuyer ? preFeeAmount + platformFee : preFeeAmount;
    const organizerReceives = passPlatformFeeToBuyer ? preFeeAmount : preFeeAmount - platformFee;

    const hasTaxes = taxesTotal > 0;
    const hasPlatformFee = platformFee > 0;

    if (!hasTaxes && !hasPlatformFee) {
        return null;
    }

    // When the platform fee is passed to the buyer the organizer keeps the full amount, so we
    // keep it minimal: just what they receive plus a short explanation. When it's absorbed we
    // show the breakdown so the smaller payout is clear.
    if (passPlatformFeeToBuyer && hasPlatformFee) {
        return (
            <div className={classes.hint}>
                <div className={`${classes.row} ${classes.total}`}>
                    <span className={classes.totalLabel}>{t`You receive`}</span>
                    <span className={classes.totalValue}>{formatCurrency(organizerReceives, currency)}</span>
                </div>
                <div className={classes.note}>
                    {t`The platform fee is added to the price and paid by the buyer, so you receive the full amount.`}
                </div>
            </div>
        );
    }

    return (
        <div className={classes.hint}>
            <div className={classes.row}>
                <span className={classes.label}>{t`Base price`}</span>
                <span className={classes.value}>{formatCurrency(price, currency)}</span>
            </div>

            {hasTaxes && (
                <div className={classes.row}>
                    <span className={classes.label}>{t`Taxes & fees`}</span>
                    <span className={classes.value}>+{formatCurrency(taxesTotal, currency)}</span>
                </div>
            )}

            <div className={`${classes.row} ${classes.total}`}>
                <span className={classes.totalLabel}>{t`Buyer pays`}</span>
                <span className={classes.totalValue}>{formatCurrency(buyerPays, currency)}</span>
            </div>

            {hasPlatformFee && (
                <div className={classes.row}>
                    <span className={classes.totalLabel}>{t`You receive`}</span>
                    <span className={classes.totalValue}>{formatCurrency(organizerReceives, currency)}</span>
                </div>
            )}

            {hasPlatformFee && (
                <div className={classes.note}>
                    {t`Platform fee of ${formatCurrency(platformFee, currency)} deducted from your payout`}
                </div>
            )}
        </div>
    );
};
