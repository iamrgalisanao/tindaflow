import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import AdminLayout from '../admin/AdminLayout';
import NotFound from '../NotFound';
import StepFigure from './StepFigure';
import { Callout, DoDont, RichText, RoleBadge } from './helpParts';
import { findGuide, guidesAfter } from './guides';

const STORAGE_KEY = 'tindaflow.help.progress';

/** Which steps a reader ticked off, remembered per browser. Storage can be blocked, so every access is guarded. */
function useProgress(slug) {
    const read = () => {
        try {
            return JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '{}')[slug] ?? [];
        } catch {
            return [];
        }
    };
    const [done, setDone] = useState(read);

    useEffect(() => {
        setDone(read());
    }, [slug]); // eslint-disable-line react-hooks/exhaustive-deps

    const toggle = useCallback(
        (index) => {
            setDone((prior) => {
                const next = prior.includes(index) ? prior.filter((value) => value !== index) : [...prior, index];
                try {
                    const all = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '{}');
                    window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ ...all, [slug]: next }));
                } catch {
                    // Not being able to remember a tick is not worth interrupting anyone over.
                }
                return next;
            });
        },
        [slug],
    );

    const reset = useCallback(() => {
        setDone([]);
        try {
            const all = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '{}');
            delete all[slug];
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(all));
        } catch {
            // Same as above.
        }
    }, [slug]);

    return { done, toggle, reset };
}

