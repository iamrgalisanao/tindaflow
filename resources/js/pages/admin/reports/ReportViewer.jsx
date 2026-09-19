import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useLocation, useParams, useSearchParams } from 'react-router-dom';
import { apiFetch } from '../../../api';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import EntityCombobox from './EntityCombobox';
import ReportCards from './ReportCards';
import ReportsAccessDenied from './ReportsAccessDenied';
import {
    Cell,
    ErrorPanel,
    ExportFailureBanner,
    ExportToast,
    StatusChips,
    StatusLegend,
    SummaryCards,
    SummaryValue,
    exportFailureMessage,
} from './ReportParts';
import { reportBySlug } from './reportsRegistry';
import { cellValue, datePreset, shortId, sumIntegers, sumMoney } from './formatters';

const PAGE_SIZE = 50;
const RETURN_KEY = 'tindaflow.returnTo';
const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;
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

function failureFrom(status, body) {
    if (status === 401) {
        return { kind: 'session' };
    }
    if (status === 403) {
        return { kind: 'forbidden' };
    }
    if (status >= 500) {
        return { kind: 'server', requestId: body?.error?.request_id ?? null };
    }
    return { kind: 'other', message: body?.error?.message ?? null };
}

/**
 * apiFetch throws a TypeError when the request never completes (network) and a SyntaxError when the
 * server answered with a non-JSON body, e.g. a gateway error page (server).
 */
async function fetchReport(slug, query) {
    try {
        const { ok, status, body } = await apiFetch(`/api/v1/reports/${slug}${query ? `?${query}` : ''}`);
        return ok ? { body } : { failure: failureFrom(status, body) };
    } catch (error) {
        return { failure: { kind: error instanceof SyntaxError ? 'server' : 'network' } };
    }
}

function deriveOptions(rows, entityFilter) {
    const seen = new Map();
    for (const row of rows) {
        const value = row[entityFilter.optionValue];
        if (value && !seen.has(value)) {
            seen.set(value, { value, code: entityFilter.optionCode?.(row), name: entityFilter.optionName(row) });
        }
    }
    return [...seen.values()];
}

function initialFilters(searchParams, report) {
    const dateFilter = report.filters.find((f) => f.type === 'date_range') ?? null;
    const entityFilter = report.filters.find((f) => f.type === 'entity') ?? null;
    let range = datePreset(dateFilter?.defaultPreset ?? 'today');
    let preset = dateFilter?.defaultPreset ?? null;
    const from = searchParams.get('from') ?? '';
    const to = searchParams.get('to') ?? '';
    if (dateFilter && ISO_DATE.test(from) && ISO_DATE.test(to) && from <= to) {
        range = { from, to };
        preset = null;
    }
    const rawEntity = entityFilter ? (searchParams.get(entityFilter.key) ?? '') : '';
    return { range, preset, entity: rawEntity.length <= 64 ? rawEntity : '' };
}

function Pager({ page, pageCount, total, onPage, alwaysControls = false }) {
    const from = total === 0 ? 0 : (page - 1) * PAGE_SIZE + 1;
    const to = Math.min(page * PAGE_SIZE, total);
    return (
        <div className="flex items-center justify-between text-xs text-slate-500">
            <span className="font-mono">
                Showing {from}&ndash;{to} of {total}
            </span>
            {(pageCount > 1 || alwaysControls) && (
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        disabled={page === 1}
                        onClick={() => onPage(page - 1)}
                        className="min-h-11 rounded border border-slate-700 px-2.5 py-1 hover:bg-slate-800 disabled:opacity-40 lg:min-h-0"
                    >
                        Previous
                    </button>
                    <span className="px-1 py-1 font-mono">
                        {page} / {pageCount}
                    </span>
                    <button
                        type="button"
                        disabled={page === pageCount}
                        onClick={() => onPage(page + 1)}
                        className="min-h-11 rounded border border-slate-700 px-2.5 py-1 hover:bg-slate-800 disabled:opacity-40 lg:min-h-0"
                    >
                        Next
                    </button>
                </div>
            )}
        </div>
    );
}

/**
 * /admin/reports/:slug -- one schema-driven viewer for all 15 reports
 * (see reportsRegistry.js). Headline totals come from the server's own
 * `summary`; the pinned footer only totals columns the registry marks
 * safe to sum, using exact BigInt-cents arithmetic. Filters live in the
 * URL (from, to, and the entity id) so a report can be reopened -- and a
 * session-expired sign-in can return to it -- with the same filters.
 */
