/**
 * The rules an override code must meet on the form. The server is the authority (OverrideCodePolicy, and the check that it differs from the
 * account password); these only save a round trip for the obvious mistakes.
 */
export const CODE_HELP = 'Use 6 to 8 digits, numbers only. Not the same digit repeated, a run such as 123456, or a short pattern repeated. It must differ from your account password.';

/** Field errors for the set-code form, or {} when it can be sent. */
export function validateCodeForm({ password, code, confirm }) {
    const errors = {};
    if (password === '') {
        errors.password = 'Enter your account password.';
    }
    if (!/^\d{6,8}$/.test(code)) {
        errors.code = 'Use 6 to 8 digits, numbers only.';
    } else if (confirm !== code) {
        errors.confirm = 'The two codes do not match.';
    }

    return errors;
}

/** "Not set", "Set on 26 Oct 2026" or "Locked until 3:15 PM" for a status answer. */
export function statusText(status) {
    if (!status) {
        return '';
    }
    if (status.locked && status.locked_until) {
        return `Locked after too many wrong codes, until ${new Date(status.locked_until).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}`;
    }
    if (!status.has_code) {
        return 'Not set';
    }

    return status.set_at ? `Set on ${new Date(status.set_at).toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' })}` : 'Set';
}
