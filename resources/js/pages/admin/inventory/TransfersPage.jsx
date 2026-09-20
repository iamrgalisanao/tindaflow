import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import SlideOver from '../SlideOver';
import { ErrorAlert, Pager, Toast } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, request } from '../catalog/catalogApi';
import { formatDateTime } from '../reports/formatters';
import { quantityText, useInventoryLookups } from './inventoryParts';
import { TerminalNotice, useTerminalState } from './stockDocsParts';
import TransferPanel from './TransferPanel';

const PER_PAGE = 25;

function MoveRoute({ transfer }) {
    return (
        <span className="text-slate-100">
            {transfer.from_location_name} <span aria-label="to" className="text-slate-500">&rarr;</span> {transfer.to_location_name}
        </span>
    );
}

/** Read-only detail of one transfer (stockTransferGet). */
function TransferDetailPanel({ id, onClose, onUnauthorized }) {
    const [transfer, setTransfer] = useState(null);
    const [failure, setFailure] = useState(null);

    useEffect(() => {
        let cancelled = false;
        request(`/api/v1/inventory/transfers/${id}`).then((response) => {
            if (cancelled) {
                return;
            }
            if (response.ok) {
                setTransfer(response.body);
            } else if (response.status === 401) {
                onUnauthorized();
            } else {
                setFailure(response);
            }
        });
        return () => {
            cancelled = true;
        };
    }, [id, onUnauthorized]);

    return (
        <SlideOver titleId="transfer_detail_title" title="Stock transfer" onClose={onClose}>
            <div className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                {failure && <ErrorAlert message={failure.status === 404 ? 'This transfer was not found.' : failureMessage(failure, 'The transfer could not be loaded.')} />}
                {!transfer && !failure && <div className="h-24 animate-pulse rounded bg-slate-950" aria-busy="true" aria-label="Loading transfer" />}
                {transfer && (
                    <>
                        <div>
                            <p className="text-base">
                                <MoveRoute transfer={transfer} />
                            </p>
                            <p className="mt-1 font-mono text-[12px] text-slate-500">{formatDateTime(transfer.created_at)}</p>
                            {transfer.note && <p className="mt-2 break-words text-sm text-slate-300">{transfer.note}</p>}
                        </div>
                        <ul aria-label="Products moved" className="divide-y divide-slate-800 rounded-md border border-slate-800">
                            {transfer.lines.map((line) => (
                                <li key={line.product_id} className="flex items-center justify-between gap-3 px-3 py-2">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm text-slate-100">{line.product_name}</p>
                                        <p className="font-mono text-[11px] text-slate-500">{line.sku}</p>
                                    </div>
                                    <span className="shrink-0 font-mono text-sm tabular-nums text-slate-100">{quantityText(line.quantity)}</span>
                                </li>
                            ))}
                        </ul>
                        <p className="text-xs text-slate-500">
                            Each product was taken out of {transfer.from_location_name} and put into {transfer.to_location_name} at the same moment, so total stock did not change. See{' '}
                            <Link to="/admin/inventory/movements" className="text-emerald-400 underline">
                                Movements
                            </Link>{' '}
                            for both entries.
                        </p>
                    </>
                )}
            </div>
        </SlideOver>
    );
}

/**
 * /admin/inventory/transfers -- stockTransferList, stockTransferGet and (in a slide-over) stockTransferCreate.
 * Reading needs STOCK_ADJUST and a session; creating writes the stock ledger, so it also needs an enrolled terminal.
 */
