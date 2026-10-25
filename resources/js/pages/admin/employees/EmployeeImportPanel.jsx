import { useState } from 'react';
import SlideOver from '../SlideOver';
import { failureMessage, request } from '../catalog/catalogApi';
import { IMPORT_TEMPLATE, importHeadline, typeShort } from './employeeConfig';

const MAX_BYTES = 1024 * 1024;

function importFailure(result) {
    if (result.status === 422) {
        return result.body?.error?.details?.file?.[0] ?? result.body?.error?.message ?? 'The file could not be read.';
    }
    return failureMessage(result, 'The file could not be checked.');
}

function RowTable({ rows, label }) {
    return (
        <div className="max-h-56 overflow-auto rounded-md border border-slate-800 [color-scheme:dark]">
            <table className="w-full text-left text-sm" aria-label={label}>
                <thead className="sticky top-0 bg-slate-950 text-[11px] uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" className="px-3 py-2 font-mono">Row</th>
                        <th scope="col" className="px-3 py-2 font-mono">What happened</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-800/70">
                    {rows.map((row) => (
                        <tr key={`${row.row}-${row.message}`}>
                            <td className="whitespace-nowrap px-3 py-2 align-top font-mono tabular-nums text-slate-400">{row.row}</td>
                            <td className="break-words px-3 py-2 text-slate-200">{row.message}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/**
 * Bulk enrolment from a CSV (employeeImport). Choosing a file checks it first (`?dry_run=true`, which changes nothing) and shows the
 * codes that would be issued, the rows skipped as already enrolled and the rows with problems; only then can the user import.
 */
export default function EmployeeImportPanel({ onClose, onImported, onUnauthorized }) {
    const [file, setFile] = useState(null);
    const [csv, setCsv] = useState('');
    const [checking, setChecking] = useState(false);
    const [importing, setImporting] = useState(false);
    const [check, setCheck] = useState(null);
    const [outcome, setOutcome] = useState(null);
    const [problem, setProblem] = useState(null);

    function send(text, dryRun) {
        return request(`/api/v1/employees/import${dryRun ? '?dry_run=true' : ''}`, { method: 'POST', headers: { 'Content-Type': 'text/csv' }, body: text });
    }

    function handleFailure(result) {
        if (result.status === 401) {
            onUnauthorized();
            return;
        }
        setProblem(importFailure(result));
    }

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
            setProblem('That file is larger than 1 MB. Split it into smaller files.');
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

    function downloadTemplate() {
        const url = URL.createObjectURL(new Blob([IMPORT_TEMPLATE], { type: 'text/csv' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = 'employees-template.csv';
        link.click();
        URL.revokeObjectURL(url);
    }

    const shown = outcome ?? check;
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
                    disabled={busy || !shown || shown.created === 0}
                    className="min-h-11 rounded-md bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50 lg:min-h-0"
                >
                    {importing ? 'Importing…' : shown && shown.created > 0 ? `Import ${shown.created} employee${shown.created === 1 ? '' : 's'}` : 'Import'}
                </button>
            )}
        </div>
    );

    return (
        <SlideOver titleId="employee_import_title" title="Import employees" onClose={onClose} footer={footer}>
            <div className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                <p className="text-sm text-slate-300">
                    Upload a CSV to enrol many employees at once. The system makes every code; the file never contains one. Someone already enrolled with the same type and
                    name is skipped, so uploading the same file twice does not add anyone twice.
                </p>
                <button type="button" onClick={downloadTemplate} className="min-h-11 rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800 lg:min-h-0">
                    Download a template (CSV)
                </button>

                <div>
                    <label htmlFor="employee_import_file" className="mb-1 block text-xs text-slate-400">
                        CSV file
                    </label>
                    <input
                        id="employee_import_file"
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
                        <p className="text-sm text-slate-200">{importHeadline(shown, Boolean(outcome))}</p>
                        {shown.employees.length > 0 && (
                            <div className="max-h-56 overflow-auto rounded-md border border-slate-800 [color-scheme:dark]">
                                <table className="w-full text-left text-sm" aria-label="Codes">
                                    <thead className="sticky top-0 bg-slate-950 text-[11px] uppercase tracking-wide text-slate-500">
                                        <tr>
                                            <th scope="col" className="px-3 py-2 font-mono">Code</th>
                                            <th scope="col" className="px-3 py-2 font-mono">Name</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-800/70">
                                        {shown.employees.map((employee) => (
                                            <tr key={employee.id}>
                                                <td className="whitespace-nowrap px-3 py-2 font-mono text-emerald-300">{employee.employee_code}</td>
                                                <td className="px-3 py-2 text-slate-200">
                                                    {employee.name} <span className="font-mono text-[10px] text-slate-500">{typeShort(employee.employee_type)}</span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        {shown.skipped > 0 && <RowTable rows={shown.skipped_rows} label="Skipped rows" />}
                        {shown.failed > 0 && (
                            <>
                                <p className="text-sm text-slate-300">Rows with problems are skipped; the rest are imported. Fix them in your file and import it again; anyone already enrolled is skipped then.</p>
                                <RowTable rows={shown.errors} label="Rows with problems" />
                            </>
                        )}
                    </section>
                )}

                <details className="rounded-md border border-slate-800 bg-slate-950/50 px-3 py-2 text-sm text-slate-300">
                    <summary className="min-h-11 cursor-pointer text-slate-200 lg:min-h-0">What should the file look like?</summary>
                    <div className="mt-2 space-y-2 text-[13px] leading-relaxed">
                        <p>
                            The first row is the heading row, comma-separated, saved as <strong>CSV UTF-8</strong>: <span className="font-mono text-emerald-400">first_name, middle_name, last_name, employee_type</span>. The middle name column is optional.
                        </p>
                        <ul className="list-disc space-y-1 pl-5">
                            <li>
                                <span className="font-mono">employee_type</span> is TSC (or DIRECT) or SUB (or SUBCONTRACTOR).
                            </li>
                            <li>Two different people with exactly the same name and type are added one at a time with New employee.</li>
                            <li>Up to 2,000 employees per file.</li>
                        </ul>
                    </div>
                </details>
            </div>
        </SlideOver>
    );
}
