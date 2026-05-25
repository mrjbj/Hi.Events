import {useEffect, useState} from "react";
import {ActionIcon, Group, Text, TextInput, Tooltip} from "@mantine/core";
import {IconGripVertical, IconPlus, IconX} from "@tabler/icons-react";
import {
    closestCenter,
    DndContext,
    PointerSensor,
    TouchSensor,
    UniqueIdentifier,
    useSensor,
    useSensors,
} from "@dnd-kit/core";
import {arrayMove, SortableContext, useSortable, verticalListSortingStrategy} from "@dnd-kit/sortable";
import {CSS} from "@dnd-kit/utilities";
import {t} from "@lingui/macro";
import classes from "./SortableOptionsInput.module.scss";

interface OptionItem {
    id: number;
    value: string;
}

let _idCounter = 0;
const nextId = () => ++_idCounter;

const toItems = (values: string[]): OptionItem[] =>
    values.map(v => ({id: nextId(), value: v}));

interface SortableOptionRowProps {
    item: OptionItem;
    usageCount?: number;
    onRemove: () => void;
}

const SortableOptionRow = ({item, usageCount, onRemove}: SortableOptionRowProps) => {
    const {attributes, listeners, setNodeRef, transform, transition, isDragging} =
        useSortable({id: item.id as UniqueIdentifier});

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
    };

    const inUse = (usageCount ?? 0) > 0;

    return (
        <div
            ref={setNodeRef}
            style={style}
            className={`${classes.optionRow} ${isDragging ? classes.dragging : ''}`}
        >
            <span
                {...attributes}
                {...listeners}
                className={classes.dragHandle}
                aria-label={t`Drag to reorder`}
            >
                <IconGripVertical size={14}/>
            </span>
            <Text size="sm" className={classes.optionLabel}>{item.value}</Text>
            {inUse && (
                <Text size="xs" c="dimmed">
                    {usageCount === 1 ? t`1 answer` : t`${usageCount} answers`}
                </Text>
            )}
            <Tooltip
                label={inUse
                    ? t`Used by ${usageCount} stored answer(s). Removing requires a migration plan.`
                    : t`Remove option`}
                withArrow
                multiline
                w={240}
            >
                <ActionIcon
                    size="xs"
                    variant="subtle"
                    color="red"
                    onClick={onRemove}
                    aria-label={t`Remove option`}
                >
                    <IconX size={12}/>
                </ActionIcon>
            </Tooltip>
        </div>
    );
};

interface SortableOptionsInputProps {
    label?: string;
    value: string[];
    onChange: (value: string[]) => void;
    /** Optional map of option value → usage count. Drives "N answers" badges. */
    usageCounts?: Record<string, number>;
    /**
     * Called when user clicks remove on an option that is in use (count > 0).
     * Caller is expected to gather a migration plan and then apply the remove via the regular
     * onChange when ready. Return true to proceed with immediate removal, false to abort.
     * If not provided, in-use options remove immediately (no migration step).
     */
    onRemoveInUse?: (value: string, usageCount: number) => boolean | Promise<boolean>;
}

export const SortableOptionsInput = ({label, value, onChange, usageCounts, onRemoveInUse}: SortableOptionsInputProps) => {
    const [items, setItems] = useState<OptionItem[]>(() => toItems(value));
    const [inputValue, setInputValue] = useState('');

    // Re-sync internal items when parent's value diverges (e.g. options added via migration modal).
    // Compare by joined string to avoid reference equality false-negatives.
    useEffect(() => {
        const current = items.map(i => i.value).join('\x00');
        const incoming = value.join('\x00');
        if (current !== incoming) {
            setItems(toItems(value));
        }
    }, [value]);

    const sensors = useSensors(useSensor(PointerSensor), useSensor(TouchSensor));

    const handleDragEnd = (event: any) => {
        const {active, over} = event;
        if (over && active.id !== over.id) {
            const oldIndex = items.findIndex(i => i.id === active.id);
            const newIndex = items.findIndex(i => i.id === over.id);
            const reordered = arrayMove(items, oldIndex, newIndex);
            setItems(reordered);
            onChange(reordered.map(i => i.value));
        }
    };

    const handleAdd = () => {
        const trimmed = inputValue.trim();
        if (!trimmed) return;
        const newItem: OptionItem = {id: nextId(), value: trimmed};
        const updated = [...items, newItem];
        setItems(updated);
        onChange(updated.map(i => i.value));
        setInputValue('');
    };

    const handleRemove = async (id: number) => {
        const target = items.find(i => i.id === id);
        if (!target) return;
        const count = usageCounts?.[target.value] ?? 0;
        if (count > 0 && onRemoveInUse) {
            const proceed = await onRemoveInUse(target.value, count);
            if (!proceed) return;
        }
        const updated = items.filter(i => i.id !== id);
        setItems(updated);
        onChange(updated.map(i => i.value));
    };

    const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleAdd();
        }
    };

    return (
        <div>
            {label && (
                <Text component="label" size="sm" fw={500} mb={4} display="block">
                    {label}
                </Text>
            )}
            <Group gap="xs" mb={items.length > 0 ? 'xs' : 0} align="flex-end">
                <TextInput
                    placeholder={t`Type an option and press Enter`}
                    value={inputValue}
                    onChange={e => setInputValue(e.currentTarget.value)}
                    onKeyDown={handleKeyDown}
                    style={{flex: 1}}
                    size="sm"
                />
                <ActionIcon
                    onClick={handleAdd}
                    disabled={!inputValue.trim()}
                    variant="light"
                    size="lg"
                    aria-label={t`Add option`}
                >
                    <IconPlus size={16}/>
                </ActionIcon>
            </Group>

            {items.length > 0 && (
                <DndContext
                    sensors={sensors}
                    collisionDetection={closestCenter}
                    onDragEnd={handleDragEnd}
                >
                    <SortableContext
                        items={items.map(i => i.id) as UniqueIdentifier[]}
                        strategy={verticalListSortingStrategy}
                    >
                        <div className={classes.optionsList}>
                            {items.map(item => (
                                <SortableOptionRow
                                    key={item.id}
                                    item={item}
                                    usageCount={usageCounts?.[item.value]}
                                    onRemove={() => handleRemove(item.id)}
                                />
                            ))}
                        </div>
                    </SortableContext>
                </DndContext>
            )}
        </div>
    );
};
