import {useEffect, useRef, useState} from "react";
import {ActionIcon, Button, Loader, Tooltip} from "@mantine/core";
import {t, Trans} from "@lingui/macro";
import {
    IconCheck,
    IconCircleDashed,
    IconExternalLink,
    IconLayoutSidebarLeftCollapse,
    IconRefresh,
    IconSparkles,
} from "@tabler/icons-react";
import QRCode from "react-qr-code";
import {useNavigate} from "react-router";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {useGetEventImages} from "../../../queries/useGetEventImages.ts";
import {useGetEventSettings} from "../../../queries/useGetEventSettings.ts";
import {useGetEventSeatingSections} from "../../../queries/useGetSeatingSections.ts";
import {useGetSeatingLayout} from "../../../queries/useGetSeatingLayout.ts";
import {SeatMapPreview} from "./SeatMapPreview.tsx";
import {SegmentedControl} from "@mantine/core";
import {useGetAccount} from "../../../queries/useGetAccount.ts";
import {useGetMercadoPagoStatus} from "../../../queries/useGetMercadoPagoStatus.ts";
import {getProductsFromEvent} from "../../../utilites/helpers.ts";
import {eventHomepageUrl, eventPreviewPath} from "../../../utilites/urlHelper.ts";
import {IdParam} from "../../../types.ts";
import {CopyButton} from "./CopyBlock.tsx";
import type {BuildStep} from "./index.tsx";
import classes from './AssistantStudio.module.scss';

interface AssistantStudioProps {
    /** The event being built; null while the flyer is still being read. */
    eventId: IdParam | null;
    /** Data URL of the flyer dropped into the chat, shown until the event exists. */
    flyerPreview?: string | null;
    /** Bumped every time a write tool finishes so the page reloads. */
    version: number;
    /** True while the assistant is answering: the page gets a "building" veil. */
    building: boolean;
    /** The tool running right now, for the veil caption. */
    buildingStep?: string | null;
    /** Every write that finished this session, oldest first. */
    buildLog?: BuildStep[];
    onClose: () => void;
}

const STEP_LABELS: Record<string, () => string> = {
    create_draft_event: () => t`Creating the event`,
    create_ticket: () => t`Adding tickets`,
    attach_flyer_to_event: () => t`Setting the flyer as cover`,
    apply_flyer_palette: () => t`Painting the page with the flyer colours`,
    set_event_theme: () => t`Changing the page colours`,
    set_offline_payment: () => t`Setting up offline payment`,
    create_seating_section: () => t`Adding a seat map section`,
    delete_seating_section: () => t`Removing a seat map section`,
    reorder_seating_sections: () => t`Reordering the seat map`,
    set_platform_fee_payer: () => t`Setting who pays the commission`,
    set_checkout_settings: () => t`Updating checkout settings`,
    set_event_location: () => t`Setting the location`,
    publish_event: () => t`Publishing`,
    update_event: () => t`Updating the event`,
    update_ticket: () => t`Updating a ticket`,
    delete_ticket: () => t`Removing a ticket`,
    create_promo_code: () => t`Creating the promo code`,
};

const DONE_LABELS: Record<string, () => string> = {
    create_draft_event: () => t`Event created`,
    create_ticket: () => t`Ticket added`,
    attach_flyer_to_event: () => t`Flyer set as cover`,
    apply_flyer_palette: () => t`Page painted with the flyer colours`,
    set_event_theme: () => t`Page colours changed`,
    set_offline_payment: () => t`Offline payment set`,
    create_seating_section: () => t`Seat map section added`,
    delete_seating_section: () => t`Seat map section removed`,
    reorder_seating_sections: () => t`Seat map reordered`,
    set_platform_fee_payer: () => t`Commission payer set`,
    set_checkout_settings: () => t`Checkout settings updated`,
    set_event_location: () => t`Location set`,
    publish_event: () => t`Published`,
    update_event: () => t`Event updated`,
    update_ticket: () => t`Ticket updated`,
    delete_ticket: () => t`Ticket removed`,
    create_promo_code: () => t`Promo code created`,
};

