import { useState } from 'react';
import SlideOver from '../SlideOver';
import { downloadProductsCsv, failureMessage, request } from './catalogApi';

const MAX_BYTES = 5 * 1024 * 1024;
const REQUIRED_FOR_NEW = ['name', 'unit_of_measure', 'selling_price', 'tax_class'];
const OPTIONAL = ['barcode', 'description', 'category', 'brand', 'cost', 'track_inventory', 'reorder_level', 'active'];

function importFailure(result) {
    if (result.status === 422) {
        return result.body?.error?.details?.file?.[0] ?? result.body?.error?.message ?? 'The file could not be read.';
    }
    return failureMessage(result, 'The file could not be checked.');
}

function Counts({ result, done }) {
    const cells = [
        { label: done ? 'Created' : 'Will create', value: result.created, tone: 'text-emerald-400' },
        { label: done ? 'Updated' : 'Will update', value: result.updated, tone: 'text-emerald-400' },
        { label: 'Unchanged', value: result.unchanged, tone: 'text-slate-300' },
        { label: done ? 'Skipped' : 'Problems', value: result.failed, tone: result.failed > 0 ? 'text-rose-400' : 'text-slate-300' },
    ];
    return (
        <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            {cells.map((cell) => (
                <div key={cell.label} className="rounded-md border border-slate-800 bg-slate-950 px-3 py-2">
                    <dt className="text-[11px] uppercase tracking-wide text-slate-500">{cell.label}</dt>
                    <dd className={`font-mono text-lg tabular-nums ${cell.tone}`}>{cell.value}</dd>
                </div>
            ))}
        </dl>
    );
}

/**
 * Bulk product import from a CSV (productImport). Choosing a file checks it first (`?dry_run=true`, which
 * changes nothing) and shows what an import would do and which rows have problems; only then can the user
 * import. Rows with problems are skipped and the rest are imported.
 */
