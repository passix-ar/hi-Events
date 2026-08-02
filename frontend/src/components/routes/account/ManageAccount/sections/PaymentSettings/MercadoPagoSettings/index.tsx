import {useEffect, useState} from "react";
import {Alert, Button, Group, List, Text, ThemeIcon, Title} from "@mantine/core";
import {modals} from "@mantine/modals";
import {IconAlertTriangle, IconCheck, IconCreditCard, IconPlugConnectedX} from "@tabler/icons-react";
import {t, Plural} from "@lingui/macro";
import {useGetAccount} from "../../../../../../../queries/useGetAccount.ts";
import {useGetMercadoPagoStatus} from "../../../../../../../queries/useGetMercadoPagoStatus.ts";
import {
    useGetMercadoPagoDisconnectStatus
} from "../../../../../../../queries/useGetMercadoPagoDisconnectStatus.ts";
import {accountClient} from "../../../../../../../api/account.client.ts";
import {Card} from "../../../../../../common/Card";
import {showError, showSuccess} from "../../../../../../../utilites/notifications.tsx";

export const MercadoPagoSettings = () => {
    const accountQuery = useGetAccount();
    const account = accountQuery.data;
    const [isRedirecting, setIsRedirecting] = useState(false);
    const [isDisconnecting, setIsDisconnecting] = useState(false);

    const statusQuery = useGetMercadoPagoStatus(account?.id);
    const status = statusQuery.data;

    const disconnectStatusQuery = useGetMercadoPagoDisconnectStatus(account?.id, !!status?.is_connected);
    const disconnectStatus = disconnectStatusQuery.data;
    const canDisconnect = disconnectStatus?.can_disconnect ?? false;
    const affectedEventCount = disconnectStatus?.affected_events?.length ?? 0;

    useEffect(() => {
        if (typeof window === 'undefined') return;
        const params = new URLSearchParams(window.location.search);
        if (params.get('mp_connected') === '1') {
            showSuccess(t`MercadoPago connected successfully!`);
            statusQuery.refetch();
        } else if (params.get('mp_error') === 'already_connected') {
            showError(t`This MercadoPago account is already connected to another Passix account.`);
        } else if (params.get('mp_error')) {
            showError(t`There was a problem connecting MercadoPago. Please try again.`);
        }
    }, []);

    const handleConnect = async () => {
        if (!account?.id) return;
        setIsRedirecting(true);
        try {
            const data = await accountClient.getMercadoPagoConnectUrl(account.id);
            showSuccess(t`Redirecting to MercadoPago...`);
            window.location.href = data.authorization_url;
        } catch (err: any) {
            showError(err?.response?.data?.message || t`Failed to get MercadoPago authorization URL.`);
            setIsRedirecting(false);
        }
    };

    const handleDisconnect = () => {
        if (!account?.id) return;
        modals.openConfirmModal({
            title: t`Disconnect MercadoPago`,
            children: (
                <>
                    {affectedEventCount > 0 && (
                        <Text size="sm" mb="xs">
                            <Plural
                                value={affectedEventCount}
                                one="# published event will stop offering MercadoPago. It can still sell with its other payment methods."
                                other="# published events will stop offering MercadoPago. They can still sell with their other payment methods."
                            />
                        </Text>
                    )}
                    <Text size="sm" mb="xs">
                        {t`You won't be able to receive new MercadoPago payments until you reconnect.`}
                    </Text>
                    <Text size="sm" c="dimmed">
                        {t`Payments already started will still be credited, and past orders are not affected.`}
                    </Text>
                </>
            ),
            labels: {confirm: t`Disconnect`, cancel: t`Cancel`},
            confirmProps: {color: 'red'},
            onConfirm: async () => {
                setIsDisconnecting(true);
                try {
                    await accountClient.disconnectMercadoPago(account.id);
                    showSuccess(t`MercadoPago disconnected.`);
                    statusQuery.refetch();
                } catch (err: any) {
                    showError(err?.response?.data?.message || t`Failed to disconnect MercadoPago.`);
                    disconnectStatusQuery.refetch();
                } finally {
                    setIsDisconnecting(false);
                }
            },
        });
    };

    if (!account || statusQuery.isLoading) {
        return null;
    }

    return (
        <Card>
            <Group gap="xs" mb="md">
                <ThemeIcon size="lg" radius="md" variant="light" color="blue">
                    <IconCreditCard size={20}/>
                </ThemeIcon>
                <Title order={3}>{t`MercadoPago`}</Title>
            </Group>

            {status?.is_connected ? (
                <>
                    <Group gap="xs" mb="md">
                        <ThemeIcon size="sm" variant="light" radius="xl" color="green">
                            <IconCheck size={16}/>
                        </ThemeIcon>
                        <Text size="sm" fw={500}>{t`MercadoPago is connected`}</Text>
                    </Group>
                    <Text size="sm" c="dimmed" mb="lg">
                        {t`Your MercadoPago account is connected and ready to process payments.`}
                    </Text>

                    {!disconnectStatusQuery.isLoading && !canDisconnect && (
                        <Alert
                            variant="light"
                            color="orange"
                            icon={<IconAlertTriangle size={18}/>}
                            title={t`MercadoPago can't be disconnected right now`}
                            mb="lg"
                        >
                            <Text size="sm" mb="xs">{disconnectStatus?.reason}</Text>
                            <List size="sm" withPadding>
                                {disconnectStatus?.blocking_events?.map((event) => (
                                    <List.Item key={event.id}>{event.title}</List.Item>
                                ))}
                            </List>
                        </Alert>
                    )}

                    <Group gap="sm">
                        <Button
                            variant="light"
                            size="sm"
                            leftSection={<IconCreditCard size={16}/>}
                            onClick={handleConnect}
                            loading={isRedirecting}
                        >
                            {t`Reconnect MercadoPago`}
                        </Button>
                        <Button
                            variant="subtle"
                            color="red"
                            size="sm"
                            leftSection={<IconPlugConnectedX size={16}/>}
                            onClick={handleDisconnect}
                            loading={isDisconnecting}
                            disabled={disconnectStatusQuery.isLoading || !canDisconnect}
                        >
                            {t`Disconnect`}
                        </Button>
                    </Group>
                </>
            ) : (
                <>
                    <Text size="sm" c="dimmed" mb="lg">
                        {t`Connect your MercadoPago account to start accepting payments in Argentina.`}
                    </Text>
                    <Button
                        variant="light"
                        size="sm"
                        color="blue"
                        leftSection={<IconCreditCard size={16}/>}
                        onClick={handleConnect}
                        loading={isRedirecting}
                    >
                        {t`Connect with MercadoPago`}
                    </Button>
                </>
            )}
        </Card>
    );
};
