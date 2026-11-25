/** How each license state (openapi LicenseStatus.state) is named and coloured, shared by the License page and the dashboard notice. */
export const STATE_LABEL = {
    LEGACY: 'Not managed',
    NOT_ACTIVATED: 'Not activated',
    ACTIVE: 'Active',
    WARNING: 'Expires soon',
    EXPIRED: 'Expired',
    LOCKED: 'Locked',
};

export const STATE_BADGE = {
    LEGACY: 'bg-slate-800 text-slate-300',
    NOT_ACTIVATED: 'bg-rose-950 text-rose-300',
    ACTIVE: 'bg-emerald-950 text-emerald-300',
    WARNING: 'bg-amber-950 text-amber-300',
    EXPIRED: 'bg-rose-950 text-rose-300',
    LOCKED: 'bg-rose-950 text-rose-300',
};

/** "25 Dec 2026, 05:00" in the browser's locale and zone; a dash when there is no time. */
export function formatWhen(value) {
    if (!value) {
        return '—';
    }
    return new Date(value).toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/** What the dashboard says when the license needs someone's attention, or null when it does not. */
export function licenseNotice(license) {
    if (!license) {
        return null;
    }
    switch (license.state) {
        case 'WARNING':
            return { tone: 'amber', text: `The license runs out on ${formatWhen(license.valid_until)}. Connect this computer to the internet, or ask your provider for a code.` };
        case 'EXPIRED':
        case 'LOCKED':
        case 'NOT_ACTIVATED': {
            const refused = license.enforcing ? 'New shifts cannot be opened.' : 'Report mode: nothing is refused yet.';
            return { tone: 'rose', text: `${license.message ?? STATE_LABEL[license.state]} ${refused}` };
        }
        default:
            return null;
    }
}
