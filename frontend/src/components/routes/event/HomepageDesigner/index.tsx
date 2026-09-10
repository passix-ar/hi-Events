import {useEffect, useRef, useState} from "react";
import classes from './HomepageDesigner.module.scss';
import {useParams} from "react-router";
import {useGetEventSettings} from "../../../../queries/useGetEventSettings.ts";
import {useUpdateEventSettings} from "../../../../mutations/useUpdateEventSettings.ts";
import {useFormErrorResponseHandler} from "../../../../hooks/useFormErrorResponseHandler.tsx";
import {CoverImagePosition, EventSettings, HomepageThemeSettings, IdParam} from "../../../../types.ts";
import {showSuccess} from "../../../../utilites/notifications.tsx";
import {t} from "@lingui/macro";
import {useForm} from "@mantine/form";
import {Button, Group, TextInput, Accordion, Stack, Text} from "@mantine/core";
import {IconColorPicker, IconHelp, IconPhoto, IconPalette, IconTypography} from "@tabler/icons-react";
import {Tooltip} from "../../../common/Tooltip";
import {CustomSelect} from "../../../common/CustomSelect";
import {GET_EVENT_IMAGES_QUERY_KEY, useGetEventImages} from "../../../../queries/useGetEventImages.ts";
import {eventPreviewPath} from "../../../../utilites/urlHelper.ts";
import {LoadingMask} from "../../../common/LoadingMask";
import {ImageUploadDropzone} from "../../../common/ImageUploadDropzone";
import {CoverImageEditor} from "../../../common/CoverImageEditor";
import {LandingBannerPreview} from "../../../common/LandingBannerPreview";
import {queryClient} from "../../../../utilites/queryClient.ts";
import {GET_EVENT_PUBLIC_QUERY_KEY} from "../../../../queries/useGetEventPublic.ts";
import {ThemeColorControls} from "../../../common/ThemeColorControls";
import {ThemeFontControl} from "../../../common/ThemeFontControl";
import {
    DEFAULT_COVER_IMAGE_POSITION,
    DEFAULT_COVER_IMAGE_SCALE,
    validateThemeSettings
} from "../../../../utilites/themeUtils.ts";
import {DEFAULT_HOMEPAGE_FONT} from "../../../../constants/homepageFonts.ts";

interface FormValues {
    homepage_theme_settings: Partial<HomepageThemeSettings>;
    continue_button_text: string;
}

