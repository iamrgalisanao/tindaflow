import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import { ErrorAlert } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, request } from '../catalog/catalogApi';
import { EMPLOYEE_TYPES, chunkCards, employeeQuery, typeLabel } from './employeeConfig';

const CARDS_PER_PAGE = 8;

/** Hides everything but the sheet when printing, and breaks the page after each sheet of cards. */
const PRINT_CSS = `
@media print {
  body * { visibility: hidden !important; }
  #id_card_sheet, #id_card_sheet * { visibility: visible !important; }
  #id_card_sheet { position: absolute; left: 0; top: 0; width: 100%; }
  .id-card-page { break-after: page; }
  .id-card-page:last-child { break-after: auto; }
}
`;

/**
 * /admin/employees/cards -- the printable ID-card sheet (employeeIdCards): name, type and the code of each active employee, eight
 * cards to a page. The code is the value a QR image will carry; this slice prints it as large text (no QR library is installed, and
 * dependencies are not added without approval), which a cashier can key in at the till. Filtering by type or search prints a subset.
 */
export default function EmployeeCardsPage() {
    const { setUser } = useAuth();
    const [type, setType] = useState('');
    const [search, setSearch] = useState('');
    const [term, setTerm] = useState('');
    const [cards, setCards] = useState(null);
    const [failure, setFailure] = useState(null);
    const seq = useRef(0);

    const signIn = useCallback(() => {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }, [setUser]);

    const load = useCallback(async () => {
        const current = ++seq.current;
        const response = await request(`/api/v1/employees/id-cards?${employeeQuery({ search: term, type })}`);
        if (current !== seq.current) {
            return;
        }
        if (response.ok) {
            setCards(response.body.data);
            setFailure(null);
        } else {
            setCards(null);
            setFailure(response);
        }
    }, [term, type]);

    useEffect(() => {
        load();
    }, [load]);

    const pages = cards ? chunkCards(cards, CARDS_PER_PAGE) : [];

    return (
        <AdminLayout title="Employees / ID cards" requiredCapability="EMPLOYEE_MANAGE" wide>
            <style>{PRINT_CSS}</style>
            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-xl font-semibold text-slate-100">Print ID cards</h2>
                    <p className="mt-1 max-w-2xl text-sm text-slate-400">
                        One card for each active employee. Cashiers type the code at the till; a QR image on the card is not available yet.{' '}
                        <Link to="/admin/employees" className="text-emerald-400 underline">
                            Back to employees
                        </Link>
                    </p>
                </div>
                <button
                    type="button"
                    onClick={() => window.print()}
                    disabled={!cards || cards.length === 0}
                    className="min-h-11 rounded-md bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50 lg:min-h-0"
                >
                    Print
                </button>
            </div>

            <div className="mb-4 flex flex-col gap-3 rounded-lg border border-slate-800 bg-slate-900 p-3 md:flex-row md:items-end">
                <form
                    role="search"
                    onSubmit={(event) => {
                        event.preventDefault();
                        setTerm(search);
                    }}
                    className="flex flex-1 items-end gap-2"
                >
                    <div className="min-w-0 flex-1">
                        <label htmlFor="cards_search" className="mb-1 block text-xs text-slate-500">
                            Search by name or code
                        </label>
                        <input
                            id="cards_search"
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            className="min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-slate-100 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 lg:min-h-0"
                        />
                    </div>
                    <button type="submit" className="min-h-11 rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-200 hover:bg-slate-800 lg:min-h-0">
                        Search
                    </button>
                </form>
                <div>
                    <label htmlFor="cards_type" className="mb-1 block text-xs text-slate-500">
                        Type
                    </label>
                    <select
                        id="cards_type"
                        value={type}
                        onChange={(event) => setType(event.target.value)}
                        className="min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1.5 text-sm text-slate-100 md:w-48 lg:min-h-0"
                    >
                        <option value="">All types</option>
                        {EMPLOYEE_TYPES.map((entry) => (
                            <option key={entry.id} value={entry.id}>
                                {entry.label}
                            </option>
                        ))}
                    </select>
                </div>
            </div>

            {failure && (
                <ErrorAlert
                    message={failure.status === 422 ? (failure.body?.error?.details?.search?.[0] ?? 'Narrow the search.') : failureMessage(failure, 'The cards could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 || failure.status === 422 ? undefined : load}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}
            {!cards && !failure && <div className="h-32 animate-pulse rounded bg-slate-900" aria-busy="true" aria-label="Loading cards" />}
            {cards && cards.length === 0 && <p className="rounded-lg border border-dashed border-slate-800 p-8 text-center text-sm text-slate-300">No active employees match. Nothing to print.</p>}

            {cards && cards.length > 0 && (
                <div id="id_card_sheet" aria-label={`${cards.length} ID cards`}>
                    {pages.map((sheet, index) => (
                        <div key={index} className="id-card-page mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            {sheet.map((card) => (
                                <div key={card.id} className="break-inside-avoid rounded-lg border-2 border-slate-400 bg-white p-4 text-black">
                                    <p className="text-[11px] font-semibold uppercase tracking-widest text-slate-600">{typeLabel(card.employee_type)}</p>
                                    <p className="mt-1 break-words text-lg font-bold leading-tight">{card.name}</p>
                                    <p className="mt-3 text-center font-mono text-3xl font-bold tracking-[0.25em]">{card.employee_code}</p>
                                </div>
                            ))}
                        </div>
                    ))}
                </div>
            )}
        </AdminLayout>
    );
}
