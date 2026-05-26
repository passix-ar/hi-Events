import {Link, useParams} from "react-router";
import {PageBody} from "../../../../common/PageBody";
import {Button} from "@mantine/core";
import {IconChevronLeft} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import ProductSalesReport from "../ProductSalesReport";
import {ReportTypes} from "../../../../../types.ts";
import {DailySalesReport} from "../DailySalesReport";
import PromoCodesReport from "../PromoCodesReport";

const renderReport = (reportType: string) => {
    switch (reportType) {
        case ReportTypes.ProductSales:
            return <ProductSalesReport/>;
        case ReportTypes.DailySales:
            return <DailySalesReport/>;
        case ReportTypes.PromoCodes:
            return <PromoCodesReport/>;
        default:
            return <div>{t`Report not found`}</div>;
    }
};

const ReportLayout = () => {
    const {eventId, reportType} = useParams();

    return (
        <PageBody>
            <Button mb={20}
                    leftSection={<IconChevronLeft/>}
                    variant={'transparent'}
                    component={Link}
                    to={`/manage/event/${eventId}/reports`}
                    pl={0}
            >
                {t`Back to Reports`}
            </Button>
            <div>
                {renderReport(reportType as string)}
            </div>
        </PageBody>
    );
}

export default ReportLayout;
