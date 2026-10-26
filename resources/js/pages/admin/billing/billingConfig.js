import { formatMoney, formatQuantity } from '../reports/formatters';

/**
 * Pure helpers for the Billing screens (ADR-016 section 8, slice 4.1). Money stays a string end to end and is only ever formatted,
 * never added with floating point, except for the tiny sums below which work in whole centavos as BigInt.
 */

export const HALVES = [
    { id: '1', label: '1st to 15th' },
    { id: '2', label: '16th to end of month' },
];

export const MAX_DAYS = 366;

/** Today in Manila as "YYYY-MM-DD" (the business calendar; never the browser's own zone). */
export function manilaToday(now = new Date()) {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
}

/** The cut-off that contains today: { month: 'YYYY-MM', half: '1' | '2' }. */
export function currentCutoff(now = new Date()) {
    const [year, month, day] = manilaToday(now).split('-');
    return { month: `${year}-${month}`, half: Number(day) <= 15 ? '1' : '2' };
}

/** The first and last date of a named cut-off: the 16th to the real last day of the month (28, 29, 30 or 31). */
export function cutoffRange(month, half) {
    const match = /^(\d{4})-(\d{2})$/.exec(month ?? '');
    if (!match) {
        return null;
    }
    const year = Number(match[1]);
    const monthNumber = Number(match[2]);
    if (monthNumber < 1 || monthNumber > 12) {
        return null;
    }
    const lastDay = new Date(Date.UTC(year, monthNumber, 0)).getUTCDate();
    const pad = (n) => String(n).padStart(2, '0');
    return String(half) === '2'
        ? { from: `${month}-16`, to: `${month}-${pad(lastDay)}` }
        : { from: `${month}-01`, to: `${month}-15` };
}

function utcDay(date) {
    const [year, month, day] = date.split('-').map(Number);
    return Date.UTC(year, month - 1, day);
}

/** Days from `from` to `to`, counting both ends. */
export function daysBetween(from, to) {
    return Math.round((utcDay(to) - utcDay(from)) / 86400000) + 1;
}

/** Client-side checks for the picker; the server has the last word. Returns { field: message }. */
export function validateRange(form) {
    if (form.mode === 'cutoff') {
        return cutoffRange(form.month, form.half) ? {} : { month: 'Pick the month.' };
    }
    const errors = {};
    if (!/^\d{4}-\d{2}-\d{2}$/.test(form.from ?? '')) {
        errors.from = 'Enter the first date.';
    }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(form.to ?? '')) {
        errors.to = 'Enter the last date.';
    }
    if (Object.keys(errors).length === 0) {
        if (form.from > form.to) {
            errors.from = 'The first date cannot be after the last date.';
        } else if (daysBetween(form.from, form.to) > MAX_DAYS) {
            errors.to = `A statement can cover at most ${MAX_DAYS} days. Choose a shorter range.`;
        }
    }
    return errors;
}

/** "2026-10-26T14:30" typed in the browser is a Manila wall-clock time; the API wants an instant with its offset. */
export function asOfInstant(localValue) {
    return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(localValue ?? '') ? `${localValue}:00+08:00` : null;
}

/** The request parameters for billingStatementGet / billingRunCreate. Blank filters are left out. */
export function statementParams(form) {
    const params = form.mode === 'cutoff' ? { cutoff_month: form.month, cutoff_half: form.half } : { from: form.from, to: form.to };
    if (form.employeeType) {
        params.employee_type = form.employeeType;
    }
    if (form.employeeId) {
        params.employee_id = form.employeeId;
    }
    const asOf = asOfInstant(form.asOf);
    if (asOf) {
        params.as_of = asOf;
    }
    return params;
}

export function toQuery(params) {
    return new URLSearchParams(params).toString();
}

/** A voided or refunded amount is shown as a deduction; zero stays plain. */
export function negativeMoney(amount) {
    const text = String(amount ?? '0.00');
    return /^0+(\.0+)?$/.test(text) || text.startsWith('-') ? formatMoney(text) : formatMoney(`-${text}`);
}

/** Every account id on a statement, in order (what "Expand all" opens). */
export function accountIds(statement) {
    return (statement?.accounts ?? []).map((account) => account.employee_id);
}

/** Expand all when something is collapsed, otherwise collapse all. */
export function toggleAllIds(statement, expanded) {
    const ids = accountIds(statement);
    return ids.length > 0 && ids.every((id) => expanded.has(id)) ? new Set() : new Set(ids);
}

export function toggleId(expanded, id) {
    const next = new Set(expanded);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    return next;
}

/** Account -> meal type -> item rows flattened for the print document and for assertions. */
export function flatRows(statement) {
    const rows = [];
    for (const account of statement?.accounts ?? []) {
        for (const type of account.meal_types) {
            for (const item of type.items) {
                rows.push({ employee_code: account.employee_code, meal_type: type.meal_type, item: item.item, kind: item.kind, net_amount: item.net_amount });
            }
        }
    }
    return rows;
}

export function csvFileName(statement, run) {
    return run ? `billing_${run.run_number}.csv` : `billing_${statement.criteria.from}_${statement.criteria.to}.csv`;
}

export function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

const KIND_LABEL = { DISH: 'Dish', ADDON: 'Add-on', PRODUCT: 'Item' };

