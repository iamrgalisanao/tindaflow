import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { shortId } from './formatters';

/**
 * Single-select, searchable picker for a report's entity filter (product / category / cashier).
 * One value only -- the API takes a single product_id / category_id / cashier_id.
 * options: [{ value, code?, name }]; value '' means "All".
 */
export default function EntityCombobox({ id, label, noun, searchPlaceholder, options, value, loading, onChange }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const rootRef = useRef(null);
    const triggerRef = useRef(null);
    const searchRef = useRef(null);
    const popoverRef = useRef(null);
    const listId = useId();

    const needle = query.trim().toLowerCase();
    const items = useMemo(() => {
        const matches = options.filter(
            (option) => needle === '' || `${option.code ?? ''} ${option.name} ${option.value}`.toLowerCase().includes(needle),
        );
        return needle === '' ? [{ value: '', name: `All ${noun}`, all: true }, ...matches] : matches;
    }, [options, needle, noun]);

    const selected = options.find((option) => option.value === value);
    const selectedLabel = value === '' ? 'All' : selected ? `${selected.code ? `${selected.code} ` : ''}${selected.name}` : shortId(value);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        searchRef.current?.focus({ preventScroll: true });
        popoverRef.current?.scrollIntoView?.({ block: 'nearest' });
        function onPointerDown(event) {
            if (rootRef.current && !rootRef.current.contains(event.target)) {
                setOpen(false);
            }
        }
        document.addEventListener('mousedown', onPointerDown);
        return () => document.removeEventListener('mousedown', onPointerDown);
    }, [open]);

    function openList() {
        setQuery('');
        setActive(Math.max(0, items.findIndex((item) => item.value === value)));
        setOpen(true);
    }

    function close() {
        setOpen(false);
        triggerRef.current?.focus();
    }

    function choose(item) {
        setOpen(false);
        triggerRef.current?.focus();
        if (item.value !== value) {
            onChange(item.value);
        }
    }

    function onKeyDown(event) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((index) => Math.min(items.length - 1, index + 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((index) => Math.max(0, index - 1));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (items[active]) {
                choose(items[active]);
            }
        } else if (event.key === 'Escape') {
            event.preventDefault();
            close();
        } else if (event.key === 'Tab') {
            setOpen(false);
        }
    }

    return (
        <div ref={rootRef} className="relative flex w-full items-center gap-1 md:w-auto">
            <span className="text-xs text-slate-500">{label}</span>
            <button
                ref={triggerRef}
                id={id}
                type="button"
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-controls={open ? listId : undefined}
                onClick={() => (open ? close() : openList())}
                className="flex min-h-11 min-w-0 flex-1 items-center justify-between gap-2 rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-left text-sm text-slate-100 hover:border-slate-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 md:max-w-64 lg:min-h-0"
            >
                <span className="truncate">{selectedLabel}</span>
                <span aria-hidden="true" className="text-slate-500">&#9662;</span>
            </button>
            {value !== '' && (
                <button
                    type="button"
                    aria-label={`Clear ${label.toLowerCase()} filter`}
                    onClick={() => onChange('')}
                    className="flex h-11 w-11 items-center justify-center rounded text-slate-400 hover:bg-slate-800 hover:text-slate-200 lg:h-7 lg:w-7"
                >
                    &times;
                </button>
            )}

            {open && (
                <div ref={popoverRef} className="absolute left-0 top-full z-30 mt-1 scroll-mb-28 w-[min(22rem,calc(100vw-2rem))] rounded-md border border-slate-600 bg-slate-800 shadow-[0_4px_12px_rgba(0,0,0,0.45)]">
                    <div className="border-b border-slate-700 p-2">
                        <input
                            ref={searchRef}
                            type="search"
                            role="combobox"
                            aria-expanded="true"
                            aria-controls={listId}
                            aria-activedescendant={items[active] ? `${listId}-${active}` : undefined}
                            aria-label={searchPlaceholder}
                            placeholder={searchPlaceholder}
                            value={query}
                            onChange={(event) => {
                                setQuery(event.target.value);
                                setActive(0);
                            }}
                            onKeyDown={onKeyDown}
                            className="w-full rounded border border-slate-600 bg-slate-950 px-2 py-1.5 text-sm text-slate-100 placeholder:text-slate-500 focus:border-emerald-500 focus:outline-none"
                        />
                    </div>
                    <ul id={listId} role="listbox" aria-label={label} className="max-h-72 overflow-y-auto py-1">
                        {loading && options.length === 0 && (
                            <li className="space-y-2 px-3 py-2" aria-busy="true">
                                <p className="text-xs text-slate-400">Fetching {noun} active in this date range…</p>
                                {[0, 1, 2].map((n) => (
                                    <div key={n} className="h-4 animate-pulse rounded bg-slate-700" />
                                ))}
                            </li>
                        )}
                        {!(loading && options.length === 0) && items.length === 0 && (
                            <li className="px-3 py-4 text-center text-sm text-slate-400">
                                No {noun} match &lsquo;{query.trim()}&rsquo;
                                <span className="mt-1 block text-xs text-slate-500">Try part of the name or clear the search.</span>
                            </li>
                        )}
                        {items.map((item, index) => (
                            <li
                                key={item.value || 'all'}
                                id={`${listId}-${index}`}
                                role="option"
                                aria-selected={item.value === value}
                                onMouseDown={(event) => {
                                    event.preventDefault();
                                    choose(item);
                                }}
                                onMouseEnter={() => setActive(index)}
                                className={`mx-1 flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 ${
                                    index === active ? 'bg-slate-700 ring-2 ring-emerald-500' : ''
                                }`}
                            >
                                <span
                                    aria-hidden="true"
                                    className={`flex h-4 w-4 shrink-0 items-center justify-center rounded-full border ${
                                        item.value === value ? 'border-emerald-400' : 'border-slate-500'
                                    }`}
                                >
                                    {item.value === value && <span className="h-2 w-2 rounded-full bg-emerald-400" />}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm text-slate-100">
                                        {item.code && <span className="mr-2 font-mono text-emerald-400">{item.code}</span>}
                                        {item.name}
                                    </span>
                                    {item.all ? (
                                        <span className="block font-mono text-[11px] text-slate-500">Resets the filter</span>
                                    ) : (
                                        <span className="block font-mono text-[11px] text-slate-500">id: {shortId(item.value)}</span>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <p className="border-t border-slate-700 px-3 py-2 text-[11px] text-slate-400">
                        Only {noun} with sales in this date range are listed.
                    </p>
                </div>
            )}
        </div>
    );
}
