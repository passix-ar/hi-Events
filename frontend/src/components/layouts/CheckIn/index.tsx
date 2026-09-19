import {useParams} from "react-router";
import {useGetCheckInListPublic} from "../../../queries/useGetCheckInListPublic.ts";
import {ReactNode, useCallback, useEffect, useMemo, useRef, useState} from "react";
import {useDisclosure, useNetwork} from "@mantine/hooks";
import {Attendee} from "../../../types.ts";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import {t, Trans} from "@lingui/macro";
import {AxiosError} from "axios";
import classes from "./CheckIn.module.scss";
import {ActionIcon, Modal} from "@mantine/core";
import {SearchBar} from "../../common/SearchBar";
import {IconInfoCircle, IconQrcode, IconVolume, IconVolumeOff} from "@tabler/icons-react";
import {QRScannerComponent} from "../../common/CheckIn/QrScanner/QrScanner.tsx";
import {isPendingCheckIn, useCheckInRoster} from "../../../hooks/useCheckInRoster.ts";
import {useScanSounds} from "../../../hooks/useScanSounds.tsx";
import {useDeleteCheckInPublic} from "../../../mutations/useDeleteCheckInPublic.ts";
import {NoResultsSplash} from "../../common/NoResultsSplash";
import {Countdown} from "../../common/Countdown";
import Truncate from "../../common/Truncate";
import {Header} from "../../common/Header";
import {publicCheckInClient} from "../../../api/check-in.client.ts";
import {SyncStatus} from "../../common/CheckIn/SyncStatus";
import {AttendeeList} from "../../common/CheckIn/AttendeeList";
import {CheckInOptionsModal} from "../../common/CheckIn/CheckInOptionsModal";
import {ScannerSelectionModal} from "../../common/CheckIn/ScannerSelectionModal";
import {CheckInInfoModal} from "../../common/CheckIn/CheckInInfoModal";
import {HidScannerStatus} from "../../common/CheckIn/HidScannerStatus";
import {Button} from "@mantine/core";
import {isScannableBarcode} from "./barcode.ts";

// Past a handful, individual refusals stop being readable at the door and become
// a wall of toasts: the rest are summarised and the list is where to look.
const MAX_REFUSAL_TOASTS = 3;

// Shorter than the roster's timeout because someone is standing at the door waiting on this one.
// It also has to end no matter what: the reentrancy guard is already held when this request goes
// out, so a request that never settles leaves the scanner refusing every scan until a reload.
const ATTENDEE_LOOKUP_TIMEOUT_MS = 5_000;

