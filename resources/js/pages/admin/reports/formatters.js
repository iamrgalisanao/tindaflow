/**
 * Cell/summary formatting for the report viewer. Money values arrive as
 * exact 2-decimal strings (openapi.yaml Money) and are formatted and
 * summed as STRINGS/BigInt cents -- never through floating point.
 */

const NUMERIC = /^-?\d+(\.\d+)?$/;

function groupThousands(integerDigits) {
    return integerDigits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

export function isNumericString(value) {
    return typeof value === 'string' ? NUMERIC.test(value) : typeof value === 'number' && Number.isFinite(value);
}

/** "-1234.5" -> "-1,234.50" (always exactly 2 decimals, string-based). */
export function formatMoney(value) {
    const text = String(value);
    if (!NUMERIC.test(text)) {
        return text;
    }
    const negative = text.startsWith('-');
    const [whole, fraction = ''] = text.replace('-', '').split('.');
    const decimals = (fraction + '00').slice(0, 2);
    return `${negative ? '-' : ''}₱${groupThousands(whole)}.${decimals}`;
}

/** Quantities carry up to 3 decimals ("5.000", "2.500"); trim insignificant zeros. */
export function formatQuantity(value) {
    const text = String(value);
    if (!NUMERIC.test(text)) {
        return text;
    }
    const negative = text.startsWith('-');
    const [whole, fraction = ''] = text.replace('-', '').split('.');
    const trimmed = fraction.replace(/0+$/, '');
    return `${negative ? '-' : ''}${groupThousands(whole)}${trimmed ? `.${trimmed}` : ''}`;
}

export function formatDateTime(iso) {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return String(iso);
    }
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

/** Exact decimal-string sum over a column, as BigInt cents. Non-numeric/null cells are skipped. */
export function sumMoney(rows, key) {
    let cents = 0n;
    for (const row of rows) {
        const value = row[key];
        if (value === null || value === undefined || !NUMERIC.test(String(value))) {
            continue;
        }
        const text = String(value);
        const negative = text.startsWith('-');
        const [whole, fraction = ''] = text.replace('-', '').split('.');
        const amount = BigInt(whole) * 100n + BigInt((fraction + '00').slice(0, 2));
        cents += negative ? -amount : amount;
    }
    const negative = cents < 0n;
    const abs = negative ? -cents : cents;
    return `${negative ? '-' : ''}${abs / 100n}.${String(abs % 100n).padStart(2, '0')}`;
}

export function sumIntegers(rows, key) {
    return rows.reduce((sum, row) => sum + (Number(row[key]) || 0), 0);
}

const BADGE = {
    COMPLETED: 'border-emerald-800/60 bg-emerald-950/80 text-emerald-400',
    VOIDED: 'border-rose-800/60 bg-rose-950/80 text-rose-400',
    REFUNDED: 'border-amber-800/60 bg-amber-950/80 text-amber-400',
    PARTIALLY_REFUNDED: 'border-amber-800/60 bg-amber-950/80 text-amber-400',
    APPROVED: 'border-emerald-800/60 bg-emerald-950/80 text-emerald-400',
    REJECTED: 'border-rose-800/60 bg-rose-950/80 text-rose-400',
    REQUESTED: 'border-slate-700 bg-slate-800 text-slate-300',
};

// Void/refund outcomes. VOIDED/COMPLETED are the final executed outcome (emerald); the sale-level
// VOIDED in BADGE above stays rose because it means "this sale is struck", a different context.
const OUTCOME_BADGE = {
    REQUESTED: 'border-slate-600 bg-slate-800 text-slate-300',
    APPROVED: 'border-amber-700/60 bg-amber-950/60 text-amber-400',
    REJECTED: 'border-rose-700/70 bg-transparent text-rose-400',
    VOIDED: 'border-emerald-700/70 bg-emerald-950/70 text-emerald-300',
    COMPLETED: 'border-emerald-700/70 bg-emerald-950/70 text-emerald-300',
};

const TIER_BADGE = {
    OUT_OF_STOCK: { text: 'OUT OF STOCK', className: 'border-rose-600 bg-rose-600 text-white' },
    CRITICAL: { text: 'CRITICAL', className: 'border-rose-700 bg-transparent text-rose-400' },
    LOW: { text: 'LOW', className: 'border-amber-700 bg-transparent text-amber-400' },
};

/**
 * Display convention only (not a business rule): 0 on hand = out of stock, under half the reorder
 * level = critical, otherwise low. Quantities are compared numerically; they are never money.
 */
export function stockTier(quantityOnHand, reorderLevel) {
    const onHand = Number(quantityOnHand);
    const reorder = Number(reorderLevel);
    if (onHand <= 0) {
        return 'OUT_OF_STOCK';
    }
    return onHand < reorder / 2 ? 'CRITICAL' : 'LOW';
}

export function cellValue(column, row) {
    return column.value ? column.value(row) : row[column.key];
}

/**
 * @returns {{text: string, className: string, title?: string}}
 */
export function formatReportCell(value, format) {
    if (value === null || value === undefined || value === '') {
        return { text: '—', className: 'text-slate-600' };
    }

    switch (format) {
        case 'currency':
            return { text: formatMoney(value), className: 'font-mono tabular-nums text-slate-100' };
        case 'quantity':
            return { text: formatQuantity(value), className: 'font-mono tabular-nums text-slate-200' };
        case 'signed_quantity': {
            const negative = String(value).startsWith('-');
            return {
                text: `${negative ? '−' : '+'}${formatQuantity(String(value).replace('-', ''))}`,
                className: `font-mono tabular-nums font-semibold ${negative ? 'text-rose-400' : 'text-emerald-400'}`,
            };
        }
        case 'integer':
            return { text: Number(value).toLocaleString('en-US'), className: 'font-mono tabular-nums text-slate-200' };
        case 'mono_id':
            return {
                text: String(value).length > 12 ? String(value).slice(0, 8) : String(value),
                title: String(value),
                className: 'inline-block rounded border border-slate-700/60 bg-slate-800/80 px-1.5 py-0.5 font-mono text-[11px] text-emerald-400',
            };
        case 'date':
            return { text: String(value), className: 'font-mono text-slate-300' };
        case 'datetime':
            return { text: formatDateTime(value), className: 'font-mono text-[12px] text-slate-300' };
        case 'status_badge': {
            const status = String(value).toUpperCase();
            return {
                text: status.replaceAll('_', ' '),
                className: `inline-block rounded border px-1.5 py-0.5 font-mono text-[10px] font-semibold ${BADGE[status] ?? 'border-slate-700 bg-slate-800 text-slate-300'}`,
            };
        }
        case 'outcome_badge': {
            const status = String(value).toUpperCase();
            return {
                text: status.replaceAll('_', ' '),
                className: `inline-block rounded border px-1.5 py-0.5 font-mono text-[10px] font-semibold ${OUTCOME_BADGE[status] ?? 'border-slate-700 bg-slate-800 text-slate-300'}`,
            };
        }
        case 'stock_tier': {
            const tier = TIER_BADGE[value] ?? TIER_BADGE.LOW;
            return {
                text: tier.text,
                className: `inline-block whitespace-nowrap rounded border px-1.5 py-0.5 font-mono text-[10px] font-bold ${tier.className}`,
            };
        }
        case 'variance': {
            // variance = declared - expected (invariant #38): negative is a shortage.
            const text = String(value);
            if (!NUMERIC.test(text)) {
                return { text, className: 'text-slate-300' };
            }
            const cents = sumMoney([{ v: text }], 'v');
            if (cents === '0.00') {
                return { text: `${formatMoney('0.00')} balanced`, className: 'font-mono tabular-nums text-slate-400' };
            }
            if (cents.startsWith('-')) {
                return {
                    text: `${formatMoney(cents)} short`,
                    className: 'rounded border border-rose-800/40 bg-rose-950/40 px-1.5 py-0.5 font-mono font-bold tabular-nums text-rose-400',
                };
            }
            return {
                text: `+${formatMoney(cents)} over`,
                className: 'rounded border border-amber-800/40 bg-amber-950/40 px-1.5 py-0.5 font-mono font-bold tabular-nums text-amber-400',
            };
        }
        case 'shortfall':
            return {
                text: `${formatQuantity(value)} short`,
                className: 'rounded border border-rose-800/60 bg-rose-950/60 px-1.5 py-0.5 font-mono font-bold tabular-nums text-rose-400',
            };
        default:
            return { text: String(value), className: 'text-slate-300' };
    }
}

// ---- date presets (local calendar dates, YYYY-MM-DD) ----

function ymd(date) {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function datePreset(name, now = new Date()) {
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    switch (name) {
        case 'yesterday': {
            const d = new Date(today);
            d.setDate(d.getDate() - 1);
            return { from: ymd(d), to: ymd(d) };
        }
        case 'this_week': {
            const d = new Date(today);
            d.setDate(d.getDate() - ((d.getDay() + 6) % 7)); // Monday
            return { from: ymd(d), to: ymd(today) };
        }
        case 'this_month':
            return { from: ymd(new Date(today.getFullYear(), today.getMonth(), 1)), to: ymd(today) };
        case 'today':
        default:
            return { from: ymd(today), to: ymd(today) };
    }
}