/** The build steps as a timeline, newest at the bottom, each one sliding in. */
const BuildTimeline = ({log, running}: { log: BuildStep[]; running?: string | null }) => {
    if (log.length === 0 && !running) {
        return null;
    }

    return (
        <ol className={classes.timeline} aria-label={t`What the assistant did`}>
            {log.map(step => (
                <li key={step.at} className={`${classes.timelineItem} ${step.success ? '' : classes.timelineFailed}`}>
                    <span className={classes.timelineDot}><IconCheck size={11}/></span>
                    <span>{(DONE_LABELS[step.name] ?? (() => step.name))()}</span>
                    <time className={classes.timelineTime}>
                        {new Date(step.at).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit', second: '2-digit'})}
                    </time>
                </li>
            ))}
            {running && (
                <li className={`${classes.timelineItem} ${classes.timelineRunning}`}>
                    <span className={classes.timelineDot}><Loader size={9} color="currentColor"/></span>
                    <span>{(STEP_LABELS[running] ?? (() => running))()}…</span>
                </li>
            )}
        </ol>
    );
};

/**
 * The right half of the assistant in studio mode: the organizer's real public
 * event page, reloaded every time the assistant finishes a write, with the
 * setup checklist above it. Nothing here is a mock: the iframe is the same
 * preview route the homepage designer uses, and the checklist reads the same
 * queries as the Getting Started page.
 */
export const AssistantStudio = (props: AssistantStudioProps) => (
    props.eventId
        ? <EventStudio {...props} eventId={props.eventId}/>
        : <EmptyStudio {...props}/>
);

/** Before the event exists: the flyer on stage (or a hint), same chrome. */
const EmptyStudio = ({flyerPreview, building, buildingStep, buildLog = [], onClose}: AssistantStudioProps) => {
    const caption = buildingStep && STEP_LABELS[buildingStep] ? STEP_LABELS[buildingStep]() : t`Working on it…`;

    return (
        <div className={classes.studio}>
            <div className={classes.top}>
                <div className={classes.titleBlock}>
                    <div className={classes.eyebrow}>
                        <IconSparkles size={14}/>
                        <Trans>Live page</Trans>
                    </div>
                    <div className={classes.title}>
                        {flyerPreview ? t`Reading your flyer…` : t`Your event will appear here`}
                    </div>
                </div>
                <div className={classes.topActions}>
                    <Tooltip label={t`Back to the chat`}>
                        <ActionIcon variant="subtle" color="gray" aria-label={t`Back to the chat`} onClick={onClose}>
                            <IconLayoutSidebarLeftCollapse size={18}/>
                        </ActionIcon>
                    </Tooltip>
                </div>
            </div>
            {(buildLog.length > 0 || building) && (
                <div className={classes.checklist}>
                    <BuildTimeline log={buildLog} running={building && buildingStep && STEP_LABELS[buildingStep] ? buildingStep : null}/>
                </div>
            )}
            <div className={classes.canvas}>
                <div className={classes.flyerStage}>
                    {flyerPreview
                        ? <img src={flyerPreview} alt="" className={classes.flyer}/>
                        : (
                            <p className={classes.emptyHint}>
                                <Trans>Drop a flyer into the chat, or tell the assistant what you are organizing, and watch the page build itself here.</Trans>
                            </p>
                        )}
                </div>
                {building && (
                    <div className={classes.veil}>
                        <div className={classes.veilCard}>
                            <Loader size="xs" type="dots"/>
                            <span>{caption}</span>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};

const EventStudio = ({eventId, flyerPreview, version, building, buildingStep, buildLog = [], onClose}: AssistantStudioProps & { eventId: IdParam }) => {
    const navigate = useNavigate();
    const {data: event, refetch: refetchEvent} = useGetEvent(eventId);
    const {data: images, refetch: refetchImages} = useGetEventImages(eventId);
    const {data: eventSettings, refetch: refetchSettings} = useGetEventSettings(eventId);
    const {data: seatingPage, refetch: refetchSeating} = useGetEventSeatingSections(eventId, {perPage: 100, pageNumber: 1});
    const sections = seatingPage?.data ?? [];
    const {data: seatingLayout, refetch: refetchLayout} = useGetSeatingLayout(eventId);
    const [view, setView] = useState<'page' | 'seats'>('page');
    const [newSectionId, setNewSectionId] = useState<number | null>(null);
    const lastSeatStep = [...buildLog].reverse().find(step => ['create_seating_section', 'delete_seating_section', 'reorder_seating_sections'].includes(step.name));
    const {data: account} = useGetAccount();
    const {data: mpStatus} = useGetMercadoPagoStatus(account?.id);
    const [frameLoaded, setFrameLoaded] = useState(false);
    const [celebrate, setCelebrate] = useState(false);
    const previousStatus = useRef<string | undefined>(undefined);

    // Each finished write reloads the page and the checklist data.
    useEffect(() => {
        setFrameLoaded(false);
        void refetchEvent();
        void refetchImages();
        void refetchSettings();
        void refetchLayout();
        void refetchSeating().then(result => {
            // A section just built: show the map and let the new block drop in.
            if (lastSeatStep && Date.now() - lastSeatStep.at < 5000) {
                setView('seats');
                const latest = [...(result.data?.data ?? [])].sort((a, b) => (b.id ?? 0) - (a.id ?? 0))[0];
                setNewSectionId(latest?.id ?? null);
            } else if (buildLog.length > 0 && Date.now() - buildLog[buildLog.length - 1].at < 5000) {
                setView('page');
            }
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [version, eventId]);

    // Going DRAFT -> LIVE during this session is the moment worth a celebration.
    useEffect(() => {
        const status = event?.status;
        if (previousStatus.current && previousStatus.current !== 'LIVE' && status === 'LIVE') {
            setCelebrate(true);
            const timer = window.setTimeout(() => setCelebrate(false), 4500);
            return () => window.clearTimeout(timer);
        }
        previousStatus.current = status;
    }, [event?.status]);

    const products = getProductsFromEvent(event) ?? [];
    const hasTickets = products.length > 0;
    const hasPaidTickets = products.some(product => product.type !== 'FREE');
    const hasCover = (images ?? []).some(image => image.type === 'EVENT_COVER');
    const isLive = event?.status === 'LIVE';
    const mpConnected = mpStatus?.is_connected ?? false;
    const offlineEnabled = (eventSettings?.payment_providers ?? []).some(provider => String(provider) === 'OFFLINE');
    const needsMp = hasPaidTickets && !mpConnected && !offlineEnabled;

    const steps: { key: string; label: string; done: boolean; warn?: boolean; to?: string }[] = [
        {key: 'event', label: t`Event`, done: !!event},
        {key: 'tickets', label: t`Tickets`, done: hasTickets},
        {key: 'cover', label: t`Flyer`, done: hasCover},
        {key: 'mp', label: offlineEnabled && !mpConnected ? t`Offline payment` : t`Mercado Pago`, done: !hasPaidTickets || mpConnected || offlineEnabled, warn: needsMp, to: '/account/payment'},
        {key: 'live', label: t`Published`, done: isLive},
    ];
    const completed = steps.filter(step => step.done).length;

    const publicUrl = event && isLive ? eventHomepageUrl(event) : null;
    const caption = buildingStep && STEP_LABELS[buildingStep] ? STEP_LABELS[buildingStep]() : t`Working on it…`;

    return (
        <div className={classes.studio}>
            <div className={classes.top}>
                <div className={classes.titleBlock}>
                    <div className={classes.eyebrow}>
                        <IconSparkles size={14}/>
                        <Trans>Live page</Trans>
                    </div>
                    <div className={classes.title} title={event?.title}>
                        {event?.title ?? (flyerPreview ? t`Reading your flyer…` : '…')}
                    </div>
                </div>
                <div className={classes.topActions}>
                    {event && (
                        <span className={`${classes.status} ${isLive ? classes.statusLive : classes.statusDraft}`}>
                            {isLive ? t`On sale` : t`Draft`}
                        </span>
                    )}
                    {(
                        <Tooltip label={t`Reload the page`}>
                            <ActionIcon variant="subtle" color="gray" aria-label={t`Reload the page`} onClick={() => { setFrameLoaded(false); void refetchEvent(); }}>
                                <IconRefresh size={16}/>
                            </ActionIcon>
                        </Tooltip>
                    )}
                    {event && (
                        <Button
                            size="compact-sm"
                            variant="light"
                            onClick={() => { onClose(); navigate(`/manage/event/${event.id}/getting-started`); }}
                        >
                            <Trans>Open in the panel</Trans>
                        </Button>
                    )}
                    <Tooltip label={t`Back to the chat`}>
                        <ActionIcon variant="subtle" color="gray" aria-label={t`Back to the chat`} onClick={onClose}>
                            <IconLayoutSidebarLeftCollapse size={18}/>
                        </ActionIcon>
                    </Tooltip>
                </div>
            </div>

            <div className={classes.checklist} aria-label={t`Setup progress`}>
                <div className={classes.progressTrack}>
                    <div className={classes.progressBar} style={{width: `${(completed / steps.length) * 100}%`}}/>
                </div>
                <ol className={classes.steps}>
                    {steps.map(step => (
                        <li key={step.key} className={`${classes.step} ${step.done ? classes.stepDone : ''} ${step.warn ? classes.stepWarn : ''}`}>
                            <span className={classes.stepIcon}>
                                {step.done ? <IconCheck size={13}/> : <IconCircleDashed size={13}/>}
                            </span>
                            {step.warn && step.to
                                ? <button type="button" className={classes.stepLink} onClick={() => { onClose(); navigate(step.to as string); }}>{step.label}</button>
                                : <span>{step.label}</span>}
                        </li>
                    ))}
                </ol>
                <BuildTimeline log={buildLog} running={building && buildingStep && STEP_LABELS[buildingStep] ? buildingStep : null}/>
            </div>

            {(
                <div className={classes.viewSwitch}>
                    <SegmentedControl
                        size="xs"
                        value={view}
                        onChange={value => setView(value as 'page' | 'seats')}
                        data={[
                            {value: 'page', label: t`Page`},
                            {value: 'seats', label: sections.length > 0 ? t`Seat map (${sections.length})` : t`Seat map`},
                        ]}
                    />
                </div>
            )}

            <div className={classes.canvas}>
                {view === 'seats' ? (
                    <SeatMapPreview sections={sections} stage={seatingLayout} highlightId={newSectionId}/>
                ) : (
                <iframe
                    key={`${eventId}-${version}`}
                    src={eventPreviewPath(eventId)}
                    title={t`Event page preview`}
                    className={`${classes.frame} ${frameLoaded ? classes.frameLoaded : ''}`}
                    onLoad={() => setFrameLoaded(true)}
                />
                )}
                {view === 'page' && !frameLoaded && (
                    <div className={classes.frameLoading}>
                        <Loader size="sm" type="dots"/>
                    </div>
                )}

                {building && (
                    <div className={classes.veil}>
                        <div className={classes.veilCard}>
                            <Loader size="xs" type="dots"/>
                            <span>{caption}</span>
                        </div>
                    </div>
                )}

                {celebrate && (
                    <div className={classes.confetti} aria-hidden>
                        {Array.from({length: 48}).map((_, index) => (
                            <span
                                key={index}
                                className={classes.confettiPiece}
                                style={{
                                    left: `${(index * 37) % 100}%`,
                                    animationDelay: `${(index % 12) * 0.12}s`,
                                    background: ['#d6ff3d', '#ff6b6b', '#4dabf7', '#ffd43b', '#f783ac'][index % 5],
                                }}
                            />
                        ))}
                    </div>
                )}
            </div>

            {publicUrl && (
                <div className={classes.shareBar}>
                    <div className={classes.qr}>
                        <QRCode value={publicUrl} size={72} bgColor="transparent" fgColor="currentColor"/>
                    </div>
                    <div className={classes.shareText}>
                        <div className={classes.shareTitle}><Trans>It is on sale. Share it:</Trans></div>
                        <a href={publicUrl} target="_blank" rel="noopener noreferrer" className={classes.shareLink}>
                            {publicUrl.replace(/^https?:\/\//, '')}
                            <IconExternalLink size={12}/>
                        </a>
                    </div>
                    <div className={classes.shareCopy}>
                        <CopyButton text={publicUrl}/>
                    </div>
                </div>
            )}
        </div>
    );
};
