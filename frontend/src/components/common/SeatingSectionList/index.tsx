import {IdParam, SeatingSection} from "../../../types";
import {Badge, Button} from "@mantine/core";
import {t, Trans} from "@lingui/macro";
import {IconArmchair, IconGripVertical, IconPencil, IconPlus, IconTrash} from "@tabler/icons-react";
import Truncate from "../Truncate";
import {NoResultsSplash} from "../NoResultsSplash";
import classes from './SeatingSectionList.module.scss';
import {Card} from "../Card";
import {ReactNode, useEffect, useState} from "react";
import {ActionMenu} from "../ActionMenu";
import {useDisclosure} from "@mantine/hooks";
import {EditSeatingSectionModal} from "../../modals/EditSeatingSectionModal";
import {useDeleteSeatingSection} from "../../../mutations/useDeleteSeatingSection";
import {useReorderSeatingSections} from "../../../mutations/useReorderSeatingSections";
import {SeatingStage} from "../SeatingChart";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../utilites/confirmationDialog.tsx";
import {useParams} from "react-router";
import {closestCenter, DndContext, DragEndEvent, PointerSensor, TouchSensor, UniqueIdentifier, useDroppable, useSensor, useSensors} from "@dnd-kit/core";
import {SortableContext, useSortable, verticalListSortingStrategy} from "@dnd-kit/sortable";
import {CSS} from "@dnd-kit/utilities";


const SortableSectionCard = ({sectionId, children}: { sectionId: IdParam, children: ReactNode }) => {
    const {attributes, listeners, setNodeRef, transform, transition, isDragging} = useSortable({
        id: sectionId as UniqueIdentifier,
    });

    // React 18 does not forward `ref` as a prop to function components, so it goes on a
    // plain element rather than on Card, or dnd-kit never gets the node.
    return (
        <div
            ref={setNodeRef}
            style={{transform: CSS.Transform.toString(transform), transition, opacity: isDragging ? 0.6 : undefined}}
        >
            <Card className={classes.sectionCard}>
                <div {...attributes} {...listeners} className={classes.dragHandle} aria-label={t`Reorder`}>
                    <IconGripVertical size={16} stroke={1.5}/>
                </div>
                {children}
            </Card>
        </div>
    );
};

interface SeatingSectionListProps {
    seatingSections: SeatingSection[];
    openCreateModal: () => void;
}

type Position = 'BEHIND' | 'LEFT' | 'CENTER' | 'RIGHT';

const POSITIONS: Position[] = ['BEHIND', 'LEFT', 'CENTER', 'RIGHT'];

const groupByZone = (sections: SeatingSection[]): Record<Position, number[]> => {
    const zones = {BEHIND: [], LEFT: [], CENTER: [], RIGHT: []} as Record<Position, number[]>;

    sections.forEach((section) => {
        zones[(section.layout_position ?? 'CENTER') as Position].push(Number(section.id));
    });

    return zones;
};

const Zone = ({position, children}: { position: Position, children: ReactNode }) => {
    const {setNodeRef, isOver} = useDroppable({id: position});

    return (
        <div ref={setNodeRef} className={classes.zone} data-over={isOver || undefined}>
            <div className={classes.zoneLabel}>{positionLabel(position)}</div>
            {children}
        </div>
    );
};

const positionLabel = (position?: string): string => ({
    BEHIND: t`Behind the stage`,
    LEFT: t`Left`,
    RIGHT: t`Right`,
}[position ?? ''] ?? t`Centre`);

