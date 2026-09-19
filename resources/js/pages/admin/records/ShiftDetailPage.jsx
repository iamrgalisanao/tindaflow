import { Link, useParams } from 'react-router-dom';
import AdminLayout from '../AdminLayout';
import { ErrorAlert } from '../catalog/CatalogParts';
import { failureMessage } from '../catalog/catalogApi';
import { formatDateTime, shortId } from '../reports/formatters';
import { DayStatusBadge, Facts, Money, Panel, Variance, XReadingTotals, useLoad, useSignIn } from './historyParts';

/**
 * /admin/shifts/:shiftId -- shiftGet plus shiftXReadingList: one shift's figures and every X-reading taken
 * during it. An open shift's figures are still filling in; they settle when the cashier closes it.
 */
export default function ShiftDetailPage() {
    const { shiftId } = useParams();
    const signIn = useSignIn();
    const shift = useLoad(`/api/v1/shifts/${shiftId}`);
    const readings = useLoad(shift.data ? `/api/v1/shifts/${shiftId}/x-readings` : null);

    const current = shift.data;
    const closed = current?.status === 'CLOSED';

    return (
        <AdminLayout title="Shift" requiredCapability="REPORT_VIEW" wide>
            <p className="mb-3 text-xs">
                <Link to="/admin/shifts" className="text-emerald-400 hover:underline">
                    &larr; All shifts
                </Link>
            </p>

            {shift.failure && (
                <ErrorAlert
                    message={shift.failure.status === 404 ? 'This shift could not be found.' : failureMessage(shift.failure, 'The shift could not be loaded.')}
                    onRetry={[401, 403, 404].includes(shift.failure.status) ? undefined : shift.reload}
                    onSignIn={shift.failure.status === 401 ? signIn : undefined}
                />
            )}

            {shift.loading && !current && !shift.failure && <div className="h-40 animate-pulse rounded bg-slate-900" aria-busy="true" aria-label="Loading shift" />}

            {current && (
                <>
                    <div className="mb-4 flex flex-wrap items-center gap-3">
                        <h2 className="text-xl font-semibold text-slate-100">Shift opened {formatDateTime(current.opened_at)}</h2>
                        <DayStatusBadge status={current.status} />
                    </div>

                    <Panel title="The shift" note={closed ? undefined : 'Still open: cash figures are worked out when the cashier closes the shift.'}>
                        <Facts
                            items={[
                                ['Cashier', <span key="c" title={current.cashier_id}>{shortId(current.cashier_id)}</span>],
                                ['Terminal', <span key="t" title={current.terminal_id}>{shortId(current.terminal_id)}</span>],
                                [
                                    'Fiscal day',
                                    <Link key="d" to={`/admin/fiscal-days/${current.fiscal_day_id}`} className="text-emerald-400 hover:underline">
                                        {shortId(current.fiscal_day_id)}
                                    </Link>,
                                ],
                                ['Opened', formatDateTime(current.opened_at)],
                                ['Closed', current.closed_at ? formatDateTime(current.closed_at) : '—'],
                            ]}
                        />
                    </Panel>

                    <Panel title="Cash drawer">
                        <Facts
                            items={[
                                ['Opening cash', <Money key="o" value={current.opening_cash} />],
                                ['Cash sales', <Money key="cs" value={current.cash_sales} />],
                                ['Non-cash sales', <Money key="ns" value={current.non_cash_sales} />],
                                ['Refunds', <Money key="r" value={current.refunds_total} />],
                                ['Cash in', <Money key="ci" value={current.cash_in_total} />],
                                ['Cash out', <Money key="co" value={current.cash_out_total} />],
                                ['Expected cash', <Money key="e" value={current.expected_cash} />],
                                ['Declared cash', <Money key="d" value={current.declared_cash} />],
                                ['Variance', <Variance key="v" value={current.variance} />],
                            ]}
                        />
                    </Panel>

                    <Panel title="X-readings" note="Snapshots of the shift's figures. The last one, taken when the shift closes, is the closing reading.">
                        {readings.failure && (
                            <ErrorAlert
                                message={failureMessage(readings.failure, 'The readings could not be loaded.')}
                                onRetry={[401, 403].includes(readings.failure.status) ? undefined : readings.reload}
                                onSignIn={readings.failure.status === 401 ? signIn : undefined}
                            />
                        )}
                        {readings.loading && !readings.data && !readings.failure && <div className="h-16 animate-pulse rounded bg-slate-950" aria-busy="true" />}
                        {readings.data && readings.data.length === 0 && <p className="text-sm text-slate-500">No X-reading was taken during this shift.</p>}
                        {readings.data && readings.data.length > 0 && (
                            <ul className="space-y-4">
                                {readings.data.map((reading) => (
                                    <li key={reading.id} className="rounded-md border border-slate-800 bg-slate-950/50 p-3">
                                        <p className="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-100">
                                            <span className="font-mono text-[12px] text-slate-400">{formatDateTime(reading.generated_at)}</span>
                                            <span className="rounded border border-slate-700 px-1.5 py-0.5 font-mono text-[10px] font-semibold text-slate-300">
                                                {reading.is_closing_reading ? 'CLOSING READING' : 'INTERIM READING'}
                                            </span>
                                            <span className="font-mono text-[11px] text-slate-500">
                                                covers {formatDateTime(reading.from_at)} to {formatDateTime(reading.to_at)}
                                            </span>
                                        </p>
                                        <XReadingTotals totals={reading.totals_snapshot} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </>
            )}
        </AdminLayout>
    );
}
