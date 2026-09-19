import { useEffect, useId, useMemo, useState } from 'react';
import { fetchAll } from '../catalog/catalogApi';

/** direction: +1 adds stock, -1 removes it. Mirrors StockLedger::INFLOW / OUTFLOW on the server. */
export const MOVEMENT_TYPES = [
    { id: 'OPENING_STOCK', label: 'Opening stock', direction: 1 },
    { id: 'PURCHASE_RECEIPT', label: 'Purchase receipt', direction: 1 },
    { id: 'SALE_RETURN', label: 'Sale return', direction: 1 },
    { id: 'STOCK_ADJUSTMENT_IN', label: 'Adjustment in', direction: 1 },
    { id: 'TRANSFER_IN', label: 'Transfer in', direction: 1 },
    { id: 'SALE', label: 'Sale', direction: -1 },
    { id: 'STOCK_ADJUSTMENT_OUT', label: 'Adjustment out', direction: -1 },
    { id: 'DAMAGE', label: 'Damage', direction: -1 },
    { id: 'EXPIRED', label: 'Expired', direction: -1 },
    { id: 'TRANSFER_OUT', label: 'Transfer out', direction: -1 },
];

const TYPE_BY_ID = Object.fromEntries(MOVEMENT_TYPES.map((type) => [type.id, type]));

export const directionOf = (movementType) => TYPE_BY_ID[movementType]?.direction ?? 1;

export function MovementBadge({ movementType }) {
    const type = TYPE_BY_ID[movementType];
    const tone = directionOf(movementType) > 0 ? 'border-emerald-700/70 text-emerald-400' : 'border-amber-700/70 text-amber-400';
    return (
        <span className={`inline-block whitespace-nowrap rounded border px-1.5 py-0.5 font-mono text-[10px] font-semibold ${tone}`}>
            {(type?.label ?? movementType).toUpperCase()}
        </span>
    );
}

/** A quantity string as a signed, thousands-grouped decimal with insignificant zeros trimmed. */
export function quantityText(value, signed = false) {
    const text = String(value);
    const negative = text.startsWith('-');
    const [whole, fraction = ''] = text.replace('-', '').split('.');
    const trimmed = fraction.replace(/0+$/, '');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const sign = negative ? '-' : signed ? '+' : '';
    return `${sign}${grouped}${trimmed ? `.${trimmed}` : ''}`;
}

/** Quantities carry up to three decimals; do the sums on integer thousandths so nothing drifts. */
export function toThousandths(text) {
    const [whole, fraction = ''] = String(text).replace('-', '').split('.');
    const value = Number(whole) * 1000 + Number(`${fraction}000`.slice(0, 3));
    return String(text).startsWith('-') ? -value : value;
}

export function fromThousandths(value) {
    const sign = value < 0 ? '-' : '';
    const absolute = Math.abs(value);
    return `${sign}${Math.floor(absolute / 1000)}.${String(absolute % 1000).padStart(3, '0')}`;
}

export const QUANTITY_PATTERN = /^\d{1,7}(\.\d{1,3})?$/;

/** Loads every product and location once so ids in stock rows and movements can be shown as names. */
export function useInventoryLookups() {
    const [state, setState] = useState({ loading: true, products: [], locations: [], failed: false });
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        let cancelled = false;
        setState((prior) => ({ ...prior, loading: true, failed: false }));
        Promise.all([fetchAll('/api/v1/products'), fetchAll('/api/v1/inventory-locations')]).then(([products, locations]) => {
            if (cancelled) {
                return;
            }
            setState({ loading: false, failed: !products.ok || !locations.ok, products: products.rows, locations: locations.rows });
        });
        return () => {
            cancelled = true;
        };
    }, [attempt]);

    const productsById = useMemo(() => Object.fromEntries(state.products.map((product) => [product.id, product])), [state.products]);
    const locationsById = useMemo(() => Object.fromEntries(state.locations.map((location) => [location.id, location])), [state.locations]);

    return { ...state, productsById, locationsById, retry: () => setAttempt((count) => count + 1) };
}

/**
 * Required single product choice for the receive/adjust panel: a search box over the tracked, active
 * products with the matches listed under it. (The report filters' EntityCombobox always offers "All",
 * which does not make sense for a required field.)
 */
export function ProductPicker({ id, products, value, error, disabled, onChange }) {
    const [query, setQuery] = useState('');
    const listId = useId();
    const selected = products.find((product) => product.id === value);
    const needle = query.trim().toLowerCase();
    const matches = useMemo(
        () =>
            products
                .filter((product) => needle === '' || `${product.sku} ${product.barcode ?? ''} ${product.name}`.toLowerCase().includes(needle))
                .slice(0, 8),
        [products, needle],
    );

    if (selected) {
        return (
            <div className="flex items-center justify-between gap-3 rounded-md border border-slate-700 bg-slate-950 px-3 py-2">
                <div className="min-w-0">
                    <p className="truncate text-sm text-slate-100">{selected.name}</p>
                    <p className="truncate font-mono text-[11px] text-slate-500">
                        {selected.sku} &middot; {selected.unit_of_measure}
                    </p>
                </div>
                {!disabled && (
                    <button
                        type="button"
                        onClick={() => {
                            onChange('');
                            setQuery('');
                        }}
                        className="min-h-11 shrink-0 text-xs text-emerald-400 hover:underline lg:min-h-0"
                    >
                        Change
                    </button>
                )}
            </div>
        );
    }

    return (
        <div>
            <input
                id={id}
                type="search"
                autoComplete="off"
                placeholder="Search by name, SKU or barcode"
                value={query}
                aria-invalid={Boolean(error)}
                aria-controls={listId}
                onChange={(event) => setQuery(event.target.value)}
                className={`min-h-11 w-full rounded-md border bg-slate-950 px-3 py-1.5 text-sm text-slate-100 placeholder:text-slate-600 focus:outline-none focus:ring-1 lg:min-h-0 ${
                    error ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
                }`}
            />
            <ul id={listId} aria-label="Matching products" className="mt-1 max-h-56 overflow-y-auto rounded-md border border-slate-800 bg-slate-950">
                {matches.length === 0 && <li className="px-3 py-2 text-xs text-slate-500">No tracked product matches.</li>}
                {matches.map((product) => (
                    <li key={product.id}>
                        <button
                            type="button"
                            onClick={() => onChange(product.id)}
                            className="flex min-h-11 w-full items-center justify-between gap-3 px-3 py-1.5 text-left hover:bg-slate-800 lg:min-h-0"
                        >
                            <span className="min-w-0 truncate text-sm text-slate-100">{product.name}</span>
                            <span className="shrink-0 font-mono text-[11px] text-slate-500">{product.sku}</span>
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}
