import {TextInput, TextInputProps} from '@mantine/core';
import {IconSearch, IconX} from '@tabler/icons-react';
import classes from './SearchBar.module.scss';
import {useEffect, useRef, useState} from "react";
import {SortSelector, SortSelectorProps} from "../SortSelector";
import {t} from "@lingui/macro";
import classNames from "classnames";
import {PaginationData, QueryFilters} from "../../../types.ts";

interface SearchBarProps extends TextInputProps {
    onClear: () => void;
    sortProps?: SortSelectorProps | undefined,
    inputRef?: React.Ref<HTMLInputElement>,
}

interface SearchBarWrapperProps {
    placeholder?: string,
    setSearchParams: (updates: Partial<QueryFilters>) => void,
    searchParams: Partial<QueryFilters>,
    pagination?: PaginationData,
}

export const SearchBarWrapper = ({setSearchParams, searchParams, pagination, placeholder}: SearchBarWrapperProps) => {
    return (
        <SearchBar
            value={searchParams.query}
            onChange={(event) => {
                setSearchParams({
                    query: event.target.value,
                    pageNumber: 1,
                });
            }}
            onClear={() => setSearchParams({
                query: '',
                pageNumber: 1,
            })}
            placeholder={placeholder || t`Search...`}
            sortProps={pagination ? {
                selected: searchParams.sortBy && searchParams.sortDirection
                    ? searchParams.sortBy + ':' + searchParams.sortDirection
                    : pagination?.default_sort + ':' + pagination?.default_sort_direction,
                options: pagination?.allowed_sorts,
                onSortSelect: (key, sortDirection) => {
                    setSearchParams({
                        sortBy: key,
                        sortDirection: sortDirection,
                    })
                },
            } : undefined}
        />
    );
}

export const SearchBar = ({sortProps, onClear, value, onChange, inputRef, ...props}: SearchBarProps) => {
    const [searchValue, setSearchValue] = useState<typeof value>(value);
    const isFocused = useRef(false);

    // The parent `value` is URL-backed and debounced (~300ms), so we mirror it
    // into local state for instant typing. But never overwrite local state from
    // that lagging value WHILE the field is focused — otherwise a value that
    // arrives mid-interaction (e.g. when a row menu re-renders the page) can
    // clobber what the user is typing. Deliberate clears (empty value) still
    // apply even when focused.
    useEffect(() => {
        if (!isFocused.current || !value) {
            setSearchValue(value);
        }
    }, [value])

    return (
        <div className={classNames(classes.searchBarWrapper, props.className)}>
            <TextInput
                className={classes.searchBar}
                leftSection={<IconSearch size="1.1rem" stroke={1.5}/>}
                radius="sm"
                size="md"
                value={searchValue}
                ref={inputRef}
                {...props}
                onFocus={(event) => {
                    isFocused.current = true;
                    props.onFocus?.(event);
                }}
                onBlur={(event) => {
                    isFocused.current = false;
                    setSearchValue(value);
                    props.onBlur?.(event);
                }}
                onChange={(event) => {
                    setSearchValue(event.currentTarget.value);
                    if (onChange) {
                        onChange(event);
                    }
                }}
                rightSection={<IconX aria-label={t`Clear Search Text`}
                                     color={'#ddd'}
                                     style={{cursor: 'pointer'}}
                                     display={value ? 'block' : 'none'}
                                     onClick={() => onClear()}
                />}
            />

            {sortProps
                && <SortSelector
                    selected={sortProps.selected}
                    options={sortProps.options}
                    onSortSelect={sortProps.onSortSelect}/>
            }
        </div>
    );
};


