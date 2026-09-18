import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiFetch } from '../api';

const PAYMENT_METHODS = ['CASH', 'GCASH', 'MAYA', 'CARD', 'OTHER'];

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
    const [step, setStep] = useState('loading'); // loading | not-enrolled | open-shift | cart | checkout | receipt
    const [error, setError] = useState(null);
    const [shift, setShift] = useState(null);

    const [openingCash, setOpeningCash] = useState('');
    const [openingBusy, setOpeningBusy] = useState(false);

    const [search, setSearch] = useState('');
    const [results, setResults] = useState([]);
    const [cart, setCart] = useState([]); // [{product, quantity}]

    const [paymentMethod, setPaymentMethod] = useState('CASH');
    const [paymentAmount, setPaymentAmount] = useState('');
    const [checkoutBusy, setCheckoutBusy] = useState(false);

    const [sale, setSale] = useState(null);

    const checkShiftState = useCallback(async () => {
        setStep('loading');
        setError(null);
        const { ok, status, body } = await apiFetch('/api/v1/shifts/current');
        if (ok) {
            setShift(body);
            setStep('cart');
        } else if (status === 403 && body?.error?.code === 'TERMINAL_NOT_ENROLLED') {
            setStep('not-enrolled');
        } else if (status === 404 && body?.error?.code === 'NO_CURRENT_SHIFT') {
            setStep('open-shift');
        } else {
            setError(body?.error?.message ?? 'Could not determine shift status.');
            setStep('open-shift');
        }
    }, []);

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
            setStep('cart');
        } else {
            setError(body?.error?.message ?? 'Could not open a shift.');
        }
        setOpeningBusy(false);
    }

    async function runSearch(event) {
        event.preventDefault();
        const { ok, body } = await apiFetch(`/api/v1/products?active=1&search=${encodeURIComponent(search)}`);
        setResults(ok ? body.data : []);
    }

    function addToCart(product) {
        setCart((prior) => {
            const existing = prior.find((line) => line.product.id === product.id);
            if (existing) {
                return prior.map((line) =>
                    line.product.id === product.id ? { ...line, quantity: line.quantity + 1 } : line,
                );
            }
            return [...prior, { product, quantity: 1 }];
        });
    }

    function setQuantity(productId, quantity) {
        setCart((prior) => prior.map((line) => (line.product.id === productId ? { ...line, quantity } : line)));
    }

    function removeFromCart(productId) {
        setCart((prior) => prior.filter((line) => line.product.id !== productId));
    }

    const previewTotal = cart.reduce((sum, line) => sum + Number(line.product.selling_price) * Number(line.quantity || 0), 0);

    function goToCheckout() {
        setPaymentAmount(previewTotal.toFixed(2));
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
                payments: [{ method: paymentMethod, amount: Number(paymentAmount).toFixed(2) }],
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

    function startNewSale() {
        setCart([]);
        setResults([]);
        setSearch('');
        setSale(null);
        setStep('cart');
    }

    if (step === 'loading') {
        return <div className="p-8 text-sm text-gray-500">Loading…</div>;
    }

    if (step === 'not-enrolled') {
        return (
            <div className="mx-auto max-w-md p-8 text-center">
                <p className="rounded-md bg-amber-50 px-3 py-3 text-sm text-amber-800">
                    This browser is not enrolled as any terminal. Ask an admin to enroll it under Terminal Enrollment.
                </p>
                <Link to="/" className="mt-4 inline-block text-sm text-gray-600 underline">
                    Back to dashboard
                </Link>
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-gray-50">
            <header className="flex items-center justify-between border-b border-gray-200 bg-white px-4 py-3">
                <h1 className="text-base font-semibold text-gray-900">POS</h1>
                <Link to="/" className="text-sm text-gray-600 underline">
                    Dashboard
                </Link>
            </header>

            {error && <p className="mx-4 mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}

            {step === 'open-shift' && (
                <form onSubmit={openShift} className="mx-auto mt-8 max-w-sm space-y-3 rounded-lg border border-gray-200 bg-white p-6">
                    <h2 className="text-sm font-medium text-gray-700">Open a shift to start selling</h2>
                    <label htmlFor="opening_cash" className="block text-sm text-gray-600">
                        Opening cash
                    </label>
                    <input
                        id="opening_cash"
                        type="text"
                        inputMode="decimal"
                        placeholder="0.00"
                        value={openingCash}
                        onChange={(event) => setOpeningCash(event.target.value)}
                        className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                    />
                    <button
                        type="submit"
                        disabled={openingBusy || !openingCash}
                        className="w-full rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
                    >
                        {openingBusy ? 'Opening…' : 'Open shift'}
                    </button>
                </form>
            )}

            {step === 'cart' && (
                <div className="mx-auto grid max-w-4xl grid-cols-1 gap-4 p-4 sm:grid-cols-2">
                    <div className="space-y-3">
                        <form onSubmit={runSearch} className="flex gap-2">
                            <input
                                type="text"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Search by name or SKU"
                                className="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm"
                            />
                            <button type="submit" className="rounded-md border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50">
                                Search
                            </button>
                        </form>
                        <ul className="divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white">
                            {results.length === 0 && <li className="p-3 text-sm text-gray-400">No results.</li>}
                            {results.map((product) => (
                                <li key={product.id} className="flex items-center justify-between p-3">
                                    <div>
                                        <p className="text-sm font-medium text-gray-900">{product.name}</p>
                                        <p className="text-xs text-gray-500">
                                            {product.sku} · ₱{product.selling_price}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => addToCart(product)}
                                        className="rounded-md bg-gray-900 px-3 py-1 text-xs font-medium text-white hover:bg-gray-800"
                                    >
                                        Add
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <div className="space-y-3">
                        <div className="rounded-lg border border-gray-200 bg-white">
                            <h2 className="border-b border-gray-100 px-3 py-2 text-sm font-medium text-gray-700">Cart</h2>
                            <ul className="divide-y divide-gray-100">
                                {cart.length === 0 && <li className="p-3 text-sm text-gray-400">Cart is empty.</li>}
                                {cart.map((line) => (
                                    <li key={line.product.id} className="flex items-center justify-between gap-2 p-3">
                                        <span className="flex-1 text-sm text-gray-900">{line.product.name}</span>
                                        <input
                                            type="text"
                                            inputMode="decimal"
                                            value={line.quantity}
                                            onChange={(event) => setQuantity(line.product.id, event.target.value)}
                                            className="w-16 rounded-md border border-gray-300 px-2 py-1 text-right text-sm"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => removeFromCart(line.product.id)}
                                            className="text-xs text-red-600 underline"
                                        >
                                            Remove
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </div>

                        <div className="rounded-lg border border-gray-200 bg-white p-3">
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-gray-500">Preview total (server recomputes)</span>
                                <span className="font-mono font-semibold">₱{previewTotal.toFixed(2)}</span>
                            </div>
                        </div>

                        <button
                            type="button"
                            disabled={cart.length === 0}
                            onClick={goToCheckout}
                            className="w-full rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
                        >
                            Proceed to payment
                        </button>
                    </div>
                </div>
            )}

            {step === 'checkout' && (
                <form onSubmit={completeSale} className="mx-auto mt-8 max-w-sm space-y-3 rounded-lg border border-gray-200 bg-white p-6">
                    <h2 className="text-sm font-medium text-gray-700">Payment</h2>
                    <p className="text-sm text-gray-500">
                        Preview total: <span className="font-mono">₱{previewTotal.toFixed(2)}</span>
                    </p>

                    <label htmlFor="payment_method" className="block text-sm text-gray-600">
                        Method
                    </label>
                    <select
                        id="payment_method"
                        value={paymentMethod}
                        onChange={(event) => setPaymentMethod(event.target.value)}
                        className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                    >
                        {PAYMENT_METHODS.map((method) => (
                            <option key={method} value={method}>
                                {method}
                            </option>
                        ))}
                    </select>

                    <label htmlFor="payment_amount" className="block text-sm text-gray-600">
                        Amount tendered
                    </label>
                    <input
                        id="payment_amount"
                        type="text"
                        inputMode="decimal"
                        value={paymentAmount}
                        onChange={(event) => setPaymentAmount(event.target.value)}
                        className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                    />

                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={() => setStep('cart')}
                            className="flex-1 rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50"
                        >
                            Back
                        </button>
                        <button
                            type="submit"
                            disabled={checkoutBusy}
                            className="flex-1 rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
                        >
                            {checkoutBusy ? 'Completing…' : 'Complete sale'}
                        </button>
                    </div>
                </form>
            )}

            {step === 'receipt' && sale && (
                <div className="mx-auto mt-8 max-w-sm space-y-3 rounded-lg border border-gray-200 bg-white p-6">
                    <h2 className="text-sm font-medium text-gray-700">Sale complete</h2>
                    <dl className="space-y-1 text-sm">
                        <div className="flex justify-between">
                            <dt className="text-gray-500">Transaction</dt>
                            <dd className="font-mono">{sale.transaction_number}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-gray-500">Invoice #</dt>
                            <dd className="font-mono">{sale.invoice_number}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-gray-500">Grand total</dt>
                            <dd className="font-mono font-semibold">₱{sale.grand_total}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-gray-500">Tendered</dt>
                            <dd className="font-mono">₱{sale.amount_tendered}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-gray-500">Change</dt>
                            <dd className="font-mono">₱{sale.change}</dd>
                        </div>
                    </dl>
                    <ul className="divide-y divide-gray-100 border-t border-gray-100 pt-2 text-sm">
                        {sale.items.map((item) => (
                            <li key={item.id} className="flex justify-between py-1">
                                <span>
                                    {item.quantity} × {item.product_name_snapshot}
                                </span>
                                <span className="font-mono">₱{item.net_line_amount}</span>
                            </li>
                        ))}
                    </ul>
                    <button
                        type="button"
                        onClick={startNewSale}
                        className="w-full rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
                    >
                        New sale
                    </button>
                </div>
            )}
        </div>
    );
}
