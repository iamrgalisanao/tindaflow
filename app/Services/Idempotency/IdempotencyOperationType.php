<?php

namespace App\Services\Idempotency;

/**
 * The 14 mandated idempotent operations (api-design.md §5, Stage 4 pass
 * 3) plus the two added in stage 25, matching
 * `idempotency_records_operation_type_check` exactly
 * (database/migrations/2026_01_01_000190_create_idempotency_records_table.php,
 * extended by 2026_09_20_121409_extend_idempotency_operation_types_for_counts_and_transfers.php).
 */
enum IdempotencyOperationType: string
{
    case Checkout = 'CHECKOUT';
    case SaleVoid = 'SALE_VOID';
    case SaleRefund = 'SALE_REFUND';
    case VoidApprove = 'VOID_APPROVE';
    case VoidReject = 'VOID_REJECT';
    case RefundApprove = 'REFUND_APPROVE';
    case RefundReject = 'REFUND_REJECT';
    case ShiftOpen = 'SHIFT_OPEN';
    case ShiftClose = 'SHIFT_CLOSE';
    case CashMovement = 'CASH_MOVEMENT';
    case StockReceipt = 'STOCK_RECEIPT';
    case StockAdjustment = 'STOCK_ADJUSTMENT';
    case FiscalDayClose = 'FISCAL_DAY_CLOSE';
    case InvoiceReprint = 'INVOICE_REPRINT';
    // Added in stage 25; the CHECK constraint was extended by a forward migration.
    case StockCountPost = 'STOCK_COUNT_POST';
    case StockTransfer = 'STOCK_TRANSFER';
}
