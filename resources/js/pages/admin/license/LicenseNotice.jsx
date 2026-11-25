import { Link } from 'react-router-dom';
import { useLoad } from '../records/historyParts';
import { licenseNotice } from './licenseState';

const TONE = {
    amber: 'border-amber-700 bg-amber-950/40',
    rose: 'border-rose-800 bg-rose-950/40',
};

/**
 * The dashboard's warning that the license needs attention (ADR-018: the WARNING state, and the states that refuse new shifts), shown
 * only to someone who holds LICENSE_MANAGE and so can act on it. It stays silent when all is well, when the build is not
 * license-managed, and when the state cannot be read: the License page reports its own errors.
 */
export default function LicenseNotice({ allowed }) {
    const { data } = useLoad(allowed ? '/api/v1/license' : null);
    const notice = allowed ? licenseNotice(data) : null;

    if (!notice) {
        return null;
    }

    return (
        <div role="status" className={`mb-4 flex flex-wrap items-center gap-3 rounded-lg border px-4 py-3 text-sm text-slate-100 ${TONE[notice.tone]}`}>
            <span className="min-w-0 flex-1">{notice.text}</span>
            <Link to="/admin/license" className="text-sm font-medium text-emerald-300 underline">
                Open License
            </Link>
        </div>
    );
}
