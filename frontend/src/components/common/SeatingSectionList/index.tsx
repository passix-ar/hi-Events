import {IdParam, SeatingLayoutRequest, SeatingSection} from "../../../types";
import {Badge, Button} from "@mantine/core";
import {t, Trans} from "@lingui/macro";
import {IconArmchair, IconPencil, IconPlus, IconTrash} from "@tabler/icons-react";
import {NoResultsSplash} from "../NoResultsSplash";
import Truncate from "../Truncate";
import {Card} from "../Card";
import classes from './SeatingSectionList.module.scss';
import {useState} from "react";
import {ActionMenu} from "../ActionMenu";
import {useDisclosure} from "@mantine/hooks";
import {EditSeatingSectionModal} from "../../modals/EditSeatingSectionModal";
import {useDeleteSeatingSection} from "../../../mutations/useDeleteSeatingSection";
import {useSaveSeatingLayout} from "../../../mutations/useSaveSeatingLayout";
import {useGetSeatingLayout} from "../../../queries/useGetSeatingLayout.ts";

import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../utilites/confirmationDialog.tsx";
import {useParams} from "react-router";
import {SeatingCanvas} from "../SeatingCanvas";

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
    const saveLayoutMutation = useSaveSeatingLayout();
    const {data: layout} = useGetSeatingLayout(eventId);
    const stage = {x: layout?.stage_x ?? 0, y: layout?.stage_y ?? -140};

    const handleLayoutChange = (stage: { x: number, y: number }, sections: SeatingLayoutRequest['sections']) => {
        saveLayoutMutation.mutate({
            eventId,
            layout: {stage_x: stage.x, stage_y: stage.y, sections},
        }, {
            onError: (error: any) => showError(error?.response?.data?.message || error.message),
        });
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
            <div className={classes.sectionList}>
                {seatingSections.map((section) => (
                    <Card className={classes.sectionCard} key={section.id}>
                        <div className={classes.sectionHeader}>
                            <div className={classes.sectionProduct}>
                                <IconArmchair size={16}/>
                                {section.product?.title || t`Unknown product`}
                            </div>
                            <Badge variant={'light'} color={section.status === 'ACTIVE' ? 'green' : 'gray'}>
                                {section.status === 'ACTIVE' ? t`Active` : t`Inactive`}
                            </Badge>
                        </div>

                        <div className={classes.sectionName}>
                            <b><Truncate text={section.name} length={30}/></b>
                        </div>

                        <div className={classes.sectionInfo}>
                            <div className={classes.sectionStats}>
                                <span><Trans>{section.row_count} rows × {section.seats_per_row} seats</Trans></span>
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
                                                    onClick: () => confirmationDialog(
                                                        t`Are you sure you would like to delete this Seating Section?`,
                                                        () => handleDeleteSection(section.id as IdParam),
                                                    ),
                                                    color: 'red',
                                                },
                                            ],
                                        },
                                    ]}
                                />
                            </div>
                        </div>
                    </Card>
                ))}
            </div>

            <SeatingCanvas
                sections={seatingSections}
                stage={stage}
                onChange={handleLayoutChange}
            />

            {(editModalOpen && selectedSeatingSectionId)
                && <EditSeatingSectionModal onClose={closeEditModal}
                                            seatingSectionId={selectedSeatingSectionId}/>}
        </>
    );
};
