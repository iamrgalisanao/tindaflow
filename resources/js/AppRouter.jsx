import { Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';
import Pos from './pages/Pos';
import TerminalEnroll from './pages/TerminalEnroll';
import StoreSetupOverview from './pages/admin/StoreSetupOverview';
import FiscalInstallations from './pages/admin/FiscalInstallations';
import InvoiceSeriesPage from './pages/admin/InvoiceSeriesPage';
import InventoryLocations from './pages/admin/InventoryLocations';
import TaxRegistrations from './pages/admin/TaxRegistrations';
import ProductsPage from './pages/admin/catalog/ProductsPage';
import CatalogNamesPage from './pages/admin/catalog/CatalogNamesPage';
import UsersPage from './pages/admin/users/UsersPage';
import StockPage from './pages/admin/inventory/StockPage';
import SalesPage from './pages/admin/sales/SalesPage';
import SaleDetailPage from './pages/admin/sales/SaleDetailPage';
import ApprovalsPage from './pages/admin/sales/ApprovalsPage';
import AuditLogPage from './pages/admin/records/AuditLogPage';
import JournalPage from './pages/admin/records/JournalPage';
import MovementsPage from './pages/admin/inventory/MovementsPage';
import ReportsHub from './pages/admin/reports/ReportsHub';
import ReportViewer from './pages/admin/reports/ReportViewer';

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
                <Route
                    path="/pos"
                    element={
                        <RequireAuth>
                            <Pos />
                        </RequireAuth>
                    }
                />
                <Route
                    path="/terminals"
                    element={
                        <RequireAuth>
                            <TerminalEnroll />
                        </RequireAuth>
                    }
                />
                <Route
                    path="/admin/store-setup"
                    element={
                        <RequireAuth>
                            <StoreSetupOverview />
                        </RequireAuth>
                    }
                />
                <Route
                    path="/admin/store-setup/fiscal-installations"
                    element={
                        <RequireAuth>
                            <FiscalInstallations />
                        </RequireAuth>
                    }
                />
                <Route
                    path="/admin/store-setup/invoice-series"
                    element={
                        <RequireAuth>
                            <InvoiceSeriesPage />
                        </RequireAuth>
                    }
                />
                <Route
                    path="/admin/store-setup/inventory-locations"
                    element={
                        <RequireAuth>
                            <InventoryLocations />
                        </RequireAuth>
                    }
                />
                <Route
                    path="/admin/store-setup/tax-registrations"
                    element={
                        <RequireAuth>
                            <TaxRegistrations />
                        </RequireAuth>
                    }
                />
                <Route
                    path="/admin/users"
                    element={
                        <RequireAuth>
                            <UsersPage />
                        </RequireAuth>
                    }
                />
                <Route
                    path="/admin/sales"
                    element={
                        <RequireAuth>
                            <SalesPage />
                        </RequireAuth>
                    }
                />
                <Route
                    path="/admin/sales/approvals"
                    element={
                        <RequireAuth>
                            <ApprovalsPage />
                        </RequireAuth>
                    }
                />
                <Route
                    path="/admin/sales/:saleId"
                    element={
                        <RequireAuth>
                            <SaleDetailPage />
                        </RequireAuth>
                    }
                />
                <Route
                    path="/admin/audit"
                    element={
                        <RequireAuth>
                            <AuditLogPage />
                        </RequireAuth>
                    }
                />
                <Route
                    path="/admin/journal"
                    element={
                        <RequireAuth>
                            <JournalPage />
                        </RequireAuth>
                    }
                />
                <Route path="/admin/inventory" element={<Navigate to="/admin/inventory/stock" replace />} />
                <Route
                    path="/admin/inventory/stock"
                    element={
                        <RequireAuth>
                            <StockPage />
                        </RequireAuth>
                    }
                />
                <Route
                    path="/admin/inventory/movements"
                    element={
                        <RequireAuth>
                            <MovementsPage />
                        </RequireAuth>
                    }
                />
                <Route path="/admin/catalog" element={<Navigate to="/admin/catalog/products" replace />} />
                <Route
                    path="/admin/catalog/products"
                    element={
                        <RequireAuth>
                            <ProductsPage />
                        </RequireAuth>
                    }
                />
                <Route
                    path="/admin/catalog/categories"
                    element={
                        <RequireAuth>
                            <CatalogNamesPage kind="categories" />
                        </RequireAuth>
                    }
                />
                <Route
                    path="/admin/catalog/brands"
                    element={
                        <RequireAuth>
                            <CatalogNamesPage kind="brands" />
                        </RequireAuth>
                    }
                />
                <Route
                    path="/admin/reports"
                    element={
                        <RequireAuth>
                            <ReportsHub />
                        </RequireAuth>
                    }
                />
                <Route
                    path="/admin/reports/:slug"
                    element={
                        <RequireAuth>
                            <ReportViewer />
                        </RequireAuth>
                    }
                />
                <Route path="*" element={<Navigate to="/" replace />} />
            </Routes>
        </AuthProvider>
    );
}
