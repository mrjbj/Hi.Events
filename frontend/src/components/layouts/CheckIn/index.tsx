import {useParams} from "react-router";
import {useGetCheckInListPublic} from "../../../queries/useGetCheckInListPublic.ts";
import {useCallback, useEffect, useMemo, useRef, useState} from "react";
import {useDebouncedValue, useDisclosure, useNetwork} from "@mantine/hooks";
import {modals} from "@mantine/modals";
import {Attendee, QueryFilters, QueryFilterOperator} from "../../../types.ts";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import {t, Trans} from "@lingui/macro";
import {AxiosError} from "axios";
import classes from "./CheckIn.module.scss";
import {ActionIcon, Menu, Modal, Badge as MantineBadge, Group, Select, Text, UnstyledButton} from "@mantine/core";
import {useNavigate} from "react-router";
import {SearchBar} from "../../common/SearchBar";
import {IconArmchair, IconCheck, IconChevronDown, IconFilterOff, IconInfoCircle, IconQrcode, IconUsersGroup, IconVolume, IconVolumeOff} from "@tabler/icons-react";
import {useGetCheckInListFilterOptionsPublic} from "../../../queries/useGetCheckInListFilterOptionsPublic.ts";
import {useGetCheckInListSiblingsPublic} from "../../../queries/useGetCheckInListSiblingsPublic.ts";
import {QRScannerComponent} from "../../common/AttendeeCheckInTable/QrScanner.tsx";
import {useGetCheckInListAttendees} from "../../../queries/useGetCheckInListAttendeesPublic.ts";
import {useCreateCheckInPublic} from "../../../mutations/useCreateCheckInPublic.ts";
import {useDeleteCheckInPublic} from "../../../mutations/useDeleteCheckInPublic.ts";
import {NoResultsSplash} from "../../common/NoResultsSplash";
import {Countdown} from "../../common/Countdown";
import Truncate from "../../common/Truncate";
import {Header} from "../../common/Header";
import {publicCheckInClient} from "../../../api/check-in.client.ts";
import {isSsr} from "../../../utilites/helpers.ts";
import {AttendeeList} from "../../common/CheckIn/AttendeeList";
import {CheckInOptionsModal} from "../../common/CheckIn/CheckInOptionsModal";
import {AttendeeProfileModal} from "../../common/CheckIn/AttendeeProfileModal";
import {CaptureAttendeeOnArrivalModal} from "../../common/CheckIn/CaptureAttendeeOnArrivalModal";
import {ScannerSelectionModal} from "../../common/CheckIn/ScannerSelectionModal";
import {CheckInInfoModal} from "../../common/CheckIn/CheckInInfoModal";
import {HidScannerStatus} from "../../common/CheckIn/HidScannerStatus";
import {Button} from "@mantine/core";

