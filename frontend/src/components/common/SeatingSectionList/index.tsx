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
import {closestCenter, DndContext, PointerSensor, TouchSensor, UniqueIdentifier, useSensor, useSensors} from "@dnd-kit/core";
import {SortableContext, useSortable, verticalListSortingStrategy} from "@dnd-kit/sortable";
import {CSS} from "@dnd-kit/utilities";
import {useDragItemsHandler} from "../../../hooks/useDragItemsHandler.ts";

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

export const SeatingSectionList = ({seatingSections, openCreateModal}: SeatingSectionListProps) => {
    // The route's id is what the sections query is cached under. Taking it from a section
    // instead gives a number where the cache holds a string, and nothing ever refetches.
    const {eventId} = useParams();
    const [editModalOpen, {open: openEditModal, close: closeEditModal}] = useDisclosure(false);
    const [selectedSeatingSectionId, setSelectedSeatingSectionId] = useState<IdParam>();
    const deleteMutation = useDeleteSeatingSection();
    const reorderMutation = useReorderSeatingSections();
    const sensors = useSensors(useSensor(PointerSensor), useSensor(TouchSensor));

    const {items, setItems, handleDragEnd} = useDragItemsHandler({
        initialItemIds: seatingSections.map((section) => Number(section.id)),
        onSortEnd: (sectionIds) => {
            reorderMutation.mutate({eventId, sectionIds}, {
                onSuccess: () => showSuccess(t`Seating sections reordered`),
                onError: (error: any) => showError(error?.response?.data?.message || error.message),
            });
        },
    });

    useEffect(() => {
        setItems(seatingSections.map((section) => Number(section.id)));
    }, [seatingSections]);

    const orderedSections = items
        .map((id) => seatingSections.find((section) => Number(section.id) === id))
        .filter((section): section is SeatingSection => !!section);

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
            <SortableContext items={items as UniqueIdentifier[]} strategy={verticalListSortingStrategy}>
            <div className={classes.sectionList}>
                <SeatingStage/>
                {orderedSections.map((section) => (
                    <SortableSectionCard key={section.id} sectionId={section.id as IdParam}>
                        <div className={classes.sectionHeader}>
                            <div className={classes.sectionProduct}>
                                <IconArmchair size={16}/>
                                {section.product?.title || t`Unknown product`}
                            </div>
                            <div>
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
                ))}
            </div>
            </SortableContext>
            </DndContext>
            {(editModalOpen && selectedSeatingSectionId)
                && <EditSeatingSectionModal onClose={closeEditModal}
                                            seatingSectionId={selectedSeatingSectionId}/>}
        </>
    );
};