const CheckIn = () => {
    const networkStatus = useNetwork();
    const {checkInListShortId} = useParams();
    const CheckInListQuery = useGetCheckInListPublic(checkInListShortId);
    const checkInList = CheckInListQuery?.data?.data;
    const event = checkInList?.event;
    const eventSettings = event?.settings;
    const [searchQuery, setSearchQuery] = useState('');
    const [qrScannerOpen, setQrScannerOpen] = useState(false);
    const [scannerSelectionOpen, setScannerSelectionOpen] = useState(false);
    const [hidScannerMode, setHidScannerMode] = useState(false);
    const [pageHasFocus, setPageHasFocus] = useState(true);
    const barcodeTimeoutRef = useRef<NodeJS.Timeout | null>(null);
    const isProcessingRef = useRef(false);
    // The keys the USB reader has typed so far. A ref rather than state: it changes on every
    // character and nothing renders it, and as state it rebuilt the listener on each keystroke.
    const currentBarcodeRef = useRef('');
    // Every sound the door makes comes from here — see useScanSounds for why that matters.
    const {isSoundOn, setIsSoundOn, playSuccess, playError, playInProgress, audioElements} = useScanSounds();
    const [selectedAttendee, setSelectedAttendee] = useState<Attendee | null>(null);
    const [checkInModalOpen, checkInModalHandlers] = useDisclosure(false);
    const [infoModalOpen, infoModalHandlers] = useDisclosure(false, {
            onOpen: () => {
                CheckInListQuery.refetch();
            }
        }
    );

    const products = checkInList?.products;
    // Memoised because the hook keeps queueCheckIn stable on it: a fresh array every render would
    // rebuild the scan handler on every render too.
    const allowedProductIds = useMemo(() => products?.map(product => product.id), [products]);
    const roster = useCheckInRoster(
        checkInListShortId,
        Boolean(checkInList?.is_active && !checkInList?.is_expired),
        allowedProductIds,
        Boolean(checkInList?.is_expired),
    );
    // Who is being checked out, not whether someone is: as a single flag it put every button in the
    // list into a loading state, so undoing one check-in froze the whole door. Derived from the
    // mutation rather than mirrored in its own state, so there is one source of truth.
    const checkOutMutation = useDeleteCheckInPublic();
    const checkingOutPublicId = checkOutMutation.isPending
        ? checkOutMutation.variables?.attendeePublicId ?? null
        : null;

    // The list is searched in memory: no request per keystroke, and it works
    // with the connection down.
    // Memoised on the roster and the query: without it the whole list is filtered again on every
    // render, and the list is the length of the event.
    //
    // Email is not searchable here and the placeholder no longer offers it: the public check-in
    // resource deliberately withholds it (see AttendeeWithCheckInPublicResource, and the test that
    // pins it), so the field never arrives and the filter silently matched nothing.
    const normalizedSearch = searchQuery.trim().toLowerCase();
    const attendees = useMemo(() => normalizedSearch === ''
        ? roster.attendees
        : roster.attendees.filter(a =>
            `${a.first_name} ${a.last_name}`.toLowerCase().includes(normalizedSearch)
            || a.public_id.toLowerCase().includes(normalizedSearch)
            || String(a.order_id) === normalizedSearch
        ), [roster.attendees, normalizedSearch]);

    // Counted off the roster in memory rather than off the check-in list's own totals: this way it
    // includes the check-ins that are still queued, so the number on screen matches what the door
    // has actually let through.
    const checkedInCount = useMemo(
        () => roster.attendees.filter(attendee => attendee.check_in).length,
        [roster.attendees],
    );

    // A check-in the server refused (cancelled ticket, unpaid order) is reported
    // when its background sync comes back, not at scan time. Each one is a person
    // who did not get through, so none is dropped silently.
    useEffect(() => {
        if (roster.rejected.length === 0) return;

        roster.rejected
            .slice(0, MAX_REFUSAL_TOASTS)
            .forEach(({attendee, message}) => showError(attendee ? scanFeedback(attendee, message) : message));

        const remaining = roster.rejected.length - MAX_REFUSAL_TOASTS;
        if (remaining > 0) {
            showError(t`And ${remaining} more check-in(s) refused — check the list`);
        }

        playError();
        roster.clearRejected();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [roster.rejected]);
    const areOfflinePaymentsEnabled = eventSettings?.payment_providers?.includes('OFFLINE');
    const allowOrdersAwaitingOfflinePaymentToCheckIn = areOfflinePaymentsEnabled
        && eventSettings?.allow_orders_awaiting_offline_payment_to_check_in;

    // Retrying the roster alone does nothing in the case where the warning matters most: opening the
    // scanner with no signal means the check-in list never loaded, and without it the roster refresh
    // is disabled and returns immediately. Both have to be retried.
    const retrySync = () => {
        CheckInListQuery.refetch();
        roster.refresh();
    };

    // What the door needs to read at a glance: the ticket type, and the seat when the event has one.
    // An event without assigned seating has no seat_label, so this degrades to the title alone.
    const ticketInfoFor = useCallback((attendee: Attendee) => {
        // This list's own products first, so the usual scan stays local. A ticket from another list
        // of the event is not in there at all, and for those the title travels with the attendee.
        const productTitle = products?.find(product => product.id === attendee.product_id)?.title
            ?? attendee.product_title;

        return [productTitle, attendee.seat_label].filter(Boolean).join(' · ');
    }, [products]);

    const scanFeedback = useCallback((attendee: Attendee, message: ReactNode) => {
        const ticketInfo = ticketInfoFor(attendee);

        return (
            <>
                {message}
                {ticketInfo && <div className={classes.scanProduct}>{ticketInfo}</div>}
            </>
        );
    }, [ticketInfoFor]);

    // Resolves locally and returns at once: the check-in is queued and confirmed
    // with the server in the background (see useCheckInRoster).
    //
    // Memoised, with everything below it, because the keyboard listener for the USB scanner is
    // rebuilt whenever this identity changes: unmemoised it was torn down and re-registered on
    // every render, which at a door means on every character the reader types.
    const handleCheckInAction = useCallback((
        attendee: Attendee,
        action: 'check-in' | 'check-in-and-mark-order-as-paid'
    ): boolean => {
        const outcome = roster.queueCheckIn(attendee, action);

        if (outcome.status === 'already-checked-in') {
            showError(scanFeedback(attendee,
                <Trans>{attendee.first_name} {attendee.last_name} is already checked in</Trans>));
            playError();
            return false;
        }

        if (outcome.status === 'cancelled') {
            showError(scanFeedback(attendee,
                <Trans>{attendee.first_name} {attendee.last_name}'s ticket is cancelled</Trans>));
            playError();
            return false;
        }

        // A ticket this list does not cover: the server would refuse it anyway, and until now that
        // refusal came back as a 409 that wiped the whole pending queue. The door needs to send the
        // person to the right entrance, so it is said here rather than swallowed.
        if (outcome.status === 'not-on-this-list') {
            showError(scanFeedback(attendee,
                <Trans>{attendee.first_name} {attendee.last_name}'s ticket is not valid for this check-in list</Trans>));
            playError();
            return false;
        }

        showSuccess(scanFeedback(attendee,
            <Trans>{attendee.first_name} <b>checked in</b> successfully</Trans>));
        playSuccess();
        checkInModalHandlers.close();
        setSelectedAttendee(null);
        return true;
    }, [roster.queueCheckIn, scanFeedback, playError, playSuccess, checkInModalHandlers]);

    const handleCheckInToggle = (attendee: Attendee) => {
        if (attendee.check_in) {
            // Undoing a check-in needs the server's record, so it waits for a
            // pending one to be confirmed first.
            if (isPendingCheckIn(attendee.check_in)) {
                showError(t`This check-in is still syncing. Please try again in a moment.`);
                return;
            }

            checkOutMutation.mutate({
                checkInListShortId,
                checkInShortId: attendee.check_in.short_id,
                attendeePublicId: attendee.public_id,
            }, {
                onSuccess: () => {
                    roster.patchAttendee(attendee.public_id, {check_in: undefined});
                    showSuccess(<Trans>{attendee.first_name} <b>checked out</b> successfully</Trans>);
                    playSuccess();
                },
                // Handled here rather than inside the mutation: this is the screen that knows
                // whether the door is offline, and what to tell the person holding the phone.
                onError: (error) => {
                    playError();
                    if (!networkStatus.online) {
                        showError(t`You are offline`);
                        return;
                    }

                    if (error instanceof AxiosError) {
                        showError(error?.response?.data?.message || t`Unable to check out attendee`);
                    } else {
                        showError(t`Unable to check out attendee`);
                    }
                },
            });
            return;
        }

        const isAttendeeAwaitingPayment = attendee.status === 'AWAITING_PAYMENT';

        if (allowOrdersAwaitingOfflinePaymentToCheckIn && isAttendeeAwaitingPayment) {
            setSelectedAttendee(attendee);
            checkInModalHandlers.open();
            return;
        }

        if (!allowOrdersAwaitingOfflinePaymentToCheckIn && isAttendeeAwaitingPayment) {
            showError(t`You cannot check in attendees with unpaid orders. This setting can be changed in the event settings.`);
            return;
        }

        handleCheckInAction(attendee, 'check-in');
    };

    const handleQrCheckIn = useCallback(async (attendeePublicId: string): Promise<boolean> => {
        // Reentrancy only: one scan is resolved at a time. Repeated reads of the same code are
        // already held off by the scanner itself, and a ticket that went through is refused below
        // on its check_in. This used to also keep a set of seen codes and a single timestamp — but
        // the timestamp was global, so "this ticket was just scanned" was decided by whatever was
        // scanned last, and its cleanup timer outlived the page.
        if (isProcessingRef.current) {
            return false;
        }

        isProcessingRef.current = true;

        try {
            // The roster holds the whole list, so a scan normally resolves here with
            // no request. The network fallback only covers a ticket sold after the
            // last refresh.
            let attendee = roster.findByPublicId(attendeePublicId);

            if (!attendee) {
                try {
                    const {data} = await publicCheckInClient.getCheckInListAttendee(
                        checkInListShortId, attendeePublicId, ATTENDEE_LOOKUP_TIMEOUT_MS,
                    );
                    attendee = data;
                } catch (error) {
                    // A 404 is about the code, not the connection: a QR from another event, or from
                    // another app entirely. Reporting it as a failed request sends the person at the
                    // door to check the wifi over a ticket that was never valid here.
                    const isUnknownCode = error instanceof AxiosError && error.response?.status === 404;

                    showError(isUnknownCode
                        ? t`Attendee not found`
                        : networkStatus.online ? t`Unable to fetch attendee` : t`You are offline`);
                    playError();
                    return false;
                }

                if (!attendee) {
                    showError(t`Attendee not found`);
                    playError();
                    return false;
                }
            }

            // Check if already checked in
            if (attendee.check_in) {
                showError(scanFeedback(attendee,
                    <Trans>{attendee.first_name} {attendee.last_name} is already checked in</Trans>));
                playError();
                return false;
            }

            // Lists are independent by design (general vs VIP), so this is not the
            // server's decision: the scanner rejects and the person at the door can
            // still let them through from the list, deliberately.
            const enteredElsewhere = attendee.other_check_ins?.[0];
            if (enteredElsewhere) {
                const listName = enteredElsewhere.check_in_list_name ?? t`another list`;
                const time = new Date(enteredElsewhere.checked_in_at).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
                showError(scanFeedback(attendee,
                    <Trans>{attendee.first_name} {attendee.last_name} already entered at {time} via <b>{listName}</b></Trans>));
                playError();
                return false;
            }

            const isAttendeeAwaitingPayment = attendee.status === 'AWAITING_PAYMENT';

            if (allowOrdersAwaitingOfflinePaymentToCheckIn && isAttendeeAwaitingPayment) {
                setSelectedAttendee(attendee);
                checkInModalHandlers.open();
                return false;
            }

            if (!allowOrdersAwaitingOfflinePaymentToCheckIn && isAttendeeAwaitingPayment) {
                showError(t`You cannot check in attendees with unpaid orders. This setting can be changed in the event settings.`);
                playError();
                return false;
            }

            return handleCheckInAction(attendee, 'check-in');
        } finally {
            // In a finally so no path can leave the gate closed: one early return that forgot to
            // reopen it left the scanner refusing every later scan until the page was reloaded.
            isProcessingRef.current = false;
        }
    }, [roster.findByPublicId, checkInListShortId, allowOrdersAwaitingOfflinePaymentToCheckIn, checkInModalHandlers, handleCheckInAction, scanFeedback, playError, networkStatus.online]);


    // Process completed barcode
    const processBarcode = useCallback((barcode: string) => {
        if (isScannableBarcode(barcode)) {
            handleQrCheckIn(barcode);
        }
    }, [handleQrCheckIn]);

    // Track page focus
    useEffect(() => {
        const handleFocus = () => setPageHasFocus(true);
        const handleBlur = () => setPageHasFocus(false);

        window.addEventListener('focus', handleFocus);
        window.addEventListener('blur', handleBlur);

        return () => {
            window.removeEventListener('focus', handleFocus);
            window.removeEventListener('blur', handleBlur);
        };
    }, []);

    // Global keyboard listener for HID scanner mode.
    //
    // Stands down while the camera scanner is open: a USB reader left plugged in goes on typing
    // into the page behind the modal, and both paths then feed the same code to the same handler.
    useEffect(() => {
        if (!hidScannerMode || qrScannerOpen) return;

        const handleKeyPress = (e: KeyboardEvent) => {
            // Ignore if user is typing in an input field
            if (e.target instanceof HTMLInputElement ||
                e.target instanceof HTMLTextAreaElement) {
                return;
            }

            if (e.key === 'Enter') {
                // Process the accumulated barcode on Enter
                if (currentBarcodeRef.current.length > 0) {
                    processBarcode(currentBarcodeRef.current);
                    currentBarcodeRef.current = '';
                }
            } else if (e.key.length === 1) {
                currentBarcodeRef.current += e.key;

                if (barcodeTimeoutRef.current) {
                    clearTimeout(barcodeTimeoutRef.current);
                }

                // The reader types a whole code in one burst; a gap this long means it stopped,
                // so what is buffered is either a complete code or someone's stray keystrokes.
                barcodeTimeoutRef.current = setTimeout(() => {
                    const barcode = currentBarcodeRef.current;
                    currentBarcodeRef.current = '';

                    if (isScannableBarcode(barcode)) {
                        processBarcode(barcode);
                    }
                }, 100);
            }
        };

        window.addEventListener('keypress', handleKeyPress);

        return () => {
            window.removeEventListener('keypress', handleKeyPress);
            if (barcodeTimeoutRef.current) {
                clearTimeout(barcodeTimeoutRef.current);
            }
            currentBarcodeRef.current = '';
        };
    }, [hidScannerMode, qrScannerOpen, processBarcode]);

    if (CheckInListQuery.error && (CheckInListQuery.error as any).response?.status === 404) {
        return (
            <NoResultsSplash
                heading={t`Check-in list not found`}
                imageHref={'/blank-slate/check-in-lists.svg'}
                subHeading={(
                    <>
                        <p>
                            {t`The check-in list you are looking for does not exist.`}
                        </p>
                    </>
                )}
            />)
    }

    if (checkInList?.is_expired) {
        return (
            <NoResultsSplash
                heading={t`Check-in list has expired`}
                imageHref={'/blank-slate/check-in-lists.svg'}
                subHeading={(
                    <>
                        <p>
                            <Trans>
                                This check-in list has expired and is no longer available for check-ins.
                            </Trans>
                        </p>
                    </>
                )}
            />)
    }

    if (checkInList && !checkInList?.is_active) {
        return (
            <NoResultsSplash
                heading={t`Check-in list is not active`}
                imageHref={'/blank-slate/check-in-lists.svg'}
                subHeading={(
                    <>
                        <p>
                            {t`This check-in list is not yet active and is not available for check-ins.`}
                        </p>
                        <p>
                            Check-in list will activate in{' '}<br/>
                            <b>
                                <Countdown
                                    targetDate={checkInList.activates_at as string}
                                    onExpiry={() => CheckInListQuery.refetch()}
                                />
                            </b>
                        </p>
                    </>
                )}
            />)
    }

    return (
        <div className={classes.container}>
            <Header
                fullWidth
                rightContent={(
                    <>
                        {!networkStatus.online && (
                            <div className={classes.offline}/>
                        )}
                        <ActionIcon
                            display={'flex'}
                            variant={'transparent'}
                            color={'white'}
                            onClick={() => infoModalHandlers.open()}
                        >
                            <IconInfoCircle/>
                        </ActionIcon>
                    </>
                )}/>
            <HidScannerStatus
                isActive={hidScannerMode}
                pageHasFocus={pageHasFocus}
                onDisable={() => setHidScannerMode(false)}
            />
            <div className={classes.header}>
                <div>
                    <h4 className={classes.title}>
                        <Truncate text={checkInList?.name} length={30}/>
                    </h4>
                </div>
                <div className={classes.search}>
                    <div className={classes.searchBar}>
                        <SearchBar
                            className={classes.searchInput}
                            mb={20}
                            value={searchQuery}
                            onChange={(event) => setSearchQuery(event.target.value)}
                            onClear={() => setSearchQuery('')}
                            placeholder={t`Search by name, order # or attendee #...`}
                        />
                        <Button variant={'light'} size={'md'} className={classes.scanButton}
                                onClick={() => setScannerSelectionOpen(true)} leftSection={<IconQrcode/>}>
                            {t`Scan`}
                        </Button>
                        <ActionIcon 
                            aria-label={isSoundOn ? t`Turn sound off` : t`Turn sound on`} 
                            variant={'light'} 
                            size={'xl'}
                            onClick={() => setIsSoundOn(!isSoundOn)}
                        >
                            {isSoundOn ? <IconVolume size={24}/> : <IconVolumeOff size={24}/>}
                        </ActionIcon>
                        <ActionIcon aria-label={t`Scan`} variant={'light'} size={'xl'}
                                    className={classes.scanIcon}
                                    onClick={() => setScannerSelectionOpen(true)}>
                            <IconQrcode size={32}/>
                        </ActionIcon>
                    </div>
                </div>
            </div>
            <SyncStatus
                online={networkStatus.online}
                pendingCount={roster.pendingCount}
                stuckCount={roster.stuckCount}
                loadedAt={roster.loadedAt}
                isLoading={roster.isLoading}
                loadError={roster.loadError}
                onRetry={retrySync}
            />
            <AttendeeList
                attendees={attendees}
                products={products}
                isLoading={roster.isLoading && roster.attendees.length === 0}
                checkingOutPublicId={checkingOutPublicId}
                allowOrdersAwaitingOfflinePaymentToCheckIn={allowOrdersAwaitingOfflinePaymentToCheckIn || false}
                isSearching={normalizedSearch !== ''}
                checkedInCount={checkedInCount}
                totalCount={roster.attendees.length}
                onCheckInToggle={handleCheckInToggle}
                onClickSound={playSuccess}
            />
            <CheckInOptionsModal
                isOpen={checkInModalOpen}
                attendee={selectedAttendee}
                onClose={() => {
                    checkInModalHandlers.close();
                    setSelectedAttendee(null);
                }}
                onCheckIn={(action) => selectedAttendee && handleCheckInAction(selectedAttendee, action)}
            />
            <ScannerSelectionModal
                isOpen={scannerSelectionOpen}
                isHidScannerActive={hidScannerMode}
                onClose={() => setScannerSelectionOpen(false)}
                onCameraSelect={() => {
                    setScannerSelectionOpen(false);
                    setQrScannerOpen(true);
                }}
                onHidScannerSelect={() => {
                    setScannerSelectionOpen(false);
                    if (!hidScannerMode) {
                        setHidScannerMode(true);
                    }
                }}
            />
            {qrScannerOpen && (
                <Modal.Root
                    opened
                    onClose={() => setQrScannerOpen(false)}
                    fullScreen
                    radius={0}
                    transitionProps={{transition: 'fade', duration: 200}}
                    padding={'none'}
                >
                    <Modal.Overlay/>
                    <Modal.Content>
                        <QRScannerComponent
                            onAttendeeScanned={handleQrCheckIn}
                            onClose={() => setQrScannerOpen(false)}
                            isSoundOn={isSoundOn}
                            onSoundToggle={() => setIsSoundOn(!isSoundOn)}
                            onScanStart={playInProgress}
                        />
                    </Modal.Content>
                </Modal.Root>
            )}
            <CheckInInfoModal
                isOpen={infoModalOpen}
                checkInList={checkInList}
                onClose={infoModalHandlers.close}
            />
            {audioElements}
        </div>
    );
}

export default CheckIn;