export const SeatingSectionList = ({seatingSections, openCreateModal}: SeatingSectionListProps) => {
    // The route's id is what the sections query is cached under. Taking it from a section
    // instead gives a number where the cache holds a string, and nothing ever refetches.
    const {eventId} = useParams();
    const [editModalOpen, {open: openEditModal, close: closeEditModal}] = useDisclosure(false);
    const [selectedSeatingSectionId, setSelectedSeatingSectionId] = useState<IdParam>();
    const deleteMutation = useDeleteSeatingSection();
    const reorderMutation = useReorderSeatingSections();
    const sensors = useSensors(useSensor(PointerSensor), useSensor(TouchSensor));

    const [zones, setZones] = useState<Record<Position, number[]>>(() => groupByZone(seatingSections));

    useEffect(() => {
        setZones(groupByZone(seatingSections));
    }, [seatingSections]);

    const sectionById = (id: number) => seatingSections.find((section) => Number(section.id) === id);

    const zoneOf = (id: UniqueIdentifier): Position | undefined => {
        if (POSITIONS.includes(id as Position)) {
            return id as Position;
        }

        return POSITIONS.find((position) => zones[position].includes(Number(id)));
    };

    const save = (next: Record<Position, number[]>) => {
        const sections = POSITIONS.flatMap((position) => next[position]
            .map((id) => ({id, layout_position: position})));

        reorderMutation.mutate({eventId, sections}, {
            onSuccess: () => showSuccess(t`Seating layout saved`),
            onError: (error: any) => showError(error?.response?.data?.message || error.message),
        });
    };

    const handleDragEnd = ({active, over}: DragEndEvent) => {
        if (!over) {
            return;
        }

        const from = zoneOf(active.id);
        const to = zoneOf(over.id);

        if (!from || !to) {
            return;
        }

        const next: Record<Position, number[]> = {...zones};
        next[from] = next[from].filter((id) => id !== Number(active.id));

        const overIndex = next[to].indexOf(Number(over.id));
        next[to] = overIndex < 0
            ? [...next[to], Number(active.id)]
            : [...next[to].slice(0, overIndex), Number(active.id), ...next[to].slice(overIndex)];

        if (JSON.stringify(next) === JSON.stringify(zones)) {
            return;
        }

        setZones(next);
        save(next);
    };

    const handleDeleteSection = (seatingSectionId: IdParam) => {
        deleteMutation.mutate({seatingSectionId, eventId}, {
            onSuccess: () => {
                showSuccess(t`Seating section deleted successfully`);
            },
            onError: (error: any) => {
                showError(error?.response?.data?.message || error.message);
            }
        });
    }

    if (seatingSections.length === 0) {
        return (
            <NoResultsSplash
                heading={t`No Seating Sections`}
                imageHref={'/blank-slate/capacity-assignments.svg'}
                subHeading={(
                    <>
                        <p>
                            <Trans>
                                <p>
                                    Create seating sections so ticket buyers can choose their own numbered seat.
                                </p>
                                <p>
                                    Each section is a grid of rows and seats linked to a product. For example, a
                                    <b> Balcony</b> section with 10 rows of 20 seats sells through your
                                    <b> Balcony Ticket</b> product, while general admission products remain unchanged.
                                </p>
                            </Trans>
                        </p>
                        <Button
                            size={'xs'}
                            leftSection={<IconPlus/>}
                            color={'green'}
                            onClick={() => openCreateModal()}>{t`Create Seating Section`}
                        </Button>
                    </>
                )}
            />
        );
    }

    return (
        <>
            <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
            <div className={classes.room}>
                <Zone position={'BEHIND'}>
                    <SortableContext items={zones.BEHIND as UniqueIdentifier[]} strategy={verticalListSortingStrategy}>
                        {zones.BEHIND.map((id) => renderCard(id))}
                    </SortableContext>
                </Zone>

                <SeatingStage/>

                <div className={classes.roomFront}>
                    {(['LEFT', 'CENTER', 'RIGHT'] as Position[]).map((position) => (
                        <Zone key={position} position={position}>
                            <SortableContext items={zones[position] as UniqueIdentifier[]} strategy={verticalListSortingStrategy}>
                                {zones[position].map((id) => renderCard(id))}
                            </SortableContext>
                        </Zone>
                    ))}
                </div>
            </div>
            </DndContext>
            {(editModalOpen && selectedSeatingSectionId)
                && <EditSeatingSectionModal onClose={closeEditModal}
                                            seatingSectionId={selectedSeatingSectionId}/>}
        </>
    );

    function renderCard(id: number) {
        const section = sectionById(id);

        if (!section) {
            return null;
        }

        return (
                    <SortableSectionCard key={section.id} sectionId={section.id as IdParam}>
                        <div className={classes.sectionHeader}>
                            <div className={classes.sectionProduct}>
                                <IconArmchair size={16}/>
                                {section.product?.title || t`Unknown product`}
                            </div>
                            <div>
                                <Badge variant={'outline'} color={'gray'} mr={6}>
                                    {positionLabel(section.layout_position)}
                                </Badge>
                                <Badge variant={'light'} color={section.status === 'ACTIVE' ? 'green' : 'gray'}>
                                    {section.status}
                                </Badge>
                            </div>
                        </div>
                        <div className={classes.sectionName}>
                            <b>
                                <Truncate text={section.name} length={30}/>
                            </b>
                        </div>

                        <div className={classes.sectionInfo}>
                            <div className={classes.sectionStats}>
                                <span>
                                    <Trans>{section.row_count} rows × {section.seats_per_row} seats</Trans>
                                </span>
                                <span className={classes.sectionCounts}>
                                    <Badge variant={'light'} color={'teal'} size={'sm'}>
                                        {section.seats_available ?? 0} {t`available`}
                                    </Badge>
                                    <Badge variant={'light'} color={'yellow'} size={'sm'}>
                                        {section.seats_held ?? 0} {t`held`}
                                    </Badge>
                                    <Badge variant={'light'} color={'gray'} size={'sm'}>
                                        {section.seats_sold ?? 0} {t`sold`}
                                    </Badge>
                                </span>
                            </div>
                            <div className={classes.sectionActions}>
                                <ActionMenu
                                    itemsGroups={[
                                        {
                                            label: t`Manage`,
                                            items: [
                                                {
                                                    label: t`Edit Section`,
                                                    icon: <IconPencil size={14}/>,
                                                    onClick: () => {
                                                        setSelectedSeatingSectionId(section.id as IdParam);
                                                        openEditModal();
                                                    }
                                                },
                                            ],
                                        },
                                        {
                                            label: t`Danger zone`,
                                            items: [
                                                {
                                                    label: t`Delete Section`,
                                                    icon: <IconTrash size={14}/>,
                                                    onClick: () => {
                                                        confirmationDialog(
                                                            t`Are you sure you would like to delete this Seating Section?`,
                                                            () => {
                                                                handleDeleteSection(section.id as IdParam);
                                                            })
                                                    },
                                                    color: 'red',
                                                },
                                            ],
                                        },
                                    ]}
                                />
                            </div>
                        </div>
                    </SortableSectionCard>
        );
    }
};
