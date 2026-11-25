import { useCallback, useEffect, useState } from 'react';
import AdminLayout from '../AdminLayout';
import { ErrorAlert } from '../catalog/CatalogParts';
import { failureMessage, request } from '../catalog/catalogApi';
import { STATE_BADGE, STATE_LABEL, formatWhen } from './licenseState';

const card = 'rounded-lg border border-slate-800 bg-slate-900 p-4';
const button =
    'min-h-11 rounded-md bg-emerald-500 px-4 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';

const HARDWARE = {
    FRESH: 'Up to date',
    STALE: 'Out of date (over 26 hours old). Activation and renewal wait until the host helper writes it again.',
    MISSING: 'Not found. Check that the host helper is installed on this computer.',
    INVALID: 'Unreadable. Check the host helper on this computer.',
};

function Fact({ label, children }) {
    return (
        <div className="grid gap-1 py-2 sm:grid-cols-[14rem_1fr]">
            <dt className="text-sm text-slate-400">{label}</dt>
            <dd className="text-sm text-slate-100">{children}</dd>
        </div>
    );
}

/**
 * /admin/license -- licenseStatus, licenseRenew, licenseCodeApply (ADR-018 decision 10, LICENSE_MANAGE). What the license allows right
 * now and why, the certificate number the provider asks for, "check now", and entry of an offline code the provider sent. The server
 * decides everything; this page only shows it. A build with no vendor key has nothing to manage and says so.
 */
