import {useParams} from "react-router";
import {useCreateMercadoPagoPreference} from "../../../../../../queries/useCreateMercadoPagoPreference.ts";
import {Button, Text, Stack, Alert} from "@mantine/core";
import {IconAlertCircle} from "@tabler/icons-react";
import {LoadingMask} from "../../../../../common/LoadingMask";
import {t} from "@lingui/macro";
import classes from "./MercadoPago.module.scss";

interface MercadoPagoPaymentMethodProps {
    onRedirect?: () => void;
}

export const MercadoPagoPaymentMethod = ({onRedirect}: MercadoPagoPaymentMethodProps) => {
    const {eventId, orderShortId} = useParams();

    const {
        data: mpData,
        isFetching,
        error: mpError,
    } = useCreateMercadoPagoPreference(eventId, orderShortId);

    if (isFetching) {
        return <LoadingMask/>;
    }

    if (mpError) {
        return (
            <Alert icon={<IconAlertCircle size={16}/>} color="red" mt="md">
                {/* @ts-ignore */}
                {mpError.response?.data?.message || t`Could not initialize MercadoPago payment. Please try again.`}
            </Alert>
        );
    }

    const handlePay = () => {
        if (!mpData) return;
        onRedirect?.();
        const url = mpData.is_sandbox ? mpData.sandbox_init_point : mpData.init_point;
        window.location.href = url;
    };

    return (
        <Stack gap="md" className={classes.container}>
            <Text size="sm" c="dimmed">
                {t`You will be redirected to MercadoPago to complete your payment securely.`}
            </Text>
            <Button
                className={classes.mpButton}
                onClick={handlePay}
                disabled={!mpData}
                fullWidth
            >
                {t`Pay with MercadoPago`}
            </Button>
        </Stack>
    );
};
