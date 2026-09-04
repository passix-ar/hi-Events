import {PageBody} from "../../../common/PageBody";
import {PageTitle} from "../../../common/PageTitle";
import {t} from "@lingui/macro";
import {useParams} from "react-router";
import {useGetEventSeatingSections} from "../../../../queries/useGetSeatingSections.ts";
import {SeatingSectionList} from "../../../common/SeatingSectionList";
import {TableSkeleton} from "../../../common/TableSkeleton";
import {CreateSeatingSectionModal} from "../../../modals/CreateSeatingSectionModal";
import {useDisclosure} from "@mantine/hooks";
import {ToolBar} from "../../../common/ToolBar";
import {SearchBarWrapper} from "../../../common/SearchBar";
import {Button} from "@mantine/core";
import {IconPlus} from "@tabler/icons-react";
import {useFilterQueryParamSync} from "../../../../hooks/useFilterQueryParamSync.ts";
import {QueryFilters} from "../../../../types.ts";
import {Pagination} from "../../../common/Pagination";

const Seating = () => {
    const {eventId} = useParams();
    const [searchParams, setSearchParams] = useFilterQueryParamSync();
    const {data: seatingSectionsData} = useGetEventSeatingSections(
        eventId,
        searchParams as QueryFilters,
    );
    const seatingSections = seatingSectionsData?.data;
    const pagination = seatingSectionsData?.meta
    const [createModalOpen, {open: openCreateModal, close: closeCreateModal}] = useDisclosure(false);

    return (
        <PageBody>
            <PageTitle
                subheading={t`Let ticket buyers choose their own numbered seat by creating seating sections linked to your products.`}
            >
                {t`Assigned Seating`}
            </PageTitle>

            <ToolBar searchComponent={() => (
                <SearchBarWrapper
                    placeholder={t`Search seating sections...`}
                    setSearchParams={setSearchParams}
                    searchParams={searchParams}
                    pagination={pagination}
                />
            )}>
                <Button
                    leftSection={<IconPlus/>}
                    color={'green'}
                    onClick={() => openCreateModal()}>{t`Create Seating Section`}
                </Button>
            </ToolBar>

            <TableSkeleton isVisible={!seatingSections}/>

            {seatingSections && <SeatingSectionList
                seatingSections={seatingSections}
                openCreateModal={openCreateModal}
            />}

            {createModalOpen && <CreateSeatingSectionModal onClose={closeCreateModal}/>}

            {(!!seatingSections?.length && (pagination?.total || 0) >= 20) && (
                <Pagination value={searchParams.pageNumber}
                            onChange={(value) => setSearchParams({pageNumber: value})}
                            total={Number(pagination?.last_page)}
                />
            )}
        </PageBody>
    );
}

export default Seating;
