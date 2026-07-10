import {GenericModalProps, IdParam, ProductCategory, SeatingSectionRequest} from "../../../types.ts";
import {Modal} from "../../common/Modal";
import {t} from "@lingui/macro";
import {SeatingSectionForm} from "../../forms/SeatingSectionForm";
import {useForm} from "@mantine/form";
import {Button} from "@mantine/core";
import {showSuccess} from "../../../utilites/notifications.tsx";
import {useParams} from "react-router";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler.tsx";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {useEditSeatingSection} from "../../../mutations/useEditSeatingSection.ts";
import {useGetSeatingSection} from "../../../queries/useGetSeatingSection.ts";
import {useEffect} from "react";

interface EditSeatingSectionModalProps {
    seatingSectionId: IdParam;
}

export const EditSeatingSectionModal = ({
                                            onClose,
                                            seatingSectionId,
                                        }: GenericModalProps & EditSeatingSectionModalProps) => {
    const {eventId} = useParams();
    const errorHandler = useFormErrorResponseHandler();
    const {data: seatingSection} = useGetSeatingSection(eventId, seatingSectionId);
    const {data: event} = useGetEvent(eventId);
    const form = useForm<SeatingSectionRequest>({
        initialValues: {
            name: '',
            product_id: undefined as unknown as number,
            row_count: 10,
            seats_per_row: 10,
            status: 'ACTIVE',
            disabled_seats: [],
        }
    });
    const editMutation = useEditSeatingSection();

    const handleSubmit = (requestData: SeatingSectionRequest) => {
        editMutation.mutate({
            eventId: eventId,
            seatingSectionId: seatingSectionId,
            seatingSectionData: {
                ...requestData,
                product_id: Number(requestData.product_id),
            },
        }, {
            onSuccess: () => {
                showSuccess(t`Successfully updated Seating Section`);
                onClose();
            },
            onError: (error) => errorHandler(form, error),
        })
    }

    useEffect(() => {
        if (seatingSection) {
            form.setValues({
                name: seatingSection.name,
                product_id: String(seatingSection.product_id) as unknown as number,
                row_count: seatingSection.row_count,
                seats_per_row: seatingSection.seats_per_row,
                status: seatingSection.status,
                disabled_seats: seatingSection.seats
                    ?.filter((seat) => seat.state === 'DISABLED')
                    .map((seat) => seat.label) ?? [],
            });
        }
    }, [seatingSection]);

    return (
        <Modal opened onClose={onClose} heading={t`Edit Seating Section`}>
            <form onSubmit={form.onSubmit(handleSubmit)}>
                {event && <SeatingSectionForm form={form}
                                              productsCategories={event.product_categories as ProductCategory[]}
                                              seats={seatingSection?.seats}/>}
                <Button
                    type={'submit'}
                    fullWidth
                    loading={editMutation.isPending}
                >
                    {t`Edit Seating Section`}
                </Button>
            </form>
        </Modal>
    );
}
