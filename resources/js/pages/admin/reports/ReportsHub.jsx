import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import AdminLayout from '../AdminLayout';
import ReportsAccessDenied from './ReportsAccessDenied';
import { REPORT_CATEGORIES, REPORTS } from './reportsRegistry';

export const REPORTS_NAV = [{ to: '/admin/reports', label: 'All Reports' }];

/** /admin/reports -- the 15 reports grouped by category, with search and a category filter. */
export default function ReportsHub() {
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState('all');

    const visible = useMemo(() => {
        const needle = query.trim().toLowerCase();
        return REPORTS.filter(
            (report) =>
                (category === 'all' || report.category === category) &&
                (needle === '' || `${report.title} ${report.description}`.toLowerCase().includes(needle)),
        );
    }, [query, category]);

    return (
        <AdminLayout
            title="Reports"
            navItems={REPORTS_NAV}
            requiredCapability="REPORT_VIEW"
            deniedView={<ReportsAccessDenied />}
            wide
        >
            <div className="mb-5 flex flex-wrap items-center gap-3">
                <input
                    id="report_search"
                    type="search"
                    placeholder="Search reports"
                    aria-label="Search reports"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    className="w-64 rounded-md border border-slate-700 bg-slate-900 px-3 py-1.5 text-sm text-slate-100 placeholder:text-slate-600"
                />
                <div className="flex flex-wrap gap-1">
                    {[{ id: 'all', label: 'All' }, ...REPORT_CATEGORIES].map((item) => (
                        <button
                            key={item.id}
                            type="button"
                            onClick={() => setCategory(item.id)}
                            className={`rounded border px-2.5 py-1 text-xs ${
                                category === item.id
                                    ? 'border-emerald-500 bg-emerald-950/60 text-emerald-400'
                                    : 'border-slate-700 text-slate-400 hover:bg-slate-800'
                            }`}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>
            </div>

            {visible.length === 0 && <p className="text-sm text-slate-500">No reports match.</p>}

            {REPORT_CATEGORIES.map((group) => {
                const reports = visible.filter((report) => report.category === group.id);
                if (reports.length === 0) {
                    return null;
                }
                return (
                    <section key={group.id} className="mb-6">
                        <h2 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {group.label} <span className="font-mono text-slate-600">({reports.length})</span>
                        </h2>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {reports.map((report) => (
                                <Link
                                    key={report.slug}
                                    to={`/admin/reports/${report.slug}`}
                                    className="rounded-lg border border-slate-800 bg-slate-900 p-4 hover:border-emerald-700"
                                >
                                    <p className="text-sm font-medium text-slate-100">{report.title}</p>
                                    <p className="mt-1 text-xs leading-relaxed text-slate-500">{report.description}</p>
                                    <p className="mt-3 font-mono text-[11px] text-slate-600">
                                        {report.filters.some((f) => f.type === 'date_range') ? 'Date range' : 'Current snapshot'}
                                    </p>
                                </Link>
                            ))}
                        </div>
                    </section>
                );
            })}
        </AdminLayout>
    );
}
