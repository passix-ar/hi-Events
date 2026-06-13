import {CSSProperties, PointerEvent as ReactPointerEvent, useRef, useState} from "react";
import {Button, Group, Slider, Text} from "@mantine/core";
import {IconArrowsMove, IconRestore, IconZoomIn} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import {CoverImagePosition} from "../../../types.ts";
import {DEFAULT_COVER_IMAGE_POSITION, DEFAULT_COVER_IMAGE_SCALE} from "../../../utilites/themeUtils.ts";
import classes from "./CoverImageEditor.module.scss";

interface CoverImageEditorProps {
    imageUrl: string;
    aspectRatio?: number;
    position: CoverImagePosition;
    scale: number;
    onChange: (value: { position: CoverImagePosition; scale: number }) => void;
}

const MIN_SCALE = 1;
const MAX_SCALE = 3;
const clamp = (value: number, min: number, max: number) => Math.min(max, Math.max(min, value));

export const CoverImageEditor = ({imageUrl, aspectRatio, position, scale, onChange}: CoverImageEditorProps) => {
    const frameRef = useRef<HTMLDivElement | null>(null);
    const dragOrigin = useRef<{x: number; y: number; posX: number; posY: number} | null>(null);
    const [isDragging, setIsDragging] = useState(false);

    const handlePointerDown = (event: ReactPointerEvent<HTMLDivElement>) => {
        event.currentTarget.setPointerCapture(event.pointerId);
        dragOrigin.current = {x: event.clientX, y: event.clientY, posX: position.x, posY: position.y};
        setIsDragging(true);
    };

    const handlePointerMove = (event: ReactPointerEvent<HTMLDivElement>) => {
        const origin = dragOrigin.current;
        const frame = frameRef.current;
        if (!origin || !frame) return;

        const {width, height} = frame.getBoundingClientRect();
        // Dragging the image right reveals the left side, so object-position decreases.
        const nextX = clamp(origin.posX - ((event.clientX - origin.x) / width) * 100, 0, 100);
        const nextY = clamp(origin.posY - ((event.clientY - origin.y) / height) * 100, 0, 100);

        onChange({position: {x: nextX, y: nextY}, scale});
    };

    const endDrag = () => {
        dragOrigin.current = null;
        setIsDragging(false);
    };

    const handleReset = () => onChange({
        position: {...DEFAULT_COVER_IMAGE_POSITION},
        scale: DEFAULT_COVER_IMAGE_SCALE,
    });

    const isDefault = position.x === DEFAULT_COVER_IMAGE_POSITION.x
        && position.y === DEFAULT_COVER_IMAGE_POSITION.y
        && scale === DEFAULT_COVER_IMAGE_SCALE;

    const frameStyle = {
        aspectRatio: aspectRatio ? `${aspectRatio}` : '3 / 1',
        '--cover-pos': `${position.x}% ${position.y}%`,
        '--cover-scale': scale,
    } as CSSProperties;

    return (
        <div className={classes.editor}>
            <div
                ref={frameRef}
                className={`${classes.frame} ${isDragging ? classes.dragging : ''}`}
                style={frameStyle}
                onPointerDown={handlePointerDown}
                onPointerMove={handlePointerMove}
                onPointerUp={endDrag}
                onPointerCancel={endDrag}
            >
                <img src={imageUrl} alt="" className={classes.image} draggable={false}/>
                <div className={classes.hint}>
                    <IconArrowsMove size={14}/>
                    <span>{t`Drag to reposition`}</span>
                </div>
            </div>

            <Group gap="xs" wrap="nowrap" mt="sm">
                <IconZoomIn size={16} style={{color: 'var(--mantine-color-gray-6)', flexShrink: 0}}/>
                <Slider
                    flex={1}
                    min={MIN_SCALE}
                    max={MAX_SCALE}
                    step={0.05}
                    value={scale}
                    label={(v) => `${v.toFixed(1)}x`}
                    onChange={(value) => onChange({position, scale: value})}
                />
            </Group>

            <Group justify="end" mt="xs">
                <Button
                    variant="subtle"
                    size="compact-xs"
                    color="gray"
                    leftSection={<IconRestore size={14}/>}
                    onClick={handleReset}
                    disabled={isDefault}
                >
                    {t`Reset`}
                </Button>
            </Group>

            <Text size="xs" c="dimmed" mt={4}>
                {t`Drag the image and adjust the zoom to choose what's visible in the banner.`}
            </Text>
        </div>
    );
};