const HomepageDesigner = () => {
    const {eventId} = useParams();
    const eventSettingsQuery = useGetEventSettings(eventId);
    const eventImagesQuery = useGetEventImages(eventId);
    const updateMutation = useUpdateEventSettings();

    const iframeRef = useRef<HTMLIFrameElement>(null);
    const lastSentSettings = useRef<string | null>(null);

    const [iframeSrc, setIframeSrc] = useState<string | null>(null);
    const [iframeLoaded, setIframeLoaded] = useState(false);
    const [lastCoverId, setLastCoverId] = useState<IdParam | null>(null);
    // Only the images section starts open: it is what most organisers come here for, it
    // is where the optional banner lives, and on a phone four open panels bury the preview.
    const [accordionValue, setAccordionValue] = useState<string[]>(['images']);

    const existingCover = eventImagesQuery.data?.find((image) => image.type === 'EVENT_COVER');
    const existingBanner = eventImagesQuery.data?.find((image) => image.type === 'EVENT_BANNER');

    // Shape is never enforced on upload — an organiser's only artwork is often the wrong
    // proportion and rejecting it just sends them to Canva to crop it worse. These flag the
    // cases where the framing will visibly suffer, and leave the decision to them.
    const ratioOf = (image?: {width?: number | null; height?: number | null}) =>
        (image?.width && image?.height) ? image.width / image.height : null;
    const coverRatio = ratioOf(existingCover);
    const bannerRatio = ratioOf(existingBanner);
    const coverIsOffShape = coverRatio !== null && (coverRatio > 1.35 || coverRatio < 0.7);
    // La franja del destacado es 2.4:1 (1920x800) y recorta lo que no calza. La subida
    // solo frena lo cuadrado y lo vertical, asi que el aviso es lo que hace el trabajo
    // fino: dice el porcentaje real, porque "no esta en la proporcion recomendada" no le
    // mueve la aguja a nadie y "se recorta el 45%" si.
    const BANNER_RATIO = 2.4;
    const bannerCropPercent = bannerRatio === null ? null
        : Math.round((1 - Math.min(bannerRatio, BANNER_RATIO) / Math.max(bannerRatio, BANNER_RATIO)) * 100);
    const bannerIsOffShape = bannerCropPercent !== null && bannerCropPercent > 10;
    // Un banner mas alto que la franja pierde arriba y abajo; uno mas chato, los costados.
    const bannerCropsVertically = bannerRatio !== null && bannerRatio < BANNER_RATIO;

    const form = useForm<FormValues>({
        initialValues: {
            homepage_theme_settings: {
                accent: '#d6ff3d',
                background: '#0b0b0e',
                mode: 'dark',
                background_type: 'COLOR',
                font_family: DEFAULT_HOMEPAGE_FONT,
            },
            continue_button_text: '',
        }
    });

    const formErrorHandle = useFormErrorResponseHandler();

    useEffect(() => {
        if (eventSettingsQuery?.isFetched && eventSettingsQuery?.data) {
            const settings = eventSettingsQuery.data;
            const themeSettings = validateThemeSettings(settings.homepage_theme_settings);

            form.setValues({
                homepage_theme_settings: themeSettings,
                continue_button_text: settings.continue_button_text,
            });
        }
    }, [eventSettingsQuery.isFetched]);

    useEffect(() => {
        if (eventSettingsQuery.isFetched && eventImagesQuery.isFetched && !iframeSrc) {
            setIframeSrc(eventPreviewPath(eventId));
        }
    }, [eventSettingsQuery.isFetched, eventImagesQuery.isFetched]);

    useEffect(() => {
        if (existingCover?.id !== lastCoverId && iframeSrc) {
            setLastCoverId(existingCover?.id);
            setIframeSrc(eventPreviewPath(eventId) + `?cover_image_id=${existingCover?.id}`);
            setIframeLoaded(false);
        }
    }, [existingCover?.id]);

    const handleSubmit = (values: FormValues) => {
        const validatedTheme = validateThemeSettings(values.homepage_theme_settings);

        const eventSettings: Partial<EventSettings> = {
            homepage_theme_settings: validatedTheme,
            continue_button_text: values.continue_button_text,
            // Also update legacy fields for backward compatibility during transition
            homepage_primary_color: validatedTheme.accent,
            homepage_body_background_color: validatedTheme.background,
            homepage_background_type: validatedTheme.background_type,
        };

        updateMutation.mutate(
            {eventSettings, eventId: eventId},
            {
                onSuccess: () => {
                    showSuccess(t`Successfully Updated Homepage Design`);
                },
                onError: (error) => {
                    formErrorHandle(form, error);
                },
            }
        );
    };

    const handleImageChange = () => {
        queryClient.invalidateQueries({
            queryKey: [GET_EVENT_IMAGES_QUERY_KEY, eventId]
        });
        queryClient.invalidateQueries({
            queryKey: [GET_EVENT_PUBLIC_QUERY_KEY, eventId]
        });
    };

    const handleCoverAdjust = ({position, scale}: { position: CoverImagePosition; scale: number }) => {
        form.setFieldValue('homepage_theme_settings', {
            ...form.values.homepage_theme_settings,
            cover_image_position: position,
            cover_image_scale: scale,
        });
    };

    const handleCoverUpload = () => {
        // A new image rarely fits the previous framing, so start fresh.
        handleCoverAdjust({position: {...DEFAULT_COVER_IMAGE_POSITION}, scale: DEFAULT_COVER_IMAGE_SCALE});
        handleImageChange();
    };

    const sendSettingsToIframe = () => {
        if (iframeRef.current?.contentWindow && iframeLoaded) {
            const themeSettings = validateThemeSettings(form.values.homepage_theme_settings);

            const settingsToSend = {
                homepage_theme_settings: themeSettings,
                continue_button_text: form.values.continue_button_text,
            };

            const settingsJson = JSON.stringify(settingsToSend);
            if (settingsJson !== lastSentSettings.current) {
                iframeRef.current.contentWindow.postMessage(
                    {type: "UPDATE_SETTINGS", settings: settingsToSend},
                    "*"
                );
                lastSentSettings.current = settingsJson;
            }
        }
    };

    useEffect(() => {
        sendSettingsToIframe();
    }, [iframeLoaded, form.values]);

    const handleThemeChange = (themeSettings: Partial<HomepageThemeSettings>) => {
        form.setFieldValue('homepage_theme_settings', themeSettings);
    };

    const handleBackgroundTypeChange = (backgroundType: string | string[]) => {
        const value = Array.isArray(backgroundType) ? backgroundType[0] : backgroundType;
        form.setFieldValue('homepage_theme_settings', {
            ...form.values.homepage_theme_settings,
            background_type: value as 'COLOR' | 'MIRROR_COVER_IMAGE',
        });
    };

    return (
        <div className={classes.container}>
            <div className={classes.sidebar}>
                <div className={classes.sticky}>
                    <div className={classes.header}>
                        <h2>{t`Homepage Design`}</h2>
                        <Text c="dimmed" size="sm">{t`Customize the layout, colors, and branding of your event homepage.`}</Text>
                    </div>

                    <Accordion
                        multiple
                        value={accordionValue}
                        onChange={setAccordionValue}
                        variant="contained"
                        className={classes.accordion}
                    >
                        <Accordion.Item value="images" className={classes.accordionItem}>
                            <Accordion.Control icon={<IconPhoto size={20} />}>
                                <Text fw={500}>{t`Images`}</Text>
                            </Accordion.Control>
                            <Accordion.Panel>
                                <Stack gap="lg">
                                    <div>
                                        <Group justify={'space-between'} mb="xs">
                                            <Text fw={500} size="sm">{t`Event Image`}</Text>
                                            <Tooltip
                                                label={t`We recommend a square flyer of 1080px by 1080px and a maximum file size of 5MB. Any proportion is accepted.`}>
                                                <IconHelp size={16} style={{ color: 'var(--mantine-color-gray-6)' }}/>
                                            </Tooltip>
                                        </Group>
                                        <ImageUploadDropzone
                                            imageType="EVENT_COVER"
                                            entityId={eventId}
                                            onUploadSuccess={handleCoverUpload}
                                            onDeleteSuccess={handleImageChange}
                                            existingImageData={{
                                                url: existingCover?.url,
                                                id: existingCover?.id,
                                            }}
                                            helpText={t`Your square flyer. It heads your event page and represents the event in listings and when the link is shared.`}
                                            displayMode="compact"
                                        />
                                        {coverIsOffShape && (
                                            <Text size="xs" c="orange.5" mt={6}>
                                                {t`This image is not square, so listings will show it with space around it. A square flyer looks better, but you can leave this one.`}
                                            </Text>
                                        )}
                                        {existingCover?.url && (
                                            <CoverImageEditor
                                                imageUrl={existingCover.url}
                                                aspectRatio={(existingCover.width && existingCover.height)
                                                    ? existingCover.width / existingCover.height
                                                    : undefined}
                                                position={form.values.homepage_theme_settings.cover_image_position
                                                    ?? DEFAULT_COVER_IMAGE_POSITION}
                                                scale={form.values.homepage_theme_settings.cover_image_scale
                                                    ?? DEFAULT_COVER_IMAGE_SCALE}
                                                onChange={handleCoverAdjust}
                                            />
                                        )}
                                    </div>

                                    <div>
                                        <Group justify={'space-between'} mb="xs">
                                            <Text fw={500} size="sm">{t`Featured banner (optional)`}</Text>
                                            <Tooltip
                                                label={t`We recommend 1920px by 800px, which fills the featured strip exactly. Any landscape image is accepted — square and portrait ones are not, because the strip would crop them beyond recognition.`}>
                                                <IconHelp size={16} style={{ color: 'var(--mantine-color-gray-6)' }}/>
                                            </Tooltip>
                                        </Group>
                                        <ImageUploadDropzone
                                            imageType="EVENT_BANNER"
                                            entityId={eventId}
                                            onUploadSuccess={handleImageChange}
                                            onDeleteSuccess={handleImageChange}
                                            existingImageData={{
                                                url: existingBanner?.url,
                                                id: existingBanner?.id,
                                            }}
                                            helpText={t`A wide banner is required to appear in the featured slot on the Passix homepage. Without one your event is still listed, using your square flyer.`}
                                            displayMode="compact"
                                        />
                                        {bannerIsOffShape && (
                                            <Text size="xs" c="orange.5" mt={6}>
                                                {bannerCropsVertically
                                                    ? t`The featured strip will crop about ${bannerCropPercent}% off the top and bottom of this banner. Check nothing important sits there — a 1920x800 image fits with nothing cut off.`
                                                    : t`The featured strip will crop about ${bannerCropPercent}% off the sides of this banner. Check nothing important sits there — a 1920x800 image fits with nothing cut off.`}
                                            </Text>
                                        )}
                                        {existingBanner?.url && (
                                            <>
                                                <LandingBannerPreview imageUrl={existingBanner.url}/>
                                                <Text size="xs" c="primary.4" mt={6}>
                                                    {t`Your event can now appear on the Passix homepage.`}
                                                </Text>
                                            </>
                                        )}
                                    </div>
                                </Stack>
                            </Accordion.Panel>
                        </Accordion.Item>

                        <Accordion.Item value="colors" className={classes.accordionItem}>
                            <Accordion.Control icon={<IconPalette size={20} />}>
                                <Text fw={500}>{t`Theme & Colors`}</Text>
                            </Accordion.Control>
                            <Accordion.Panel>
                                <form onSubmit={form.onSubmit(handleSubmit)}>
                                    <fieldset disabled={eventSettingsQuery.isLoading || updateMutation.isPending} className={classes.fieldset}>
                                        <Stack gap="md">
                                            <CustomSelect
                                                optionList={[
                                                    {
                                                        icon: <IconColorPicker/>,
                                                        label: t`Color`,
                                                        value: 'COLOR',
                                                        description: t`Choose a color for your background`,
                                                    },
                                                    {
                                                        icon: <IconPhoto/>,
                                                        label: t`Use cover image`,
                                                        value: 'MIRROR_COVER_IMAGE',
                                                        description: t`Use a blurred version of the cover image as the background`,
                                                        disabled: !existingCover,
                                                    },
                                                ]}
                                                label={t`Background Type`}
                                                name={'homepage_theme_settings.background_type'}
                                                value={form.values.homepage_theme_settings.background_type || 'COLOR'}
                                                onChange={handleBackgroundTypeChange}
                                            />

                                            <ThemeColorControls
                                                values={form.values.homepage_theme_settings}
                                                onChange={handleThemeChange}
                                                disabled={eventSettingsQuery.isLoading || updateMutation.isPending}
                                            />
                                        </Stack>
                                    </fieldset>
                                </form>
                            </Accordion.Panel>
                        </Accordion.Item>

                        <Accordion.Item value="typography" className={classes.accordionItem}>
                            <Accordion.Control icon={<IconTypography size={20} />}>
                                <Text fw={500}>{t`Typography`}</Text>
                            </Accordion.Control>
                            <Accordion.Panel>
                                <fieldset disabled={eventSettingsQuery.isLoading || updateMutation.isPending} className={classes.fieldset}>
                                    <ThemeFontControl
                                        value={form.values.homepage_theme_settings.font_family}
                                        onChange={(fontFamily) => form.setFieldValue('homepage_theme_settings', {
                                            ...form.values.homepage_theme_settings,
                                            font_family: fontFamily,
                                        })}
                                        disabled={eventSettingsQuery.isLoading || updateMutation.isPending}
                                    />
                                </fieldset>
                            </Accordion.Panel>
                        </Accordion.Item>

                        <Accordion.Item value="button" className={classes.accordionItem}>
                            <Accordion.Control icon={<IconTypography size={20} />}>
                                <Text fw={500}>{t`Button Text`}</Text>
                            </Accordion.Control>
                            <Accordion.Panel>
                                <form onSubmit={form.onSubmit(handleSubmit)}>
                                    <fieldset disabled={eventSettingsQuery.isLoading || updateMutation.isPending} className={classes.fieldset}>
                                        <Stack gap="md">
                                            <TextInput
                                                label={t`Continue Button Text`}
                                                description={t`Customize the text shown on the continue button`}
                                                placeholder={t`e.g., Get Tickets, Register Now`}
                                                size="sm"
                                                {...form.getInputProps('continue_button_text')}
                                            />
                                        </Stack>
                                    </fieldset>
                                </form>
                            </Accordion.Panel>
                        </Accordion.Item>
                    </Accordion>

                    <Button
                        loading={updateMutation.isPending}
                        type="submit"
                        fullWidth
                        mt="md"
                        onClick={() => form.onSubmit(handleSubmit)()}
                    >
                        {t`Save Changes`}
                    </Button>
                </div>
            </div>

            <div className={classes.previewContainer}>
                <h2>{t`Homepage Preview`}</h2>
                <div className={classes.iframeContainer}>
                    {iframeSrc ? (
                        <iframe
                            ref={iframeRef}
                            src={iframeSrc}
                            title="Event Preview"
                            onLoad={() => setIframeLoaded(true)}
                        />
                    ) : (
                        <LoadingMask/>
                    )}
                </div>
            </div>
        </div>
    );
};

export default HomepageDesigner;
