import { Fragment, useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { apiFetch } from '../../../api';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import ReportsAccessDenied from './ReportsAccessDenied';
import { REPORTS_NAV } from './ReportsHub';
import { reportBySlug } from './reportsRegistry';
import { datePreset, formatMoney, formatReportCell, sumIntegers, sumMoney } from './formatters';

const PAGE_SIZE = 50;
const PRESETS = [
    { id: 'today', label: 'Today' },
    { id: 'yesterday', label: 'Yesterday' },
    { id: 'this_week', label: 'This week' },
    { id: 'this_month', label: 'This month' },
];

function compare(a, b) {
    if (a === b) {
        return 0;
    }
    if (a === null || a === undefined) {
        return 1;
    }
    if (b === null || b === undefined) {
        return -1;
    }
    const numeric = Number(a) - Number(b);
    return Number.isNaN(numeric) ? String(a).localeCompare(String(b)) : numeric;
}

function Cell({ value, format }) {
    const { text, className, title } = formatReportCell(value, format);
    return (
        <span className={className} title={title}>
            {text}
        </span>
    );
}

function SummaryValue({ value, format }) {
    if (value === null || value === undefined) {
        return <span className="text-slate-600">{'—'}</span>;
    }
    if (format === 'currency') {
        return <span className="font-mono tabular-nums">{formatMoney(value)}</span>;
    }
    if (format === 'variance') {
        return <Cell value={value} format="variance" />;
    }
    return <span className="font-mono tabular-nums">{Number(value).toLocaleString('en-US')}</span>;
}

/**
 * /admin/reports/:slug -- one schema-driven viewer for all 15 reports
 * (see reportsRegistry.js). Headline totals come from the server's own
 * `summary`; the pinned footer only totals columns the registry marks
 * safe to sum, using exact BigInt-cents arithmetic.
 */
export default function ReportViewer() {
    const { slug } = useParams();
    const { user } = useAuth();
    const canView = user.capabilities.includes('REPORT_VIEW');
    const report = reportBySlug(slug);

    const dateFilter = report?.filters.find((f) => f.type === 'date_range') ?? null;
    const entityFilter = report?.filters.find((f) => f.type === 'entity') ?? null;

    const [range, setRange] = useState(() => datePreset(dateFilter?.defaultPreset ?? 'today'));
    const [preset, setPreset] = useState(dateFilter?.defaultPreset ?? null);
    const [entityValue, setEntityValue] = useState('');
    const [entityOptions, setEntityOptions] = useState([]);

    const [data, setData] = useState(null);
    const [status, setStatus] = useState('loading'); // loading | ready | error
    const [error, setError] = useState(null);
    const [exporting, setExporting] = useState(false);

    const [sort, setSort] = useState({ key: null, dir: 'asc' });
    const [page, setPage] = useState(1);

    const buildQuery = useCallback(
        (filters) => {
            const params = new URLSearchParams();
            if (dateFilter) {
                params.set('from', filters.from);
                params.set('to', filters.to);
            }
            if (entityFilter && filters.entity) {
                params.set(entityFilter.key, filters.entity);
            }
            return params.toString();
        },
        [dateFilter, entityFilter],
    );

    const load = useCallback(
        async (filters) => {
            if (!report) {
                return;
            }
            if (dateFilter && filters.from > filters.to) {
                setStatus('error');
                setError('"From" must be on or before "To".');
                return;
            }
            setStatus('loading');
            setError(null);
            const query = buildQuery(filters);
            const { ok, body } = await apiFetch(`/api/v1/reports/${report.slug}${query ? `?${query}` : ''}`);
            if (!ok) {
                setStatus('error');
                setError(body?.error?.message ?? 'The report could not be loaded.');
                return;
            }
            setData(body);
            setPage(1);
            setStatus('ready');
            if (entityFilter && !filters.entity) {
                // Options come from the unfiltered result -- no category/user list endpoint exists yet.
                const seen = new Map();
                for (const row of body.rows) {
                    const value = row[entityFilter.optionValue];
                    if (value && !seen.has(value)) {
                        seen.set(value, entityFilter.optionLabel(row));
                    }
                }
                setEntityOptions([...seen].map(([value, label]) => ({ value, label })));
            }
        },
        [report, dateFilter, entityFilter, buildQuery],
    );

    useEffect(() => {
        if (!report || !canView) {
            return;
        }
        const initialRange = datePreset(dateFilter?.defaultPreset ?? 'today');
        setRange(initialRange);
        setPreset(dateFilter?.defaultPreset ?? null);
        setEntityValue('');
        setEntityOptions([]);
        setSort({ key: null, dir: 'asc' });
        setData(null);
        load({ ...initialRange, entity: '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [slug]);

    function applyPreset(id) {
        const next = datePreset(id);
        setPreset(id);
        setRange(next);
        load({ ...next, entity: entityValue });
    }

    function applyFilters(event) {
        event.preventDefault();
        load({ ...range, entity: entityValue });
    }

    function changeEntity(value) {
        setEntityValue(value);
        load({ ...range, entity: value });
    }

    async function exportCsv() {
        setExporting(true);
        setError(null);
        try {
            const query = buildQuery({ ...range, entity: entityValue });
            const response = await fetch(`/api/v1/reports/${report.slug}${query ? `?${query}` : ''}`, {
                headers: { Accept: 'text/csv' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error('The CSV export failed.');
            }
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            const suffix = dateFilter ? `_${range.from}_${range.to}` : `_${range.to}`;
            link.href = url;
            link.download = `${report.exportPrefix}${suffix}.csv`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        } catch (exception) {
            setError(exception.message);
        }
        setExporting(false);
    }

    const rows = useMemo(() => {
        if (!data) {
            return [];
        }
        const copy = [...data.rows];
        if (report?.groupBy) {
            copy.sort((a, b) => compare(a[report.groupBy.key], b[report.groupBy.key]));
        }
        if (sort.key) {
            const factor = sort.dir === 'asc' ? 1 : -1;
            copy.sort((a, b) => {
                if (report?.groupBy) {
                    const grouped = compare(a[report.groupBy.key], b[report.groupBy.key]);
                    if (grouped !== 0) {
                        return grouped;
                    }
                }
                return factor * compare(a[sort.key], b[sort.key]);
            });
        }
        return copy;
    }, [data, sort, report]);

    const shell = (content) => (
        <AdminLayout
            title="Reports"
            navItems={REPORTS_NAV}
            requiredCapability="REPORT_VIEW"
            deniedView={<ReportsAccessDenied />}
            wide
        >
            {content}
        </AdminLayout>
    );

    if (!report) {
        return shell(
            <div className="rounded-lg border border-slate-800 bg-slate-900 p-6">
                <p className="text-sm text-slate-300">There is no report called &quot;{slug}&quot;.</p>
                <Link to="/admin/reports" className="mt-3 inline-block text-sm text-emerald-400 underline">
                    Back to all reports
                </Link>
            </div>,
        );
    }

    const pageCount = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
    const pageRows = rows.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);
    const totaledColumns = report.columns.filter((c) => c.total);
    const applied = data ? Object.entries(data.filters_applied ?? {}).filter(([, v]) => v !== null && v !== '') : [];

    function toggleSort(key) {
        setSort((prior) => (prior.key === key ? { key, dir: prior.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'asc' }));
    }

    let previousGroup = Symbol('none');

    return shell(
        <>
            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <Link to="/admin/reports" className="text-xs text-slate-500 underline hover:text-slate-300">
                        &larr; All reports
                    </Link>
                    <h2 className="mt-1 text-xl font-semibold text-slate-100">{report.title}</h2>
                    <p className="mt-1 max-w-2xl text-sm text-slate-400">{report.description}</p>
                    {data && (
                        <p className="mt-1 font-mono text-[11px] text-slate-600">
                            Generated {new Date(data.generated_at).toLocaleString()}
                        </p>
                    )}
                </div>
                <button
                    type="button"
                    disabled={exporting || status !== 'ready'}
                    onClick={exportCsv}
                    title="Exports raw decimals with no currency symbol or thousands separators, for spreadsheets and audits."
                    className="rounded-md bg-emerald-500 px-3 py-2 text-sm font-medium text-slate-950 hover:bg-emerald-400 disabled:opacity-50"
                >
                    {exporting ? 'Exporting…' : 'Export CSV'}
                </button>
            </div>

            {(dateFilter || entityFilter) && (
                <form
                    onSubmit={applyFilters}
                    className="mb-3 flex flex-wrap items-end gap-3 rounded-lg border border-slate-800 bg-slate-900 p-3"
                >
                    {dateFilter && (
                        <>
                            <div className="flex gap-1">
                                {PRESETS.map((item) => (
                                    <button
                                        key={item.id}
                                        type="button"
                                        onClick={() => applyPreset(item.id)}
                                        className={`rounded border px-2.5 py-1.5 text-xs ${
                                            preset === item.id
                                                ? 'border-emerald-500 bg-emerald-950/60 text-emerald-400'
                                                : 'border-slate-700 text-slate-400 hover:bg-slate-800'
                                        }`}
                                    >
                                        {item.label}
                                    </button>
                                ))}
                            </div>
                            <label className="text-xs text-slate-500" htmlFor="report_from">
                                From
                                <input
                                    id="report_from"
                                    type="date"
                                    value={range.from}
                                    onChange={(event) => {
                                        setPreset(null);
                                        setRange((prior) => ({ ...prior, from: event.target.value }));
                                    }}
                                    className="ml-2 rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-sm text-slate-100"
                                />
                            </label>
                            <label className="text-xs text-slate-500" htmlFor="report_to">
                                To
                                <input
                                    id="report_to"
                                    type="date"
                                    value={range.to}
                                    onChange={(event) => {
                                        setPreset(null);
                                        setRange((prior) => ({ ...prior, to: event.target.value }));
                                    }}
                                    className="ml-2 rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-sm text-slate-100"
                                />
                            </label>
                            <button
                                type="submit"
                                className="rounded-md border border-emerald-700 px-3 py-1.5 text-xs font-medium text-emerald-400 hover:bg-emerald-900/40"
                            >
                                Apply
                            </button>
                        </>
                    )}
                    {entityFilter && (
                        <label className="text-xs text-slate-500" htmlFor="report_entity">
                            {entityFilter.label}
                            <select
                                id="report_entity"
                                value={entityValue}
                                onChange={(event) => changeEntity(event.target.value)}
                                className="ml-2 max-w-64 rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-sm text-slate-100"
                            >
                                <option value="">All</option>
                                {entityOptions.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                </form>
            )}

            {applied.length > 0 && (
                <div className="mb-3 flex flex-wrap items-center gap-2 text-xs">
                    <span className="text-slate-600">Applied:</span>
                    {applied.map(([key, value]) => (
                        <span key={key} className="rounded border border-slate-700 bg-slate-900 px-2 py-0.5 font-mono text-slate-300">
                            {key} = {String(value).length > 12 ? String(value).slice(0, 8) : String(value)}
                        </span>
                    ))}
                </div>
            )}

            {error && (
                <p className="mb-3 rounded-md border border-red-800 bg-red-950 px-3 py-2 text-sm text-red-300" role="alert">
                    {error}
                </p>
            )}

            {status === 'ready' && data && report.summary.length > 0 && (
                <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    {report.summary.map((item) => (
                        <div key={item.key} className="rounded-lg border border-slate-800 bg-slate-900 px-4 py-3">
                            <p className="text-[11px] uppercase tracking-wide text-slate-500">{item.label}</p>
                            <p className="mt-1 text-lg font-semibold text-slate-100">
                                <SummaryValue value={data.summary?.[item.key]} format={item.format} />
                            </p>
                        </div>
                    ))}
                </div>
            )}

            {status === 'loading' && (
                <div className="space-y-2" aria-busy="true" aria-label="Loading report">
                    {[0, 1, 2, 3, 4].map((n) => (
                        <div key={n} className="h-9 animate-pulse rounded bg-slate-900" />
                    ))}
                </div>
            )}

            {status === 'ready' && rows.length === 0 && (
                <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center">
                    <p className="text-sm text-slate-300">No rows for these filters.</p>
                    <p className="mt-1 text-xs text-slate-500">
                        {dateFilter ? 'Try a wider date range.' : 'Nothing to show right now.'}
                    </p>
                </div>
            )}

            {status === 'ready' && rows.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-slate-800">
                    <table className="w-full min-w-max text-left text-sm">
                        <thead className="sticky top-0 bg-slate-950 text-[11px] uppercase tracking-wide text-slate-500">
                            <tr>
                                {report.columns.map((column) => (
                                    <th
                                        key={column.key}
                                        className={`border-b border-slate-800 px-3 py-2 font-mono ${
                                            column.align === 'right' ? 'text-right' : column.align === 'center' ? 'text-center' : ''
                                        }`}
                                    >
                                        {column.sortable ? (
                                            <button type="button" onClick={() => toggleSort(column.key)} className="uppercase hover:text-slate-300">
                                                {column.label}
                                                {sort.key === column.key ? (sort.dir === 'asc' ? ' ▲' : ' ▼') : ''}
                                            </button>
                                        ) : (
                                            column.label
                                        )}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-800/70">
                            {pageRows.map((row, index) => {
                                const groupValue = report.groupBy ? row[report.groupBy.key] : null;
                                const showGroup = report.groupBy && groupValue !== previousGroup;
                                previousGroup = groupValue;
                                return (
                                    <Fragment key={`${page}-${index}`}>
                                        {showGroup && (
                                            <tr className="bg-slate-900">
                                                <td colSpan={report.columns.length} className="px-3 py-1.5 text-xs font-semibold text-slate-400">
                                                    {report.groupBy.label}: <span className="font-mono text-emerald-400">{String(groupValue).slice(0, 8)}</span>
                                                </td>
                                            </tr>
                                        )}
                                        <tr className={`odd:bg-slate-950/40 hover:bg-slate-900 ${report.rowTone?.(row) ?? ''}`}>
                                            {report.columns.map((column) => (
                                                <td
                                                    key={column.key}
                                                    className={`whitespace-nowrap px-3 py-2 ${
                                                        column.align === 'right' ? 'text-right' : column.align === 'center' ? 'text-center' : ''
                                                    }`}
                                                >
                                                    <Cell value={column.value ? column.value(row) : row[column.key]} format={column.format} />
                                                </td>
                                            ))}
                                        </tr>
                                    </Fragment>
                                );
                            })}
                        </tbody>
                        {totaledColumns.length > 0 && (
                            <tfoot className="border-t-2 border-slate-700 bg-slate-900">
                                <tr>
                                    {report.columns.map((column, index) => (
                                        <td
                                            key={column.key}
                                            className={`whitespace-nowrap px-3 py-2 font-semibold ${
                                                column.align === 'right' ? 'text-right' : column.align === 'center' ? 'text-center' : ''
                                            }`}
                                        >
                                            {index === 0 && !column.total && (
                                                <span className="text-xs text-slate-400">Totals ({rows.length} rows)</span>
                                            )}
                                            {column.total === 'money' && (
                                                <Cell
                                                    value={sumMoney(rows, column.key)}
                                                    format={column.format === 'variance' ? 'variance' : 'currency'}
                                                />
                                            )}
                                            {column.total === 'integer' && (
                                                <span className="font-mono tabular-nums text-slate-100">
                                                    {sumIntegers(rows, column.key).toLocaleString('en-US')}
                                                </span>
                                            )}
                                        </td>
                                    ))}
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>
            )}

            {status === 'ready' && rows.length > PAGE_SIZE && (
                <div className="mt-3 flex items-center justify-between text-xs text-slate-500">
                    <span>
                        Showing {(page - 1) * PAGE_SIZE + 1}&ndash;{Math.min(page * PAGE_SIZE, rows.length)} of {rows.length}
                    </span>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            disabled={page === 1}
                            onClick={() => setPage((p) => p - 1)}
                            className="rounded border border-slate-700 px-2.5 py-1 hover:bg-slate-800 disabled:opacity-40"
                        >
                            Previous
                        </button>
                        <span className="px-1 py-1 font-mono">
                            {page} / {pageCount}
                        </span>
                        <button
                            type="button"
                            disabled={page === pageCount}
                            onClick={() => setPage((p) => p + 1)}
                            className="rounded border border-slate-700 px-2.5 py-1 hover:bg-slate-800 disabled:opacity-40"
                        >
                            Next
                        </button>
                    </div>
                </div>
            )}
        </>,
    );
}
