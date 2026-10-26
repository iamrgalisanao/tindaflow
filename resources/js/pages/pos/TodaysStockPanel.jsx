import DailyStockEditor from '../stock/DailyStockEditor';

/**
 * The till's "Today's stock" tab (ADR-017 slice B, client decision 5). A cashier sees what is cooked, sold and left, and can enter the day's
 * quantities only with a manager's code; a manager or administrator signed in at the till saves directly. The grid is the shared editor.
 */
export default function TodaysStockPanel({ canEnterDirectly, onSaved }) {
    return (
        <div className="mx-auto w-full max-w-4xl p-4">
            <DailyStockEditor canEnterDirectly={canEnterDirectly} onSaved={onSaved} />
        </div>
    );
}