export default function LicensePage() {
    const [license, setLicense] = useState(null);
    const [failure, setFailure] = useState(null);
    const [checking, setChecking] = useState(false);
    const [checkResult, setCheckResult] = useState(null);
    const [code, setCode] = useState('');
    const [applying, setApplying] = useState(false);
    const [codeResult, setCodeResult] = useState(null);

    const load = useCallback(() => {
        setFailure(null);
        request('/api/v1/license').then((response) => {
            if (response.ok) {
                setLicense(response.body);
            } else {
                setFailure(response);
            }
        });
    }, []);

    useEffect(load, [load]);

    async function checkNow() {
        setChecking(true);
        setCheckResult(null);
        const response = await request('/api/v1/license/renew', { method: 'POST' });
        setChecking(false);
        if (response.ok) {
            setLicense(response.body.license);
            setCheckResult({ ok: response.body.outcome !== 'FAILED', message: response.body.message });
        } else {
            setCheckResult({ ok: false, message: failureMessage(response, 'The license could not be checked.') });
        }
    }

    async function applyCode(event) {
        event.preventDefault();
        setApplying(true);
        setCodeResult(null);
        const response = await request('/api/v1/license/codes', { method: 'POST', body: { code } });
        setApplying(false);
        if (response.ok) {
            setLicense(response.body.license);
            setCode('');
            setCodeResult({ ok: true, message: response.body.message });
        } else {
            // The code stays in the box so a mistyped character can be corrected.
            setCodeResult({ ok: false, message: failureMessage(response, 'The code could not be applied.') });
        }
    }

    const managed = license !== null && license.state !== 'LEGACY';

    return (
        <AdminLayout title="License" requiredCapability="LICENSE_MANAGE">
            <h2 className="text-xl font-semibold text-slate-100">License</h2>
            <p className="mb-4 mt-1 text-sm text-slate-400">What this installation&apos;s license allows, and what to do when it needs attention.</p>

            {failure && <ErrorAlert message={failureMessage(failure, 'The license could not be loaded.')} onRetry={load} />}
            {license === null && !failure && <div className="h-40 animate-pulse rounded-lg bg-slate-900" aria-busy="true" aria-label="Loading the license" />}

            {license?.state === 'LEGACY' && (
                <div className={card}>
                    <p className="text-sm text-slate-100">This installation is not license-managed. Nothing is checked and nothing is refused.</p>
                </div>
            )}

            {managed && (
                <>
                    <section className={`${card} mb-4`} aria-label="License state">
                        <div className="flex flex-wrap items-center gap-3">
                            <span className={`rounded px-2 py-1 font-mono text-xs font-semibold uppercase tracking-wider ${STATE_BADGE[license.state]}`}>{STATE_LABEL[license.state]}</span>
                            {!license.enforcing && <span className="text-xs text-amber-300">Report mode: nothing is refused.</span>}
                        </div>
                        {license.message && <p className="mt-3 text-sm text-slate-100">{license.message}</p>}
                        {license.state === 'WARNING' && (
                            <p className="mt-3 text-sm text-slate-100">The license runs out soon. It renews by itself when this computer can reach the provider.</p>
                        )}

                        <dl className="mt-3 divide-y divide-slate-800">
                            <Fact label="Valid until">{formatWhen(license.valid_until)}</Fact>
                            {license.extended_until && (
                                <Fact label="Extended offline">
                                    Yes, {license.extensions_used} of {license.extensions_allowed} in a row. The certificate itself ran until {formatWhen(license.certificate_expires_at)}.
                                </Fact>
                            )}
                            <Fact label="Deployment code">
                                <span className="font-mono">{license.deployment_code ?? '—'}</span>
                            </Fact>
                            <Fact label="Certificate number">
                                <span className="font-mono">{license.certificate_number ?? '—'}</span>
                                <span className="ml-2 text-xs text-slate-500">Give this to your provider if you ask for a code.</span>
                            </Fact>
                            <Fact label="Last renewed">{formatWhen(license.last_renewed_at)}</Fact>
                            {license.last_renewal_error && (
                                <Fact label="Last problem">
                                    <span className="text-amber-300">{license.last_renewal_error}</span>
                                </Fact>
                            )}
                            {license.hardware_file && <Fact label="Hardware check file">{HARDWARE[license.hardware_file.status] ?? license.hardware_file.status}</Fact>}
                        </dl>
                    </section>

                    <section className={`${card} mb-4`} aria-label="Check the license now">
                        <h3 className="text-sm font-semibold text-slate-100">Check the license now</h3>
                        <p className="mt-1 text-sm text-slate-400">Asks the provider for the latest license. This also happens by itself every hour.</p>
                        <button type="button" onClick={checkNow} disabled={checking || license.state === 'NOT_ACTIVATED'} className={`mt-3 ${button}`}>
                            {checking ? 'Checking…' : 'Check license now'}
                        </button>
                        {checkResult && (
                            <p role={checkResult.ok ? 'status' : 'alert'} className={`mt-3 text-sm ${checkResult.ok ? 'text-emerald-300' : 'text-rose-300'}`}>
                                {checkResult.message}
                            </p>
                        )}
                    </section>

                    {license.accepts_offline_codes && (
                        <section className={card} aria-label="Enter a code">
                            <h3 className="text-sm font-semibold text-slate-100">Enter a code from your provider</h3>
                            <p className="mt-1 text-sm text-slate-400">Use this when the shop cannot get online. Paste the whole code; spaces and line breaks are fine.</p>
                            <form onSubmit={applyCode} className="mt-3">
                                <label htmlFor="license-code" className="sr-only">
                                    Code from your provider
                                </label>
                                <textarea
                                    id="license-code"
                                    value={code}
                                    onChange={(event) => setCode(event.target.value)}
                                    rows={4}
                                    maxLength={600}
                                    autoComplete="off"
                                    spellCheck={false}
                                    className="w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-sm uppercase text-slate-100 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                />
                                <button type="submit" disabled={applying || code.trim() === ''} className={`mt-2 ${button}`}>
                                    {applying ? 'Applying…' : 'Apply code'}
                                </button>
                            </form>
                            {codeResult && (
                                <p role={codeResult.ok ? 'status' : 'alert'} className={`mt-3 text-sm ${codeResult.ok ? 'text-emerald-300' : 'text-rose-300'}`}>
                                    {codeResult.message}
                                </p>
                            )}
                        </section>
                    )}
                </>
            )}
        </AdminLayout>
    );
}
