import {useEffect} from "react";
import {useLoaderData, useNavigate, useParams} from "react-router";
import {LoadingMask} from "../../common/LoadingMask";
import EventHomepage from "../EventHomepage";
import {EventNotAvailable} from "../EventHomepage/EventNotAvailable";
import {useGetEventPublic} from "../../../queries/useGetEventPublic.ts";
import {Event} from "../../../types";

export const PublicEvent = () => {
    const navigate = useNavigate();
    const {eventId, eventSlug} = useParams();

    const {event, promoCodeValid, promoCode, unresolved} = useLoaderData() as {
        event?: Event | null;
        promoCodeValid?: boolean;
        promoCode?: string | null;
        unresolved?: boolean;
    };

    // SSR can't resolve draft events: the session cookie is scoped to the API
    // host and never reaches the SSR server, so a draft the user is allowed to
    // preview comes back as 404. When the loader couldn't resolve the event we
    // re-fetch on the client, where the request carries the session cookie.
    const clientQuery = useGetEventPublic(
        eventId,
        Boolean(unresolved),
        promoCodeValid ?? false,
        promoCode ?? null,
    );

    const resolvedEvent = event ?? clientQuery.data;

    // Keep the canonical URL in sync for events resolved on the client, matching
    // the redirect the loader performs for SSR-resolved events.
    useEffect(() => {
        if (resolvedEvent?.slug && eventSlug !== resolvedEvent.slug) {
            navigate(`/event/${resolvedEvent.id}/${resolvedEvent.slug}${window.location.search}`, {replace: true});
        }
    }, [resolvedEvent?.id, resolvedEvent?.slug, eventSlug, navigate]);

    if (!resolvedEvent) {
        if (unresolved && (clientQuery.isLoading || !clientQuery.isFetched)) {
            return <LoadingMask/>;
        }
        return <EventNotAvailable/>;
    }

    return (
        <EventHomepage
            event={resolvedEvent}
            promoCodeValid={promoCodeValid}
            promoCode={promoCode ?? undefined}
        />
    );
};

export default PublicEvent;