function Step({ step, index, total, checked, onToggle, reference }) {
    return (
        <section id={`step-${index + 1}`} aria-labelledby={`step-${index + 1}-title`} className="scroll-mt-20 rounded-xl border border-slate-800 bg-slate-900/60 p-4 sm:p-5">
            <div className="mb-3 flex items-start gap-3">
                <span
                    className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full font-mono text-sm font-bold ${
                        checked ? 'bg-emerald-500 text-slate-950' : 'border border-emerald-500/60 bg-emerald-500/10 text-emerald-300'
                    }`}
                    aria-hidden="true"
                >
                    {reference ? '•' : checked ? '✓' : index + 1}
                </span>
                <div className="min-w-0 flex-1">
                    {!reference && (
                        <p className="font-mono text-[10px] uppercase tracking-wider text-slate-500">
                            Step {index + 1} of {total}
                        </p>
                    )}
                    <h3 id={`step-${index + 1}-title`} className="text-base font-semibold text-slate-100 sm:text-lg">
                        {step.title}
                    </h3>
                </div>
            </div>

            <div className="space-y-3">
                {step.body && (
                    <p className="max-w-prose text-[15px] leading-relaxed text-slate-300">
                        <RichText text={step.body} />
                    </p>
                )}

                {step.figure && <StepFigure shot={step.figure.shot} marks={step.figure.marks ?? []} alt={step.figure.alt ?? step.title} />}

                <DoDont dos={step.dos} donts={step.donts} />
                {step.tip && <Callout tone="tip">{step.tip}</Callout>}
                {step.note && <Callout tone="note">{step.note}</Callout>}
                {step.warning && <Callout tone="warning">{step.warning}</Callout>}

                {!reference && (
                    <label className="flex min-h-11 w-fit cursor-pointer items-center gap-2 rounded px-1 text-sm text-slate-400 hover:text-slate-200">
                        <input type="checkbox" checked={checked} onChange={onToggle} className="h-4 w-4 accent-emerald-500" />I did this step
                    </label>
                )}
            </div>
        </section>
    );
}

export default function GuidePage() {
    const { slug } = useParams();
    const { user } = useAuth();
    const guide = findGuide(slug);
    const { done, toggle, reset } = useProgress(slug);

    useEffect(() => {
        window.scrollTo({ top: 0 });
    }, [slug]);

    if (!guide) {
        return <NotFound />;
    }

    const upNext = guidesAfter(guide);
    const forMe = guide.roles.includes(user.role);
    const finished = !guide.reference && done.length >= guide.steps.length;

    return (
        <AdminLayout title={`Help / ${guide.title}`} requiredCapability={null} wide>
            <nav aria-label="Breadcrumb" className="mb-3 text-xs text-slate-500">
                <Link to="/help" className="hover:text-slate-300 hover:underline">
                    Help &amp; guides
                </Link>
                <span className="mx-1.5 text-slate-700">/</span>
                <span>{guide.category}</span>
            </nav>

            <div className="grid gap-6 lg:grid-cols-[15rem_minmax(0,1fr)]">
                <aside className="hidden lg:sticky lg:top-16 lg:block lg:self-start">
                    <div className="rounded-xl border border-slate-800 bg-slate-900 p-3">
                        <p className="mb-2 font-mono text-[10px] uppercase tracking-wider text-slate-500">In this guide</p>
                        <ol className="space-y-0.5">
                            {guide.steps.map((step, index) => (
                                <li key={step.title}>
                                    <a href={`#step-${index + 1}`} className="flex min-h-9 items-start gap-2 rounded px-1.5 py-1.5 text-[13px] leading-snug text-slate-400 hover:bg-slate-800 hover:text-slate-100">
                                        <span
                                            className={`mt-px flex h-5 w-5 shrink-0 items-center justify-center rounded-full font-mono text-[10px] font-bold ${
                                                done.includes(index) ? 'bg-emerald-500 text-slate-950' : 'border border-slate-600 text-slate-400'
                                            }`}
                                            aria-hidden="true"
                                        >
                                            {done.includes(index) ? '✓' : index + 1}
                                        </span>
                                        <span>
                                            {step.title}
                                            {done.includes(index) && <span className="sr-only"> (done)</span>}
                                        </span>
                                    </a>
                                </li>
                            ))}
                        </ol>
                        <div className={`mt-3 border-t border-slate-800 pt-3 ${guide.reference ? 'hidden' : ''}`}>
                            <div className="mb-1 flex items-baseline justify-between text-[11px] text-slate-500">
                                <span>
                                    {Math.min(done.length, guide.steps.length)} of {guide.steps.length} done
                                </span>
                                {done.length > 0 && (
                                    <button type="button" onClick={reset} className="text-slate-500 underline hover:text-slate-300">
                                        Start over
                                    </button>
                                )}
                            </div>
                            <div className="h-1.5 overflow-hidden rounded-full bg-slate-800" role="progressbar" aria-valuemin={0} aria-valuemax={guide.steps.length} aria-valuenow={Math.min(done.length, guide.steps.length)} aria-label="Steps done">
                                <div className="h-full rounded-full bg-emerald-500 transition-all" style={{ width: `${(Math.min(done.length, guide.steps.length) / guide.steps.length) * 100}%` }} />
                            </div>
                        </div>
                    </div>
                </aside>

                <article className="min-w-0 space-y-5">
                    <header>
                        <div className="mb-2 flex flex-wrap items-center gap-2">
                            {guide.roles.map((role) => (
                                <RoleBadge key={role} role={role} />
                            ))}
                            <span className="text-xs text-slate-500">About {guide.minutes} minutes</span>
                        </div>
                        <h2 className="text-2xl font-semibold text-slate-100">{guide.title}</h2>
                        <p className="mt-1 max-w-prose text-[15px] leading-relaxed text-slate-400">{guide.summary}</p>
                    </header>

                    <div className="lg:hidden">
                        <label htmlFor="jump-to-step" className="mb-1 block text-xs text-slate-500">
                            {guide.reference ? 'Jump to' : 'Jump to a step'}
                        </label>
                        <select
                            id="jump-to-step"
                            defaultValue=""
                            onChange={(event) => {
                                if (event.target.value !== '') {
                                    document.getElementById(event.target.value)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                    event.target.value = '';
                                }
                            }}
                            className="min-h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-3 text-sm text-slate-100"
                        >
                            <option value="">{guide.steps.length} {guide.reference ? 'items' : 'steps'}, choose one…</option>
                            {guide.steps.map((step, index) => (
                                <option key={step.title} value={`step-${index + 1}`}>
                                    {guide.reference ? '' : `${index + 1}. `}
                                    {step.title}
                                    {done.includes(index) ? ' (done)' : ''}
                                </option>
                            ))}
                        </select>
                    </div>

                    {!forMe && (
                        <Callout tone="note">
                            {`This guide is written for ${guide.roles.map((role) => role.toLowerCase()).join(' and ')} accounts. You are signed in as a ${user.role.toLowerCase()}, so some of the screens shown here may not appear for you.`}
                        </Callout>
                    )}

                    {guide.before?.length > 0 && (
                        <div className="rounded-xl border border-slate-800 bg-slate-900 p-4">
                            <h3 className="mb-2 font-mono text-[11px] uppercase tracking-wider text-slate-400">Before you start</h3>
                            <ul className="space-y-1.5">
                                {guide.before.map((item) => (
                                    <li key={item} className="flex gap-2 text-sm text-slate-300">
                                        <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-400" aria-hidden="true" />
                                        <span>
                                            <RichText text={item} />
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {guide.steps.map((step, index) => (
                        <Step key={step.title} step={step} index={index} total={guide.steps.length} checked={done.includes(index)} onToggle={() => toggle(index)} reference={guide.reference === true} />
                    ))}

                    <div className={`rounded-xl border p-4 sm:p-5 ${finished ? 'border-emerald-500/50 bg-emerald-500/10' : 'border-slate-800 bg-slate-900'}`}>
                        <h3 className="text-base font-semibold text-slate-100">{guide.reference ? 'Still stuck?' : finished ? 'Nicely done' : 'All finished?'}</h3>
                        {guide.done && (
                            <p className="mt-1 max-w-prose text-sm leading-relaxed text-slate-300">
                                <RichText text={guide.done} />
                            </p>
                        )}
                        {upNext.length > 0 && (
                            <>
                                <p className="mb-2 mt-4 font-mono text-[11px] uppercase tracking-wider text-slate-500">Up next</p>
                                <ul className="grid gap-2 sm:grid-cols-2">
                                    {upNext.map((next) => (
                                        <li key={next.slug}>
                                            <Link to={`/help/${next.slug}`} className="flex min-h-11 flex-col justify-center rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 hover:border-emerald-600 hover:bg-slate-900">
                                                <span className="text-sm font-medium text-slate-100">{next.title}</span>
                                                <span className="text-xs text-slate-500">{next.minutes} min</span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                        <Link to="/help" className="mt-4 inline-block text-sm text-emerald-400 hover:underline">
                            ← All guides
                        </Link>
                    </div>
                </article>
            </div>
        </AdminLayout>
    );
}
