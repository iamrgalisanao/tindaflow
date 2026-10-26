import { useAuth } from '../../../context/AuthContext';
import DailyStockEditor from '../../stock/DailyStockEditor';
import AdminLayout from '../AdminLayout';

/**
 * /admin/inventory/today -- the morning screen (ADR-017 slice B, client decisions 5 and 6): the manager enters how many portions of each dish and each
 * daily product were cooked, adds a second batch later in the day, and sees what is sold and left. A grid, "copy yesterday's quantities" and quick-add
 * buttons make it fast. STOCK_ADJUST (MANAGER, ADMIN) saves directly; the same grid is on the till for a cashier, who needs a manager's code.
 */
export default function TodaysStockPage() {
    const { user } = useAuth();

    return (
        <AdminLayout title="Today's stock" requiredCapability="STOCK_ADJUST">
            <div className="mx-auto max-w-4xl">
                <DailyStockEditor canEnterDirectly={user?.capabilities?.includes('STOCK_ADJUST') ?? false} />
            </div>
        </AdminLayout>
    );
}
