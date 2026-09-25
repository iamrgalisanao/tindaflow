import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiFetch } from '../api';
import { useAuth } from '../context/AuthContext';
import { usePrintFrame } from '../lib/usePrintFrame';
import CartPanel from './pos/CartPanel';
import CatalogPanel from './pos/CatalogPanel';
import PosHeader from './pos/PosHeader';
import ShiftPanel from './pos/ShiftPanel';
import TenderPanel from './pos/TenderPanel';
import { fromThousandths, lineCents, moneyText, toCents, toThousandths } from './pos/posMoney';

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
    const [step, setStep] = useState('loading'); // loading | not-enrolled | open-shift | setup-incomplete | cart | checkout | receipt | close-shift | shift-closed | fiscal-day-closed
    const [error, setError] = useState(null);
    const [shift, setShift] = useState(null);
    const [readinessChecks, setReadinessChecks] = useState(null);
    const [view, setView] = useState('register'); // register | shift (only while a cart is open)
    const [terminalCode, setTerminalCode] = useState(null);

    const [openingCash, setOpeningCash] = useState('');
    const [openingBusy, setOpeningBusy] = useState(false);

    const [search, setSearch] = useState('');
    const [scanNotice, setScanNotice] = useState(null); // { kind: 'added' | 'error', text }
    const searchRef = useRef(null);
    const [results, setResults] = useState(null); // null = no search yet (the product grid shows), otherwise the search results
    const [cart, setCart] = useState([]); // [{product, quantity}]
    const [discount, setDiscount] = useState(''); // order-level discount, DISCOUNT_OVERRIDE only (CheckoutService rejects it otherwise)

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
            await checkReadiness();
        } else if (status === 403 && body?.error?.code === 'TERMINAL_NOT_ENROLLED') {
            setStep('not-enrolled');
        } else if (status === 404 && body?.error?.code === 'NO_CURRENT_SHIFT') {
            setStep('open-shift');
        } else {
            setError(body?.error?.message ?? 'Could not determine shift status.');
            setStep('open-shift');
        }
    }, [checkReadiness]);

    useEffect(() => {
        checkShiftState();
    }, [checkShiftState]);

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
            return [...prior, { product, quantity: '1' }];
        });
    }

    function setQuantity(productId, quantity) {
        setCart((prior) => prior.map((line) => (line.product.id === productId ? { ...line, quantity } : line)));
    }

    function removeFromCart(productId) {
        setCart((prior) => prior.filter((line) => line.product.id !== productId));
    }

    // Whole centavos, never floating point. A line whose quantity is half-typed counts as nothing and blocks Charge.
    const subtotalCents = cart.reduce((sum, line) => sum + (lineCents(line.product.selling_price, line.quantity) ?? 0n), 0n);
    const hasInvalidLine = cart.some((line) => lineCents(line.product.selling_price, line.quantity) === null);
    const canDiscount = user.capabilities.includes('DISCOUNT_OVERRIDE');
    // Clamped so the preview can never go negative; CheckoutService is the real authority either way.
    const rawDiscountCents = canDiscount ? (toCents(discount) ?? 0n) : 0n;
    const discountCents = rawDiscountCents > subtotalCents ? subtotalCents : rawDiscountCents;
    const totalCents = subtotalCents - discountCents;

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
                items: cart.map((line) => ({ product_id: line.product.id, quantity: String(line.quantity) })),
                // A blank/zero row (shown while a split payment is still being entered) is never sent.
                payments: payments
                    .filter((payment) => (toCents(payment.amount) ?? 0n) > 0n)
                    .map((payment) => ({ method: payment.method, amount: moneyText(toCents(payment.amount) ?? 0n) })),
                ...(discountCents > 0n ? { order_level_discount_amount: moneyText(discountCents) } : {}),
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
        setPayments([{ method: 'CASH', amount: '' }]);
        setResults(null);
        setSearch('');
        setSale(null);
        setView('register');
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
                onView={setView}
            />

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
                />
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
                            canDiscount={canDiscount}
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
