import {Button, Loader} from "@mantine/core";
import {IconQrcode, IconTicket} from "@tabler/icons-react";
import {t, Trans} from "@lingui/macro";
import {Attendee} from "../../../types.ts";
import classes from "../../layouts/CheckIn/CheckIn.module.scss";

interface AttendeeListProps {
    attendees: Attendee[] | undefined;
    products: { id: number; title: string; }[] | undefined;
    isLoading: boolean;
    // The one attendee whose check-out is in flight, if any. Everyone else stays usable: a door
    // does not stop while one person is being undone.
    checkingOutPublicId: string | null;
    allowOrdersAwaitingOfflinePaymentToCheckIn: boolean;
    // Whether the door is actually looking for someone. With an empty search box no rows are
    // rendered at all — see the comment on the idle state below.
    isSearching: boolean;
    checkedInCount: number;
    totalCount: number;
    onCheckInToggle: (attendee: Attendee) => void;
    onClickSound?: () => void;
}

export const AttendeeList = ({
                                 attendees,
                                 products,
                                 isLoading,
                                 checkingOutPublicId,
                                 allowOrdersAwaitingOfflinePaymentToCheckIn,
                                 isSearching,
                                 checkedInCount,
                                 totalCount,
                                 onCheckInToggle,
                                 onClickSound
                             }: AttendeeListProps) => {
    const checkInButtonText = (attendee: Attendee) => {
        if (!allowOrdersAwaitingOfflinePaymentToCheckIn && attendee.status === 'AWAITING_PAYMENT') {
            return t`Cannot Check In`;
        }

        if (attendee.status === 'CANCELLED') {
            return t`Cannot Check In (Cancelled)`;
        }

        if (attendee.check_in) {
            return t`Check Out`;
        }

        return t`Check In`;
    };

    const getButtonColor = (attendee: Attendee) => {
        if (attendee.check_in || attendee.status === 'CANCELLED') {
            return 'red';
        }
        if (attendee.status === 'AWAITING_PAYMENT' && !allowOrdersAwaitingOfflinePaymentToCheckIn) {
            return 'gray';
        }
        return 'teal';
    };

    if (isLoading || !attendees || !products) {
        return (
            <div className={classes.loading}>
                <Loader size={40}/>
            </div>
        );
    }

    // Nobody finds a person by scrolling a list of two thousand: at a door you scan, and you type a
    // name only when the code will not read. So with an empty search box the list renders nothing —
    // roughly a dozen DOM nodes per attendee that React would otherwise reconcile on every roster
    // refresh, every scan and every check-out, for rows no one was going to look at.
    if (!isSearching) {
        return (
            <div className={classes.idle}>
                <IconQrcode size={48} stroke={1.2}/>
                <p className={classes.idleHint}>
                    {t`Scan a ticket, or search by name to find someone`}
                </p>
                <p className={classes.idleProgress}>
                    <Trans>{checkedInCount} of {totalCount} checked in</Trans>
                </p>
            </div>
        );
    }

    if (attendees.length === 0) {
        return (
            <div className={classes.noResults}>
                {t`No attendees to show.`}
            </div>
        );
    }

    return (
        <div className={classes.attendees}>
            {attendees.map(attendee => {
                const isAttendeeAwaitingPayment = attendee.status === 'AWAITING_PAYMENT';
                const isCheckingOut = checkingOutPublicId === attendee.public_id;

                return (
                    <div className={classes.attendee} key={attendee.public_id}>
                        <div className={classes.details}>
                            <div className={classes.name}>
                                {attendee.first_name} {attendee.last_name}
                            </div>
                            {attendee.status === 'CANCELLED' ? (
                                <div className={classes.cancelled}>
                                    {t`Ticket Cancelled`}
                                </div>
                            ) : null}
                            {isAttendeeAwaitingPayment && (
                                <div className={classes.awaitingPayment}>
                                    {t`Awaiting payment`}
                                </div>
                            )}
                            <div className={classes.publicId}>
                                {attendee.public_id}
                            </div>
                            <div className={classes.product}>
                                <IconTicket
                                    size={15}/> {products.find(product => product.id === attendee.product_id)?.title}
                                {attendee.seat_label && <> · {attendee.seat_label}</>}
                            </div>
                        </div>
                        <div className={classes.actions}>
                            <Button
                                onClick={() => {
                                    onClickSound?.();
                                    onCheckInToggle(attendee);
                                }}
                                disabled={isCheckingOut || attendee.status === 'CANCELLED'}
                                loading={isCheckingOut}
                                color={getButtonColor(attendee)}
                                radius="md"
                            >
                                {checkInButtonText(attendee)}
                            </Button>
                        </div>
                    </div>
                );
            })}
        </div>
    );
};
