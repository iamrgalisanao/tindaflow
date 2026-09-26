import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { apiFetch, SESSION_EXPIRED_EVENT } from '../api';

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
    // True while a signed-in user's session has expired: SessionExpiredDialog asks for the password over the current screen.
    const [sessionExpired, setSessionExpired] = useState(false);
    const signedIn = useRef(false);

    useEffect(() => {
        signedIn.current = Boolean(user);
    }, [user]);

    // Only a user who WAS signed in can have a session expire; a visitor who never signed in just gets the login page.
    useEffect(() => {
        const onExpired = () => {
            if (signedIn.current) {
                setSessionExpired(true);
            }
        };
        window.addEventListener(SESSION_EXPIRED_EVENT, onExpired);

        return () => window.removeEventListener(SESSION_EXPIRED_EVENT, onExpired);
    }, []);

    const refresh = useCallback(async () => {
        const { ok, body } = await apiFetch('/api/v1/auth/me');
        setUser(ok ? body : null);
        if (!ok) {
            setSessionExpired(false);
        }
    }, []);

    useEffect(() => {
        refresh();
    }, [refresh]);

    const logout = useCallback(async () => {
        await apiFetch('/api/v1/auth/logout', { method: 'POST' });
        setUser(null);
        setSessionExpired(false);
    }, []);

    return (
        <AuthContext.Provider value={{ user, setUser, refresh, logout, sessionExpired, setSessionExpired }}>
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
