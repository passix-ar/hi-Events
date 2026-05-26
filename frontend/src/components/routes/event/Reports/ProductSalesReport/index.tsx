import {useParams} from "react-router";
import {useGetEvent} from "../../../../../queries/useGetEvent.ts";
import {formatCurrency} from "../../../../../utilites/currency.ts";
import ReportTable from "../../../../common/ReportTable";
import {t} from "@lingui/macro";

const ProductSalesReport = () => {
    const {eventId} = useParams();
    const eventQuery = useGetEvent(eventId);
    const event = eventQuery.data;

    if (!event) {
        return null;
    }

    const columns = [
        {
            key: 'product_title' as const,
            label: t`Product Title`,
            sortable: true
        },
        {
            key: 'number_sold' as const,
            label: t`Units Sold`,
            sortable: true
        },
        {
            key: 'total_gross' as const,
            label: t`Gross Sales`,
            sortable: true,
            render: (value: string) => formatCurrency(value, event?.currency)
        },
        {
            key: 'total_tax' as const,
            label: t`Tax`,
            sortable: true,
            render: (value: string) => formatCurrency(value, event?.currency)
        },
        {
            key: 'total_service_fees' as const,
            label: t`Service Fees`,
            sortable: true,
            render: (value: string) => formatCurrency(value, event?.currency)
        }
    ];

    return (
        <ReportTable
            title={t`Product Sales Report`}
            columns={columns}
            isLoading={eventQuery.isLoading}
            downloadFileName="product_sales_report.csv"
            event={event}
        />
    );
};

export default ProductSalesReport;
