import {t} from "@lingui/macro";
import {Button} from "@mantine/core";
import {IconLayoutGrid} from "@tabler/icons-react";
import {ReactNode, useEffect, useState} from "react";
import {DndContext, DragEndEvent, PointerSensor, TouchSensor, useDraggable, useSensor, useSensors} from "@dnd-kit/core";
import {IdParam, SeatingSection} from "../../../types.ts";
import {SeatingRoom} from "../SeatingRoom";
import {collides, PLAN_MARGIN, sectionSize, snapToGrid, STAGE_SIZE, tidyPlan} from "../../../utilites/seatingPlan.ts";
import {showError} from "../../../utilites/notifications.tsx";
import classes from './SeatingCanvas.module.scss';

type Spot = { x: number, y: number };

const STAGE = 'stage';

const Handle = ({id, children}: { id: string, children: ReactNode }) => {
    const {attributes, listeners, setNodeRef, transform, isDragging} = useDraggable({id});

    return (
        <div
            ref={setNodeRef}
            className={classes.handle}
            data-dragging={isDragging || undefined}
            style={transform ? {transform: `translate(${transform.x}px, ${transform.y}px)`, zIndex: 2} : undefined}
            {...attributes}
            {...listeners}
        >
            {children}
        </div>
    );
};

interface SeatingCanvasProps {
    sections: SeatingSection[];
    stage: Spot;
    onChange: (stage: Spot, sections: { id: IdParam, position_x: number, position_y: number }[]) => void;
    sectionOverlay?: (section: SeatingSection) => ReactNode;
}

export const SeatingCanvas = ({sections, stage, onChange, sectionOverlay}: SeatingCanvasProps) => {
    const [spots, setSpots] = useState<Record<string, Spot>>({});
    const sensors = useSensors(useSensor(PointerSensor), useSensor(TouchSensor));

    useEffect(() => {
        setSpots({
            [STAGE]: stage,
            ...Object.fromEntries(sections.map((section) => [
                String(section.id),
                {x: section.position_x ?? 0, y: section.position_y ?? 0},
            ])),
        });
    }, [sections, stage.x, stage.y]);

    const handleDragEnd = ({active, delta}: DragEndEvent) => {
        const id = String(active.id);
        const current = spots[id];

        if (!current || (delta.x === 0 && delta.y === 0)) {
            return;
        }

        // Kept inside the drawn margin and on the grid, so a piece cannot be dragged off the
        // plan or land a few pixels out of line with the rest.
        const clamp = (value: number) => Math.min(Math.max(snapToGrid(value), -PLAN_MARGIN), 3000);
        const target = {x: clamp(current.x + delta.x), y: clamp(current.y + delta.y)};

        const sizeOf = (key: string) => key === STAGE
            ? STAGE_SIZE
            : sectionSize(sections.find((section) => String(section.id) === key)!);

        const others = Object.entries(spots)
            .filter(([key]) => key !== id && (key === STAGE || sections.some((section) => String(section.id) === key)))
            .map(([key, spot]) => ({...spot, ...sizeOf(key)}));

        if (collides({...target, ...sizeOf(id)}, others)) {
            showError(t`That spot is taken — leave room between the pieces.`);
            return;
        }

        const moved = {...spots, [id]: target};

        setSpots(moved);
        onChange(
            moved[STAGE],
            sections
                .filter((section) => moved[String(section.id)])
                .map((section) => ({
                    id: section.id as IdParam,
                    position_x: moved[String(section.id)].x,
                    position_y: moved[String(section.id)].y,
                })),
        );
    };

    // The organizer moves the very plan the buyer will see, so there is nothing to translate
    // between what is arranged here and what is drawn there.
    const placed = sections.map((section) => ({
        ...section,
        position_x: spots[String(section.id)]?.x ?? section.position_x ?? 0,
        position_y: spots[String(section.id)]?.y ?? section.position_y ?? 0,
    }));

    return (
        <>
            <div className={classes.toolbar}>
                <p className={classes.hint}>{t`Drag the stage and each section to lay out your room.`}</p>
                <Button
                    size={'compact-xs'}
                    variant={'subtle'}
                    leftSection={<IconLayoutGrid size={14}/>}
                    onClick={() => {
                        const tidy = tidyPlan(sections);
                        setSpots({
                            [STAGE]: tidy.stage,
                            ...Object.fromEntries(tidy.sections.map((s) => [String(s.id), {x: s.position_x, y: s.position_y}])),
                        });
                        onChange(tidy.stage, tidy.sections.map((s) => ({...s, id: s.id as IdParam})));
                    }}
                >
                    {t`Tidy up`}
                </Button>
            </div>

            <div className={classes.canvas}>
                <DndContext sensors={sensors} onDragEnd={handleDragEnd}>
                    <SeatingRoom
                        sections={placed}
                        stage={spots[STAGE] ?? stage}
                        sectionOverlay={sectionOverlay}
                        wrapPiece={(key, node) => <Handle id={key}>{node}</Handle>}
                    />
                </DndContext>
            </div>
        </>
    );
};
