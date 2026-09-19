import { useCallback, useEffect, useRef, useState } from 'react';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import { shortId } from '../reports/formatters';
import { ErrorAlert, Pager, Toast } from './CatalogParts';
import { RETURN_KEY, failureMessage, fieldErrors, request } from './catalogApi';

const KINDS = {
    categories: {
        path: '/api/v1/categories',
        title: 'Categories',
        singular: 'category',
        description: 'Group products so you can filter and report on them. Categories can be added here; renaming and deleting are not available yet.',
        inputLabel: 'Category name',
        placeholder: 'e.g. Beverages',
        addLabel: 'Add category',
        added: 'Category added',
        empty: 'No categories yet. Add your first category.',
    },
    brands: {
        path: '/api/v1/brands',
        title: 'Brands',
        singular: 'brand',
        description: 'Record who makes or brands a product. Brands can be added here; renaming and deleting are not available yet.',
        inputLabel: 'Brand name',
        placeholder: 'e.g. Datu Puti',
        addLabel: 'Add brand',
        added: 'Brand added',
        empty: 'No brands yet. Add your first brand.',
    },
};

/**
 * Categories and brands share one screen pattern: the contract gives both only list and create
 * (openapi.yaml categoryList/Create, brandList/Create), so there is no rename, delete or ordering here.
 */
export default function CatalogNamesPage({ kind }) {
    const config = KINDS[kind];
    const { setUser } = useAuth();
    const [page, setPage] = useState(1);
    const [rows, setRows] = useState(null);
    const [meta, setMeta] = useState(null);
    const [failure, setFailure] = useState(null);
    const [name, setName] = useState('');
    const [nameError, setNameError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [toast, setToast] = useState(null);
    const seq = useRef(0);
    const dismissToast = useCallback(() => setToast(null), []);

    const load = useCallback(
        async (targetPage) => {
            const current = ++seq.current;
            setFailure(null);
            const result = await request(`${config.path}?per_page=25&page=${targetPage}`);
            if (current !== seq.current) {
                return;
            }
            if (!result.ok) {
                setFailure(result);
                return;
            }
            setRows(result.body.data);
            setMeta(result.body.meta);
        },
        [config.path],
    );

    useEffect(() => {
        setRows(null);
        setMeta(null);
        setPage(1);
        setName('');
        setNameError(null);
        load(1);
    }, [kind, load]);

    function signIn() {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }

    async function add(event) {
        event.preventDefault();
        if (name.trim() === '') {
            setNameError('Enter a name.');
            return;
        }
        setSaving(true);
        setNameError(null);
        const result = await request(config.path, { method: 'POST', body: { name: name.trim() } });
        setSaving(false);
        if (!result.ok) {
            const errors = fieldErrors(result);
            if (errors.name) {
                setNameError(errors.name);
            } else {
                setFailure(result);
            }
            return;
        }
        setName('');
        setToast(config.added);
        setPage(1);
        load(1);
    }

    function goTo(target) {
        setPage(target);
        load(target);
    }

    return (
        <AdminLayout title={`Catalog / ${config.title}`} requiredCapability="CATALOG_MANAGE">
            {toast && <Toast message={toast} onDismiss={dismissToast} />}
            <h2 className="mb-1 text-lg font-semibold text-slate-100">{config.title}</h2>
            <p className="mb-4 max-w-2xl text-sm text-slate-400">{config.description}</p>

            <form onSubmit={add} className="mb-4 flex flex-col gap-3 rounded-lg border border-slate-800 bg-slate-900 p-3 sm:flex-row sm:items-start" noValidate>
                <div className="min-w-0 flex-1">
                    <label htmlFor="catalog_name" className="mb-1 block text-xs text-slate-500">
                        {config.inputLabel} <span className="text-emerald-400">*</span>
                    </label>
                    <input
                        id="catalog_name"
                        type="text"
                        value={name}
                        placeholder={config.placeholder}
                        aria-invalid={Boolean(nameError)}
                        aria-describedby={nameError ? 'catalog_name_error' : undefined}
                        onChange={(event) => {
                            setName(event.target.value);
                            setNameError(null);
                        }}
                        className={`min-h-11 w-full rounded-md border bg-slate-950 px-3 py-1.5 text-sm text-slate-100 placeholder:text-slate-600 focus:outline-none focus:ring-1 lg:min-h-0 ${
                            nameError ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
                        }`}
                    />
                    {nameError && (
                        <p id="catalog_name_error" role="alert" className="mt-1 font-mono text-[11px] text-rose-400">
                            &#9888; {nameError}
                        </p>
                    )}
                </div>
                <button
                    type="submit"
                    disabled={saving}
                    className="min-h-11 rounded-md bg-emerald-500 px-4 py-1.5 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50 sm:mt-5 lg:min-h-0"
                >
                    {saving ? 'Adding…' : config.addLabel}
                </button>
            </form>

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, `The ${config.title.toLowerCase()} could not be loaded.`)}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : () => load(page)}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {rows === null && !failure && (
                <div className="space-y-2" aria-busy="true" aria-label={`Loading ${config.title.toLowerCase()}`}>
                    {[0, 1, 2, 3].map((n) => (
                        <div key={n} className="h-9 animate-pulse rounded bg-slate-900" />
                    ))}
                </div>
            )}

            {rows !== null && rows.length === 0 && (
                <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center text-sm text-slate-300">{config.empty}</div>
            )}

            {rows !== null && rows.length > 0 && (
                <div className="overflow-hidden rounded-lg border border-slate-800">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-slate-950 text-[11px] uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="border-b border-slate-800 px-3 py-2 font-mono">Name</th>
                                <th className="border-b border-slate-800 px-3 py-2 text-right font-mono">ID</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-800/70">
                            {rows.map((row) => (
                                <tr key={row.id} className="odd:bg-slate-950/40">
                                    <td className="px-3 py-2 text-slate-100">{row.name}</td>
                                    <td className="px-3 py-2 text-right">
                                        <span title={row.id} className="rounded border border-slate-700/60 bg-slate-800/80 px-1.5 py-0.5 font-mono text-[11px] text-emerald-400">
                                            {shortId(row.id)}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {rows !== null && <Pager meta={meta} onPage={goTo} />}
        </AdminLayout>
    );
}
