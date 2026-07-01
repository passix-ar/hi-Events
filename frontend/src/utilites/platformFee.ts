/**
 * Platform ("service") fee math, shared between the Platform Fees settings preview and the
 * product form breakdown so both always agree.
 *
 * - Pass to buyer: the fee is grossed up so that, after it is added on top, the organizer
 *   still receives the full amount. P = (fixed + amount * r) / (1 - r).
 * - Absorb: the fee is charged directly on the amount (fixed + amount * r) and deducted from
 *   the organizer's payout.
 *
 * `amount` is the buyer-facing price before the platform fee (base price + taxes/fees the
 * organizer added), matching how the backend computes it.
 */
export const computePlatformFee = (
    amount: number,
    feePercentage: number,
    fixedFee: number,
    passToBuyer: boolean,
): number => {
    if (!(amount > 0)) {
        return 0;
    }

    const rate = (Number(feePercentage) || 0) / 100;
    const fixed = Number(fixedFee) || 0;

    if (rate <= 0 && fixed <= 0) {
        return 0;
    }

    const fee = passToBuyer && rate < 1
        ? (fixed + amount * rate) / (1 - rate)
        : fixed + amount * rate;

    return Math.round(fee * 100) / 100;
};
