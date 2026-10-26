import { formatMoney, formatQuantity } from '../reports/formatters';

const isZero = (value) => Number(value) === 0;

/**
 * The end-of-day SALES BY MEAL TYPE section of a slip-mode day (ADR-016 amendment 2026-10-26, EOD-001), read from the figures frozen in the
 * reading when the day closed. Each meal type shows its net amount, then its dishes and items with quantity and amount, net of refunds; the
 * foot bridges to the day's net sales (sales, refunds, net, and the voids that were never counted).
 */
export default function SalesByMealTypeSection({ section }) {
    if (!section || !Array.isArray(section.meal_types) || section.meal_types.length === 0) {
        return null;
    }
    const hasRefunds = !isZero(section.returned_amount);

    return (
        <div data-testid="sales-by-meal-type">
            <p className="mb-1 text-[11px] uppercase tracking-wide text-slate-500">Sales by meal type</p>
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-left text-[11px] uppercase tracking-wide text-slate-500">
                            <th className="py-1 pr-3 font-normal">Meal type / dish</th>
                            <th className="py-1 pr-3 text-right font-normal">Qty</th>
                            {hasRefunds && <th className="py-1 pr-3 text-right font-normal">Refunded</th>}
                            <th className="py-1 text-right font-normal">Amount</th>
                        </tr>
                    </thead>
                    {section.meal_types.map((type) => (
                        <tbody key={type.meal_type} className="border-t border-slate-800">
                            <tr className="font-semibold text-slate-100">
                                <td className="py-1.5 pr-3">{type.meal_type}</td>
                                <td />
                                {hasRefunds && <td className="pr-3 text-right font-mono tabular-nums text-slate-400">{isZero(type.returned_amount) ? '' : formatMoney(type.returned_amount)}</td>}
                                <td className="text-right font-mono tabular-nums">{formatMoney(type.net_amount)}</td>
                            </tr>
                            {type.items.map((item) => (
                                <tr key={`${item.kind}:${item.item}`} className="text-slate-300">
                                    <td className="py-0.5 pl-4 pr-3">
                                        {item.kind === 'ADDON' ? '+ ' : ''}
                                        {item.item}
                                    </td>
                                    <td className="pr-3 text-right font-mono tabular-nums">{formatQuantity(item.quantity_net)}</td>
                                    {hasRefunds && <td className="pr-3 text-right font-mono tabular-nums text-slate-500">{isZero(item.returned_amount) ? '' : formatMoney(item.returned_amount)}</td>}
                                    <td className="text-right font-mono tabular-nums">{formatMoney(item.net_amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    ))}
                </table>
            </div>
            <dl className="mt-2 grid grid-cols-[1fr_auto] gap-x-6 gap-y-0.5 border-t border-slate-800 pt-2 text-sm">
                {hasRefunds && (
                    <>
                        <dt className="text-slate-400">Sales</dt>
                        <dd className="text-right font-mono tabular-nums">{formatMoney(section.sold_amount)}</dd>
                        <dt className="text-slate-400">Refunds</dt>
                        <dd className="text-right font-mono tabular-nums">{formatMoney(`-${section.returned_amount}`)}</dd>
                    </>
                )}
                <dt className="font-semibold text-slate-100">Net sales</dt>
                <dd className="text-right font-mono font-semibold tabular-nums text-slate-100">{formatMoney(section.net_amount)}</dd>
                {!isZero(section.voided_amount) && (
                    <>
                        <dt className="text-slate-400">Voids (not counted)</dt>
                        <dd className="text-right font-mono tabular-nums text-slate-400">{formatMoney(section.voided_amount)}</dd>
                    </>
                )}
            </dl>
        </div>
    );
}