export default function ProductImportPanel({ onClose, onImported, onUnauthorized }) {
    const [file, setFile] = useState(null);
    const [csv, setCsv] = useState('');
    const [checking, setChecking] = useState(false);
    const [importing, setImporting] = useState(false);
    const [check, setCheck] = useState(null);
    const [outcome, setOutcome] = useState(null);
    const [problem, setProblem] = useState(null);
    const [downloading, setDownloading] = useState(false);

    async function choose(event) {
        const chosen = event.target.files?.[0] ?? null;
        setCheck(null);
        setOutcome(null);
        setProblem(null);
        setFile(chosen);
        setCsv('');
        if (!chosen) {
            return;
        }
        if (chosen.size > MAX_BYTES) {
            setProblem('That file is larger than 5 MB. Split it into smaller files.');
            return;
        }
        const text = await chosen.text();
        setCsv(text);
        setChecking(true);
        const result = await send(text, true);
        setChecking(false);
        if (result.ok) {
            setCheck(result.body);
        } else {
            handleFailure(result);
        }
    }

    function send(text, dryRun) {
        return request(`/api/v1/products/import${dryRun ? '?dry_run=true' : ''}`, { method: 'POST', headers: { 'Content-Type': 'text/csv' }, body: text });
    }

    function handleFailure(result) {
        if (result.status === 401) {
            onUnauthorized();
            return;
        }
        setProblem(importFailure(result));
    }

    async function runImport() {
        setImporting(true);
        setProblem(null);
        const result = await send(csv, false);
        setImporting(false);
        if (result.ok) {
            setOutcome(result.body);
            onImported(result.body);
        } else {
            handleFailure(result);
        }
    }

    async function downloadCurrent() {
        setDownloading(true);
        const result = await downloadProductsCsv();
        setDownloading(false);
        if (result.status === 401) {
            onUnauthorized();
        } else if (!result.ok) {
            setProblem(failureMessage(result, 'The catalog could not be exported.'));
        }
    }

    const shown = outcome ?? check;
    const changes = shown ? shown.created + shown.updated : 0;
    const busy = checking || importing;

    const footer = (
        <div className="flex items-center justify-between border-t border-slate-800 bg-slate-950/80 px-5 py-3">
            <button type="button" onClick={onClose} className="min-h-11 rounded px-3 text-sm text-slate-300 hover:text-white lg:min-h-0">
                {outcome ? 'Close' : 'Cancel'}
            </button>
            {!outcome && (
                <button
                    type="button"
                    onClick={runImport}
                    disabled={busy || changes === 0}
                    className="min-h-11 rounded-md bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50 lg:min-h-0"
                >
                    {importing ? 'Importing…' : changes > 0 ? `Import ${changes} product${changes === 1 ? '' : 's'}` : 'Import'}
                </button>
            )}
        </div>
    );

    return (
        <SlideOver titleId="product_import_title" title="Import products" onClose={onClose} footer={footer}>
            <div className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                <p className="text-sm text-slate-300">
                    Upload a CSV to add or update many products at once. Products are matched on <span className="font-mono text-emerald-400">sku</span>: a new
                    SKU is created, an existing one is updated. The easiest start is your current catalog.
                </p>
                <button
                    type="button"
                    onClick={downloadCurrent}
                    disabled={downloading}
                    className="min-h-11 rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800 disabled:opacity-50 lg:min-h-0"
                >
                    {downloading ? 'Preparing…' : 'Download current catalog (CSV)'}
                </button>

                <div>
                    <label htmlFor="product_import_file" className="mb-1 block text-xs text-slate-400">
                        CSV file
                    </label>
                    <input
                        id="product_import_file"
                        type="file"
                        accept=".csv,text/csv"
                        onChange={choose}
                        disabled={importing || Boolean(outcome)}
                        className="block w-full text-sm text-slate-300 file:mr-3 file:min-h-11 file:rounded-md file:border file:border-slate-700 file:bg-slate-800 file:px-3 file:py-2 file:text-sm file:text-slate-200 hover:file:bg-slate-700 lg:file:min-h-0"
                    />
                    {file && <p className="mt-1 font-mono text-[11px] text-slate-500">{file.name}</p>}
                </div>

                {problem && (
                    <p role="alert" className="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100">
                        {problem}
                    </p>
                )}

                {checking && (
                    <p role="status" className="text-sm text-slate-400">
                        Checking the file…
                    </p>
                )}

                {shown && (
                    <section aria-label={outcome ? 'Import result' : 'What the import will do'} className="space-y-3">
                        <h3 className="text-sm font-semibold text-slate-100">{outcome ? 'Import finished' : 'Nothing has been changed yet'}</h3>
                        <Counts result={shown} done={Boolean(outcome)} />
                        {shown.failed > 0 && (
                            <>
                                <p className="text-sm text-slate-300">
                                    {outcome
                                        ? 'These rows were skipped. Fix them in your file and import it again; the rest are already saved.'
                                        : 'Rows with problems are skipped; the rest are imported. Fix them in your file first if you would rather import everything at once.'}
                                </p>
                                <div className="max-h-64 overflow-auto rounded-md border border-slate-800 [color-scheme:dark]">
                                    <table className="w-full text-left text-sm">
                                        <thead className="sticky top-0 bg-slate-950 text-[11px] uppercase tracking-wide text-slate-500">
                                            <tr>
                                                <th scope="col" className="px-3 py-2 font-mono">
                                                    Row
                                                </th>
                                                <th scope="col" className="px-3 py-2 font-mono">
                                                    Problem
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-800/70">
                                            {shown.errors.map((error) => (
                                                <tr key={`${error.row}-${error.message}`}>
                                                    <td className="whitespace-nowrap px-3 py-2 align-top font-mono tabular-nums text-slate-400">{error.row}</td>
                                                    <td className="break-words px-3 py-2 text-slate-200">{error.message}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </>
                        )}
                        {!outcome && changes === 0 && shown.failed === 0 && <p className="text-sm text-slate-300">Every product in this file is already up to date.</p>}
                    </section>
                )}

                <details className="rounded-md border border-slate-800 bg-slate-950/50 px-3 py-2 text-sm text-slate-300">
                    <summary className="min-h-11 cursor-pointer text-slate-200 lg:min-h-0">What should the file look like?</summary>
                    <div className="mt-2 space-y-2 text-[13px] leading-relaxed">
                        <p>
                            The first row is the heading row, comma-separated, saved as <strong>CSV UTF-8</strong>. Only <span className="font-mono text-emerald-400">sku</span> is
                            always needed.
                        </p>
                        <p>
                            To <strong>create</strong> a product the file also needs: <span className="font-mono">{REQUIRED_FOR_NEW.join(', ')}</span>.
                        </p>
                        <p>
                            Optional columns: <span className="font-mono">{OPTIONAL.join(', ')}</span>.
                        </p>
                        <ul className="list-disc space-y-1 pl-5">
                            <li>A column you leave out is left alone. A blank cell clears an optional field.</li>
                            <li>Category and brand are matched by name and must already exist.</li>
                            <li>Prices need at most two decimals. Tax class is VATABLE, VAT_EXEMPT, ZERO_RATED or NON_VAT.</li>
                            <li>In Excel, format the barcode and SKU columns as Text so long numbers keep every digit.</li>
                            <li>Up to 5,000 products per file.</li>
                        </ul>
                    </div>
                </details>
            </div>
        </SlideOver>
    );
}
