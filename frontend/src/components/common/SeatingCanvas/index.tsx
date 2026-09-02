import {t} from "@lingui/macro";
import {ReactNode, useEffect, useState} from "react";
import {DndContext, DragEndEvent, PointerSensor, TouchSensor, useDraggable, useSensor, useSensors} from "@dnd-kit/core";
import {IdParam, SeatingSection} from "../../../types.ts";
import {SeatingRoom} from "../SeatingRoom";
import {PLAN_MARGIN} from "../../../utilites/seatingPlan.ts";
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

        // Kept inside the drawn margin, so a piece cannot be dragged off the plan.
        const clamp = (value: number) => Math.min(Math.max(Math.round(value), -PLAN_MARGIN), 3000);
        const moved = {...spots, [id]: {x: clamp(current.x + delta.x), y: clamp(current.y + delta.y)}};

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
            <p className={classes.hint}>{t`Drag the stage and each section to lay out your room.`}</p>

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
