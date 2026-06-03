import React from 'react';
import {Link} from "react-router";
import {Event} from "../../../../types.ts";
import classes from './EventCard.module.scss';
import {formatDateWithLocale} from "../../../../utilites/dates.ts";
import {t} from "@lingui/macro";
import {isLightColor} from "@mantine/core";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {eventHomepagePath, eventHomepageUrl} from "../../../../utilites/urlHelper.ts";
import {getProductsFromEvent} from "../../../../utilites/helpers.ts";
import {ShareComponent} from "../../../common/ShareIcon";
import dayjs from "dayjs";
import {IconCalendar, IconClock, IconMapPin, IconTicket, IconWifi} from '@tabler/icons-react';

interface EventCardProps {
    event: Event;
    primaryColor?: string;
}

const categoryDefaultImages: Record<string, string> = {
    MUSIC:      'https://images.unsplash.com/photo-1514525253161-7a46d19cd819?w=600&q=80',
    TECH:       'https://images.unsplash.com/photo-1504384308090-c894fdcc538d?w=600&q=80',
    FOOD_DRINK: 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?w=600&q=80',
    SPORTS:     'https://images.unsplash.com/photo-1461896836934-ffe607ba8211?w=600&q=80',
    FESTIVAL:   'https://images.unsplash.com/photo-1533174072545-7a4b6ad7a6c3?w=600&q=80',
    NIGHTLIFE:  'https://images.unsplash.com/photo-1566417713940-fe7c737a9ef2?w=600&q=80',
    BUSINESS:   'https://images.unsplash.com/photo-1515187029135-18ee286d815b?w=600&q=80',
    EDUCATION:  'https://images.unsplash.com/photo-1524178232363-1fb2b075b655?w=600&q=80',
    WORKSHOP:   'https://images.unsplash.com/photo-1552664730-d307ca884978?w=600&q=80',
    ART:        'https://images.unsplash.com/photo-1460661419201-fd4cecdf8a8b?w=600&q=80',
    COMEDY:     'https://images.unsplash.com/photo-1527224857830-43a7acc85260?w=600&q=80',
    THEATER:    'https://images.unsplash.com/photo-1503095396549-807759245b35?w=600&q=80',
    OTHER:      'https://images.unsplash.com/photo-1492684223066-81342ee5ff30?w=600&q=80',
};