const CheckIn = () => {
    const navigate = useNavigate();
    const networkStatus = useNetwork();
    const {checkInListShortId} = useParams();
    const CheckInListQuery = useGetCheckInListPublic(checkInListShortId);
    const checkInList = CheckInListQuery?.data?.data;
    const event = checkInList?.event;
    const eventSettings = event?.settings;
    const [searchQuery, setSearchQuery] = useState('');
    const [searchQueryDebounced] = useDebouncedValue(searchQuery, 400);
    const [qrScannerOpen, setQrScannerOpen] = useState(false);
    const [scannerSelectionOpen, setScannerSelectionOpen] = useState(false);
    const [hidScannerMode, setHidScannerMode] = useState(false);
    const [currentBarcode, setCurrentBarcode] = useState('');
    const [pageHasFocus, setPageHasFocus] = useState(true);
    const barcodeTimeoutRef = useRef<NodeJS.Timeout | null>(null);
    const isProcessingRef = useRef(false);
    const processedBarcodesRef = useRef<Set<string>>(new Set());
    const lastScanTimeRef = useRef<number>(0);
    const scanSuccessAudioRef = useRef<HTMLAudioElement | null>(null);
    const scanErrorAudioRef = useRef<HTMLAudioElement | null>(null);
    const [isSoundOn, setIsSoundOn] = useState(() => {
        if (isSsr()) return true;
        // Use a unified sound setting for all scanners
        const storedIsSoundOn = localStorage.getItem("scannerSoundOn");
        return storedIsSoundOn === null ? true : JSON.parse(storedIsSoundOn);
    });
    const [selectedAttendee, setSelectedAttendee] = useState<Attendee | null>(null);
    const [checkInModalOpen, checkInModalHandlers] = useDisclosure(false);
    const [editingAttendee, setEditingAttendee] = useState<Attendee | null>(null);
    const [profileModalOpen, profileModalHandlers] = useDisclosure(false);
    const [captureAttendee, setCaptureAttendee] = useState<Attendee | null>(null);
    const [captureModalOpen, captureModalHandlers] = useDisclosure(false);
    // Mutually-exclusive server-side filter for the attendee list. Clicking a
    // Group or Table badge (or picking from the dropdowns above the list) sets
    // this filter and pushes it into the API query — so results reflect the
    // entire DB, not just the current page. Selecting a different filter
    // replaces the prior one.
    const [attendeeFilter, setAttendeeFilter] = useState<
        | { type: 'group'; orderId: number; label: string }
        | { type: 'table'; seatInfo: string }
        | null
    >(null);
    const [infoModalOpen, infoModalHandlers] = useDisclosure(false, {
            onOpen: () => {
                CheckInListQuery.refetch();
            }
        }
    );

    const products = checkInList?.products;
    const allowOrdersAwaitingOfflinePaymentToCheckIn = Boolean(
        eventSettings?.allow_orders_awaiting_offline_payment_to_check_in,
    );
    const queryFilters: QueryFilters = {
        pageNumber: 1,
        query: searchQueryDebounced,
        perPage: 150,
        filterFields: {
            status: allowOrdersAwaitingOfflinePaymentToCheckIn
                ? {operator: QueryFilterOperator.In, value: 'ACTIVE,AWAITING_PAYMENT'}
                : {operator: QueryFilterOperator.Equals, value: 'ACTIVE'},
            ...(attendeeFilter?.type === 'group'
                ? {order_id: {operator: QueryFilterOperator.Equals, value: attendeeFilter.orderId}}
                : {}),
            ...(attendeeFilter?.type === 'table'
                ? {seat_info: {operator: QueryFilterOperator.Equals, value: attendeeFilter.seatInfo}}
                : {}),
        },
    };

    const attendeesQuery = useGetCheckInListAttendees(
        checkInListShortId,
        queryFilters,
        checkInList?.is_active && !checkInList?.is_expired,
    );
    const attendees = attendeesQuery?.data?.data;
    const filterOptionsQuery = useGetCheckInListFilterOptionsPublic(
        checkInListShortId,
        Boolean(checkInList?.is_active && !checkInList?.is_expired),
    );
    const filterOptions = filterOptionsQuery.data?.data;
    const siblingsQuery = useGetCheckInListSiblingsPublic(
        checkInListShortId,
        Boolean(checkInList),
    );
    const siblings = siblingsQuery.data?.data ?? [];
    const hasOtherLists = siblings.some(s => s.short_id !== checkInListShortId);
    const checkInMutation = useCreateCheckInPublic(queryFilters);
    const deleteCheckInMutation = useDeleteCheckInPublic(queryFilters);

    // Stable identity for the active filter. Used to decide when we're in a
    // new "filter cycle" for the clear-filter-on-empty-search prompt below.
    const activeFilterKey = useMemo(() => {
        if (!attendeeFilter) return null;
        return attendeeFilter.type === 'group'
            ? `group:${attendeeFilter.orderId}`
            : `table:${attendeeFilter.seatInfo}`;
    }, [attendeeFilter]);

    // One-shot prompt: when the attendee list comes back empty AND a
    // group/table filter is active AND the user has typed a search, offer to
    // clear the filter. Show at most once per filter cycle so fast typing
    // doesn't re-trigger the modal. The prompt resets when the filter changes
    // or is cleared (so a fresh filter pick can re-trigger).
    const clearFilterPromptShownRef = useRef<string | null>(null);

    useEffect(() => {
        clearFilterPromptShownRef.current = null;
    }, [activeFilterKey]);

    useEffect(() => {
        if (!activeFilterKey) return;
        if (attendeesQuery.isFetching) return;
        if (!Array.isArray(attendees) || attendees.length > 0) return;
        const trimmed = searchQueryDebounced.trim();
        if (!trimmed) return;
        if (clearFilterPromptShownRef.current === activeFilterKey) return;

        clearFilterPromptShownRef.current = activeFilterKey;

        modals.openConfirmModal({
            title: t`Clear filter to find attendees?`,
            children: (
                <Text size="sm">
                    {t`No matches in this filter for "${trimmed}". Clear the filter and search across all attendees?`}
                </Text>
            ),
            labels: {confirm: t`Clear filter & search`, cancel: t`Keep filter`},
            confirmProps: {color: 'violet', 'data-autofocus': true},
            onConfirm: () => setAttendeeFilter(null),
        });
    }, [activeFilterKey, attendees, attendeesQuery.isFetching, searchQueryDebounced]);

    // Save sound preference to localStorage
    useEffect(() => {
        if (!isSsr()) {
            localStorage.setItem("scannerSoundOn", JSON.stringify(isSoundOn));
        }
    }, [isSoundOn]);

    // Sound helpers
    const playSuccessSound = useCallback(() => {
        if (isSoundOn && scanSuccessAudioRef.current) {
            scanSuccessAudioRef.current.play().catch(() => {
                // Ignore audio play errors (e.g., user hasn't interacted with page)
            });
        }
    }, [isSoundOn]);

    const playErrorSound = useCallback(() => {
        if (isSoundOn && scanErrorAudioRef.current) {
            scanErrorAudioRef.current.play().catch(() => {
                // Ignore audio play errors (e.g., user hasn't interacted with page)
            });
        }
    }, [isSoundOn]);

    const playClickSound = useCallback(() => {
        if (isSoundOn && scanSuccessAudioRef.current) {
            // Use success sound for click feedback
            scanSuccessAudioRef.current.currentTime = 0; // Reset to start for quick successive clicks
            scanSuccessAudioRef.current.play().catch(() => {
                // Ignore audio play errors
            });
        }
    }, [isSoundOn]);

    const handleCheckInAction = (
        attendee: Attendee,
        action: 'check-in' | 'check-in-and-mark-order-as-paid',
        payment?: {
            payment_method: string;
            payment_reference?: string | null;
            collected_amount?: number | null;
        },
    ) => {
        checkInMutation.mutate({
            checkInListShortId: checkInListShortId,
            attendeePublicId: attendee.public_id,
            action: action,
            payment,
        }, {
            onSuccess: ({errors}) => {
                if (errors && errors[attendee.public_id]) {
                    showError(errors[attendee.public_id]);
                    playErrorSound();
                    return;
                }
                showSuccess(<Trans>{attendee.first_name} <b>checked in</b> successfully</Trans>);
                playSuccessSound();
                checkInModalHandlers.close();
                setSelectedAttendee(null);
            },
            onError: (error) => {
                playErrorSound();
                if (!networkStatus.online) {
                    showError(t`You are offline`);
                    return;
                }

                if (error instanceof AxiosError) {
                    showError(error?.response?.data?.message || t`Unable to check in attendee`);
                }
            }
        });
    };

    const handleCheckInToggle = (attendee: Attendee) => {
        if (attendee.check_in) {
            deleteCheckInMutation.mutate({
                checkInListShortId: checkInListShortId,
                checkInShortId: attendee.check_in.short_id,
            }, {
                onSuccess: () => {
                    showSuccess(<Trans>{attendee.first_name} <b>checked out</b> successfully</Trans>);
                    playSuccessSound();
                },
                onError: (error) => {
                    playErrorSound();
                    if (!networkStatus.online) {
                        showError(t`You are offline`);
                        return;
                    }

                    if (error instanceof AxiosError) {
                        showError(error?.response?.data?.message || t`Unable to check out attendee`);
                    } else {
                        showError(t`Unable to check out attendee`);
                    }
                }
            });
            return;
        }

        const isAttendeeAwaitingPayment = attendee.status === 'AWAITING_PAYMENT';

        if (allowOrdersAwaitingOfflinePaymentToCheckIn && isAttendeeAwaitingPayment) {
            setSelectedAttendee(attendee);
            checkInModalHandlers.open();
            return;
        }

        if (!allowOrdersAwaitingOfflinePaymentToCheckIn && isAttendeeAwaitingPayment) {
            showError(t`You cannot check in attendees with unpaid orders. This setting can be changed in the event settings.`);
            return;
        }

        // Gate: when an attendee was bundled and never had their details
        // captured, door staff must fill in name + email + required questions
        // before the check-in lands.
        if (attendee.profile_completion_recommended) {
            setCaptureAttendee(attendee);
            captureModalHandlers.open();
            return;
        }

        handleCheckInAction(attendee, 'check-in');
    };

    const handleQrCheckIn = useCallback(async (attendeePublicId: string) => {
        // Prevent processing if already handling a request
        if (isProcessingRef.current) {
            return;
        }

        // Check if this barcode was recently processed (within last 3 seconds)
        const now = Date.now();
        if (processedBarcodesRef.current.has(attendeePublicId) &&
            now - lastScanTimeRef.current < 3000) {
            showError(t`This ticket was just scanned. Please wait before scanning again.`);
            playErrorSound();
            return;
        }

        isProcessingRef.current = true;
        lastScanTimeRef.current = now;

        // Find the attendee in the current list or fetch them
        let attendee = attendees?.find(a => a.public_id === attendeePublicId);

        if (!attendee) {
            try {
                const {data} = await publicCheckInClient.getCheckInListAttendee(checkInListShortId, attendeePublicId);
                attendee = data;
            } catch (error) {
                showError(t`Unable to fetch attendee`);
                playErrorSound();
                isProcessingRef.current = false;
                return;
            }

            if (!attendee) {
                showError(t`Attendee not found`);
                playErrorSound();
                isProcessingRef.current = false;
                return;
            }
        }

        // Check if already checked in
        if (attendee.check_in) {
            showError(<Trans>{attendee.first_name} {attendee.last_name} is already checked in</Trans>);
            playErrorSound();
            processedBarcodesRef.current.add(attendeePublicId);
            isProcessingRef.current = false;
            return;
        }

        const isAttendeeAwaitingPayment = attendee.status === 'AWAITING_PAYMENT';

        if (allowOrdersAwaitingOfflinePaymentToCheckIn && isAttendeeAwaitingPayment) {
            setSelectedAttendee(attendee);
            checkInModalHandlers.open();
            isProcessingRef.current = false;
            return;
        }

        if (!allowOrdersAwaitingOfflinePaymentToCheckIn && isAttendeeAwaitingPayment) {
            showError(t`You cannot check in attendees with unpaid orders. This setting can be changed in the event settings.`);
            playErrorSound();
            isProcessingRef.current = false;
            return;
        }

        // Add to processed set before making the request
        processedBarcodesRef.current.add(attendeePublicId);

        // Clear old entries from the set after 10 seconds
        setTimeout(() => {
            processedBarcodesRef.current.delete(attendeePublicId);
        }, 10000);

        await handleCheckInAction(attendee, 'check-in');
        isProcessingRef.current = false;
    }, [attendees, checkInListShortId, allowOrdersAwaitingOfflinePaymentToCheckIn, checkInModalHandlers, handleCheckInAction, playErrorSound]);


    // Process completed barcode
    const processBarcode = useCallback((barcode: string) => {
        if (barcode.startsWith('A-') && barcode.length > 3) {
            handleQrCheckIn(barcode);
        }
    }, [handleQrCheckIn]);

    // Track page focus
    useEffect(() => {
        const handleFocus = () => setPageHasFocus(true);
        const handleBlur = () => setPageHasFocus(false);

        window.addEventListener('focus', handleFocus);
        window.addEventListener('blur', handleBlur);

        return () => {
            window.removeEventListener('focus', handleFocus);
            window.removeEventListener('blur', handleBlur);
        };
    }, []);

    // Global keyboard listener for HID scanner mode
    useEffect(() => {
        if (!hidScannerMode) return;

        const handleKeyPress = (e: KeyboardEvent) => {
            // Ignore if user is typing in an input field
            if (e.target instanceof HTMLInputElement ||
                e.target instanceof HTMLTextAreaElement) {
                return;
            }

            if (e.key === 'Enter') {
                // Process the accumulated barcode on Enter
                if (currentBarcode.length > 0) {
                    processBarcode(currentBarcode);
                    setCurrentBarcode('');
                }
            } else if (e.key.length === 1) {
                // Accumulate characters
                setCurrentBarcode(prev => {
                    const newBarcode = prev + e.key;

                    // Clear any existing timeout
                    if (barcodeTimeoutRef.current) {
                        clearTimeout(barcodeTimeoutRef.current);
                    }

                    // Set timeout to clear barcode if no more input (scanner stopped)
                    barcodeTimeoutRef.current = setTimeout(() => {
                        // Auto-process if it looks like a complete barcode
                        if (newBarcode.startsWith('A-') && newBarcode.length > 3) {
                            processBarcode(newBarcode);
                        }
                        setCurrentBarcode('');
                    }, 100);

                    return newBarcode;
                });
            }
        };

        window.addEventListener('keypress', handleKeyPress);

        return () => {
            window.removeEventListener('keypress', handleKeyPress);
            if (barcodeTimeoutRef.current) {
                clearTimeout(barcodeTimeoutRef.current);
            }
        };
    }, [hidScannerMode, currentBarcode, processBarcode]);

    if (CheckInListQuery.error && (CheckInListQuery.error as any).response?.status === 404) {
        return (
            <NoResultsSplash
                heading={t`Check-in list not found`}
                imageHref={'/blank-slate/check-in-lists.svg'}
                subHeading={(
                    <>
                        <p>
                            {t`The check-in list you are looking for does not exist.`}
                        </p>
                    </>
                )}
            />)
    }

    if (checkInList?.is_expired) {
        return (
            <NoResultsSplash
                heading={t`Check-in list has expired`}
                imageHref={'/blank-slate/check-in-lists.svg'}
                subHeading={(
                    <>
                        <p>
                            <Trans>
                                This check-in list has expired and is no longer available for check-ins.
                            </Trans>
                        </p>
                    </>
                )}
            />)
    }

    if (checkInList && !checkInList?.is_active) {
        return (
            <NoResultsSplash
                heading={t`Check-in list is not active`}
                imageHref={'/blank-slate/check-in-lists.svg'}
                subHeading={(
                    <>
                        <p>
                            {t`This check-in list is not yet active and is not available for check-ins.`}
                        </p>
                        <p>
                            Check-in list will activate in{' '}<br/>
                            <b>
                                <Countdown
                                    targetDate={checkInList.activates_at as string}
                                    onExpiry={() => CheckInListQuery.refetch()}
                                />
                            </b>
                        </p>
                    </>
                )}
            />)
    }

    return (
        <div className={classes.container}>
            <Header
                fullWidth
                rightContent={(
                    <>
                        {!networkStatus.online && (
                            <div className={classes.offline}/>
                        )}
                        <ActionIcon
                            display={'flex'}
                            variant={'transparent'}
                            color={'white'}
                            onClick={() => infoModalHandlers.open()}
                        >
                            <IconInfoCircle/>
                        </ActionIcon>
                    </>
                )}/>
            <HidScannerStatus
                isActive={hidScannerMode}
                pageHasFocus={pageHasFocus}
                onDisable={() => setHidScannerMode(false)}
            />
            <div className={classes.header}>
                <div>
                    {hasOtherLists ? (
                        <Menu shadow="md" position="bottom-start" width={260}>
                            <Menu.Target>
                                <UnstyledButton
                                    className={classes.titleButton}
                                    aria-label={t`Switch check-in list`}
                                >
                                    <h4 className={classes.title}>
                                        <Truncate text={checkInList?.name} length={30}/>
                                        <IconChevronDown size={16} className={classes.titleChevron}/>
                                    </h4>
                                </UnstyledButton>
                            </Menu.Target>
                            <Menu.Dropdown>
                                <Menu.Label>{t`Switch check-in list`}</Menu.Label>
                                {siblings.map(sibling => {
                                    const isCurrent = sibling.short_id === checkInListShortId;
                                    return (
                                        <Menu.Item
                                            key={sibling.short_id}
                                            leftSection={isCurrent ? <IconCheck size={14}/> : <span style={{display: 'inline-block', width: 14}}/>}
                                            disabled={isCurrent}
                                            onClick={() => {
                                                if (isCurrent) return;
                                                navigate(`/check-in/${sibling.short_id}`);
                                            }}
                                        >
                                            <Text size="sm" fw={isCurrent ? 600 : 400}>
                                                {sibling.name}
                                            </Text>
                                            {(sibling.is_expired || !sibling.is_active) && (
                                                <Text size="xs" c="dimmed">
                                                    {sibling.is_expired ? t`Expired` : t`Not yet active`}
                                                </Text>
                                            )}
                                        </Menu.Item>
                                    );
                                })}
                            </Menu.Dropdown>
                        </Menu>
                    ) : (
                        <h4 className={classes.title}>
                            <Truncate text={checkInList?.name} length={30}/>
                        </h4>
                    )}
                </div>
                <div className={classes.search}>
                    <div className={classes.searchBar}>
                        <SearchBar
                            className={classes.searchInput}
                            value={searchQuery}
                            onChange={(event) => setSearchQuery(event.target.value)}
                            onClear={() => setSearchQuery('')}
                            onKeyDown={(event) => {
                                if (event.key !== 'Escape') return;
                                if (searchQuery !== '') {
                                    event.preventDefault();
                                    setSearchQuery('');
                                } else if (attendeeFilter !== null) {
                                    event.preventDefault();
                                    setAttendeeFilter(null);
                                }
                            }}
                            placeholder={t`Search by name, order #, attendee # or email...`}
                        />
                        <Select
                            className={classes.filterSelectGroup}
                            size="md"
                            placeholder={t`Group`}
                            value={attendeeFilter?.type === 'group' ? String(attendeeFilter.orderId) : null}
                            data={(filterOptions?.groups ?? []).map(g => ({
                                value: String(g.order_id),
                                label: g.label || t`Group purchase`,
                            }))}
                            onChange={(value) => {
                                if (!value) {
                                    if (attendeeFilter?.type === 'group') setAttendeeFilter(null);
                                    return;
                                }
                                const match = filterOptions?.groups.find(g => String(g.order_id) === value);
                                setAttendeeFilter({
                                    type: 'group',
                                    orderId: Number(value),
                                    label: match?.label || t`Group purchase`,
                                });
                            }}
                            leftSection={<IconUsersGroup size={16}/>}
                            clearable
                            searchable
                            disabled={!filterOptions || filterOptions.groups.length === 0}
                            aria-label={t`Filter by group purchase`}
                        />
                        <Select
                            className={classes.filterSelect}
                            size="md"
                            placeholder={t`Table`}
                            value={attendeeFilter?.type === 'table' ? attendeeFilter.seatInfo : null}
                            data={filterOptions?.tables ?? []}
                            onChange={(value) => {
                                if (!value) {
                                    if (attendeeFilter?.type === 'table') setAttendeeFilter(null);
                                    return;
                                }
                                setAttendeeFilter({type: 'table', seatInfo: value});
                            }}
                            leftSection={<IconArmchair size={16}/>}
                            clearable
                            searchable
                            disabled={!filterOptions || filterOptions.tables.length === 0}
                            aria-label={t`Filter by table`}
                        />
                        <Button variant={'light'} size={'md'} className={classes.scanButton}
                                onClick={() => setScannerSelectionOpen(true)} leftSection={<IconQrcode/>}>
                            {t`Scan`}
                        </Button>
                        <ActionIcon
                            aria-label={isSoundOn ? t`Turn sound off` : t`Turn sound on`}
                            variant={'light'}
                            size={'xl'}
                            onClick={() => setIsSoundOn(!isSoundOn)}
                        >
                            {isSoundOn ? <IconVolume size={24}/> : <IconVolumeOff size={24}/>}
                        </ActionIcon>
                        <ActionIcon aria-label={t`Scan`} variant={'light'} size={'xl'}
                                    className={classes.scanIcon}
                                    onClick={() => setScannerSelectionOpen(true)}>
                            <IconQrcode size={32}/>
                        </ActionIcon>
                    </div>
                </div>
            </div>
            {attendeeFilter && (
                <Group align="center" mb="sm" px="sm" py="xs" gap="xs"
                       style={{background: 'var(--mantine-color-violet-0)', borderRadius: 8}}>
                    {attendeeFilter.type === 'group' ? (
                        <>
                            <IconUsersGroup size={16}/>
                            <Text size="sm">
                                {t`Showing group:`} <b>{attendeeFilter.label}</b>
                            </Text>
                        </>
                    ) : (
                        <>
                            <IconArmchair size={16}/>
                            <Text size="sm">
                                {t`Showing table:`} <b>{attendeeFilter.seatInfo}</b>
                            </Text>
                        </>
                    )}
                    <MantineBadge color="violet" variant="light" size="sm">
                        {attendees?.length ?? 0}
                    </MantineBadge>
                    <ActionIcon
                        variant="subtle"
                        color="gray"
                        size="sm"
                        aria-label={t`Clear filter`}
                        onClick={() => setAttendeeFilter(null)}
                    >
                        <IconFilterOff size={16}/>
                    </ActionIcon>
                </Group>
            )}
            <AttendeeList
                attendees={attendees}
                products={products}
                isLoading={attendeesQuery.isFetching}
                isCheckInPending={checkInMutation.isPending}
                isDeletePending={deleteCheckInMutation.isPending}
                allowOrdersAwaitingOfflinePaymentToCheckIn={allowOrdersAwaitingOfflinePaymentToCheckIn || false}
                hasActiveFilter={!!attendeeFilter}
                searchQuery={searchQueryDebounced}
                onClearFilter={() => setAttendeeFilter(null)}
                onCheckInToggle={handleCheckInToggle}
                onEditAttendee={(attendee) => {
                    setEditingAttendee(attendee);
                    profileModalHandlers.open();
                }}
                onFilterByGroup={(attendee) => {
                    // Prefer the rich label from filterOptions (already includes
                    // shortcode suffix + ticket count) so the banner matches the
                    // dropdown. Fall back to buyer name / email if the options
                    // query hasn't resolved yet.
                    const match = filterOptions?.groups.find(g => g.order_id === attendee.order_id);
                    const buyer = [attendee.buyer_first_name, attendee.buyer_last_name]
                        .filter(Boolean).join(' ').trim();
                    setSearchQuery('');
                    setAttendeeFilter({
                        type: 'group',
                        orderId: attendee.order_id,
                        label: match?.label || buyer || attendee.buyer_email || t`Group purchase`,
                    });
                }}
                onFilterByTable={(seatInfo) => {
                    setSearchQuery('');
                    setAttendeeFilter({type: 'table', seatInfo});
                }}
                onClickSound={playClickSound}
            />
            <CheckInOptionsModal
                isOpen={checkInModalOpen}
                attendee={selectedAttendee}
                isPending={checkInMutation.isPending}
                onClose={() => {
                    checkInModalHandlers.close();
                    setSelectedAttendee(null);
                }}
                onCheckIn={(action) => selectedAttendee && handleCheckInAction(selectedAttendee, action)}
                onCheckInAndMarkAsPaid={(payment) =>
                    selectedAttendee && handleCheckInAction(selectedAttendee, 'check-in-and-mark-order-as-paid', payment)
                }
            />
            <AttendeeProfileModal
                opened={profileModalOpen}
                attendee={editingAttendee}
                eventId={typeof event?.id === 'string' ? Number(event.id) : event?.id}
                checkInListShortId={checkInListShortId}
                onClose={() => {
                    profileModalHandlers.close();
                    setEditingAttendee(null);
                }}
            />
            <CaptureAttendeeOnArrivalModal
                opened={captureModalOpen}
                attendee={captureAttendee}
                eventId={typeof event?.id === 'string' ? Number(event.id) : event?.id}
                checkInListShortId={checkInListShortId}
                onCheckInConfirmed={async (updatedAttendee) => {
                    // Fire the actual check-in after capture saves landed.
                    // handleCheckInAction is fire-and-forget; the user sees
                    // a success toast from inside its onSuccess.
                    handleCheckInAction(updatedAttendee, 'check-in');
                }}
                onClose={() => {
                    captureModalHandlers.close();
                    setCaptureAttendee(null);
                }}
            />
            <ScannerSelectionModal
                isOpen={scannerSelectionOpen}
                isHidScannerActive={hidScannerMode}
                onClose={() => setScannerSelectionOpen(false)}
                onCameraSelect={() => {
                    setScannerSelectionOpen(false);
                    setQrScannerOpen(true);
                }}
                onHidScannerSelect={() => {
                    setScannerSelectionOpen(false);
                    if (!hidScannerMode) {
                        setHidScannerMode(true);
                    }
                }}
            />
            {qrScannerOpen && (
                <Modal.Root
                    opened
                    onClose={() => setQrScannerOpen(false)}
                    fullScreen
                    radius={0}
                    transitionProps={{transition: 'fade', duration: 200}}
                    padding={'none'}
                >
                    <Modal.Overlay/>
                    <Modal.Content>
                        <QRScannerComponent
                            onAttendeeScanned={handleQrCheckIn}
                            onClose={() => setQrScannerOpen(false)}
                            isSoundOn={isSoundOn}
                        />
                    </Modal.Content>
                </Modal.Root>
            )}
            <CheckInInfoModal
                isOpen={infoModalOpen}
                checkInList={checkInList}
                onClose={infoModalHandlers.close}
            />
            {/* Audio elements for HID scanner sounds */}
            <audio ref={scanSuccessAudioRef} src="/sounds/scan-success.wav"/>
            <audio ref={scanErrorAudioRef} src="/sounds/scan-error.wav"/>
        </div>
    );
}

export default CheckIn;
