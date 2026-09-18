import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { apiFetch } from '../api';

const AuthContext = createContext(null);

/**
 * Holds "who is logged in," resolved from GET /auth/me (openapi.yaml
 * UserSummary: id/name/email/role/capabilities/active) -- no client-side
 * assumption about identity, only what the session already proves. No
 * heavier state library is justified until more than this one piece of
 * cross-page state exists.
 */
export function AuthProvider({ children }) {
    const [user, setUser] = useState(undefined); // undefined = still checking, null = signed out

    const refresh = useCallback(async () => {
        const { ok, body } = await apiFetch('/api/v1/auth/me');
        setUser(ok ? body : null);
    }, []);

    useEffect(() => {
        refresh();
    }, [refresh]);

    const logout = useCallback(async () => {
        await apiFetch('/api/v1/auth/logout', { method: 'POST' });
        setUser(null);
    }, []);

    return (
        <AuthContext.Provider value={{ user, setUser, refresh, logout }}>
            {children}
        </AuthContext.Provider>
    );
}

export function useAuth() {
    const context = useContext(AuthContext);
    if (!context) {
        throw new Error('useAuth must be used within an AuthProvider');
    }
    return context;
}
