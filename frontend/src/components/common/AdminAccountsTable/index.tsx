import {Badge, Button, Stack, Text} from "@mantine/core";
import {t} from "@lingui/macro";
import {AdminAccount} from "../../../api/admin.client";
import {IconCalendar, IconWorld, IconBuildingBank, IconUsers, IconEye, IconMessage, IconBuildingStore} from "@tabler/icons-react";
import classes from "./AdminAccountsTable.module.scss";
import {IdParam} from "../../../types";
import {useNavigate} from "react-router";

interface AdminAccountsTableProps {
    accounts: AdminAccount[];
    onImpersonate: (userId: IdParam, accountId: IdParam) => void;
    isLoading?: boolean;
}

const AdminAccountsTable = ({accounts, onImpersonate, isLoading}: AdminAccountsTableProps) => {
    const navigate = useNavigate();

    if (!accounts || accounts.length === 0) {
        return (
            <div className={classes.emptyState}>
                <Text size="lg" c="dimmed">{t`No accounts found`}</Text>
            </div>
        );
    }

    const formatDate = (dateString?: string) => {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleDateString();
    };

    const getRoleBadgeColor = (role: string) => {
        switch (role) {
            case 'ADMIN':
                return 'blue';
            case 'ORGANIZER':
                return 'green';
            case 'SUPERADMIN':
                return 'red';
            default:
                return 'gray';
        }
    };

    const canImpersonate = (role: string) => {
        return role !== 'SUPERADMIN';
    };

    const getTierBadgeColor = (tierName?: string) => {
        if (!tierName) return 'gray';
        const name = tierName.toLowerCase();
        if (name.includes('premium')) return 'green';
        if (name.includes('trusted')) return 'blue';
        return 'gray';
    };


    return (
        <div className={classes.cardsContainer}>
            {accounts.map((account) => {
                // An account represents an organization. Lead with the first organization's
                // name, flag how many more it has, and list them all below. Fall back to the
                // raw account name only when no organization exists yet.
                const organizers = account.organizers ?? [];
                const orgCount = account.organizers_count ?? 0;
                const primaryOrganizer = organizers[0];
                const title = primaryOrganizer?.name ?? account.name;
                const extraOrganizers = orgCount - 1;

                return (
                <div key={account.id} className={classes.accountCard}>
                    <div className={classes.cardHeader}>
                        <div className={classes.accountInfo}>
                            <h3 className={classes.accountName}>
                                {title}
                                {extraOrganizers > 0 && (
                                    <Badge size="sm" variant="light" color="green" ml="xs">
                                        {t`+${extraOrganizers} more`}
                                    </Badge>
                                )}
                            </h3>
                            {primaryOrganizer ? (
                                <span className={classes.accountEmail}>
                                    {t`Account`}: {account.name} · {account.email}
                                </span>
                            ) : (
                                <span className={classes.accountEmail}>{account.email}</span>
                            )}
                        </div>
                        <Button
                            size="xs"
                            variant="light"
                            leftSection={<IconEye size={14} />}
                            onClick={() => navigate(`/admin/accounts/${account.id}`)}
                        >
                            {t`View Details`}
                        </Button>
                    </div>

                    <div className={classes.cardBody}>
                        <div className={classes.statsGrid}>
                            <div className={classes.statItem}>
                                <IconBuildingStore size={18} />
                                <Stack gap={2}>
                                    <Text size="xs" c="dimmed">{t`Organizations`}</Text>
                                    <Text size="lg" fw={600}>{account.organizers_count ?? 0}</Text>
                                </Stack>
                            </div>
                            <div className={classes.statItem}>
                                <IconBuildingBank size={18} />
                                <Stack gap={2}>
                                    <Text size="xs" c="dimmed">{t`Events`}</Text>
                                    <Text size="lg" fw={600}>{account.events_count}</Text>
                                </Stack>
                            </div>
                            <div className={classes.statItem}>
                                <IconUsers size={18} />
                                <Stack gap={2}>
                                    <Text size="xs" c="dimmed">{t`Users`}</Text>
                                    <Text size="lg" fw={600}>{account.users_count}</Text>
                                </Stack>
                            </div>
                            <div className={classes.statItem}>
                                <IconMessage size={18} />
                                <Stack gap={2}>
                                    <Text size="xs" c="dimmed">{t`Messaging Tier`}</Text>
                                    <Badge
                                        size="sm"
                                        color={getTierBadgeColor(account.messaging_tier?.name)}
                                    >
                                        {account.messaging_tier?.name || t`Untrusted`}
                                    </Badge>
                                </Stack>
                            </div>
                        </div>

                        {organizers.length > 1 && (
                            <div className={classes.usersSection}>
                                <Text size="sm" fw={600} mb="xs">{t`Organizations`}</Text>
                                <div style={{display: 'flex', flexWrap: 'wrap', gap: 6}}>
                                    {organizers.map((organizer) => (
                                        <Badge key={organizer.id} variant="light" color="green">
                                            {organizer.name}
                                        </Badge>
                                    ))}
                                </div>
                            </div>
                        )}

                        {account.users && account.users.length > 0 && (
                            <div className={classes.usersSection}>
                                <Text size="sm" fw={600} mb="xs">{t`Users`}</Text>
                                <div className={classes.usersList}>
                                    {account.users.map((user) => (
                                        <div key={user.id} className={classes.userItem}>
                                            <div className={classes.userDetails}>
                                                <Text size="sm" fw={500}>
                                                    {user.first_name} {user.last_name}
                                                </Text>
                                                <Text size="xs" c="dimmed">{user.email}</Text>
                                                <Badge
                                                    size="xs"
                                                    color={getRoleBadgeColor(user.role)}
                                                    mt={4}
                                                >
                                                    {user.role}
                                                </Badge>
                                            </div>
                                            {canImpersonate(user.role) && (
                                                <Button
                                                    size="xs"
                                                    variant="light"
                                                    onClick={() => onImpersonate(user.id, account.id)}
                                                    disabled={isLoading}
                                                >
                                                    {t`Impersonate`}
                                                </Button>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        <div className={classes.cardFooter}>
                            <div className={classes.footerInfo}>
                                <div className={classes.footerItem}>
                                    <IconCalendar size={14} />
                                    <span>{formatDate(account.created_at)}</span>
                                </div>
                                {account.timezone && (
                                    <div className={classes.footerItem}>
                                        <IconWorld size={14} />
                                        <span>{account.timezone}</span>
                                    </div>
                                )}
                                {account.currency_code && (
                                    <div className={classes.footerItem}>
                                        <Badge size="sm" variant="light">
                                            {account.currency_code}
                                        </Badge>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
                );
            })}
        </div>
    );
};

export default AdminAccountsTable;
