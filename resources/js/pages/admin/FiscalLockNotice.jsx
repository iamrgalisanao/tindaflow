import { useAuth } from '../../context/AuthContext';

/** ADR-014: the fiscal configuration is read-only for a store's administrators until the server operator unlocks it. */
export function useCanConfigureFiscal() {
    const { user } = useAuth();

    return user.capabilities.includes('FISCAL_CONFIGURATION_MANAGE');
}

export default function FiscalLockNotice() {
    return (
        <p role="note" className="mb-4 rounded-md border border-amber-800/60 bg-amber-950/30 px-3 py-2 text-sm text-amber-300">
            This part of the setup is locked. It holds your tax registration and invoice numbering, so it is changed only during installation or a
            support visit. To change it, ask your TindaFlow provider to unlock it for you. What you see here is read-only.
        </p>
    );
}
