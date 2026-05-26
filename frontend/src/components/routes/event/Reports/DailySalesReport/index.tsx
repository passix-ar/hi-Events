import {useParams} from "react-router";
import {useGetEvent} from "../../../../../queries/useGetEvent.ts";
import {formatCurrency} from "../../../../../utilites/currency.ts";
import {formatDateWithLocale} from "../../../../../utilites/dates.ts";
import ReportTable from "../../../../common/ReportTable";
import {t} from "@lingui/macro";

export const DailySalesReport = () => {
    const {eventId} = useParams();
    const eventQuery = useGetEvent(eventId);
    const event = eventQuery.data;

    if (!event) {
        return null;
    }

    const columns = [
        {
            key: 'date' as const,
            label: t`Date`,
            sortable: true,
            render: (value: string) => formatDateWithLocale(value, 'shortDate', event?.timezone)
        },
        {
            key: 'sales_total_gross' as const,
            label: t`Sales Total Gross`,
            sortable: true,
            render: (value: string) => formatCurrency(value)
        },
        {
            key: 'total_tax' as const,
            label: t`Total Tax`,
            sortable: true,
            render: (value: string) => formatCurrency(value)
        },
        {
            key: 'sales_total_before_additions' as const,
            label: t`Net Sales`,
            sortable: true,
            render: (value: string) => formatCurrency(value)
        },
        {
            key: 'products_sold' as const,
            label: t`Products Sold`,
            sortable: true
        },
        {
            key: 'orders_created' as const,
            label: t`Completed Orders`,
            sortable: true
        },
        {
            key: 'total_fee' as const,
            label: t`Total Fee`,
            sortable: true,
            render: (value: string) => formatCurrency(value)
        },
        {
            key: 'total_refunded' as const,
            label: t`Total Refunded`,
            sortable: true,
            render: (value: string) => formatCurrency(value)
        },
        {
            key: 'total_views' as const,
            label: t`Total Views`,
            sortable: true
        }
    ];

    return (
        <ReportTable
            title={t`Daily Sales Report`}
            columns={columns}
            isLoading={eventQuery.isLoading}
            downloadFileName="daily_sales_report.csv"
            showDateFilter={true}
            filterRow={(row) => Number(row.orders_created) > 0}
            event={event}
        />
    );
};
