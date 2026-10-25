import { useCallback, useEffect, useRef, useState } from 'react';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import { ActiveBadge, ErrorAlert, Toast } from './CatalogParts';
import { RETURN_KEY, failureMessage, fieldErrors, fetchAll, request } from './catalogApi';
import { groupTree, moveId } from './menuGroupOptions';

const inputClass = (error) =>
    `min-h-11 w-full rounded-md border bg-slate-950 px-3 py-1.5 text-sm text-slate-100 placeholder:text-slate-600 focus:outline-none focus:ring-1 lg:min-h-0 ${
        error ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
    }`;

/** A small name form, used to add a group or sub-group and to rename one. */
function NameForm({ initial = '', label, submitLabel, busy, error, onSubmit, onCancel }) {
    const [name, setName] = useState(initial);
    const id = `menu_group_name_${label.replaceAll(/\W+/g, '_')}`;

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit(name.trim());
            }}
            className="flex flex-col gap-2 sm:flex-row sm:items-start"
            noValidate
        >
            <div className="min-w-0 flex-1">
                <label htmlFor={id} className="sr-only">
                    {label}
                </label>
                <input
                    id={id}
                    type="text"
                    autoFocus
                    value={name}
                    placeholder={label}
                    aria-invalid={Boolean(error)}
                    onChange={(event) => setName(event.target.value)}
                    className={inputClass(error)}
                />
                {error && (
                    <p role="alert" className="mt-1 font-mono text-[11px] text-rose-400">
                        &#9888; {error}
                    </p>
                )}
            </div>
            <div className="flex gap-2">
                <button type="submit" disabled={busy || name.trim() === ''} className="min-h-11 rounded-md bg-emerald-500 px-3 py-1.5 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50 lg:min-h-0">
                    {busy ? 'Saving…' : submitLabel}
                </button>
                {onCancel && (
                    <button type="button" onClick={onCancel} className="min-h-11 rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-200 hover:bg-slate-800 lg:min-h-0">
                        Cancel
                    </button>
                )}
            </div>
        </form>
    );
}

/**
 * /admin/catalog/menu-groups -- the till's navigation tree (ADR-016): top groups (Breakfast, Lunch ...) with an optional level of
 * sub-groups. Add, rename, reorder (up/down), hide and show. Nothing is deleted. Products are placed in a group from the
 * product form.
 */