export default function ReportViewer() {
    const { slug } = useParams();
    const { user, setUser } = useAuth();
    const location = useLocation();
    const [searchParams, setSearchParams] = useSearchParams();
    const canView = user.capabilities.includes('REPORT_VIEW');
    const report = reportBySlug(slug);

    const dateFilter = report?.filters.find((f) => f.type === 'date_range') ?? null;
    const entityFilter = report?.filters.find((f) => f.type === 'entity') ?? null;

    const [initial] = useState(() => (report ? initialFilters(searchParams, report) : null));
    const [range, setRange] = useState(() => initial?.range ?? datePreset('today'));
    const [preset, setPreset] = useState(initial?.preset ?? null);
    const [entityValue, setEntityValue] = useState(initial?.entity ?? '');
    const [entityOptions, setEntityOptions] = useState([]);
    const [optionsFor, setOptionsFor] = useState(null);
    const [optionsLoading, setOptionsLoading] = useState(false);
    const [loadedRange, setLoadedRange] = useState(null);

    const [data, setData] = useState(null);
    const [status, setStatus] = useState('loading'); // loading | ready | error
    const [failure, setFailure] = useState(null);
    const [exporting, setExporting] = useState(false);
    const [exportBanner, setExportBanner] = useState(null);
    const [toast, setToast] = useState(null);

    const [sort, setSort] = useState({ key: null, dir: 'asc' });
    const [statusFilter, setStatusFilter] = useState('all');
    const [page, setPage] = useState(1);
    const [scrollInfo, setScrollInfo] = useState({ left: false, right: false });

    const lastFilters = useRef(null);
    const requestSeq = useRef(0);
    const optionsSeq = useRef(0);
    const scrollRef = useRef(null);

    const rangeInvalid = Boolean(dateFilter && range.from && range.to && range.from > range.to);

    const load = useCallback(
        async (filters) => {
            if (!report) {
                return;
            }
            lastFilters.current = filters;
            if (dateFilter && filters.from > filters.to) {
                return; // The form blocks this inline; no request is ever sent.
            }
            const params = new URLSearchParams();
            if (dateFilter) {
                params.set('from', filters.from);
                params.set('to', filters.to);
            }
            if (entityFilter && filters.entity) {
                params.set(entityFilter.key, filters.entity);
            }
            setSearchParams(params, { replace: true });

            const seq = ++requestSeq.current;
            setStatus('loading');
            setFailure(null);
            setExportBanner(null);
            const result = await fetchReport(report.slug, params.toString());
            if (seq !== requestSeq.current) {
                return; // A newer request superseded this one.
            }
            if (result.failure) {
                setData(null);
                setFailure(result.failure);
                setStatus('error');
                return;
            }
            setData(result.body);
            setLoadedRange({ from: filters.from, to: filters.to });
            setPage(1);
            setStatus('ready');
            if (entityFilter && !filters.entity) {
                // No category/user list endpoint exists, so options come from the unfiltered result.
                setEntityOptions(deriveOptions(result.body.rows, entityFilter));
                setOptionsFor({ from: filters.from, to: filters.to });
            }
        },
        [report, dateFilter, entityFilter, setSearchParams],
    );

    const loadOptions = useCallback(
        async (optionRange) => {
            if (!entityFilter || !report) {
                return;
            }
            const seq = ++optionsSeq.current;
            const params = new URLSearchParams();
            if (dateFilter) {
                params.set('from', optionRange.from);
                params.set('to', optionRange.to);
            }
            setOptionsLoading(true);
            const result = await fetchReport(report.slug, params.toString());
            if (seq !== optionsSeq.current) {
                return;
            }
            setOptionsLoading(false);
            if (!result.failure) {
                setEntityOptions(deriveOptions(result.body.rows, entityFilter));
                setOptionsFor({ from: optionRange.from, to: optionRange.to });
            }
        },
        [report, dateFilter, entityFilter],
    );

    useEffect(() => {
        if (!report || !canView) {
            return;
        }
        const start = initialFilters(searchParams, report);
        optionsSeq.current += 1;
        setRange(start.range);
        setPreset(start.preset);
        setEntityValue(start.entity);
        setEntityOptions([]);
        setOptionsFor(null);
        setOptionsLoading(false);
        setLoadedRange(null);
        setSort(report.defaultSort ?? { key: null, dir: 'asc' });
        setStatusFilter('all');
        setExportBanner(null);
        setData(null);
        load({ ...start.range, entity: start.entity });
        if (start.entity) {
            loadOptions(start.range);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [slug]);

    useEffect(() => {
        if (!toast) {
            return undefined;
        }
        const timer = setTimeout(() => setToast(null), 6000);
        return () => clearTimeout(timer);
    }, [toast]);

    function applyPreset(id) {
        const next = datePreset(id);
        setPreset(id);
        setRange(next);
        load({ ...next, entity: entityValue });
    }

    function applyFilters(event) {
        event.preventDefault();
        if (!rangeInvalid) {
            load({ ...range, entity: entityValue });
        }
    }

    function changeEntity(value) {
        setEntityValue(value);
        const base = rangeInvalid ? lastFilters.current : range;
        load({ from: base.from, to: base.to, entity: value });
    }

    function signIn() {
        try {
            sessionStorage.setItem(RETURN_KEY, `${location.pathname}${location.search}`);
        } catch {
            // Session storage can be unavailable; the user simply lands on the dashboard after sign-in.
        }
        setUser(null); // RequireAuth then redirects to /login.
    }

    async function exportCsv() {
        const filters = lastFilters.current;
        setExporting(true);
        setExportBanner(null);
        setToast(null);
        const params = new URLSearchParams();
        if (dateFilter) {
            params.set('from', filters.from);
            params.set('to', filters.to);
        }
        if (entityFilter && filters.entity) {
            params.set(entityFilter.key, filters.entity);
        }
        let failedKind = null;
        try {
            const query = params.toString();
            const response = await fetch(`/api/v1/reports/${report.slug}${query ? `?${query}` : ''}`, {
                headers: { Accept: 'text/csv' },
                credentials: 'same-origin',
            });
            if (response.ok) {
                const blob = await response.blob();
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                const suffix = dateFilter ? `_${filters.from}_${filters.to}` : `_${new Date().toISOString().slice(0, 10)}`;
                const filename = `${report.exportPrefix}${suffix}.csv`;
                link.href = url;
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                link.remove();
                URL.revokeObjectURL(url);
                setToast({ filename });
            } else {
                failedKind = failureFrom(response.status, null).kind;
            }
        } catch {
            failedKind = 'network';
        }
        if (failedKind) {
            setExportBanner({ message: exportFailureMessage(failedKind) });
        }
        setExporting(false);
    }

    const statusCounts = useMemo(() => {
        if (!data || !report?.statusFilter) {
            return {};
        }
        const counts = {};
        for (const row of data.rows) {
            const value = row[report.statusFilter.key];
            counts[value] = (counts[value] ?? 0) + 1;
        }
        return counts;
    }, [data, report]);

    const rows = useMemo(() => {
        if (!data) {
            return [];
        }
        let copy = [...data.rows];
        if (report?.statusFilter && statusFilter !== 'all') {
            copy = copy.filter((row) => row[report.statusFilter.key] === statusFilter);
        }
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
    }, [data, sort, report, statusFilter]);

    const pageCount = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
    const pageRows = useMemo(() => rows.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE), [rows, page]);

    const updateScroll = useCallback(() => {
        const element = scrollRef.current;
        if (!element) {
            return;
        }
        setScrollInfo({
            left: element.scrollLeft > 0,
            right: element.scrollLeft + element.clientWidth < element.scrollWidth - 1,
        });
    }, []);

    useEffect(() => {
        updateScroll();
        window.addEventListener('resize', updateScroll);
        return () => window.removeEventListener('resize', updateScroll);
    }, [updateScroll, pageRows, status]);

    const shell = (content) => (
        <AdminLayout
            title="Reports"
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

    const totaledColumns = report.columns.filter((c) => c.total);
    const mobileTotalColumns = report.mobileTotals
        ? totaledColumns.filter((c) => report.mobileTotals.includes(c.key))
        : totaledColumns.slice(0, 2);
    const selectedOption = entityOptions.find((option) => option.value === entityValue);
    const applied = data ? Object.entries(data.filters_applied ?? {}).filter(([, v]) => v !== null && v !== '') : [];
    const optionsStale = Boolean(
        entityValue &&
            optionsFor &&
            loadedRange &&
            (optionsFor.from !== loadedRange.from || optionsFor.to !== loadedRange.to),
    );
    const hasRows = Boolean(data && data.rows.length > 0);
    const summaryItems =
        status === 'ready' && data
            ? [
                  ...report.summary.map((item) => ({
                      label: item.label,
                      node: <SummaryValue value={data.summary?.[item.key]} format={item.format} />,
                  })),
                  ...(report.derivedSummary?.(data.rows) ?? []).map((item) => ({
                      label: item.label,
                      node: <span className="font-mono tabular-nums">{item.value}</span>,
                      sub: item.sub,
                      tone: item.tone,
                  })),
              ]
            : [];

    function toggleSort(key) {
        setSort((prior) => (prior.key === key ? { key, dir: prior.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'asc' }));
    }

    const stickyFirst = (index, base) =>
        index === 0
            ? `sticky left-0 ${base} ${scrollInfo.left ? 'shadow-[4px_0_8px_-2px_rgba(0,0,0,0.6)]' : ''}`
            : '';

    let previousGroup = Symbol('none');

    return shell(
        <>
            {toast && <ExportToast filename={toast.filename} onDismiss={() => setToast(null)} />}

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
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
                    disabled={exporting || status !== 'ready' || !hasRows}
                    onClick={exportCsv}
                    title={
                        status === 'ready' && !hasRows
                            ? 'Nothing to export for these filters.'
                            : 'Exports raw decimals with no currency symbol or thousands separators, for spreadsheets and audits.'
                    }
                    className="flex min-h-11 w-full items-center justify-center gap-2 rounded-md bg-emerald-500 px-3 py-2 text-sm font-medium text-slate-950 hover:bg-emerald-400 disabled:opacity-50 sm:w-auto lg:min-h-0"
                >
                    {exporting && (
                        <span
                            aria-hidden="true"
                            className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-slate-950/40 border-t-slate-950"
                        />
                    )}
                    {exporting ? 'Exporting…' : 'Export CSV'}
                </button>
            </div>

            {exportBanner && (
                <ExportFailureBanner
                    message={exportBanner.message}
                    onRetry={exportCsv}
                    onDismiss={() => setExportBanner(null)}
                />
            )}

            {(dateFilter || entityFilter) && (
                <form
                    onSubmit={applyFilters}
                    className="mb-3 flex flex-col gap-3 rounded-lg border border-slate-800 bg-slate-900 p-3 md:flex-row md:flex-wrap md:items-end"
                >
                    {dateFilter && (
                        <>
                            <div className="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1 md:mx-0 md:overflow-visible md:p-0" role="group" aria-label="Date presets">
                                {PRESETS.map((item) => (
                                    <button
                                        key={item.id}
                                        type="button"
                                        onClick={() => applyPreset(item.id)}
                                        className={`min-h-11 shrink-0 rounded border px-2.5 py-1.5 text-xs lg:min-h-0 ${
                                            preset === item.id
                                                ? 'border-emerald-500 bg-emerald-950/60 text-emerald-400'
                                                : 'border-slate-700 text-slate-400 hover:bg-slate-800'
                                        }`}
                                    >
                                        {item.label}
                                    </button>
                                ))}
                            </div>
                            <div>
                                <label className="flex flex-col gap-1 text-xs text-slate-500 md:flex-row md:items-center md:gap-2" htmlFor="report_from">
                                    From
                                    <input
                                        id="report_from"
                                        type="date"
                                        value={range.from}
                                        aria-invalid={rangeInvalid}
                                        aria-describedby={rangeInvalid ? 'report_range_error' : undefined}
                                        onChange={(event) => {
                                            setPreset(null);
                                            setRange((prior) => ({ ...prior, from: event.target.value }));
                                        }}
                                        className={`min-h-11 w-full rounded-md border bg-slate-950 px-2 py-1 text-sm text-slate-100 md:w-auto lg:min-h-0 ${
                                            rangeInvalid ? 'border-rose-500' : 'border-slate-700'
                                        }`}
                                    />
                                </label>
                                {rangeInvalid && (
                                    <p id="report_range_error" role="alert" className="mt-1 font-mono text-[11px] text-rose-400">
                                        &#9888; &quot;From&quot; must be on or before &quot;To&quot;.{' '}
                                        <span className="text-slate-500">No request was sent.</span>
                                    </p>
                                )}
                            </div>
                            <label className="flex flex-col gap-1 text-xs text-slate-500 md:flex-row md:items-center md:gap-2" htmlFor="report_to">
                                To
                                <input
                                    id="report_to"
                                    type="date"
                                    value={range.to}
                                    onChange={(event) => {
                                        setPreset(null);
                                        setRange((prior) => ({ ...prior, to: event.target.value }));
                                    }}
                                    className="min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-sm text-slate-100 md:w-auto lg:min-h-0"
                                />
                            </label>
                            <button
                                type="submit"
                                disabled={rangeInvalid}
                                className="min-h-11 w-full rounded-md border border-emerald-700 px-3 py-1.5 text-xs font-medium text-emerald-400 hover:bg-emerald-900/40 disabled:cursor-not-allowed disabled:border-slate-700 disabled:text-slate-600 disabled:hover:bg-transparent md:w-auto lg:min-h-0"
                            >
                                Apply
                            </button>
                        </>
                    )}
                    {entityFilter && (
                        <EntityCombobox
                            id="report_entity"
                            label={entityFilter.label}
                            noun={entityFilter.noun}
                            searchPlaceholder={entityFilter.searchPlaceholder}
                            options={entityOptions}
                            value={entityValue}
                            loading={status === 'loading' || optionsLoading}
                            onChange={changeEntity}
                        />
                    )}
                </form>
            )}

            {(applied.length > 0 || entityValue) && (
                <div className="mb-3 flex flex-wrap items-center gap-2 text-xs">
                    <span className="text-slate-600">Applied:</span>
                    {applied.map(([key, value]) => (
                        <span key={key} className="rounded border border-slate-700 bg-slate-900 px-2 py-0.5 font-mono text-slate-300">
                            {key} = {shortId(value)}
                            {entityFilter && key === entityFilter.key && selectedOption
                                ? ` (${selectedOption.code ?? selectedOption.name})`
                                : ''}
                        </span>
                    ))}
                    {entityValue && (
                        <button type="button" onClick={() => changeEntity('')} className="text-slate-400 underline hover:text-slate-200">
                            Reset filter
                        </button>
                    )}
                </div>
            )}

            {optionsStale && (
                <div className="mb-3 flex flex-wrap items-center gap-3 rounded-md border border-amber-700/60 bg-amber-950/20 px-3 py-2 text-xs text-slate-200">
                    <span className="min-w-0 flex-1">
                        Date range changed since the {entityFilter.noun} list was loaded &mdash; reset the filter or refresh the
                        list.
                    </span>
                    <button
                        type="button"
                        onClick={() => loadOptions(loadedRange)}
                        className="min-h-11 rounded border border-amber-700 px-2.5 py-1 text-amber-300 hover:bg-amber-900/30 lg:min-h-0"
                    >
                        Refresh options
                    </button>
                </div>
            )}

            {status === 'error' && failure && (
                <ErrorPanel failure={failure} onRetry={() => load(lastFilters.current)} onSignIn={signIn} />
            )}

            {status === 'ready' && data && report.statusFilter && (
                <StatusChips
                    config={report.statusFilter}
                    counts={statusCounts}
                    total={data.rows.length}
                    value={statusFilter}
                    onChange={(value) => {
                        setStatusFilter(value);
                        setPage(1);
                    }}
                />
            )}

            {summaryItems.length > 0 && <SummaryCards items={summaryItems} />}
            {status === 'ready' && report.note && <p className="mb-4 text-xs text-slate-500">{report.note}</p>}

            {status === 'loading' && (
                <div className="space-y-2" aria-busy="true" aria-label="Loading report">
                    {[0, 1, 2, 3, 4].map((n) => (
                        <div key={n} className="h-9 animate-pulse rounded bg-slate-900" />
                    ))}
                </div>
            )}

            {status === 'ready' && data && data.rows.length === 0 && (
                <div
                    className={`rounded-lg border border-dashed p-8 text-center ${
                        report.emptyState?.tone === 'ok' ? 'border-emerald-900/70 bg-emerald-950/10' : 'border-slate-800'
                    }`}
                >
                    {report.emptyState?.tone === 'ok' && (
                        <span
                            aria-hidden="true"
                            className="mx-auto mb-2 flex h-9 w-9 items-center justify-center rounded border border-emerald-700 text-emerald-400"
                        >
                            &#10003;
                        </span>
                    )}
                    <p className="text-sm text-slate-200">{report.emptyState?.title ?? 'No rows for these filters.'}</p>
                    <p className="mt-1 text-xs text-slate-500">
                        {report.emptyState?.body ?? (dateFilter ? 'Try a wider date range.' : 'Nothing to show right now.')}
                    </p>
                </div>
            )}

            {status === 'ready' && hasRows && rows.length === 0 && (
                <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center">
                    <p className="text-sm text-slate-300">No rows with this status in the loaded results.</p>
                    <button
                        type="button"
                        onClick={() => setStatusFilter('all')}
                        className="mt-2 text-xs text-emerald-400 underline"
                    >
                        Show all statuses
                    </button>
                </div>
            )}

            {status === 'ready' && rows.length > 0 && (
                <>
                    <ReportCards key={`${page}-${statusFilter}-${slug}`} report={report} rows={pageRows} />

                    <div className="relative hidden md:block">
                        <div ref={scrollRef} onScroll={updateScroll} className="overflow-x-auto rounded-lg border border-slate-800 [color-scheme:dark]">
                            <table className="w-full min-w-max text-left text-sm">
                                <thead className="sticky top-0 bg-slate-950 text-[11px] uppercase tracking-wide text-slate-500">
                                    <tr>
                                        {report.columns.map((column, index) => (
                                            <th
                                                key={column.key}
                                                className={`z-10 border-b border-slate-800 bg-slate-950 px-3 py-2 font-mono ${
                                                    column.align === 'right' ? 'text-right' : column.align === 'center' ? 'text-center' : ''
                                                } ${stickyFirst(index, 'bg-slate-950')}`}
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
                                                            {report.groupBy.label}: <span className="font-mono text-emerald-400">{shortId(groupValue)}</span>
                                                        </td>
                                                    </tr>
                                                )}
                                                <tr className={`group odd:bg-slate-950/40 hover:bg-slate-900 ${report.rowTone?.(row) ?? ''}`}>
                                                    {report.columns.map((column, columnIndex) => (
                                                        <td
                                                            key={column.key}
                                                            className={`whitespace-nowrap px-3 py-2 ${
                                                                column.align === 'right' ? 'text-right' : column.align === 'center' ? 'text-center' : ''
                                                            } ${stickyFirst(columnIndex, 'z-[1] bg-slate-950 group-hover:bg-slate-900')}`}
                                                        >
                                                            <Cell value={cellValue(column, row)} format={column.format} row={row} />
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
                                                    } ${stickyFirst(index, 'z-[1] bg-slate-900')}`}
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
                        {scrollInfo.right && (
                            <>
                                <div
                                    aria-hidden="true"
                                    className="pointer-events-none absolute inset-y-0 right-0 w-14 rounded-r-lg bg-gradient-to-l from-slate-950 to-transparent"
                                />
                                <button
                                    type="button"
                                    onClick={() => scrollRef.current?.scrollBy({ left: 240, behavior: 'smooth' })}
                                    className="absolute right-2 top-14 z-20 rounded-full border border-emerald-700 bg-slate-900 px-2.5 py-1 font-mono text-[11px] text-emerald-400 hover:bg-slate-800"
                                >
                                    Scroll &rsaquo;
                                </button>
                            </>
                        )}
                    </div>

                    <div className="mt-3 hidden md:block">
                        <Pager page={page} pageCount={pageCount} total={rows.length} onPage={setPage} />
                    </div>

                    <div className="sticky bottom-0 z-20 -mx-4 mt-3 border-t border-slate-800 bg-slate-900 px-4 py-2 md:hidden">
                        {mobileTotalColumns.length > 0 && (
                            <div className="mb-2">
                                <p className="text-[11px] uppercase tracking-wide text-slate-500">Totals ({rows.length} rows)</p>
                                <p className="flex flex-wrap gap-x-4 text-sm text-slate-300">
                                    {mobileTotalColumns.map((column) => (
                                        <span key={column.key}>
                                            {column.label}:{' '}
                                            {column.total === 'integer' ? (
                                                <span className="font-mono tabular-nums text-slate-100">
                                                    {sumIntegers(rows, column.key).toLocaleString('en-US')}
                                                </span>
                                            ) : (
                                                <Cell
                                                    value={sumMoney(rows, column.key)}
                                                    format={column.format === 'variance' ? 'variance' : 'currency'}
                                                />
                                            )}
                                        </span>
                                    ))}
                                </p>
                            </div>
                        )}
                        <Pager page={page} pageCount={pageCount} total={rows.length} onPage={setPage} alwaysControls />
                    </div>
                </>
            )}

            {status === 'ready' && report.statusLegend && <StatusLegend legend={report.statusLegend} />}
        </>,
    );
}
