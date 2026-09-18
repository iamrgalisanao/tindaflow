import { Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';

function RequireAuth({ children }) {
    const { user } = useAuth();

    if (user === undefined) {
        return null; // still resolving GET /auth/me
    }

    if (user === null) {
        return <Navigate to="/login" replace />;
    }

    return children;
}

function RedirectIfAuthenticated({ children }) {
    const { user } = useAuth();

    if (user === undefined) {
        return null;
    }

    if (user) {
        return <Navigate to="/" replace />;
    }

    return children;
}

export default function AppRouter() {
    return (
        <AuthProvider>
            <Routes>
                <Route
                    path="/login"
                    element={
                        <RedirectIfAuthenticated>
                            <Login />
                        </RedirectIfAuthenticated>
                    }
                />
                <Route
                    path="/"
                    element={
                        <RequireAuth>
                            <Dashboard />
                        </RequireAuth>
                    }
                />
                <Route path="*" element={<Navigate to="/" replace />} />
            </Routes>
        </AuthProvider>
    );
}
