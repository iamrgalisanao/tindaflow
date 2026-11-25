import { useEffect, useState } from 'react';
import { apiFetch } from '../../api';
import { formatWhen } from '../admin/license/licenseState';

/** How often the till asks again while it sits open: a license changes by the hour at most. */
export const LICENSE_NOTICE_REFRESH_MS = 5 * 60 * 1000;

const REASON = {
    NOT_ACTIVATED: 'TindaFlow is not activated.',
    EXPIRED: 'The license has run out.',
    CLOCK_ROLLBACK: "The computer's date or time looks wrong.",
    FINGERPRINT_MISMATCH: 'The license does not match this computer.',
    REVOKED: 'The license has been withdrawn by the provider.',
};

/** What the cashier reads for a notice (openapi LicenseTillNotice), or null when there is nothing to say. */
export function tillNoticeText(notice) {
    if (notice?.kind === 'EXPIRING') {
        return {
            urgent: false,
            text: `The license runs out on ${formatWhen(notice.valid_until)}. After that, a new shift cannot be opened. Tell your manager now.`,
        };
    }
    if (notice?.kind !== 'NEW_SHIFT_REFUSED') {
        return null;
    }
    const why = REASON[notice.reason] ?? 'There is a problem with the license.';
    if (notice.sales_stopped) {
        return { urgent: true, text: `${why} This shift can no longer sell. You can still close it. Tell your manager.` };
    }
    if (notice.sales_stop_at) {
        return {
            urgent: true,
            text: `${why} This shift can sell until ${formatWhen(notice.sales_stop_at)}. After you close it, you cannot open another. Tell your manager now.`,
        };
    }
    return { urgent: true, text: `${why} A new shift cannot be opened. Tell your manager.` };
}

/**
 * The till's license warning (ADR-019), shown to whoever is selling. It asks again when the till moves to another step (a shift
 * opened or closed) and every few minutes. A request that fails says nothing: the banner must never get in the way of selling.
 */
export default function LicenseBanner({ step }) {
    const [notice, setNotice] = useState(null);

    useEffect(() => {
        let current = true;
        const load = () =>
            apiFetch('/api/v1/license/till-notice')
                .then(({ ok, body }) => {
                    if (current && ok) {
                        setNotice(body?.notice ?? null);
                    }
                })
                .catch(() => {});
        load();
        const timer = setInterval(load, LICENSE_NOTICE_REFRESH_MS);

        return () => {
            current = false;
            clearInterval(timer);
        };
    }, [step]);

    const shown = tillNoticeText(notice);
    if (!shown) {
        return null;
    }

    return (
        <div
            role={shown.urgent ? 'alert' : 'status'}
            className={`border-b px-4 py-2 text-sm font-medium text-slate-100 ${shown.urgent ? 'border-rose-800 bg-rose-950' : 'border-amber-700 bg-amber-950'}`}
        >
            {shown.text}
        </div>
    );
}