export const EventCard: React.FC<EventCardProps> = ({event, primaryColor = '#d6ff3d'}) => {
    const dateTextColor = isLightColor(primaryColor) ? '#000000' : '#ffffff';
    const placeholderImage = categoryDefaultImages[event.category] ?? categoryDefaultImages.OTHER;

    // Format dates using the event's timezone
    const startMonth = formatDateWithLocale(event.start_date, "monthShort", event.timezone);
    const startDay = formatDateWithLocale(event.start_date, "dayOfMonth", event.timezone);
    const startTime = formatDateWithLocale(event.start_date, "timeOnly", event.timezone);
    const endTime = event.end_date ? formatDateWithLocale(event.end_date, "timeOnly", event.timezone) : null;
    const prettyTimezone = formatDateWithLocale(event.start_date, "timezone", event.timezone);

    const isSameDay = event.end_date && event.start_date.substring(0, 10) === event.end_date.substring(0, 10);
    const endMonth = event.end_date ? formatDateWithLocale(event.end_date, "monthShort", event.timezone) : null;
    const endDay = event.end_date ? formatDateWithLocale(event.end_date, "dayOfMonth", event.timezone) : null;

    const coverImage = event.images?.find(img => img.type === 'EVENT_COVER');
    const location = event?.settings?.location_details?.city || event?.settings?.location_details?.venue_name;
    const isOnlineEvent = event.settings?.is_online_event;

    // Check if event is live
    const now = dayjs();
    const startDate = dayjs(event.start_date);
    const endDate = event.end_date ? dayjs(event.end_date) : startDate.add(2, 'hour');
    const isLive = now.isAfter(startDate) && now.isBefore(endDate);

    // Get products from event categories
    const products = getProductsFromEvent(event) || [];

    // Calculate price range from products
    let lowestPrice: number | null = null;
    let highestPrice: number | null = null;

    products.forEach(product => {
        if (product.prices && product.prices.length > 0) {
            product.prices.forEach(price => {
                const priceValue = price.price || 0;
                if (lowestPrice === null || priceValue < lowestPrice) {
                    lowestPrice = priceValue;
                }
                if (highestPrice === null || priceValue > highestPrice) {
                    highestPrice = priceValue;
                }
            });
        } else {
            const priceValue = product.price || 0;
            if (lowestPrice === null || priceValue < lowestPrice) {
                lowestPrice = priceValue;
            }
            if (highestPrice === null || priceValue > highestPrice) {
                highestPrice = priceValue;
            }
        }
    });

    const eventPath = eventHomepagePath(event);

    return (
        <Link to={eventPath} className={classes.eventCardLink}>
            <article className={classes.eventCard}>
                {/* Image Section */}
                <div className={classes.eventImage}>
                    <div className={classes.imageWrapper}>
                        {coverImage ? (
                            <img
                                src={coverImage.url}
                                alt={event.title}
                                loading="lazy"
                            />
                        ) : (
                            <img
                                src={placeholderImage}
                                alt={event.title}
                                loading="lazy"
                            />
                        )}

                        {/* Floating elements on image */}
                        <div className={classes.imageOverlay}>
                            {isLive && (
                                <div className={classes.liveIndicator}>
                                    <span className={classes.liveDot}></span>
                                    <span className={classes.liveText}>{t`LIVE`}</span>
                                </div>
                            )}
                            <div className={classes.shareButton} onClick={(e) => e.preventDefault()}>
                                <ShareComponent
                                    title={event.title}
                                    text={event.description_preview || ''}
                                    url={eventHomepageUrl(event)}
                                    hideShareButtonText={true}
                                    className={classes.shareIcon}
                                />
                            </div>
                        </div>
                    </div>

                    <div className={classes.dateBadge}>
                        <IconCalendar size={16}/>
                        <span>{startMonth} {startDay}</span>
                    </div>
                </div>

                {/* Content Section */}
                <div className={classes.eventContent}>
                    <div className={classes.eventHeader}>
                        <h3 className={classes.eventTitle}>{event.title}</h3>

                        <div className={classes.eventDateTime}>
                            <IconClock size={14}/>
                            <span>
                                {startTime}
                                {endTime && (
                                    <>
                                        {!isSameDay
                                            ? ` - ${endMonth} ${endDay}, ${endTime}`
                                            : ` - ${endTime}`
                                        }
                                    </>
                                )}
                                {prettyTimezone && (
                                    <span title={event.timezone} className={classes.timezone}> ({prettyTimezone})</span>
                                )}
                            </span>
                        </div>
                    </div>

                    {event.description_preview && (
                        <p className={classes.eventDescription}>
                            {event.description_preview}
                        </p>
                    )}

                    <div className={classes.eventFooter}>
                        <div className={classes.eventMeta}>
                            {(location || isOnlineEvent) && (
                                <div className={classes.location}>
                                    {isOnlineEvent ? (
                                        <><IconWifi size={14}/><span>{t`Online Event`}</span></>
                                    ) : (
                                        <><IconMapPin size={14}/><span>{location}</span></>
                                    )}
                                </div>
                            )}
                        </div>

                        {lowestPrice !== null && (
                            <div className={classes.priceSection}>
                                <IconTicket size={14}/>
                                <span className={lowestPrice === 0 && highestPrice === 0 ? classes.free : classes.price}>
                                    {lowestPrice === 0 && highestPrice === 0 ? (
                                        t`Free`
                                    ) : highestPrice !== null && highestPrice !== lowestPrice ? (
                                        `${formatCurrency(lowestPrice, event.currency)} - ${formatCurrency(highestPrice, event.currency)}`
                                    ) : (
                                        formatCurrency(lowestPrice, event.currency)
                                    )}
                                </span>
                            </div>
                        )}
                    </div>
                </div>
            </article>
        </Link>
    );
};