export default function TransfersPage() {
    const { user, setUser } = useAuth();
    const lookups = useInventoryLookups();
    const terminal = useTerminalState();
    const [page, setPage] = useState(1);
    const [reloadKey, setReloadKey] = useState(0);
    const [result, setResult] = useState(null);
    const [failure, setFailure] = useState(null);
    const [loading, setLoading] = useState(true);
    const [creating, setCreating] = useState(false);
    const [viewing, setViewing] = useState(null);
    const [toast, setToast] = useState(null);
    const seq = useRef(0);
    const dismissToast = useCallback(() => setToast(null), []);
    const closePanels = useCallback(() => {
        setCreating(false);
        setViewing(null);
    }, []);

    const signIn = useCallback(() => {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }, [setUser]);

    useEffect(() => {
        const current = ++seq.current;
        setLoading(true);
        setFailure(null);
        request(`/api/v1/inventory/transfers?per_page=${PER_PAGE}&page=${page}`).then((response) => {
            if (current !== seq.current) {
                return;
            }
            setLoading(false);
            if (response.ok) {
                setResult(response.body);
            } else {
                setResult(null);
                setFailure(response);
            }
        });
    }, [page, reloadKey]);

    const rows = result?.data ?? [];
    const tracked = lookups.products.filter((product) => product.track_inventory);
    const hasTwoLocations = lookups.locations.length >= 2;
    const canManageTerminals = user.capabilities.includes('TERMINAL_MANAGE');
    const canCreateLocations = user.capabilities.includes('FISCAL_CONFIGURATION_MANAGE');
    const canMove = !lookups.loading && hasTwoLocations && terminal.state !== 'not-enrolled';

    return (
        <AdminLayout title="Stock transfers" requiredCapability="STOCK_ADJUST" wide>
            {toast && <Toast message={toast} onDismiss={dismissToast} />}

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-xl font-semibold text-slate-100">Stock transfers</h2>
                    <p className="mt-1 max-w-2xl text-sm text-slate-400">
                        Move stock between the locations of this store, for example from the backroom to the counter. Sales still take stock from your default location.
                    </p>
                </div>
                <button
                    type="button"
                    disabled={!canMove}
                    onClick={() => setCreating(true)}
                    className="min-h-11 shrink-0 self-start whitespace-nowrap rounded-md bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-40 lg:min-h-0"
                >
                    Move stock
                </button>
            </div>

            <TerminalNotice terminal={terminal} canManageTerminals={canManageTerminals} doing="move stock" />

            {!lookups.loading && !lookups.failed && !hasTwoLocations && (
                <div role="status" className="mb-3 rounded-md border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-300">
                    This store has {lookups.locations.length === 1 ? 'only one location' : 'no locations'}, so there is nowhere to move stock to.{' '}
                    {canCreateLocations ? (
                        <Link to="/admin/store-setup/inventory-locations" className="text-emerald-400 underline">
                            Add a location
                        </Link>
                    ) : (
                        'Ask an administrator to add another location.'
                    )}
                </div>
            )}
            {lookups.failed && !lookups.loading && <ErrorAlert message="Locations and products could not be loaded, so stock cannot be moved yet." onRetry={lookups.retry} />}

            {failure && (
                <ErrorAlert
                    message={failureMessage(failure, 'The transfers could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 ? undefined : () => setReloadKey((key) => key + 1)}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {loading && !result && !failure && (
                <div className="space-y-2" aria-busy="true" aria-label="Loading transfers">
                    {[0, 1, 2].map((n) => (
                        <div key={n} className="h-11 animate-pulse rounded bg-slate-900" />
                    ))}
                </div>
            )}

            {result && rows.length === 0 && (
                <div className="rounded-lg border border-dashed border-slate-800 p-8 text-center">
                    <p className="text-sm text-slate-200">No stock has been moved between locations yet.</p>
                </div>
            )}

            {result && rows.length > 0 && (
                <div className={loading ? 'opacity-60 transition-opacity' : ''}>
                    <div className="hidden overflow-x-auto rounded-lg border border-slate-800 [color-scheme:dark] md:block">
                        <table className="w-full min-w-max text-left text-sm">
                            <thead className="bg-slate-950 text-[11px] uppercase tracking-wide text-slate-500">
                                <tr>
                                    {['When', 'Moved', 'Products', 'Note', ''].map((heading) => (
                                        <th key={heading || 'view'} className="border-b border-slate-800 px-3 py-2 font-mono">
                                            {heading}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-800/70">
                                {rows.map((row) => (
                                    <tr key={row.id} className="odd:bg-slate-950/40 hover:bg-slate-900">
                                        <td className="px-3 py-2 font-mono text-[12px] text-slate-400">{formatDateTime(row.created_at)}</td>
                                        <td className="px-3 py-2">
                                            <MoveRoute transfer={row} />
                                        </td>
                                        <td className="px-3 py-2 font-mono tabular-nums text-slate-300">{row.lines_count}</td>
                                        <td className="max-w-xs truncate px-3 py-2 text-slate-400">{row.note ?? '—'}</td>
                                        <td className="px-3 py-2 text-right">
                                            <button type="button" onClick={() => setViewing(row.id)} className="whitespace-nowrap text-xs text-emerald-400 hover:underline">
                                                View
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <ul className="space-y-3 md:hidden" aria-label="Stock transfers">
                        {rows.map((row) => (
                            <li key={row.id}>
                                <button type="button" onClick={() => setViewing(row.id)} className="block w-full rounded-lg border border-slate-800 bg-slate-900 p-3 text-left hover:bg-slate-800/60">
                                    <p className="text-sm">
                                        <MoveRoute transfer={row} />
                                    </p>
                                    <p className="mt-1 font-mono text-[11px] text-slate-500">
                                        {formatDateTime(row.created_at)} &middot; {row.lines_count} {row.lines_count === 1 ? 'product' : 'products'}
                                    </p>
                                    {row.note && <p className="mt-1 break-words text-xs text-slate-400">{row.note}</p>}
                                </button>
                            </li>
                        ))}
                    </ul>

                    <Pager meta={result.meta} onPage={setPage} />
                </div>
            )}

            {creating && (
                <TransferPanel
                    locations={lookups.locations}
                    products={tracked}
                    onClose={closePanels}
                    onUnauthorized={signIn}
                    onDone={(transfer) => {
                        setCreating(false);
                        setToast(`Moved ${transfer.lines_count} ${transfer.lines_count === 1 ? 'product' : 'products'} from ${transfer.from_location_name} to ${transfer.to_location_name}.`);
                        setPage(1);
                        setReloadKey((key) => key + 1);
                    }}
                />
            )}
            {viewing && <TransferDetailPanel id={viewing} onClose={closePanels} onUnauthorized={signIn} />}
        </AdminLayout>
    );
}
