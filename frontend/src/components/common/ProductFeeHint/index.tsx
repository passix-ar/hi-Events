import {t} from "@lingui/macro";
import {Text} from "@mantine/core";
import {IdParam} from "../../../types.ts";
import {useGetPlatformFeePreview} from "../../../queries/useGetPlatformFeePreview.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import classes from "./ProductFeeHint.module.scss";

interface ProductFeeHintProps {
    eventId: IdParam;
    price: number;
    currency: string;
    passToBuyer: boolean;
}

// MercadoPago AR Checkout Pro ≈ 6.29% + 21% IVA (immediate accreditation).
// Estimate only, to show an approximate net payout — not used for charging.
const MP_FEE_PERCENT = 6.29 * 1.21;

// Platform fee rates are price-independent, so one fixed-sample fetch is enough.
const FEE_RATE_SAMPLE_PRICE = 1000;

export const ProductFeeHint = ({eventId, price, currency, passToBuyer}: ProductFeeHintProps) => {
    const {data: fee} = useGetPlatformFeePreview(eventId, FEE_RATE_SAMPLE_PRICE);

    if (!fee || !(price > 0)) {
        return null;
    }

    const platformRate = fee.percentage_fee / 100;
    const platformFee = Math.round((passToBuyer
        ? (fee.fixed_fee_converted + price * platformRate) / (1 - platformRate)
        : fee.fixed_fee_converted + price * platformRate) * 100) / 100;

    const buyerPays = Math.round((passToBuyer ? price + platformFee : price) * 100) / 100;
    const mpFee = Math.round(buyerPays * (MP_FEE_PERCENT / 100) * 100) / 100;
    const youReceive = Math.round((buyerPays - platformFee - mpFee) * 100) / 100;
    const totalFees = Math.round((platformFee + mpFee) * 100) / 100;

    return (
        <div className={classes.hint}>
            {passToBuyer && (
                <div className={classes.row}>
                    <span>{t`Buyer pays`}</span>
                    <span className={classes.value}>{formatCurrency(buyerPays, currency)}</span>
                </div>
            )}
            <div className={classes.row}>
                <span>{t`You receive approximately`}</span>
                <span className={classes.valueStrong}>{formatCurrency(youReceive, currency)}</span>
            </div>
            <div className={classes.row}>
                <span>{t`Fees & charges`}</span>
                <span className={classes.value}>{formatCurrency(totalFees, currency)}</span>
            </div>
            <Text size="xs" c="dimmed" mt={4}>
                {t`Platform ${formatCurrency(platformFee, currency)} · MercadoPago approx. ${formatCurrency(mpFee, currency)}`}
            </Text>
        </div>
    );
};