export function kindLabel(kind) {
    return KIND_LABEL[kind] ?? String(kind ?? '');
}

function formatWhen(iso) {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return String(iso ?? '');
    }
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(date).replace(',', '');
}

export { formatWhen };

/**
 * A self-contained, printable HTML document for a statement (every account fully expanded). Printed through the print frame the invoices use.
 * Every value that came from data is escaped; the document carries no script.
 */
export function printDocument(statement, run = null) {
    const e = escapeHtml;
    const c = statement.criteria;
    const accounts = statement.accounts
        .map(
            (account) => `
<section class="account">
  <h2>${e(account.employee_code)} &middot; ${e(account.employee_name)} <small>${account.employee_type === 'SUBCONTRACTOR' ? 'SUB' : 'TSC'}</small></h2>
  <table>
    <thead><tr><th>Meal type / item</th><th class="n">Qty</th><th class="n">Amount</th><th class="n">Returned</th><th class="n">Net</th></tr></thead>
    <tbody>
    ${account.meal_types
        .map(
            (type) => `
      <tr class="type"><td colspan="3">${e(type.meal_type)}</td><td class="n">${e(negativeMoney(type.returned_amount))}</td><td class="n">${e(formatMoney(type.net_amount))}</td></tr>
      ${type.items
          .map(
              (item) => `<tr><td class="indent">${e(item.item)}${item.kind === 'ADDON' ? ' (add-on)' : ''}</td><td class="n">${e(formatQuantity(item.quantity_sold))}</td><td class="n">${e(formatMoney(item.sold_amount))}</td><td class="n">${e(negativeMoney(item.returned_amount))}</td><td class="n">${e(formatMoney(item.net_amount))}</td></tr>`,
          )
          .join('')}`,
        )
        .join('')}
    </tbody>
    <tfoot><tr><td colspan="2">Total for ${e(account.employee_name)}</td><td class="n">${e(formatMoney(account.sold_amount))}</td><td class="n">${e(negativeMoney(account.returned_amount))}</td><td class="n">${e(formatMoney(account.net_amount))}</td></tr></tfoot>
  </table>
</section>`,
        )
        .join('');

    const adjustments =
        statement.adjustments.length === 0
            ? '<p>No voids or refunds in this period.</p>'
            : `<table><thead><tr><th>Recorded</th><th>Type</th><th>Employee</th><th>Sold on</th><th>Document</th><th>Reason</th><th class="n">Amount</th></tr></thead><tbody>${statement.adjustments
                  .map(
                      (a) =>
                          `<tr><td>${e(formatWhen(a.recorded_at))}</td><td>${e(a.type)}</td><td>${e(a.employee_code)} ${e(a.employee_name)}</td><td>${e(a.sold_on)}</td><td>${e(a.document_reference ?? '')}</td><td>${e(a.reason ?? '')}</td><td class="n">${e(negativeMoney(a.amount))}</td></tr>`,
                  )
                  .join('')}</tbody></table>`;

    const filters = [c.employee_type ? (c.employee_type === 'SUBCONTRACTOR' ? 'SUB employees only' : 'TSC employees only') : null, c.employee_code ? `Employee ${c.employee_code}` : null]
        .filter(Boolean)
        .join(' &middot; ');

    return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Billing statement ${e(run?.run_number ?? '')}</title>
<style>
  body { font: 12px/1.4 Arial, Helvetica, sans-serif; color: #111; margin: 16mm 12mm; }
  h1 { font-size: 18px; margin: 0 0 2px; } h2 { font-size: 13px; margin: 14px 0 4px; } h2 small { font-weight: normal; color: #555; }
  .meta { color: #333; margin-bottom: 10px; } table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  th, td { padding: 2px 6px; border-bottom: 1px solid #ddd; text-align: left; } th { background: #f2f2f2; } .n { text-align: right; white-space: nowrap; }
  .indent { padding-left: 18px; } .type td { font-weight: bold; background: #fafafa; } tfoot td { font-weight: bold; border-top: 1px solid #111; }
  .account { break-inside: avoid-page; } .grand { margin-top: 14px; font-size: 14px; font-weight: bold; text-align: right; }
  .note { margin-top: 18px; color: #555; font-size: 11px; }
</style></head><body>
<h1>Billing statement &mdash; ${e(statement.party.name)}</h1>
<div class="meta">${e(c.period_label)} (${e(c.from)} to ${e(c.to)})${filters ? ` &middot; ${filters}` : ''}<br>
${run ? `Billing run <strong>${e(run.run_number)}</strong> &middot; ` : 'Not saved &middot; '}generated ${e(formatWhen(statement.generated_at))} &middot; as of ${e(formatWhen(c.as_of))}</div>
${accounts || '<p>No credit sales in this period.</p>'}
<h2>Adjustments (voids and refunds)</h2>
${adjustments}
<p class="grand">Sold ${e(formatMoney(statement.totals.sold_amount))} &middot; Returned ${e(negativeMoney(statement.totals.returned_amount))} &middot; TOTAL DUE ${e(formatMoney(statement.totals.net_amount))}</p>
<p class="note">${e(statement.totals.employee_count)} employees, ${e(statement.totals.sale_count)} sales. Amounts come from the credit ledger and equal what the employees owe for this period.</p>
</body></html>`;
}
