import {t, Trans} from "@lingui/macro";
import {Alert, Badge, Code, Collapse, Drawer, Group, Stack, Text, UnstyledButton} from "@mantine/core";
import {IconChevronDown, IconChevronRight} from "@tabler/icons-react";
import {useMemo, useState} from "react";
import {IdParam, OutgoingMessageEvent} from "../../../../types.ts";
import {
    MessageEventSource,
    useGetOutgoingMessageEvents,
} from "../../../../queries/useGetOutgoingMessageEvents.ts";
import {TableSkeleton} from "../../../common/TableSkeleton";
import classes from "./EventLogDrawer.module.scss";

interface EventLogDrawerProps {
    eventId: IdParam | null;
    messageId: IdParam | null;
    source: MessageEventSource;
    opened: boolean;
    onClose: () => void;
    recipient?: string;
    subject?: string;
}

const eventTypeColor = (eventType: string) => {
    switch (eventType) {
        case 'Delivery':
        case 'Send':
            return 'green';
        case 'DeliveryDelay':
            return 'yellow';
        case 'Bounce':
        case 'Reject':
            return 'red';
        case 'Complaint':
        case 'RenderingFailure':
            return 'orange';
        case 'Open':
        case 'Click':
            return 'blue';
        default:
            return 'gray';
    }
};

const formatTimestamp = (value: string | null | undefined) => {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString();
};

const unwrapNestedJson = (payload: Record<string, unknown> | null) => {
    if (!payload) return null;
    const copy: Record<string, unknown> = {...payload};
    if (typeof copy.Message === 'string') {
        try {
            copy.Message = JSON.parse(copy.Message);
        } catch {
            // leave as string
        }
    }
    return copy;
};

const prettyPayload = (event: OutgoingMessageEvent) => {
    const unwrapped = unwrapNestedJson(event.raw_payload);
    return unwrapped ? JSON.stringify(unwrapped, null, 2) : null;
};

interface EventGroup {
    key: string;
    eventType: string;
    eventSubtype: string | null;
    events: OutgoingMessageEvent[];
}

const groupEvents = (events: OutgoingMessageEvent[]): EventGroup[] => {
    const groups: EventGroup[] = [];
    for (const event of events) {
        const last = groups[groups.length - 1];
        if (last && last.eventType === event.event_type && last.eventSubtype === event.event_subtype) {
            last.events.push(event);
        } else {
            groups.push({
                key: `${event.event_type}-${event.event_subtype ?? ''}-${event.id}`,
                eventType: event.event_type,
                eventSubtype: event.event_subtype,
                events: [event],
            });
        }
    }
    return groups;
};

interface SingleEventRowProps {
    event: OutgoingMessageEvent;
}

const SingleEventRow = ({event}: SingleEventRowProps) => {
    const [expanded, setExpanded] = useState(false);
    const pretty = useMemo(() => prettyPayload(event), [event]);

    return (
        <div className={classes.eventRow}>
            <UnstyledButton
                onClick={() => setExpanded((v) => !v)}
                className={classes.eventHeader}
                disabled={!pretty}
            >
                <Group gap="xs" wrap="nowrap">
                    {pretty ? (
                        expanded ? <IconChevronDown size={16}/> : <IconChevronRight size={16}/>
                    ) : (
                        <span style={{width: 16}}/>
                    )}
                    <Badge size="sm" color={eventTypeColor(event.event_type)} variant="filled">
                        {event.event_type}
                    </Badge>
                    {event.event_subtype && (
                        <Text size="xs" c="dimmed">{event.event_subtype}</Text>
                    )}
                </Group>
                <Text size="xs" c="dimmed">
                    {formatTimestamp(event.occurred_at ?? event.created_at)}
                </Text>
            </UnstyledButton>

            {pretty && (
                <Collapse in={expanded}>
                    <Code block className={classes.payload}>{pretty}</Code>
                </Collapse>
            )}
        </div>
    );
};

interface GroupRowProps {
    group: EventGroup;
}

