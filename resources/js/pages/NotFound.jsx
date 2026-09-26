import { Link, useLocation } from 'react-router-dom';
import AdminLayout from './admin/AdminLayout';

/**
 * The catch-all. It used to be `<Navigate to="/" replace />`, which silently dropped anyone with a
 * mistyped, stale or renamed link onto the dashboard with no indication that the address they asked
 * for does not exist -- and, because the redirect replaced the history entry, no way back to it.
 *
 * Follows the inline-403 pattern (ReportsAccessDenied): stay inside the shell the user was heading
 * for, name the code, show the one fact that matters -- here, the path that missed -- and offer real
 * ways onward. Nothing is invented: there is no search, no "did you mean", and no error reporting,
 * because none of those exist in this app.
 *
 * A path under /pos gets the till's own full-screen treatment instead of the back-office shell: a
 * cashier who fat-fingers a POS address should not be dropped into the admin chrome, which is the
 * same reasoning the not-enrolled and setup-incomplete gates already follow.
 */
export default function NotFound() {
    const { pathname } = useLocation();
    const inPos = pathname.startsWith('/pos');

    const body = (
        <div className="mx-auto max-w-xl rounded-lg border border-slate-700 bg-slate-900 p-6">
            <p className="mb-1 font-mono text-[11px] uppercase tracking-wide text-amber-400">404 · Not found</p>
            <h2 className="text-lg font-semibold text-slate-100">There is no page at this address</h2>
            <p className="mt-2 text-sm text-slate-400">
                The link may be mistyped, or it may point at a screen that has moved or never existed. Nothing is wrong with your account.
            </p>

            <dl className="mt-4 rounded-md border border-slate-800 bg-slate-950 p-3 text-sm">
                <dt className="text-slate-500">You asked for</dt>
                <dd className="mt-0.5 break-all font-mono text-xs text-amber-300">{pathname}</dd>
            </dl>

            <div className="mt-5 flex flex-wrap gap-2">
                <Link
                    to={inPos ? '/pos' : '/'}
                    className="min-h-11 rounded-md bg-emerald-500 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-emerald-400 lg:min-h-0"
                >
                    {inPos ? 'Back to the till' : 'Dashboard'}
                </Link>
                <Link
                    to={inPos ? '/' : '/pos'}
                    className="min-h-11 rounded-md border border-slate-700 px-4 py-2 text-sm text-slate-300 hover:bg-slate-800 lg:min-h-0"
                >
                    {inPos ? 'Dashboard' : 'POS / Till'}
                </Link>
            </div>
        </div>
    );

    if (inPos) {
        return <div className="min-h-screen bg-slate-950 p-8 text-slate-100 [color-scheme:dark]">{body}</div>;
    }

    // requiredCapability={null}: a missing page is not a permissions problem, so every signed-in user
    // sees the same thing, with the navigation they do have still beside them.
    return (
        <AdminLayout title="Not found" requiredCapability={null}>
            {body}
        </AdminLayout>
    );
}
