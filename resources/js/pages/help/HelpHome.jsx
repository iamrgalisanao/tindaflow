import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import AdminLayout from '../admin/AdminLayout';
import { RoleBadge } from './helpParts';
import { CATEGORIES, GUIDES } from './guides';

const ROLE_FILTERS = [
    { key: 'ALL', label: 'Everyone' },
    { key: 'CASHIER', label: 'Cashier' },
    { key: 'MANAGER', label: 'Manager' },
    { key: 'ADMIN', label: 'Admin' },
];

const searchable = (guide) =>
    [guide.title, guide.summary, guide.category, ...guide.steps.flatMap((step) => [step.title, step.body ?? ''])].join(' ').toLowerCase();

function GuideCard({ guide }) {
    return (
        <li>
            <Link
                to={`/help/${guide.slug}`}
                className="group flex h-full min-h-11 flex-col rounded-xl border border-slate-800 bg-slate-900 p-4 hover:border-emerald-600/70 hover:bg-slate-900/80"
            >
                <div className="mb-2 flex flex-wrap items-center gap-1.5">
                    {guide.roles.map((role) => (
                        <RoleBadge key={role} role={role} />
                    ))}
                </div>
                <h4 className="text-[15px] font-semibold text-slate-100 group-hover:text-emerald-300">{guide.title}</h4>
                <p className="mt-1 flex-1 text-sm leading-relaxed text-slate-400">{guide.summary}</p>
                <p className="mt-3 font-mono text-[11px] text-slate-500">
                    {guide.steps.length} steps · about {guide.minutes} min
                </p>
            </Link>
        </li>
    );
}

/** The three markers every picture in the guides uses, drawn once here so the reader learns the code before the first guide. */
function HowToRead() {
    return (
        <section aria-label="How to read the pictures" className="mb-6 rounded-xl border border-slate-800 bg-slate-900 p-4">
            <h3 className="mb-3 text-sm font-semibold text-slate-100">How to read the pictures</h3>
            <div className="grid gap-3 sm:grid-cols-3">
                <div className="flex items-start gap-3">
                    <span className="relative mt-1 flex h-9 w-14 shrink-0 items-center justify-center rounded-md border-2 border-emerald-400 bg-emerald-400/10 animate-help-pulse motion-reduce:animate-none">
                        <span className="absolute -left-2 -top-2 flex h-5 w-5 items-center justify-center rounded-full bg-emerald-400 text-[11px] font-bold text-slate-950">1</span>
                    </span>
                    <p className="text-sm text-slate-300">
                        <strong className="text-emerald-300">Green</strong> is where to click or type. A cursor shows you, in numbered order.
                    </p>
                </div>
                <div className="flex items-start gap-3">
                    <span className="relative mt-1 flex h-9 w-14 shrink-0 items-center justify-center rounded-md border-2 border-dashed border-red-400 bg-red-500/10">
                        <span className="absolute -left-2 -top-2 flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-[11px] font-bold text-white">×</span>
                    </span>
                    <p className="text-sm text-slate-300">
                        <strong className="text-red-300">Red</strong> is what to leave alone, usually because it undoes work or sends you somewhere else.
                    </p>
                </div>
                <div className="flex items-start gap-3">
                    <span className="relative mt-1 flex h-9 w-14 shrink-0 items-center justify-center rounded-md border-2 border-sky-400 bg-sky-400/10">
                        <span className="absolute -left-2 -top-2 flex h-5 w-5 items-center justify-center rounded-full bg-sky-400 text-[11px] font-bold text-slate-950">?</span>
                    </span>
                    <p className="text-sm text-slate-300">
                        <strong className="text-sky-300">Blue</strong> is something to read or check, such as a total. There is nothing to click.
                    </p>
                </div>
            </div>
            <p className="mt-3 text-xs text-slate-500">
                Use <strong className="text-slate-400">Pause</strong> to freeze a picture, click a numbered line under it to jump to that action, or{' '}
                <strong className="text-slate-400">Enlarge</strong> to see it bigger. The pictures use sample data, so your screen will show your own products and names.
            </p>
        </section>
    );
}

export default function HelpHome() {
    const { user } = useAuth();
    const [role, setRole] = useState(ROLE_FILTERS.some((filter) => filter.key === user.role) ? user.role : 'ALL');
    const [query, setQuery] = useState('');

    const visible = useMemo(() => {
        const needle = query.trim().toLowerCase();
        return GUIDES.filter((guide) => (role === 'ALL' || guide.roles.includes(role)) && (needle === '' || searchable(guide).includes(needle)));
    }, [role, query]);

    const startHere = role === 'ALL' || query.trim() !== '' ? [] : visible.filter((guide) => guide.startHere?.includes(role));

    return (
        <AdminLayout title="Help & guides" requiredCapability={null} wide>
            <div className="mb-5">
                <h2 className="text-xl font-semibold text-slate-100">Help &amp; guides</h2>
                <p className="mt-1 max-w-2xl text-sm text-slate-400">
                    Step-by-step guides with real screenshots. Follow the green markers to see exactly what to click, and watch for the red ones that show what not to.
                </p>
            </div>

            <HowToRead />

            <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end">
                <div className="flex-1">
                    <label htmlFor="help-search" className="mb-1 block text-xs text-slate-500">
                        Search the guides
                    </label>
                    <input
                        id="help-search"
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="For example: refund, open shift, add product"
                        className="min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-3 text-sm text-slate-100 placeholder:text-slate-600 focus:border-emerald-500 focus:outline-none"
                    />
                </div>
                <div>
                    <p className="mb-1 text-xs text-slate-500">Show guides for</p>
                    <div className="flex gap-1 rounded-lg border border-slate-700 bg-slate-950 p-1" role="group" aria-label="Show guides for">
                        {ROLE_FILTERS.map((filter) => (
                            <button
                                key={filter.key}
                                type="button"
                                aria-pressed={role === filter.key}
                                onClick={() => setRole(filter.key)}
                                className={`min-h-9 rounded px-3 text-xs font-bold uppercase tracking-wider ${
                                    role === filter.key ? 'bg-emerald-500 text-slate-950' : 'text-slate-400 hover:bg-slate-800'
                                }`}
                            >
                                {filter.label}
                            </button>
                        ))}
                    </div>
                </div>
            </div>

            {startHere.length > 0 && (
                <section className="mb-6" aria-label="Start here">
                    <h3 className="mb-2 font-mono text-[11px] uppercase tracking-wider text-emerald-400">Start here</h3>
                    <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {startHere.map((guide) => (
                            <GuideCard key={guide.slug} guide={guide} />
                        ))}
                    </ul>
                </section>
            )}

            {visible.length === 0 && (
                <div className="rounded-xl border border-slate-800 bg-slate-900 p-6 text-center">
                    <p className="text-sm text-slate-200">No guide matches that.</p>
                    <p className="mt-1 text-sm text-slate-500">Try a shorter word, or choose Everyone above.</p>
                </div>
            )}

            {CATEGORIES.map((category) => {
                const guides = visible.filter((guide) => guide.category === category.label);
                if (guides.length === 0) {
                    return null;
                }
                return (
                    <section key={category.id} className="mb-6" aria-label={category.label}>
                        <h3 className="text-sm font-semibold text-slate-100">{category.label}</h3>
                        <p className="mb-2 text-xs text-slate-500">{category.note}</p>
                        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {guides.map((guide) => (
                                <GuideCard key={guide.slug} guide={guide} />
                            ))}
                        </ul>
                    </section>
                );
            })}
        </AdminLayout>
    );
}
