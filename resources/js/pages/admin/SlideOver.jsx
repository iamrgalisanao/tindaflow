import { useEffect, useRef } from 'react';

/**
 * Right-hand Level-2 panel used by the admin forms (products, users). Escape and the backdrop close it,
 * focus starts on the first field and is trapped inside while open, page scroll is locked, and focus
 * returns to whatever opened it. `children` is the scrolling body (normally the <form>); `footer` is pinned.
 */
export default function SlideOver({ titleId, title, badge, onClose, children, footer }) {
    const panelRef = useRef(null);

    useEffect(() => {
        const opener = document.activeElement;
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        panelRef.current?.querySelector('input:not([type=hidden]), select, textarea')?.focus();

        function onKeyDown(event) {
            if (event.key === 'Escape') {
                onClose();
                return;
            }
            if (event.key !== 'Tab' || !panelRef.current) {
                return;
            }
            const focusable = panelRef.current.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled])');
            if (focusable.length === 0) {
                return;
            }
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
        document.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('keydown', onKeyDown);
            document.body.style.overflow = previousOverflow;
            opener?.focus?.();
        };
    }, [onClose]);

    return (
        <div className="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-labelledby={titleId}>
            <div className="absolute inset-0 bg-slate-950/70 backdrop-blur-[2px]" onClick={onClose} aria-hidden="true" />
            <div
                ref={panelRef}
                className="absolute inset-y-0 right-0 flex w-full max-w-[480px] flex-col border-l border-slate-700 bg-slate-900 shadow-[0_4px_12px_rgba(0,0,0,0.45)]"
            >
                <div className="flex items-center gap-3 border-b border-slate-800 px-5 py-4">
                    <h2 id={titleId} className="text-base font-semibold text-slate-100">
                        {title}
                    </h2>
                    {badge && <span className="rounded border border-slate-700 bg-slate-800/70 px-2 py-0.5 font-mono text-[11px] text-emerald-400">{badge}</span>}
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close"
                        className="ml-auto flex h-11 w-11 items-center justify-center rounded text-slate-400 hover:bg-slate-800 hover:text-slate-100 lg:h-8 lg:w-8"
                    >
                        &times;
                    </button>
                </div>
                {children}
                {footer}
            </div>
        </div>
    );
}
