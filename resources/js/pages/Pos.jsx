import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { apiFetch } from '../api';
import { useAuth } from '../context/AuthContext';
import { usePrintFrame } from '../lib/usePrintFrame';
import { ConfirmDialog } from './admin/catalog/CatalogParts';
import NotFound from './NotFound';
import CartPanel from './pos/CartPanel';
import CatalogPanel from './pos/CatalogPanel';
import PosHeader from './pos/PosHeader';
import PosLookupPanel from './pos/PosLookupPanel';
import ShiftPanel from './pos/ShiftPanel';
import TenderPanel from './pos/TenderPanel';
import XReadingPanel from './pos/XReadingPanel';
import { clampedDiscountCents, fromThousandths, lineCents, moneyText, toCents, toThousandths } from './pos/posMoney';

const PAYMENT_METHODS = ['CASH', 'GCASH', 'MAYA', 'CARD', 'OTHER'];

const READINESS_LABELS = {
    fiscal_installation: 'This terminal has no fiscal installation assigned.',
    invoice_series: 'No active invoice series for this terminal’s fiscal installation.',
    inventory_location: 'No default inventory location is set for this store.',
    tax_registration: 'No current tax registration is set for this store.',
};

function newIdempotencyKey() {
    return crypto.randomUUID();
}

/**
 * The POS checkout screen (docs/06-ui/sitemap.md /pos, /pos/checkout).
 * Internal step state rather than separate routes for this pass --
 * sitemap.md is explicitly "proposed IA, not final routing." Every
 * total shown before the receipt step is a CLIENT-SIDE PREVIEW ONLY
 * (architecture.md: React computes a preview only) -- CheckoutService
 * always recomputes authoritatively server-side; nothing here is ever
 * trusted back.
 */
