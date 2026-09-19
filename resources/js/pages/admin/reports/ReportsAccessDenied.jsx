import { Link } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';

/**
 * Inline 403 for the reports section (rendered inside the admin shell so
 * the user keeps their frame of reference). Shows only what is real:
 * the signed-in principal and the single missing capability. The Stitch
 * mockup's "Supervisor Override" / "Switch Operator" / RBAC-matrix
 * panels are not built -- no such mechanisms exist in this contract.
 */
export default function ReportsAccessDenied() {
    const { user } = useAuth();

    return (
        <div className="mx-auto max-w-xl rounded-lg border border-rose-900/60 bg-slate-900 p-6">
            <p className="mb-1 font-mono text-[11px] uppercase tracking-wide text-rose-400">403 · Forbidden</p>
            <h2 className="text-lg font-semibold text-slate-100">Access denied: REPORT_VIEW required</h2>
            <p className="mt-2 text-sm text-slate-400">
                Reports are limited to store managers and administrators. Your account is signed in, but its role
                doesn&apos;t include this capability.
            </p>

            <dl className="mt-4 grid grid-cols-3 gap-x-4 gap-y-2 rounded-md border border-slate-800 bg-slate-950 p-3 text-sm">
                <dt className="text-slate-500">Signed in as</dt>
                <dd className="col-span-2 text-slate-200">{user.name}</dd>
                <dt className="text-slate-500">Email</dt>
                <dd className="col-span-2 font-mono text-xs text-slate-300">{user.email}</dd>
                <dt className="text-slate-500">Role</dt>
                <dd className="col-span-2 font-mono text-xs text-slate-300">{user.role}</dd>
                <dt className="text-slate-500">Missing</dt>
                <dd className="col-span-2 font-mono text-xs text-rose-400">REPORT_VIEW</dd>
            </dl>

            <div className="mt-5 flex gap-2">
                <Link
                    to="/pos"
                    className="rounded-md bg-emerald-500 px-3 py-2 text-sm font-medium text-slate-950 hover:bg-emerald-400"
                >
                    Return to POS
                </Link>
                <Link to="/" className="rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-300 hover:bg-slate-800">
                    Dashboard
                </Link>
            </div>
        </div>
    );
}
