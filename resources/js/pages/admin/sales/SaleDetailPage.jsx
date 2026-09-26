import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';
import AdminLayout from '../AdminLayout';
import { ErrorAlert, Toast } from '../catalog/CatalogParts';
import { RETURN_KEY, failureMessage, request } from '../catalog/catalogApi';
import { formatDateTime, formatMoney, formatQuantity, shortId } from '../reports/formatters';
import InvoicePanel from './InvoicePanel';
import RefundPanel from './RefundPanel';
import { fromCents, toCents } from './refundMath';
import VoidSalePanel from './VoidSalePanel';
import { StatusBadge, useTerminalState } from './salesParts';

function Section({ title, children, aside }) {
    return (
        <section className="rounded-lg border border-slate-800 bg-slate-900">
            <div className="flex items-center justify-between border-b border-slate-800 px-4 py-2.5">
                <h3 className="font-mono text-[11px] font-semibold uppercase tracking-wider text-slate-400">{title}</h3>
                {aside}
            </div>
            <div className="p-4">{children}</div>
        </section>
    );
}

function Row({ label, value, strong }) {
    return (
        <div className="flex items-baseline justify-between gap-4 py-0.5 text-sm">
            <span className="text-slate-400">{label}</span>
            <span className={`font-mono tabular-nums ${strong ? 'text-base font-semibold text-slate-100' : 'text-slate-200'}`}>{value}</span>
        </div>
    );
}

/**
 * /admin/sales/:saleId -- saleGet plus the two ways to correct a sale. A void cancels the whole sale and
 * only works while its fiscal day is open; a refund returns part or all of it afterwards. Either is
 * recorded against this browser's enrolled terminal, and whether it happens now or waits for a manager
 * depends on the user's approve capability (the panels say which).
 */