export default function Pos() {
    const { user } = useAuth();
    const navigate = useNavigate();
    const { pathname } = useLocation();
    const [step, setStep] = useState('loading'); // loading | not-enrolled | other-cashier-shift | open-shift | setup-incomplete | cart | checkout | receipt | close-shift | shift-closed | fiscal-day-closed
    const [error, setError] = useState(null);
    const [shift, setShift] = useState(null);
    const [readinessChecks, setReadinessChecks] = useState(null);
    /**
     * The till's three destinations are addressable (sitemap.md /pos, /pos/lookup): `view` is derived
     * from the URL rather than held in state, so the browser's Back button and a reload both land where
     * the cashier expects. One splat route keeps ONE Pos mounted across them -- separate <Route>
     * elements would remount it and silently discard an in-progress cart on every tab switch.
     *
     * The other steps are deliberately NOT addressable. `loading`, `not-enrolled`,
     * `other-cashier-shift`, `open-shift` and `setup-incomplete` are resolved from server state on
     * mount, so they are conditions rather than destinations; `checkout`, `close-shift` and the two
     * closing summaries depend on state that exists only in this component, so a URL for them could
     * never be reloaded into anything meaningful.
     */
    const sub = pathname.replace(/^\/pos\/?/, '');
    const view = sub === '' ? 'register' : sub;
    const goToView = (key) => navigate(key === 'register' ? '/pos' : `/pos/${key}`);
    const [terminalCode, setTerminalCode] = useState(null);

    const [openingCash, setOpeningCash] = useState('');
    const [openingBusy, setOpeningBusy] = useState(false);

    const [search, setSearch] = useState('');
    const [scanNotice, setScanNotice] = useState(null); // { kind: 'added' | 'error', text }
    const searchRef = useRef(null);
    const [results, setResults] = useState(null); // null = no search yet (the product grid shows), otherwise the search results
    const [cart, setCart] = useState([]); // [{product, quantity}]
    const [discount, setDiscount] = useState(''); // order-level discount, DISCOUNT_OVERRIDE only (CheckoutService rejects it otherwise)

    // sitemap.md's POS navigation rule: leaving /pos must never discard an in-progress cart silently.
    // The cart lives in this component's state, so unmounting is what destroys it -- the confirmation
    // has to happen before the route changes, not after. Holds the destination until the cashier decides.
    const [pendingExit, setPendingExit] = useState(null);
    // Senior Citizen (RA 9994) / PWD (RA 10754): 20% off the VAT-exclusive price, VAT exempt where the store
    // charges VAT at all. Unlike the discount box above, any cashier can use this -- it is the customer's own
    // legal entitlement, not a discretionary override -- but the exact amount is computed only by the server
    // (the VAT decomposition it runs is not duplicated here), so the total shown before Complete sale stays the
    // pre-discount figure: always enough to cover the real, lower charge, never a risk of under-tendering.
    const [statutoryDiscount, setStatutoryDiscount] = useState({ enabled: false, type: 'SENIOR_CITIZEN', rule: 'STANDARD_20', weeklyUsed: '', idNumber: '', name: '' });

    const [payments, setPayments] = useState([{ method: 'CASH', amount: '' }]); // [{method, amount}], split tender is 2+ rows
    const [checkoutBusy, setCheckoutBusy] = useState(false);

    const [sale, setSale] = useState(null);

    // Printing the invoice after checkout. The first print is the plain original (a read); every print after
    // that is a reprint -- marked REPRINT/COPY and recorded -- so no unmarked duplicate can come from here.
    const { print: printDocument, frame: printFrame } = usePrintFrame();
    const [originalPrinted, setOriginalPrinted] = useState(false);
    const [printBusy, setPrintBusy] = useState(false);
    const [printError, setPrintError] = useState(null);
    const [copyKey, setCopyKey] = useState(newIdempotencyKey);

    const [cashMovementType, setCashMovementType] = useState('CASH_IN');
    const [cashMovementAmount, setCashMovementAmount] = useState('');
    const [cashMovementReason, setCashMovementReason] = useState('');
    const [cashMovementBusy, setCashMovementBusy] = useState(false);
    const [cashMovementNotice, setCashMovementNotice] = useState(null);

    // Interim readings for the open shift. The list is what the till has seen this session plus whatever
    // was already on the shift; `latestXReading` is the one whose figures are on screen.
    const [xReadings, setXReadings] = useState([]);
    const [latestXReading, setLatestXReading] = useState(null);
    const [xReadingBusy, setXReadingBusy] = useState(false);
    const [xReadingError, setXReadingError] = useState(null);

    const [declaredCash, setDeclaredCash] = useState('');
    const [closeBusy, setCloseBusy] = useState(false);
    const [closeResult, setCloseResult] = useState(null);
    const [fiscalDayCloseResult, setFiscalDayCloseResult] = useState(null);

    // Non-authoritative pre-check (StoreSetupReadinessService mirrors, but
    // never calls, CheckoutService's own resolvers) -- lets a cashier see
    // a clear blocked state instead of attempting a checkout that is
    // guaranteed to fail with a setup-defect 500 (the exact bug a freshly
    // seeded store hit during this feature's own verification).
    const checkReadiness = useCallback(async () => {
        const { ok, body } = await apiFetch('/api/v1/store-setup/readiness');
        if (ok && !body.ready) {
            setReadinessChecks(body.checks);
            setStep('setup-incomplete');
        } else {
            setStep('cart');
        }
    }, []);

    const checkShiftState = useCallback(async () => {
        setStep('loading');
        setError(null);
        const { ok, status, body } = await apiFetch('/api/v1/shifts/current');
        if (ok) {
            setShift(body);
            // Only labels the header; the till works without it.
            apiFetch('/api/v1/terminal/current').then((current) => current.ok && setTerminalCode(current.body.terminal_code));
            // shiftCurrentGet resolves the TERMINAL's open shift, not this cashier's: filtering by
            // terminal alone happily returns a shift another cashier left open here. CheckoutService
            // checks terminal AND cashier and rejects that case -- but only at finalisation, which
            // would let a whole cart be rung up first and fail at Complete. Gate it here instead,
            // where the cashier can still do something about it. (Same reasoning as the backend's own
            // comment on that check: "Cashier A left a shift open, Cashier B is now logged in".)
            if (body.cashier_id !== user.id) {
                setStep('other-cashier-shift');
                return;
            }
            await checkReadiness();
        } else if (status === 403 && body?.error?.code === 'TERMINAL_NOT_ENROLLED') {
            setStep('not-enrolled');
        } else if (status === 404 && body?.error?.code === 'NO_CURRENT_SHIFT') {
            setStep('open-shift');
        } else {
            setError(body?.error?.message ?? 'Could not determine shift status.');
            setStep('open-shift');
        }
    }, [checkReadiness, user.id]);

    useEffect(() => {
        checkShiftState();
    }, [checkShiftState]);

    // Readings already taken on this shift (possibly on another browser). Session-only read, so it works
    // even where the terminal credential does not; a failure just leaves the list empty rather than
    // blocking the panel, since taking a new reading does not depend on it.
    useEffect(() => {
        if (view !== 'shift' || shift === null) {
            return undefined;
        }
        let cancelled = false;
        apiFetch(`/api/v1/shifts/${shift.id}/x-readings`).then(({ ok, body }) => {
            if (!cancelled && ok) {
                setXReadings(body);
            }
        });
        return () => {
            cancelled = true;
        };
    }, [view, shift]);

    async function openShift(event) {
        event.preventDefault();
        setOpeningBusy(true);
        setError(null);
        const { ok, body } = await apiFetch('/api/v1/shifts/open', {
            method: 'POST',
            headers: { 'Idempotency-Key': newIdempotencyKey() },
            body: { opening_cash: openingCash },
        });
        if (ok) {
            setShift(body.shift);
            await checkReadiness();
        } else {
            setError(body?.error?.message ?? 'Could not open a shift.');
        }
        setOpeningBusy(false);
    }

    async function runSearch(event) {
        event.preventDefault();
        const query = search.trim();
        setScanNotice(null);
        if (query === '') {
            setResults(null);
            return;
        }

        // A barcode scanner types the code and presses Enter, which submits this form. Try the text as a
        // barcode first; anything that is not one falls through to the ordinary name/SKU search below.
        if (/^\S{3,}$/.test(query)) {
            try {
                const scan = await apiFetch(`/api/v1/products/by-barcode/${encodeURIComponent(query)}`);
                if (scan.ok) {
                    // Inactive products are returned so the till can say why they cannot be sold.
                    setScanNotice(
                        scan.body.active
                            ? { kind: 'added', text: `Added ${scan.body.name}` }
                            : { kind: 'error', text: `${scan.body.name} is inactive and cannot be sold.` },
                    );
                    if (scan.body.active) {
                        addToCart(scan.body);
                    }
                    setSearch('');
                    setResults(null);
                    searchRef.current?.focus();
                    return;
                }
            } catch {
                // Could not reach the lookup: fall back to the ordinary search rather than blocking the cashier.
            }
        }

        const { ok, body } = await apiFetch(`/api/v1/products?active=1&search=${encodeURIComponent(query)}`);
        const found = ok ? body.data : [];
        setResults(found);
        if (found.length === 0) {
            setScanNotice({ kind: 'error', text: `No product found for “${query}”.` });
        }
    }

    function addToCart(product) {
        setCart((prior) => {
            const existing = prior.find((line) => line.product.id === product.id);
            if (existing) {
                return prior.map((line) =>
                    line.product.id === product.id ? { ...line, quantity: fromThousandths((toThousandths(line.quantity) ?? 0n) + 1000n) } : line,
                );
            }
            return [...prior, { product, quantity: '1', discount: '' }];
        });
    }

    function setQuantity(productId, quantity) {
        setCart((prior) => prior.map((line) => (line.product.id === productId ? { ...line, quantity } : line)));
    }

    function setLineDiscount(productId, discountText) {
        setCart((prior) => prior.map((line) => (line.product.id === productId ? { ...line, discount: discountText } : line)));
    }

    function removeFromCart(productId) {
        setCart((prior) => prior.filter((line) => line.product.id !== productId));
    }

    const canDiscount = user.capabilities.includes('DISCOUNT_OVERRIDE');
    const hasInvalidLine = cart.some((line) => lineCents(line.product.selling_price, line.quantity) === null);
    const statutoryDiscountIncomplete =
        statutoryDiscount.enabled &&
        (statutoryDiscount.idNumber.trim() === '' ||
            statutoryDiscount.name.trim() === '' ||
            (statutoryDiscount.rule === 'BNPC_5' && statutoryDiscount.weeklyUsed.trim() !== '' && toCents(statutoryDiscount.weeklyUsed) === null));

    // Whole centavos, never floating point. A line whose quantity is half-typed counts as nothing and blocks Charge.
    // "subtotalCents" is the raw pre-discount total (what CartPanel shows as "Subtotal"); each line's own discount
    // is clamped against its own gross so it can never make a line negative, then the order-level discount is
    // clamped against what is left. The sum a client sees here can only ever agree with, never exceed, what
    // CheckoutService independently recomputes -- clamping less generously than the server would only be
    // confusing, never unsafe.
    const grossLineCents = cart.map((line) => lineCents(line.product.selling_price, line.quantity) ?? 0n);
    const lineDiscountCentsByLine = cart.map((line, index) => (canDiscount ? clampedDiscountCents(line.discount, grossLineCents[index]) : 0n));
    const subtotalCents = grossLineCents.reduce((sum, cents) => sum + cents, 0n);
    const afterLineDiscountsCents = grossLineCents.reduce((sum, cents, index) => sum + cents - lineDiscountCentsByLine[index], 0n);
    const orderDiscountCents = canDiscount ? clampedDiscountCents(discount, afterLineDiscountsCents) : 0n;
    const totalCents = afterLineDiscountsCents - orderDiscountCents;

    function goToCheckout() {
        setPayments([{ method: 'CASH', amount: moneyText(totalCents) }]);
        setError(null);
        setStep('checkout');
    }

    async function completeSale(event) {
        event.preventDefault();
        setCheckoutBusy(true);
        setError(null);

        const { ok, body } = await apiFetch('/api/v1/sales', {
            method: 'POST',
            headers: { 'Idempotency-Key': newIdempotencyKey() },
            body: {
                items: cart.map((line, index) => ({
                    product_id: line.product.id,
                    quantity: String(line.quantity),
                    ...(lineDiscountCentsByLine[index] > 0n ? { line_discount_amount: moneyText(lineDiscountCentsByLine[index]) } : {}),
                })),
                // A blank/zero row (shown while a split payment is still being entered) is never sent.
                payments: payments
                    .filter((payment) => (toCents(payment.amount) ?? 0n) > 0n)
                    .map((payment) => ({ method: payment.method, amount: moneyText(toCents(payment.amount) ?? 0n) })),
                ...(orderDiscountCents > 0n ? { order_level_discount_amount: moneyText(orderDiscountCents) } : {}),
                ...(statutoryDiscount.enabled
                    ? {
                          statutory_discount: {
                              type: statutoryDiscount.type,
                              rule: statutoryDiscount.rule,
                              ...(statutoryDiscount.rule === 'BNPC_5' && statutoryDiscount.weeklyUsed.trim() !== ''
                                  ? { weekly_discount_used: moneyText(toCents(statutoryDiscount.weeklyUsed) ?? 0n) }
                                  : {}),
                              id_number: statutoryDiscount.idNumber.trim(),
                              name: statutoryDiscount.name.trim(),
                          },
                      }
                    : {}),
            },
        });

        if (ok) {
            setSale(body);
            setStep('receipt');
        } else {
            setError(body?.error?.message ?? 'Checkout failed.');
        }
        setCheckoutBusy(false);
    }

    async function printInvoice() {
        setPrintBusy(true);
        setPrintError(null);
        try {
            const invoiceId = sale.invoice.id;
            const response = originalPrinted
                ? await apiFetch(`/api/v1/invoices/${invoiceId}/reprints`, { method: 'POST', headers: { 'Idempotency-Key': copyKey } })
                : await apiFetch(`/api/v1/invoices/${invoiceId}`);
            if (response.ok) {
                printDocument(originalPrinted ? response.body.invoice.render_html : response.body.render_html);
                setOriginalPrinted(true);
                setCopyKey(newIdempotencyKey()); // the next copy is a new copy, not a retry
            } else {
                setCopyKey(newIdempotencyKey());
                setPrintError(
                    response.body?.error?.code === 'TERMINAL_NOT_ENROLLED'
                        ? 'This browser is not enrolled as a terminal, so it cannot record a printed copy.'
                        : 'The invoice could not be prepared for printing. The sale is complete; you can print it from Sales history.',
                );
            }
        } catch {
            // Network failure: keep the same key, so pressing the button again can never record two copies.
            setPrintError('The connection dropped. The sale is complete. Press the button to try printing again.');
        }
        setPrintBusy(false);
    }

    function startNewSale() {
        setScanNotice(null);
        setOriginalPrinted(false);
        setPrintError(null);
        setCopyKey(newIdempotencyKey());
        setCart([]);
        setDiscount('');
        setStatutoryDiscount({ enabled: false, type: 'SENIOR_CITIZEN', rule: 'STANDARD_20', weeklyUsed: '', idNumber: '', name: '' });
        setPayments([{ method: 'CASH', amount: '' }]);
        setResults(null);
        setSearch('');
        setSale(null);
        goToView('register');
        setStep('cart');
    }

    async function recordCashMovement(event) {
        event.preventDefault();
        setCashMovementBusy(true);
        setError(null);
        setCashMovementNotice(null);

        const { ok, body } = await apiFetch(`/api/v1/shifts/${shift.id}/cash-movements`, {
            method: 'POST',
            headers: { 'Idempotency-Key': newIdempotencyKey() },
            body: { type: cashMovementType, amount: Number(cashMovementAmount).toFixed(2), reason: cashMovementReason },
        });

        if (ok) {
            setCashMovementNotice(`Recorded ${cashMovementType === 'CASH_IN' ? 'cash in' : 'cash out'}: ₱${body.amount}`);
            setCashMovementAmount('');
            setCashMovementReason('');
        } else {
            setError(body?.error?.message ?? 'Could not record the cash movement.');
        }
        setCashMovementBusy(false);
    }

    /**
     * Taking a reading writes a new audit record every time (no Idempotency-Key exists for this operation
     * in the contract -- two readings a second apart are two legitimate readings, not a double submission),
     * so the button is disabled while one is in flight rather than retried.
     */
    async function takeXReading() {
        setXReadingBusy(true);
        setXReadingError(null);

        const { ok, body } = await apiFetch(`/api/v1/shifts/${shift.id}/x-readings`, { method: 'POST' });

        if (ok) {
            setLatestXReading(body);
            setXReadings((prior) => [...prior, body]);
        } else {
            setXReadingError(
                body?.error?.code === 'TERMINAL_NOT_ENROLLED'
                    ? 'This browser is not enrolled as a terminal, so it cannot take a reading.'
                    : (body?.error?.message ?? 'The reading could not be taken.'),
            );
        }
        setXReadingBusy(false);
    }

    function goToCloseShift() {
        setError(null);
        setDeclaredCash('');
        setStep('close-shift');
    }

    async function closeShift(event) {
        event.preventDefault();
        setCloseBusy(true);
        setError(null);

        const { ok, body } = await apiFetch(`/api/v1/shifts/${shift.id}/close`, {
            method: 'POST',
            headers: { 'Idempotency-Key': newIdempotencyKey() },
            body: { declared_cash: Number(declaredCash).toFixed(2) },
        });

        if (ok) {
            setCloseResult(body);
            setStep('shift-closed');
        } else {
            setError(body?.error?.message ?? 'Could not close the shift.');
        }
        setCloseBusy(false);
    }

    async function closeFiscalDay() {
        setCloseBusy(true);
        setError(null);

        const { ok, body } = await apiFetch(`/api/v1/fiscal-days/${closeResult.shift.fiscal_day_id}/close`, {
            method: 'POST',
            headers: { 'Idempotency-Key': newIdempotencyKey() },
        });

        if (ok) {
            setFiscalDayCloseResult(body);
            setStep('fiscal-day-closed');
        } else {
            setError(body?.error?.message ?? 'Could not close the business day.');
        }
        setCloseBusy(false);
    }

    // The splat route means this component now receives every /pos/* path, so it owns the 404 for the
    // ones it does not define -- otherwise the router's catch-all would never see them.
    if (!['register', 'lookup', 'shift'].includes(view)) {
        return <NotFound />;
    }

    if (step === 'loading') {
        return <div className="min-h-screen bg-slate-950 p-8 text-sm text-slate-400">Loading…</div>;
    }

    if (step === 'not-enrolled') {
        return (
            <div className="min-h-screen bg-slate-950 text-slate-100 [color-scheme:dark]">
                <div className="mx-auto max-w-md p-8 text-center">
                    <p className="rounded-md bg-amber-500/10 px-3 py-3 text-sm text-amber-300">
                        This browser is not enrolled as any terminal. Ask an admin to enroll it under Terminal Enrollment.
                    </p>
                    <Link to="/" className="mt-4 inline-block text-sm text-slate-400 underline">
                        Back to dashboard
                    </Link>
                </div>
            </div>
        );
    }

    // A shift another cashier left open on this terminal. Only one shift can be open per terminal
    // (shifts_one_open_per_terminal), so opening your own is not an option until this one is closed --
    // and closing is terminal-scoped, not cashier-scoped, so whoever is at the till may do it. The
    // drawer still gets counted blind: closing goes through the same declared-cash step as always.
    if (step === 'other-cashier-shift') {
        return (
            <div className="min-h-screen bg-slate-950 text-slate-100 [color-scheme:dark]">
                <div className="mx-auto max-w-md p-8 text-center">
                    <p className="rounded-md bg-amber-500/10 px-3 py-3 text-sm text-amber-300">
                        Another cashier still has a shift open on this till. You cannot sell until it is closed, and only one shift can be open here at
                        a time.
                    </p>
                    {shift?.opened_at && <p className="mt-2 font-mono text-[11px] text-slate-500">open since {new Date(shift.opened_at).toLocaleString()}</p>}
                    <button
                        type="button"
                        onClick={goToCloseShift}
                        className="mt-4 min-h-12 w-full rounded bg-emerald-500 px-4 text-sm font-bold uppercase tracking-wider text-slate-950 hover:bg-emerald-400"
                    >
                        Count the drawer and close it
                    </button>
                    <p className="mt-2 text-xs text-slate-500">You will be asked to count the cash in the drawer, exactly as the cashier who opened it would be.</p>
                    <Link to="/" className="mt-4 inline-block text-sm text-slate-400 underline">
                        Back to dashboard
                    </Link>
                </div>
            </div>
        );
    }

    if (step === 'setup-incomplete') {
        const failed = Object.entries(readinessChecks ?? {}).filter(([, ready]) => !ready);
        return (
            <div className="min-h-screen bg-slate-950 text-slate-100 [color-scheme:dark]">
                <div className="mx-auto max-w-md p-8 text-center">
                    <p className="mb-3 rounded-md bg-amber-500/10 px-3 py-3 text-sm text-amber-300">
                        This terminal can&apos;t check out yet. Ask an admin to finish Store Setup:
                    </p>
                    <ul className="mb-4 space-y-1 text-left text-sm text-slate-300">
                        {failed.map(([key]) => (
                            <li key={key} className="rounded-md border border-amber-500/30 bg-slate-900 px-3 py-2">
                                {READINESS_LABELS[key] ?? key}
                            </li>
                        ))}
                    </ul>
                    <button
                        type="button"
                        onClick={checkReadiness}
                        className="rounded-md border border-slate-700 px-4 py-2 text-sm hover:bg-slate-800"
                    >
                        Check again
                    </button>
                    <Link to="/" className="mt-4 block text-sm text-slate-400 underline">
                        Back to dashboard
                    </Link>
                </div>
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100 [color-scheme:dark]">
            <PosHeader
                terminalCode={terminalCode}
                operatorName={user.name}
                shift={shift}
                showTabs={step === 'cart' || step === 'checkout'}
                view={view}
                paying={step === 'checkout'}
                onView={goToView}
                onLeave={cart.length > 0 ? setPendingExit : undefined}
            />

            {pendingExit !== null && (
                <ConfirmDialog
                    title="Leave the till with a sale in progress?"
                    body={`${cart.length === 1 ? 'One item is' : `${cart.length} items are`} in the cart and nothing has been charged yet. Leaving clears the cart — the sale is not saved anywhere until you take payment.`}
                    confirmLabel="Leave and clear the cart"
                    onConfirm={() => {
                        const destination = pendingExit;
                        setPendingExit(null);
                        navigate(destination);
                    }}
                    onCancel={() => setPendingExit(null)}
                />
            )}

            {error && <p className="mx-4 mt-3 rounded-md border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-300">{error}</p>}

            {step === 'open-shift' && (
                <form onSubmit={openShift} className="mx-auto mt-8 max-w-sm space-y-3 rounded-lg border border-slate-700 bg-slate-900 p-6">
                    <h2 className="text-sm font-medium text-slate-300">Open a shift to start selling</h2>
                    <label htmlFor="opening_cash" className="block text-sm text-slate-400">
                        Opening cash
                    </label>
                    <input
                        id="opening_cash"
                        type="text"
                        inputMode="decimal"
                        placeholder="0.00"
                        value={openingCash}
                        onChange={(event) => setOpeningCash(event.target.value)}
                        className="w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 focus:border-emerald-500 focus:outline-none"
                    />
                    <button
                        type="submit"
                        disabled={openingBusy || !openingCash}
                        className="w-full rounded-md bg-emerald-500 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-emerald-400 disabled:opacity-50"
                    >
                        {openingBusy ? 'Opening…' : 'Open shift'}
                    </button>
                </form>
            )}

            {step === 'cart' && view === 'lookup' && <PosLookupPanel canSeeAllSales={user.capabilities.includes('REPORT_VIEW')} />}

            {step === 'cart' && view === 'shift' && (
                <ShiftPanel
                    shift={shift}
                    type={cashMovementType}
                    amount={cashMovementAmount}
                    reason={cashMovementReason}
                    notice={cashMovementNotice}
                    busy={cashMovementBusy}
                    onType={setCashMovementType}
                    onAmount={setCashMovementAmount}
                    onReason={setCashMovementReason}
                    onRecord={recordCashMovement}
                    onCloseShift={goToCloseShift}
                >
                    <XReadingPanel
                        readings={xReadings}
                        latest={latestXReading}
                        cashVisible={user.capabilities.includes('REPORT_VIEW')}
                        busy={xReadingBusy}
                        error={xReadingError}
                        onTake={takeXReading}
                    />
                </ShiftPanel>
            )}

            {step === 'cart' && view === 'register' && (
                <div className="mx-auto grid w-full max-w-[1600px] grid-cols-1 gap-4 p-4 lg:h-[calc(100dvh-4.5rem)] lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:grid-rows-[minmax(0,1fr)]">
                    <div className="flex min-h-0 flex-col lg:order-2">
                        <CatalogPanel
                            search={search}
                            onSearchChange={setSearch}
                            onSearch={runSearch}
                            onClearSearch={() => {
                                setSearch('');
                                setResults(null);
                                searchRef.current?.focus();
                            }}
                            searchRef={searchRef}
                            scanNotice={scanNotice}
                            results={results}
                            onAdd={addToCart}
                        />
                    </div>
                    <div className="flex min-h-0 flex-col lg:order-1">
                        <CartPanel
                            cart={cart}
                            subtotalCents={subtotalCents}
                            discount={discount}
                            onDiscountChange={setDiscount}
                            onLineDiscountChange={setLineDiscount}
                            canDiscount={canDiscount}
                            statutoryDiscount={statutoryDiscount}
                            onStatutoryDiscountChange={setStatutoryDiscount}
                            statutoryDiscountIncomplete={statutoryDiscountIncomplete}
                            totalCents={totalCents}
                            hasInvalidLine={hasInvalidLine}
                            onQuantity={setQuantity}
                            onRemove={removeFromCart}
                            onCharge={goToCheckout}
                        />
                    </div>
                </div>
            )}

            {step === 'checkout' && (
                <TenderPanel
                    cart={cart}
                    totalCents={totalCents}
                    methods={PAYMENT_METHODS}
                    payments={payments}
                    onPayments={setPayments}
                    busy={checkoutBusy}
                    onBack={() => setStep('cart')}
                    onComplete={completeSale}
                />
            )}

            {step === 'receipt' && sale && (
                <div className="mx-auto mt-8 max-w-sm space-y-3 rounded-lg border border-slate-700 bg-slate-900 p-6">
                    <h2 className="text-sm font-medium text-slate-300">Sale complete</h2>
                    <dl className="space-y-1 text-sm">
                        <div className="flex justify-between">
                            <dt className="text-slate-400">Transaction</dt>
                            <dd className="font-mono">{sale.transaction_number}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-slate-400">Invoice #</dt>
                            <dd className="font-mono">{sale.invoice_number}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-slate-400">Grand total</dt>
                            <dd className="font-mono font-semibold">₱{sale.grand_total}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-slate-400">Tendered</dt>
                            <dd className="font-mono">₱{sale.amount_tendered}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-slate-400">Change</dt>
                            <dd className="font-mono">₱{sale.change}</dd>
                        </div>
                    </dl>
                    <ul className="divide-y divide-slate-800 border-t border-slate-800 pt-2 text-sm">
                        {sale.items.map((item) => (
                            <li key={item.id} className="flex justify-between py-1">
                                <span>
                                    {item.quantity} × {item.product_name_snapshot}
                                </span>
                                <span className="font-mono">₱{item.net_line_amount}</span>
                            </li>
                        ))}
                    </ul>
                    {sale.invoice && (
                        <div className="space-y-1">
                            <button
                                type="button"
                                disabled={printBusy}
                                onClick={printInvoice}
                                className="w-full rounded-md border border-slate-700 px-4 py-2 text-sm font-medium text-slate-100 hover:bg-slate-800 disabled:opacity-50"
                            >
                                {printBusy ? 'Preparing…' : originalPrinted ? 'Print another copy' : 'Print invoice'}
                            </button>
                            {originalPrinted && <p className="text-xs text-slate-400">Another copy is marked REPRINT — COPY and recorded.</p>}
                            {printError && (
                                <p role="alert" className="rounded-md bg-red-500/10 px-3 py-2 text-xs text-red-300">
                                    {printError}
                                </p>
                            )}
                        </div>
                    )}
                    <button
                        type="button"
                        onClick={startNewSale}
                        className="w-full rounded-md bg-emerald-500 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-emerald-400"
                    >
                        New sale
                    </button>
                    {printFrame}
                </div>
            )}

            {step === 'close-shift' && (
                <form onSubmit={closeShift} className="mx-auto mt-8 max-w-sm space-y-3 rounded-lg border border-slate-700 bg-slate-900 p-6">
                    <h2 className="text-sm font-medium text-slate-300">Close shift</h2>
                    <p className="text-xs text-slate-400">
                        Count the cash in the drawer and enter it below. The system computes the expected amount and
                        any variance after you submit -- it is never shown to you beforehand.
                    </p>
                    <label htmlFor="declared_cash" className="block text-sm text-slate-400">
                        Counted cash
                    </label>
                    <input
                        id="declared_cash"
                        type="text"
                        inputMode="decimal"
                        placeholder="0.00"
                        value={declaredCash}
                        onChange={(event) => setDeclaredCash(event.target.value)}
                        className="w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 focus:border-emerald-500 focus:outline-none"
                    />
                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={() => setStep('cart')}
                            className="flex-1 rounded-md border border-slate-700 px-4 py-2 text-sm hover:bg-slate-800"
                        >
                            Back
                        </button>
                        <button
                            type="submit"
                            disabled={closeBusy || !declaredCash}
                            className="flex-1 rounded-md bg-emerald-500 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-emerald-400 disabled:opacity-50"
                        >
                            {closeBusy ? 'Closing…' : 'Close shift'}
                        </button>
                    </div>
                </form>
            )}

            {step === 'shift-closed' && closeResult && (
                <div className="mx-auto mt-8 max-w-sm space-y-3 rounded-lg border border-slate-700 bg-slate-900 p-6">
                    <h2 className="text-sm font-medium text-slate-300">Shift closed</h2>
                    <dl className="space-y-1 text-sm">
                        <div className="flex justify-between">
                            <dt className="text-slate-400">Expected cash</dt>
                            <dd className="font-mono">₱{closeResult.shift.expected_cash}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-slate-400">Counted cash</dt>
                            <dd className="font-mono">₱{closeResult.shift.declared_cash}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-slate-400">Variance</dt>
                            <dd className={`font-mono font-semibold ${Number(closeResult.shift.variance) < 0 ? 'text-red-400' : 'text-slate-100'}`}>
                                ₱{closeResult.shift.variance}
                            </dd>
                        </div>
                    </dl>

                    {user.capabilities.includes('FISCAL_DAY_CLOSE') && (
                        <button
                            type="button"
                            disabled={closeBusy}
                            onClick={closeFiscalDay}
                            className="w-full rounded-md bg-emerald-500 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-emerald-400 disabled:opacity-50"
                        >
                            {closeBusy ? 'Closing…' : 'Close business day'}
                        </button>
                    )}
                    <Link to="/" className="block text-center text-sm text-slate-400 underline">
                        Back to dashboard
                    </Link>
                </div>
            )}

            {step === 'fiscal-day-closed' && fiscalDayCloseResult && (
                <div className="mx-auto mt-8 max-w-sm space-y-3 rounded-lg border border-slate-700 bg-slate-900 p-6">
                    <h2 className="text-sm font-medium text-slate-300">Business day closed</h2>
                    <dl className="space-y-1 text-sm">
                        <div className="flex justify-between">
                            <dt className="text-slate-400">Z-Reading #</dt>
                            <dd className="font-mono">{fiscalDayCloseResult.z_reading.totals_snapshot.z_counter}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-slate-400">Gross sales</dt>
                            <dd className="font-mono font-semibold">₱{fiscalDayCloseResult.z_reading.totals_snapshot.gross_sales}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-slate-400">VAT</dt>
                            <dd className="font-mono">₱{fiscalDayCloseResult.z_reading.totals_snapshot.vat_amount}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-slate-400">Void total</dt>
                            <dd className="font-mono">₱{fiscalDayCloseResult.z_reading.totals_snapshot.void_total}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-slate-400">Refund total</dt>
                            <dd className="font-mono">₱{fiscalDayCloseResult.z_reading.totals_snapshot.refund_total}</dd>
                        </div>
                    </dl>
                    <Link to="/" className="block text-center text-sm text-slate-400 underline">
                        Back to dashboard
                    </Link>
                </div>
            )}
        </div>
    );
}
