import {t} from "@lingui/macro";
import {ReactNode, useEffect, useState} from "react";
import {DndContext, DragEndEvent, PointerSensor, TouchSensor, useDraggable, useSensor, useSensors} from "@dnd-kit/core";
import {IdParam, SeatingSection} from "../../../types.ts";
import {SeatingStage} from "../SeatingChart";
import classes from './SeatingCanvas.module.scss';

type Spot = { x: number, y: number };
type Spots = Record<string, Spot>;

const STAGE = 'stage';

const Piece = ({id, spot, children}: { id: string, spot: Spot, children: ReactNode }) => {
    const {attributes, listeners, setNodeRef, transform, isDragging} = useDraggable({id});

    return (
        <div
            ref={setNodeRef}
            className={classes.piece}
            data-dragging={isDragging || undefined}
            style={{
                left: spot.x,
                top: spot.y,
                transform: transform ? `translate(${transform.x}px, ${transform.y}px)` : undefined,
            }}
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
    renderSection: (section: SeatingSection) => ReactNode;
}

export const SeatingCanvas = ({sections, stage, onChange, renderSection}: SeatingCanvasProps) => {
    const [spots, setSpots] = useState<Spots>({});
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

        const moved = {...spots, [id]: {x: Math.round(current.x + delta.x), y: Math.round(current.y + delta.y)}};

        setSpots(moved);
        onChange(
            moved[STAGE],
            sections.map((section) => ({
                id: section.id as IdParam,
                position_x: moved[String(section.id)].x,
                position_y: moved[String(section.id)].y,
            })),
        );
    };

    // Everything is stored around an origin, so the plan is shifted into view rather than
    // half of it sitting outside the box.
    const all = Object.values(spots);
    const offsetX = all.length ? Math.min(...all.map((s) => s.x)) : 0;
    const offsetY = all.length ? Math.min(...all.map((s) => s.y)) : 0;
    const shift = (spot: Spot) => ({x: spot.x - offsetX, y: spot.y - offsetY});

    return (
        <>
            <p className={classes.hint}>{t`Drag the stage and each section to lay out your room.`}</p>

            <div className={classes.canvas}>
                <DndContext sensors={sensors} onDragEnd={handleDragEnd}>
                    {spots[STAGE] && (
                        <Piece id={STAGE} spot={shift(spots[STAGE])}>
                            <SeatingStage/>
                        </Piece>
                    )}

                    {sections.map((section) => spots[String(section.id)] && (
                        <Piece key={section.id} id={String(section.id)} spot={shift(spots[String(section.id)])}>
                            {renderSection(section)}
                        </Piece>
                    ))}
                </DndContext>
            </div>
        </>
    );
};