export default function MenuGroupsPage() {
    const { setUser } = useAuth();
    const [rows, setRows] = useState(null);
    const [failure, setFailure] = useState(null);
    const [toast, setToast] = useState(null);
    const [busy, setBusy] = useState(false);
    const [editing, setEditing] = useState(null); // { id } being renamed | { parentId } adding a sub-group | { parentId: null } adding a top group
    const [formError, setFormError] = useState(null);
    const seq = useRef(0);
    const dismissToast = useCallback(() => setToast(null), []);

    const load = useCallback(async () => {
        const current = ++seq.current;
        const all = await fetchAll('/api/v1/menu-groups');
        if (current !== seq.current) {
            return;
        }
        if (all.ok) {
            setRows(all.rows);
            setFailure(null);
        } else {
            setFailure(all);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    function signIn() {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }

    /** Runs one change, then reloads; a failure is shown as the page error (or under the form for a field error). */
    async function run(path, options, done) {
        setBusy(true);
        setFormError(null);
        const result = await request(path, options);
        setBusy(false);
        if (!result.ok) {
            const errors = fieldErrors(result);
            const message = errors.name ?? errors.parent_id ?? errors.ids;
            if (message) {
                setFormError(message);
            } else {
                setFailure(result);
            }
            return;
        }
        setEditing(null);
        if (done) {
            setToast(done);
        }
        await load();
    }

    const tree = rows ? groupTree(rows) : [];

    const add = (parentId) => (name) => run('/api/v1/menu-groups', { method: 'POST', body: { name, ...(parentId ? { parent_id: parentId } : {}) } }, parentId ? 'Sub-group added' : 'Group added');
    const rename = (group) => (name) => run(`/api/v1/menu-groups/${group.id}`, { method: 'PATCH', body: { name } }, 'Group renamed');
    const setActive = (group, active) => run(`/api/v1/menu-groups/${group.id}/${active ? 'activate' : 'deactivate'}`, { method: 'POST' }, active ? 'Group shown on the till' : 'Group hidden from the till');
    const move = (siblings, parentId, group, direction) => {
        const ids = siblings.map((sibling) => sibling.id);
        const next = moveId(ids, group.id, direction);
        if (next !== ids) {
            run('/api/v1/menu-groups/reorder', { method: 'POST', body: { parent_id: parentId, ids: next } });
        }
    };

    /** A plain render function, not a component: a component defined here would remount (and lose focus) on every state change. */
    function renderRow(group, siblings, parentId, children) {
        const index = siblings.findIndex((sibling) => sibling.id === group.id);
        const renaming = editing?.id === group.id;
        return (
            <li key={group.id} className="border-b border-slate-800/70 last:border-b-0">
                <div className={`flex flex-wrap items-center gap-2 px-3 py-2 ${parentId ? 'pl-8' : ''}`}>
                    <div className="min-w-0 flex-1">
                        {renaming ? (
                            <NameForm initial={group.name} label="Group name" submitLabel="Save" busy={busy} error={formError} onSubmit={rename(group)} onCancel={() => setEditing(null)} />
                        ) : (
                            <span className={group.active ? 'text-slate-100' : 'text-slate-500'}>
                                {parentId && <span aria-hidden="true" className="mr-1 text-slate-600">&#8627;</span>}
                                {group.name}
                            </span>
                        )}
                    </div>
                    {!renaming && (
                        <div className="flex flex-wrap items-center gap-3 text-xs">
                            <ActiveBadge active={group.active} />
                            <button type="button" aria-label={`Move ${group.name} up`} disabled={busy || index === 0} onClick={() => move(siblings, parentId, group, -1)} className="min-h-11 px-1 text-slate-300 hover:text-emerald-400 disabled:opacity-30 lg:min-h-0">
                                &uarr;
                            </button>
                            <button type="button" aria-label={`Move ${group.name} down`} disabled={busy || index === siblings.length - 1} onClick={() => move(siblings, parentId, group, 1)} className="min-h-11 px-1 text-slate-300 hover:text-emerald-400 disabled:opacity-30 lg:min-h-0">
                                &darr;
                            </button>
                            <button type="button" onClick={() => { setFormError(null); setEditing({ id: group.id }); }} className="min-h-11 text-emerald-400 hover:underline lg:min-h-0">
                                Rename
                            </button>
                            {!parentId && (
                                <button type="button" onClick={() => { setFormError(null); setEditing({ parentId: group.id }); }} className="min-h-11 text-emerald-400 hover:underline lg:min-h-0">
                                    Add sub-group
                                </button>
                            )}
                            <button
                                type="button"
                                disabled={busy}
                                onClick={() => setActive(group, !group.active)}
                                className={`min-h-11 hover:underline lg:min-h-0 ${group.active ? 'text-slate-400 hover:text-rose-400' : 'text-emerald-400'}`}
                            >
                                {group.active ? 'Hide' : 'Show'}
                            </button>
                        </div>
                    )}
                </div>
                {children}
            </li>
        );
    }

    return (
        <AdminLayout title="Catalog / Menu groups" requiredCapability="CATALOG_MANAGE">
            {toast && <Toast message={toast} onDismiss={dismissToast} />}
            <h2 className="mb-1 text-lg font-semibold text-slate-100">Menu groups</h2>
            <p className="mb-4 max-w-2xl text-sm text-slate-400">
                The tabs the cashier sees on the till: a group such as Lunch, with optional sub-groups such as Standard Meal. Place a product in a group from its Edit form. A hidden group
                (and the sub-groups under it) disappears from the till; its products can still be found by search. Groups are never deleted. Categories are unchanged and still used for reports.
            </p>

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The menu groups could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : load}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {rows === null && !failure && (
                <div className="space-y-2" aria-busy="true" aria-label="Loading menu groups">
                    {[0, 1, 2, 3].map((n) => (
                        <div key={n} className="h-9 animate-pulse rounded bg-slate-900" />
                    ))}
                </div>
            )}

            {rows !== null && (
                <>
                    <div className="mb-4 rounded-lg border border-slate-800 bg-slate-900 p-3">
                        {editing && editing.parentId === null ? (
                            <NameForm label="New group name" submitLabel="Add group" busy={busy} error={formError} onSubmit={add(null)} onCancel={() => setEditing(null)} />
                        ) : (
                            <button type="button" onClick={() => { setFormError(null); setEditing({ parentId: null }); }} className="min-h-11 rounded-md bg-emerald-500 px-4 py-1.5 text-sm font-semibold text-slate-950 hover:bg-emerald-400 lg:min-h-0">
                                Add group
                            </button>
                        )}
                    </div>

                    {tree.length === 0 ? (
                        <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center text-sm text-slate-300">
                            No menu groups yet, so the till shows category tabs. Add your first group.
                        </div>
                    ) : (
                        <ul className="overflow-hidden rounded-lg border border-slate-800" aria-label="Menu groups">
                            {tree.map((top) =>
                                renderRow(
                                    top,
                                    tree,
                                    null,
                                    (top.children.length > 0 || editing?.parentId === top.id) && (
                                        <ul className="bg-slate-950/40">
                                            {top.children.map((child) => renderRow(child, top.children, top.id))}
                                            {editing?.parentId === top.id && (
                                                <li className="px-3 py-2 pl-8">
                                                    <NameForm label={`New sub-group of ${top.name}`} submitLabel="Add sub-group" busy={busy} error={formError} onSubmit={add(top.id)} onCancel={() => setEditing(null)} />
                                                </li>
                                            )}
                                        </ul>
                                    ),
                                ),
                            )}
                        </ul>
                    )}
                </>
            )}
        </AdminLayout>
    );
}