const GroupRow = ({group}: GroupRowProps) => {
    const [expanded, setExpanded] = useState(false);
    const first = group.events[0];
    const last = group.events[group.events.length - 1];
    const firstTs = first.occurred_at ?? first.created_at;
    const lastTs = last.occurred_at ?? last.created_at;

    return (
        <div className={classes.eventRow}>
            <UnstyledButton
                onClick={() => setExpanded((v) => !v)}
                className={classes.eventHeader}
            >
                <Group gap="xs" wrap="nowrap">
                    {expanded ? <IconChevronDown size={16}/> : <IconChevronRight size={16}/>}
                    <Badge size="sm" color={eventTypeColor(group.eventType)} variant="filled">
                        {group.eventType}
                    </Badge>
                    {group.eventSubtype && (
                        <Text size="xs" c="dimmed">{group.eventSubtype}</Text>
                    )}
                    <Badge size="xs" color="gray" variant="light">
                        × {group.events.length}
                    </Badge>
                </Group>
                <Text size="xs" c="dimmed">
                    {formatTimestamp(firstTs)} → {formatTimestamp(lastTs)}
                </Text>
            </UnstyledButton>

            <Collapse in={expanded}>
                <Stack gap={4} className={classes.groupBody}>
                    {group.events.map((event) => (
                        <NestedEventRow key={event.id} event={event}/>
                    ))}
                </Stack>
            </Collapse>
        </div>
    );
};

const NestedEventRow = ({event}: SingleEventRowProps) => {
    const [expanded, setExpanded] = useState(false);
    const pretty = useMemo(() => prettyPayload(event), [event]);

    return (
        <div>
            <UnstyledButton
                onClick={() => setExpanded((v) => !v)}
                className={classes.nestedHeader}
                disabled={!pretty}
            >
                <Group gap="xs" wrap="nowrap">
                    {pretty ? (
                        expanded ? <IconChevronDown size={14}/> : <IconChevronRight size={14}/>
                    ) : (
                        <span style={{width: 14}}/>
                    )}
                    <Text size="xs" c="dimmed">
                        {formatTimestamp(event.occurred_at ?? event.created_at)}
                    </Text>
                </Group>
            </UnstyledButton>
            {pretty && (
                <Collapse in={expanded}>
                    <Code block className={classes.payload}>{pretty}</Code>
                </Collapse>
            )}
        </div>
    );
};

export const EventLogDrawer = ({eventId, messageId, source, opened, onClose, recipient, subject}: EventLogDrawerProps) => {
    const query = useGetOutgoingMessageEvents(eventId, source, messageId, opened);
    const events = query.data?.data ?? [];
    const groups = useMemo(() => groupEvents(events), [events]);

    return (
        <Drawer
            opened={opened}
            onClose={onClose}
            position="right"
            size="lg"
            title={(
                <Stack gap={2}>
                    <Text fw={600}>{t`Provider Events`}</Text>
                    {(recipient || subject) && (
                        <Text size="xs" c="dimmed" style={{wordBreak: 'break-all'}}>
                            {subject ? `${subject} · ` : ''}{recipient ?? ''}
                        </Text>
                    )}
                </Stack>
            )}
        >
            {query.isLoading && <TableSkeleton isVisible/>}

            {!!query.error && (
                <Alert color="red" radius="md">
                    {t`Failed to load events.`}
                </Alert>
            )}

            {!query.isLoading && !query.error && events.length === 0 && (
                <Text c="dimmed" ta="center" py="xl">
                    <Trans>No provider events recorded yet.</Trans>
                </Text>
            )}

            {!query.isLoading && !query.error && groups.length > 0 && (
                <Stack gap="xs">
                    {groups.map((group) => (
                        group.events.length === 1
                            ? <SingleEventRow key={group.key} event={group.events[0]}/>
                            : <GroupRow key={group.key} group={group}/>
                    ))}
                </Stack>
            )}
        </Drawer>
    );
};
