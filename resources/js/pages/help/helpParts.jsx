import { Link } from 'react-router-dom';

/**
 * Two bits of inline markup, nothing more, so a guide can name what is on the screen without a markdown
 * library: **Button or menu name** renders as a keycap-style chip, and `typed text` renders as monospace.
 */
export function RichText({ text }) {
    return String(text)
        .split(/(\*\*[^*]+\*\*|`[^`]+`)/g)
        .filter((part) => part !== '')
        .map((part, index) => {
            if (part.startsWith('**')) {
                return (
                    <strong
                        key={index}
                        className="mx-0.5 rounded border border-slate-700 bg-slate-800 px-1.5 py-0.5 text-[0.92em] font-semibold text-emerald-300 [box-decoration-break:clone]"
                    >
                        {part.slice(2, -2)}
                    </strong>
                );
            }
            if (part.startsWith('`')) {
                return (
                    <code key={index} className="mx-0.5 rounded bg-slate-800 px-1.5 py-0.5 font-mono text-[0.9em] text-sky-300">
                        {part.slice(1, -1)}
                    </code>
                );
            }
            return part;
        });
}

const ROLE_STYLES = {
    CASHIER: 'border-sky-500/40 bg-sky-500/10 text-sky-300',
    MANAGER: 'border-amber-500/40 bg-amber-500/10 text-amber-300',
    ADMIN: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300',
};

export const ROLE_LABELS = { CASHIER: 'Cashier', MANAGER: 'Manager', ADMIN: 'Admin' };

export function RoleBadge({ role }) {
    return (
        <span className={`rounded border px-1.5 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wider ${ROLE_STYLES[role] ?? ROLE_STYLES.CASHIER}`}>
            {ROLE_LABELS[role] ?? role}
        </span>
    );
}

const CALLOUT_TONES = {
    tip: { label: 'Tip', box: 'border-sky-500/30 bg-sky-500/5', title: 'text-sky-300', icon: 'M12 3a6 6 0 0 0-3.5 10.9V17h7v-3.1A6 6 0 0 0 12 3zM9.5 20h5' },
    warning: { label: 'Careful', box: 'border-amber-500/40 bg-amber-500/5', title: 'text-amber-300', icon: 'M12 4l9 16H3L12 4zM12 10v4M12 17v.01' },
    note: { label: 'Good to know', box: 'border-slate-700 bg-slate-900', title: 'text-slate-300', icon: 'M12 8v.01M11 12h1v5h1M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z' },
};

export function Callout({ tone = 'tip', children }) {
    const style = CALLOUT_TONES[tone] ?? CALLOUT_TONES.tip;

    return (
        <div className={`flex gap-3 rounded-lg border px-3 py-2.5 ${style.box}`}>
            <svg
                viewBox="0 0 24 24"
                width="18"
                height="18"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.75"
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
                className={`mt-0.5 shrink-0 ${style.title}`}
            >
                <path d={style.icon} />
            </svg>
            <div className="min-w-0 text-sm text-slate-300">
                <p className={`mb-0.5 font-mono text-[10px] font-semibold uppercase tracking-wider ${style.title}`}>{style.label}</p>
                <p className="leading-relaxed">
                    <RichText text={children} />
                </p>
            </div>
        </div>
    );
}

/** A green "Do" column and a red "Don't" column, side by side from the sm breakpoint. Either may be omitted. */
export function DoDont({ dos = [], donts = [] }) {
    if (dos.length === 0 && donts.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-3 sm:grid-cols-2">
            {dos.length > 0 && (
                <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/5 px-3 py-2.5">
                    <p className="mb-1.5 font-mono text-[10px] font-semibold uppercase tracking-wider text-emerald-300">Do</p>
                    <ul className="space-y-1.5">
                        {dos.map((item) => (
                            <li key={item} className="flex gap-2 text-sm leading-snug text-slate-300">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className="mt-0.5 shrink-0 text-emerald-400">
                                    <path d="M5 12.5l4.5 4.5L19 7.5" />
                                </svg>
                                <span>
                                    <RichText text={item} />
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {donts.length > 0 && (
                <div className="rounded-lg border border-red-500/30 bg-red-500/5 px-3 py-2.5">
                    <p className="mb-1.5 font-mono text-[10px] font-semibold uppercase tracking-wider text-red-300">Don&apos;t</p>
                    <ul className="space-y-1.5">
                        {donts.map((item) => (
                            <li key={item} className="flex gap-2 text-sm leading-snug text-slate-300">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className="mt-0.5 shrink-0 text-red-400">
                                    <path d="M6 6l12 12M18 6L6 18" />
                                </svg>
                                <span>
                                    <RichText text={item} />
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

export function GuideLink({ guide, className = '' }) {
    return (
        <Link to={`/help/${guide.slug}`} className={className}>
            {guide.title}
        </Link>
    );
}
