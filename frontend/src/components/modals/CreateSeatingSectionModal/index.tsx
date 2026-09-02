import {GenericModalProps, ProductCategory, SeatingSectionRequest} from "../../../types.ts";
import {Modal} from "../../common/Modal";
import {t} from "@lingui/macro";
import {SeatingSectionForm} from "../../forms/SeatingSectionForm";
import {useForm} from "@mantine/form";
import {Button} from "@mantine/core";
import {useCreateSeatingSection} from "../../../mutations/useCreateSeatingSection.ts";
import {showSuccess} from "../../../utilites/notifications.tsx";
import {useParams} from "react-router";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler.tsx";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {NoResultsSplash} from "../../common/NoResultsSplash";
import {IconPlus} from "@tabler/icons-react";

export const CreateSeatingSectionModal = ({onClose}: GenericModalProps) => {
    const {eventId} = useParams();
    const errorHandler = useFormErrorResponseHandler();
    const {data: event} = useGetEvent(eventId);
    const form = useForm<SeatingSectionRequest>({
        initialValues: {
            name: '',
            product_id: undefined as unknown as number,
            row_count: 10,
            seats_per_row: 10,
            status: 'ACTIVE',
            disabled_seats: [],
            aisle_positions: [],
            layout_position: 'CENTER',
        }
    });
    const createMutation = useCreateSeatingSection();
    const eventHasTicketProducts = event?.product_categories?.some(
        category => category.products?.some(product => product.product_type === 'TICKET')
    ) === true;

    const handleSubmit = (requestData: SeatingSectionRequest) => {
        createMutation.mutate({
            eventId: eventId,
            seatingSectionData: {
                ...requestData,
                product_id: Number(requestData.product_id),
            },
        }, {
            onSuccess: () => {
                showSuccess(t`Seating section created successfully`);
                onClose();
            },
            onError: (error) => errorHandler(form, error),
        })
    }

    const NoProducts = () => {
        return (
            <NoResultsSplash
                imageHref={'/blank-slate/tickets.svg'}
                heading={t`Please create a ticket product`}
                subHeading={(
                    <>
                        <p>
                            {t`You'll need a ticket product before you can create a seating section.`}
                        </p>
                        <Button
                            size={'xs'}
                            leftSection={<IconPlus/>}
                            color={'green'}
                            onClick={() => window.location.href = `/manage/event/${eventId}/products/#create-product`}
                        >
                            {t`Create a Product`}
                        </Button>
                    </>
                )}
            />
        );
    }

    return (
        <Modal opened onClose={onClose} heading={eventHasTicketProducts ? t`Create Seating Section` : null}>
            {!eventHasTicketProducts && <NoProducts/>}
            {eventHasTicketProducts && (
                <form onSubmit={form.onSubmit(handleSubmit)}>
                    {event && <SeatingSectionForm form={form}
                                                  productsCategories={event.product_categories as ProductCategory[]}/>}
                    <Button
                        type={'submit'}
                        fullWidth
                        loading={createMutation.isPending}
                    >
                        {t`Create Seating Section`}
                    </Button>
                </form>
            )}
        </Modal>
    );
}