export default function SaleDetailPage() {
    const { saleId } = useParams();
    const { user, setUser } = useAuth();
    const terminal = useTerminalState();
    const [sale, setSale] = useState(null);
    const [failure, setFailure] = useState(null);
    const [loading, setLoading] = useState(true);
    const [panel, setPanel] = useState(null); // null | 'void' | 'refund' | 'invoice'
    const [toast, setToast] = useState(null);
    const dismissToast = useCallback(() => setToast(null), []);
    const closePanel = useCallback(() => setPanel(null), []);

    const load = useCallback(() => {
        setLoading(true);
        setFailure(null);
        request(`/api/v1/sales/${saleId}`).then((response) => {
            setLoading(false);
            if (response.ok) {
                setSale(response.body);
            } else {
                setSale(null);
                setFailure(response);
            }
        });
    }, [saleId]);

    useEffect(load, [load]);

    function signIn() {
        try {
            sessionStorage.setItem(RETURN_KEY, window.location.pathname);
        } catch {
            // Session storage can be unavailable; the user lands on the dashboard after signing in.
        }
        setUser(null);
    }

    const can = (capability) => user.capabilities.includes(capability);
    const canRecord = terminal === 'enrolled' || terminal === 'unknown';
    const canVoid = sale && sale.status === 'COMPLETED' && can('SALE_VOID');
    const canRefund = sale && ['COMPLETED', 'PARTIALLY_REFUNDED'].includes(sale.status) && can('SALE_REFUND');
    const pendingVoid = sale?.void?.status === 'REQUESTED' ? sale.void : null;
    const pendingRefunds = sale ? sale.refunds.filter((refund) => refund.status === 'REQUESTED') : [];

    function voidDone(result) {
        setPanel(null);
        setToast(result.status === 'VOIDED' ? 'Sale voided' : 'Void requested. A manager will decide it under Approvals.');
        load();
    }

    function refundDone(result) {
        setPanel(null);
        setToast(result.status === 'COMPLETED' ? `Refunded ${formatMoney(result.refund_total)}` : 'Refund requested. A manager will decide it under Approvals.');
        load();
    }

    const tax = sale?.tax_summary;

    return (
        <AdminLayout title="Sale" requiredCapability="SALE_VOID" wide>
            {toast && <Toast message={toast} onDismiss={dismissToast} />}

            <p className="mb-3 text-xs">
                <Link to="/admin/sales" className="text-emerald-400 hover:underline">
                    &larr; Sales history
                </Link>
            </p>

            {failure && (
                <ErrorAlert
                    message={failure.status === 404 ? 'This sale could not be found.' : failureMessage(failure, 'The sale could not be loaded.')}
                    onRetry={failure.status === 401 || failure.status === 403 || failure.status === 404 ? undefined : load}
                    onSignIn={failure.status === 401 ? signIn : undefined}
                />
            )}

            {loading && !sale && !failure && (
                <div className="space-y-3" aria-busy="true" aria-label="Loading sale">
                    <div className="h-16 animate-pulse rounded bg-slate-900" />
                    <div className="h-40 animate-pulse rounded bg-slate-900" />
                </div>
            )}

            {sale && (
                <div className="space-y-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div className="flex flex-wrap items-center gap-3">
                                <h2 className="text-xl font-semibold text-slate-100">Invoice {sale.invoice_number ?? '—'}</h2>
                                <StatusBadge status={sale.status} />
                            </div>
                            <p className="mt-1 font-mono text-[12px] text-slate-500">
                                {sale.transaction_number} &middot; {formatDateTime(sale.sold_at)} &middot; cashier {shortId(sale.cashier_id)}
                            </p>
                        </div>
                        <div className="flex gap-2">
                            {sale.invoice && (
                                <button
                                    type="button"
                                    onClick={() => setPanel('invoice')}
                                    className="min-h-11 rounded-md border border-slate-600 px-4 py-2 text-sm font-medium text-slate-200 hover:bg-slate-800 lg:min-h-0"
                                >
                                    Invoice
                                </button>
                            )}
                            {canRefund && (
                                <button
                                    type="button"
                                    disabled={!canRecord}
                                    onClick={() => setPanel('refund')}
                                    className="min-h-11 rounded-md bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-40 lg:min-h-0"
                                >
                                    Refund
                                </button>
                            )}
                            {canVoid && (
                                <button
                                    type="button"
                                    disabled={!canRecord}
                                    onClick={() => setPanel('void')}
                                    className="min-h-11 rounded-md border border-rose-700 px-4 py-2 text-sm font-medium text-rose-300 hover:bg-rose-950/40 disabled:cursor-not-allowed disabled:opacity-40 lg:min-h-0"
                                >
                                    Void sale
                                </button>
                            )}
                        </div>
                    </div>

                    {terminal === 'not-enrolled' && (canVoid || canRefund) && (
                        <div role="status" className="rounded-md border border-amber-800 bg-amber-950/30 px-3 py-2 text-sm text-slate-200">
                            This browser is not enrolled as a terminal, so it can show this sale but not void or refund it.{' '}
                            {can('TERMINAL_MANAGE') ? (
                                <Link to="/admin/terminals" className="text-emerald-400 underline">
                                    Enroll this browser
                                </Link>
                            ) : (
                                'Ask an administrator to enroll it.'
                            )}
                        </div>
                    )}

                    {pendingVoid && (
                        <div role="status" className="rounded-md border border-sky-800 bg-sky-950/30 px-3 py-2 text-sm text-slate-200">
                            A void was requested {formatDateTime(pendingVoid.requested_at)} (&ldquo;{pendingVoid.reason}&rdquo;) and is waiting for a manager.{' '}
                            {can('SALE_VOID_APPROVE') && (
                                <Link to="/admin/sales/approvals" className="text-emerald-400 underline">
                                    Review it
                                </Link>
                            )}
                        </div>
                    )}
                    {pendingRefunds.length > 0 && (
                        <div role="status" className="rounded-md border border-sky-800 bg-sky-950/30 px-3 py-2 text-sm text-slate-200">
                            {pendingRefunds.length === 1 ? 'A refund is' : `${pendingRefunds.length} refunds are`} waiting for a manager.{' '}
                            {can('SALE_REFUND_APPROVE') && (
                                <Link to="/admin/sales/approvals" className="text-emerald-400 underline">
                                    Review {pendingRefunds.length === 1 ? 'it' : 'them'}
                                </Link>
                            )}
                        </div>
                    )}
                    {sale.void?.status === 'VOIDED' && (
                        <div role="status" className="rounded-md border border-rose-900 bg-rose-950/30 px-3 py-2 text-sm text-slate-200">
                            Voided {formatDateTime(sale.void.resolved_at)}: &ldquo;{sale.void.reason}&rdquo;. Everything on this sale went back into stock.
                        </div>
                    )}

                    <Section title="Items">
                        <div className="overflow-x-auto [color-scheme:dark]">
                            <table className="w-full min-w-max text-left text-sm">
                                <thead className="text-[11px] uppercase tracking-wide text-slate-500">
                                    <tr>
                                        {['Item', 'Qty', 'Unit price', 'Discount', 'Line total', 'Tax'].map((heading) => (
                                            <th key={heading} className={`px-2 pb-2 font-mono ${['Qty', 'Unit price', 'Discount', 'Line total'].includes(heading) ? 'text-right' : ''}`}>
                                                {heading}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-800/70">
                                    {sale.items.map((item) => (
                                        <tr key={item.id}>
                                            <td className="px-2 py-2">
                                                <span className="text-slate-100">{item.product_name_snapshot}</span>
                                                <span className="ml-2 font-mono text-[11px] text-slate-500">{item.sku_snapshot}</span>
                                            </td>
                                            <td className="px-2 py-2 text-right font-mono tabular-nums">{formatQuantity(item.quantity)}</td>
                                            <td className="px-2 py-2 text-right font-mono tabular-nums text-slate-300">{formatMoney(item.unit_price_snapshot)}</td>
                                            <td className="px-2 py-2 text-right font-mono tabular-nums text-slate-400">
                                                {formatMoney(fromCents(toCents(item.line_discount_amount) + toCents(item.allocated_order_discount_amount)))}
                                            </td>
                                            <td className="px-2 py-2 text-right font-mono tabular-nums text-slate-100">{formatMoney(item.net_line_amount)}</td>
                                            <td className="px-2 py-2 font-mono text-[11px] text-slate-500">{item.tax_classification_snapshot.replaceAll('_', ' ')}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Section>

                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <Section title="Totals">
                            <Row label="Subtotal" value={formatMoney(sale.subtotal)} />
                            {Number(sale.discount_total) > 0 && <Row label="Discounts" value={`-${formatMoney(sale.discount_total)}`} />}
                            {Number(tax.taxable_sales) > 0 && <Row label="VATable sales" value={formatMoney(tax.taxable_sales)} />}
                            {Number(tax.vat_exempt_sales) > 0 && <Row label="VAT-exempt sales" value={formatMoney(tax.vat_exempt_sales)} />}
                            {Number(tax.zero_rated_sales) > 0 && <Row label="Zero-rated sales" value={formatMoney(tax.zero_rated_sales)} />}
                            {Number(tax.non_vat_sales) > 0 && <Row label="Non-VAT sales" value={formatMoney(tax.non_vat_sales)} />}
                            {Number(tax.vat_amount) > 0 && <Row label="VAT" value={formatMoney(tax.vat_amount)} />}
                            <div className="mt-1 border-t border-slate-800 pt-1">
                                <Row label="Total" value={formatMoney(sale.grand_total)} strong />
                            </div>
                        </Section>

                        <Section title="Payment">
                            {sale.payments.map((payment) => (
                                <Row key={payment.id} label={`${payment.method}${payment.reference_note ? ` · ${payment.reference_note}` : ''}`} value={formatMoney(payment.amount)} />
                            ))}
                            <div className="mt-1 border-t border-slate-800 pt-1">
                                <Row label="Tendered" value={formatMoney(sale.amount_tendered)} />
                                <Row label="Change" value={formatMoney(sale.change)} />
                            </div>
                            {(sale.buyer_name || sale.buyer_tin) && (
                                <p className="mt-3 border-t border-slate-800 pt-2 text-xs text-slate-400">
                                    Buyer: {sale.buyer_name ?? '—'}
                                    {sale.buyer_tin ? ` · TIN ${sale.buyer_tin}` : ''}
                                </p>
                            )}
                        </Section>
                    </div>

                    <Section title={`Refunds (${sale.refunds.length})`}>
                        {sale.refunds.length === 0 ? (
                            <p className="text-sm text-slate-500">No refunds have been taken against this sale.</p>
                        ) : (
                            <ul className="divide-y divide-slate-800/70">
                                {sale.refunds.map((refund) => (
                                    <li key={refund.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                        <div className="min-w-0">
                                            <span className="font-mono tabular-nums text-slate-100">{formatMoney(refund.refund_total)}</span>
                                            <span className="ml-3 text-slate-400">{refund.reason}</span>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <span className="font-mono text-[11px] text-slate-500">{formatDateTime(refund.refunded_at ?? refund.requested_at)}</span>
                                            <StatusBadge status={refund.status} />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>
                </div>
            )}

            {panel === 'invoice' && sale?.invoice && (
                <InvoicePanel invoiceId={sale.invoice.id} invoiceNumber={sale.invoice.invoice_number} canPrint={canRecord} onClose={closePanel} onUnauthorized={signIn} />
            )}
            {panel === 'void' && sale && (
                <VoidSalePanel sale={sale} executesImmediately={can('SALE_VOID_APPROVE')} onDone={voidDone} onClose={closePanel} onUnauthorized={signIn} />
            )}
            {panel === 'refund' && sale && (
                <RefundPanel sale={sale} executesImmediately={can('SALE_REFUND_APPROVE')} onDone={refundDone} onClose={closePanel} onUnauthorized={signIn} />
            )}
        </AdminLayout>
    );
}
