import { useState } from 'react';
import { Cell } from './ReportParts';
import { cellValue } from './formatters';

/**
 * Below 768px the table becomes one key-value card per row (the design system's mobile rule).
 * Column roles come from the registry: the first column is the card title, `mobileBadge` is the
 * pill beside it, `mobileMore` columns sit behind an expander, `mobileEmphasis` is the bold row.
 */
export default function ReportCards({ report, rows }) {
    const [open, setOpen] = useState(() => new Set());

    const titleColumn = report.columns[0];
    const badgeColumn = report.mobileBadge ? report.columns.find((column) => column.key === report.mobileBadge.key) : null;
    const moreKeys = new Set(report.mobileMore ?? []);
    const mainColumns = report.columns.filter(
        (column) => column !== titleColumn && column !== badgeColumn && !moreKeys.has(column.key),
    );
    const moreColumns = report.columns.filter((column) => moreKeys.has(column.key));
    const moreLabel = report.moreLabel ?? 'details';

    function toggle(index) {
        setOpen((prior) => {
            const next = new Set(prior);
            if (next.has(index)) {
                next.delete(index);
            } else {
                next.add(index);
            }
            return next;
        });
    }

    function renderRow(column, row) {
        const emphasized = column.key === report.mobileEmphasis;
        return (
            <div
                key={column.key}
                className={`flex items-center justify-between gap-3 py-1 text-sm ${
                    emphasized ? 'mt-1 border-t border-slate-800 pt-2 font-semibold text-slate-100' : 'text-slate-400'
                }`}
            >
                <dt>{column.label}</dt>
                <dd className="min-w-0 text-right">
                    <Cell value={cellValue(column, row)} format={column.format} row={row} />
                </dd>
            </div>
        );
    }

    return (
        <ul className="space-y-3 md:hidden" aria-label={`${report.title} rows`}>
            {rows.map((row, index) => {
                const expanded = open.has(index);
                return (
                    <li
                        key={index}
                        className={`rounded-lg border border-slate-800 bg-slate-900 p-3 ${report.rowTone?.(row) ?? ''}`}
                    >
                        <div className="mb-1 flex items-center justify-between gap-2">
                            <span className="min-w-0 break-words">
                                <Cell value={cellValue(titleColumn, row)} format={titleColumn.format} row={row} />
                            </span>
                            {badgeColumn &&
                                (report.mobileBadge.suffix === undefined ? (
                                    <span className="shrink-0">
                                        <Cell value={cellValue(badgeColumn, row)} format={badgeColumn.format} row={row} />
                                    </span>
                                ) : (
                                    <span className="shrink-0 rounded border border-slate-700 bg-slate-800 px-1.5 py-0.5 font-mono text-[11px] text-slate-300">
                                        <Cell value={cellValue(badgeColumn, row)} format={badgeColumn.format} row={row} />
                                        {report.mobileBadge.suffix}
                                    </span>
                                ))}
                        </div>
                        <dl>{mainColumns.map((column) => renderRow(column, row))}</dl>
                        {moreColumns.length > 0 && (
                            <>
                                <button
                                    type="button"
                                    aria-expanded={expanded}
                                    onClick={() => toggle(index)}
                                    className="mt-2 flex min-h-11 w-full items-center justify-center gap-1 rounded border border-slate-700 bg-slate-950 text-xs text-slate-300 hover:bg-slate-800"
                                >
                                    {expanded ? 'Hide' : 'Show'} {moreLabel} <span aria-hidden="true">{expanded ? '▴' : '▾'}</span>
                                </button>
                                {expanded && (
                                    <dl className="mt-2 rounded border border-slate-800 bg-slate-950 px-3 py-1">
                                        {moreColumns.map((column) => renderRow(column, row))}
                                    </dl>
                                )}
                            </>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
