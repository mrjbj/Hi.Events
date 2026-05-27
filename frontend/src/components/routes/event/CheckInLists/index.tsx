import {PageBody} from "../../../common/PageBody";
import {PageTitle} from "../../../common/PageTitle";
import {t, Plural} from "@lingui/macro";
import {useParams} from "react-router";
import {TableSkeleton} from "../../../common/TableSkeleton";
import {useDisclosure} from "@mantine/hooks";
import {ToolBar} from "../../../common/ToolBar";
import {SearchBarWrapper} from "../../../common/SearchBar";
import {Alert, Button, Collapse, List, Text, UnstyledButton} from "@mantine/core";
import {IconAlertTriangle, IconPlus} from "@tabler/icons-react";
import {useFilterQueryParamSync} from "../../../../hooks/useFilterQueryParamSync.ts";
import {QueryFilters} from "../../../../types.ts";
import {Pagination} from "../../../common/Pagination";
import {useGetEventCheckInLists} from "../../../../queries/useGetCheckInLists.ts";
import {useGetUncoveredCheckInListProducts} from "../../../../queries/useGetUncoveredCheckInListProducts.ts";
import {CheckInListList} from "../../../common/CheckInListList";
import {CreateCheckInListModal} from "../../../modals/CreateCheckInListModal";
import {useState} from "react";

const CheckInLists = () => {
    const {eventId} = useParams();
    const [searchParams, setSearchParams] = useFilterQueryParamSync();
    const {data: checkInListsData} = useGetEventCheckInLists(
        eventId,
        searchParams as QueryFilters,
    );
    const checkInLists = checkInListsData?.data;
    const pagination = checkInListsData?.meta;
    const [createModalOpen, {open: openCreateModal, close: closeCreateModal}] = useDisclosure(false);
    const {data: uncoveredData} = useGetUncoveredCheckInListProducts(eventId);
    const uncoveredProducts = uncoveredData?.data ?? [];
    const [uncoveredExpanded, setUncoveredExpanded] = useState(false);

    return (
        <PageBody>
            <PageTitle
                subheading={t`Set up check-in lists for different entrances, sessions, or days.`}
            >
                {t`Check-In Lists`}
            </PageTitle>

            {uncoveredProducts.length > 0 && (
                <Alert
                    variant="light"
                    color="orange"
                    icon={<IconAlertTriangle size="1rem" />}
                    mb="md"
                >
                    <UnstyledButton
                        type="button"
                        onClick={() => setUncoveredExpanded(v => !v)}
                        style={{textAlign: 'left', width: '100%'}}
                    >
                        <Text size="sm" fw={500}>
                            <Plural
                                value={uncoveredProducts.length}
                                one="# ticket type has sold attendees but isn't on any check-in list — those attendees can't be checked in."
                                other="# ticket types have sold attendees but aren't on any check-in list — those attendees can't be checked in."
                            />
                            {' '}
                            <span style={{textDecoration: 'underline'}}>
                                {uncoveredExpanded ? t`Hide tickets` : t`Show tickets`}
                            </span>
                        </Text>
                    </UnstyledButton>
                    <Collapse in={uncoveredExpanded}>
                        <List size="sm" mt="xs">
                            {uncoveredProducts.map(p => (
                                <List.Item key={p.product_id}>
                                    <b>{p.title}</b>
                                    {' — '}
                                    <Plural
                                        value={p.attendee_count}
                                        one="# attendee"
                                        other="# attendees"
                                    />
                                </List.Item>
                            ))}
                        </List>
                        <Text size="xs" c="dimmed" mt="xs">
                            {t`Edit a check-in list (or create a new one) and add these ticket types to it.`}
                        </Text>
                    </Collapse>
                </Alert>
            )}

            <ToolBar searchComponent={() => (
                <SearchBarWrapper
                    placeholder={t`Search check-in lists...`}
                    setSearchParams={setSearchParams}
                    searchParams={searchParams}
                    pagination={pagination}
                />
            )}>
                <Button
                    leftSection={<IconPlus/>}
                    color={'green'}
                    onClick={openCreateModal}>{t`Create Check-In List`}
                </Button>
            </ToolBar>

            <TableSkeleton isVisible={!checkInLists}/>

            {checkInLists && <CheckInListList
                checkInLists={checkInLists}
                openCreateModal={openCreateModal}
            />}

            {createModalOpen && <CreateCheckInListModal onClose={closeCreateModal}/>}

            {(!!checkInLists?.length && (pagination?.total || 0) >= 20) && (
                <Pagination value={searchParams.pageNumber}
                            onChange={(value) => setSearchParams({pageNumber: value})}
                            total={Number(pagination?.last_page)}
                />
            )}
        </PageBody>
    );
}

export default CheckInLists;
