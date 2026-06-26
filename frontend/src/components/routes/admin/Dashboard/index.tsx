import {Badge, Container, Group, Paper, SimpleGrid, Skeleton, Stack, Table, Text, Title} from "@mantine/core";
import {t, Trans} from "@lingui/macro";
import {IconBuildingBank, IconCalendarMonth, IconCalendarStats, IconCurrencyDollar, IconReceipt2, IconTicket} from "@tabler/icons-react";
import {useGetMe} from "../../../../queries/useGetMe";
import {useGetAdminStats} from "../../../../queries/useGetAdminStats";
import {useGetAdminDashboardData} from "../../../../queries/useGetAdminDashboardData";
import type {ReactNode} from "react";
import dayjs from "dayjs";
import relativeTime from 'dayjs/plugin/relativeTime';

dayjs.extend(relativeTime);

const formatCurrency = (amount: number, currency = 'ARS') =>
    new Intl.NumberFormat('es-AR', {
        style: 'currency',
        currency,
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount || 0);

const formatNumber = (num: number) => new Intl.NumberFormat('es-AR').format(num || 0);

interface StatCardProps {
    label: string;
    value: string;
    icon: ReactNode;
    loading: boolean;
}

const StatCard = ({label, value, icon, loading}: StatCardProps) => (
    <Paper shadow="sm" p="md" radius="md" withBorder>
        <Group gap="sm" wrap="nowrap">
            {icon}
            <div style={{flex: 1, minWidth: 0}}>
                <Text size="xs" c="dimmed" fw={500}>{label}</Text>
                {loading
                    ? <Skeleton height={28} width={90} mt={4}/>
                    : <Text size="xl" fw={700}>{value}</Text>}
            </div>
        </Group>
    </Paper>
);

const AdminDashboard = () => {
    const {data: user} = useGetMe();
    const {data: stats, isLoading} = useGetAdminStats();
    const {data: dashboardData, isLoading: isLoadingDashboard} = useGetAdminDashboardData({limit: 20});

    const reconciliation = dashboardData?.mercadopago_reconciliation ?? [];
    const totalGross = reconciliation.reduce((sum, row) => sum + (row.gross_collected || 0), 0);
    const accounts = dashboardData?.recent_accounts ?? [];

    return (
        <Container size="xl" p="xl">
            <Stack gap="xl">
                <div>
                    <Title order={1} mb="xs"><Trans>Admin Dashboard</Trans></Title>
                    {user && (
                        <Text c="dimmed">
                            <Trans>Hello {user.full_name}, manage your platform from here.</Trans>
                        </Text>
                    )}
                </div>

                {/* KPIs */}
                <SimpleGrid cols={{base: 1, sm: 2, md: 3}} spacing="md">
                    <StatCard
                        label={t`Commissions This Month`}
                        value={formatCurrency(dashboardData?.passix_commission_this_month || 0)}
                        icon={<IconCalendarStats size={32} color="var(--mantine-color-lime-7)"/>}
                        loading={isLoadingDashboard}
                    />
                    <StatCard
                        label={t`Commissions Last Month`}
                        value={formatCurrency(dashboardData?.passix_commission_last_month || 0)}
                        icon={<IconCalendarMonth size={32} color="var(--mantine-color-lime-8)"/>}
                        loading={isLoadingDashboard}
                    />
                    <StatCard
                        label={t`Historical Commissions`}
                        value={formatCurrency(dashboardData?.total_passix_commission || 0)}
                        icon={<IconCurrencyDollar size={32} color="var(--mantine-color-lime-7)"/>}
                        loading={isLoadingDashboard}
                    />
                    <StatCard
                        label={t`Gross Sales`}
                        value={formatCurrency(totalGross)}
                        icon={<IconReceipt2 size={32} color="var(--mantine-color-teal-6)"/>}
                        loading={isLoadingDashboard}
                    />
                    <StatCard
                        label={t`Total Accounts`}
                        value={formatNumber(stats?.total_accounts || 0)}
                        icon={<IconBuildingBank size={32} color="var(--mantine-color-blue-6)"/>}
                        loading={isLoading}
                    />
                    <StatCard
                        label={t`Tickets Sold`}
                        value={formatNumber(stats?.total_tickets_sold || 0)}
                        icon={<IconTicket size={32} color="var(--mantine-color-violet-6)"/>}
                        loading={isLoading}
                    />
                </SimpleGrid>

                {/* Passix Commission per event */}
                <div>
                    <Title order={2} mb="xs"><Trans>Passix Commission per Event</Trans></Title>
                    <Text size="xs" c="dimmed" mb="md">
                        <Trans>Commission taken from the marketplace fee on approved MercadoPago payments. Organizer net is gross collected minus our commission.</Trans>
                    </Text>

                    {isLoadingDashboard ? (
                        <Skeleton height={160} radius="md"/>
                    ) : reconciliation.length > 0 ? (
                        <Paper shadow="sm" radius="md" withBorder>
                            <Table striped highlightOnHover>
                                <Table.Thead>
                                    <Table.Tr>
                                        <Table.Th>{t`Event`}</Table.Th>
                                        <Table.Th>{t`Owner`}</Table.Th>
                                        <Table.Th ta="right">{t`Total Tickets`}</Table.Th>
                                        <Table.Th ta="right">{t`Tickets (MercadoPago)`}</Table.Th>
                                        <Table.Th ta="right">{t`Attendance`}</Table.Th>
                                        <Table.Th ta="right">{t`Collected (MercadoPago)`}</Table.Th>
                                        <Table.Th ta="right">{t`Passix Commission`}</Table.Th>
                                    </Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>
                                    {reconciliation.map((row) => (
                                        <Table.Tr key={`${row.event_id}-${row.currency}`}>
                                            <Table.Td><Text fw={500}>{row.event_title}</Text></Table.Td>
                                            <Table.Td>
                                                <Text>{row.account_name || '-'}</Text>
                                                <Text size="xs" c="dimmed">{row.currency}</Text>
                                            </Table.Td>
                                            <Table.Td ta="right">{formatNumber(row.total_tickets_sold)}</Table.Td>
                                            <Table.Td ta="right">{formatNumber(row.tickets_sold)}</Table.Td>
                                            <Table.Td ta="right">{formatNumber(row.checked_in)}</Table.Td>
                                            <Table.Td ta="right">{formatCurrency(row.gross_collected, row.currency)}</Table.Td>
                                            <Table.Td ta="right"><Text fw={600}>{formatCurrency(row.passix_commission, row.currency)}</Text></Table.Td>
                                        </Table.Tr>
                                    ))}
                                </Table.Tbody>
                            </Table>
                        </Paper>
                    ) : (
                        <Paper shadow="sm" p="xl" radius="md" withBorder>
                            <Stack align="center" gap="xs">
                                <IconCurrencyDollar size={48} color="var(--mantine-color-dimmed)"/>
                                <Text c="dimmed"><Trans>No approved MercadoPago payments yet</Trans></Text>
                            </Stack>
                        </Paper>
                    )}
                </div>

                {/* Accounts */}
                <div>
                    <Title order={2} mb="md"><Trans>Accounts</Trans></Title>

                    {isLoadingDashboard ? (
                        <Skeleton height={200} radius="md"/>
                    ) : accounts.length > 0 ? (
                        <Paper shadow="sm" radius="md" withBorder>
                            <Table striped highlightOnHover>
                                <Table.Thead>
                                    <Table.Tr>
                                        <Table.Th>{t`Account`}</Table.Th>
                                        <Table.Th>{t`Email`}</Table.Th>
                                        <Table.Th>{t`Signed Up`}</Table.Th>
                                        <Table.Th ta="right">{t`Events`}</Table.Th>
                                        <Table.Th>{t`Status`}</Table.Th>
                                    </Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>
                                    {accounts.map((account) => (
                                        <Table.Tr key={account.id}>
                                            <Table.Td><Text fw={500}>{account.primary_organizer_name || account.name}</Text></Table.Td>
                                            <Table.Td>{account.email}</Table.Td>
                                            <Table.Td>{dayjs(account.created_at).fromNow()}</Table.Td>
                                            <Table.Td ta="right">{formatNumber(account.events_count)}</Table.Td>
                                            <Table.Td>
                                                <Group gap="xs">
                                                    {account.account_verified_at && (
                                                        <Badge size="xs" color="green" variant="light">{t`Verified`}</Badge>
                                                    )}
                                                    {account.mercadopago_connected && (
                                                        <Badge size="xs" color="blue" variant="light">{t`MP`}</Badge>
                                                    )}
                                                </Group>
                                            </Table.Td>
                                        </Table.Tr>
                                    ))}
                                </Table.Tbody>
                            </Table>
                        </Paper>
                    ) : (
                        <Paper shadow="sm" p="xl" radius="md" withBorder>
                            <Stack align="center" gap="xs">
                                <IconBuildingBank size={48} color="var(--mantine-color-dimmed)"/>
                                <Text c="dimmed"><Trans>No accounts yet</Trans></Text>
                            </Stack>
                        </Paper>
                    )}
                </div>
            </Stack>
        </Container>
    );
};

export default AdminDashboard;
