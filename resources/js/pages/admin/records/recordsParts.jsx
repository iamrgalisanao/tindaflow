import { formatMoney, formatQuantity, shortId } from '../reports/formatters';
import { MOVEMENT_TYPES } from '../inventory/inventoryParts';

/** Audit events the application writes, in the order a person would look for them. */
export const AUDIT_TYPES = [
    { id: 'SALE_FINALIZED', label: 'Sale finalized' },
    { id: 'DISCOUNT_APPLIED', label: 'Discount applied' },
    { id: 'INVOICE_REPRINTED', label: 'Invoice reprinted' },
    { id: 'SLIP_REPRINTED', label: 'Slip reprinted' },
    { id: 'SALE_DOCUMENT_MODE_CHANGED', label: 'Sale document mode changed' },
    { id: 'SETTINGS_CHANGED', label: 'Business details changed' },
    { id: 'PRODUCT_CREATED', label: 'Product created' },
    { id: 'PRODUCT_UPDATED', label: 'Product changed' },
    { id: 'PRODUCT_IMPORTED', label: 'Products imported' },
    { id: 'PRODUCT_BARCODE_ADDED', label: 'Barcode added' },
    { id: 'PRODUCT_BARCODE_REMOVED', label: 'Barcode removed' },
    { id: 'MENU_GROUP_CREATED', label: 'Menu group created' },
    { id: 'MENU_GROUP_UPDATED', label: 'Menu group changed' },
    { id: 'MENU_GROUP_REORDERED', label: 'Menu groups reordered' },
    { id: 'MENU_DISH_CREATED', label: 'Dish created' },
    { id: 'MENU_DISH_RENAMED', label: 'Dish renamed' },
    { id: 'MENU_DISH_UPDATED', label: 'Dish changed' },
    { id: 'MENU_DISH_HIDDEN', label: 'Dish hidden' },
    { id: 'MENU_DISH_SHOWN', label: 'Dish shown' },
    { id: 'MENU_PRICE_CHANGED', label: 'Meal price changed' },
    { id: 'MENU_STRUCTURE_CHANGED', label: 'Meal menu changed' },
    { id: 'EMPLOYEE_CREATED', label: 'Employee created' },
    { id: 'EMPLOYEE_UPDATED', label: 'Employee changed' },
    { id: 'EMPLOYEE_DEACTIVATED', label: 'Employee deactivated' },
    { id: 'EMPLOYEE_REACTIVATED', label: 'Employee reactivated' },
    { id: 'EMPLOYEES_IMPORTED', label: 'Employees imported' },
    { id: 'CREDIT_LIMIT_CHANGED', label: 'Credit limit changed' },
    { id: 'CREDIT_OVERRIDE_GRANTED', label: 'Manager approval given' },
    { id: 'CREDIT_OVERRIDE_USED', label: 'Manager approval used' },
    { id: 'CREDIT_OVERRIDE_REFUSED', label: 'Manager approval refused' },
    { id: 'CREDIT_OVERRIDE_LOCKED', label: 'Manager locked out' },
    { id: 'OVERRIDE_CODE_SET', label: 'Override code set' },
    { id: 'OVERRIDE_CODE_RESET', label: 'Override code cleared' },
    { id: 'SALE_VOID_REQUESTED', label: 'Void requested' },
    { id: 'SALE_VOIDED', label: 'Sale voided' },
    { id: 'SALE_VOID_REJECTED', label: 'Void rejected' },
    { id: 'REFUND_REQUESTED', label: 'Refund requested' },
    { id: 'REFUND_CREATED', label: 'Refund completed' },
    { id: 'REFUND_REJECTED', label: 'Refund rejected' },
    { id: 'STOCK_ADJUSTED', label: 'Stock recorded' },
    { id: 'STOCK_COUNT_POSTED', label: 'Stock count posted' },
    { id: 'STOCK_COUNT_CANCELLED', label: 'Stock count cancelled' },
    { id: 'STOCK_TRANSFERRED', label: 'Stock transferred' },
    { id: 'CASH_IN', label: 'Cash in' },
    { id: 'CASH_OUT', label: 'Cash out' },
    { id: 'SHIFT_OPENED', label: 'Shift opened' },
    { id: 'SHIFT_CLOSED', label: 'Shift closed' },
    { id: 'PASSWORD_RESET', label: 'Password reset' },
    { id: 'USER_CREATED', label: 'User created' },
    { id: 'USER_UPDATED', label: 'User changed' },
    { id: 'USER_PASSWORD_CHANGED', label: 'Password changed' },
    { id: 'USER_DEACTIVATED', label: 'User deactivated' },
    { id: 'USER_ACTIVATED', label: 'User reactivated' },
    { id: 'TERMINAL_CREATED', label: 'Till added' },
    { id: 'FISCAL_CONFIGURATION_UNLOCKED', label: 'Fiscal setup unlocked' },
    { id: 'FISCAL_CONFIGURATION_LOCKED', label: 'Fiscal setup locked' },
    { id: 'TAX_REGISTRATION_CORRECTED', label: 'Tax registration corrected' },
    { id: 'TAX_REGISTRATION_CREATED', label: 'Tax registration added' },
    { id: 'FISCAL_INSTALLATION_CREATED', label: 'Fiscal installation added' },
    { id: 'FISCAL_INSTALLATION_TERMINAL_ASSIGNED', label: 'Till assigned to a fiscal installation' },
    { id: 'INVOICE_SERIES_CREATED', label: 'Invoice series created' },
    { id: 'INVOICE_SERIES_CLOSED', label: 'Invoice series closed' },
    { id: 'INVENTORY_LOCATION_CREATED', label: 'Stock location added' },
    { id: 'INVENTORY_LOCATION_UPDATED', label: 'Stock location changed' },
    { id: 'TERMINAL_ENROLLMENT_TOKEN_ISSUED', label: 'Enrollment token issued' },
    { id: 'TERMINAL_PAIRING_REQUESTED', label: 'Till approval requested' },
    { id: 'TERMINAL_PAIRING_APPROVED', label: 'Till approval granted' },
    { id: 'TERMINAL_PAIRING_DECLINED', label: 'Till approval declined' },
    { id: 'TERMINAL_PAIRING_CODE_REFUSED', label: 'Wrong till approval code' },
    { id: 'TERMINAL_ENROLLED', label: 'Till enrolled' },
    { id: 'TERMINAL_REVOKED', label: 'Till revoked' },
    { id: 'X_READING_GENERATED', label: 'X-Reading generated' },
    { id: 'Z_READING_GENERATED', label: 'Z-Reading generated' },
    { id: 'Z_READING_PRINTED', label: 'Z-Reading original printed' },
    { id: 'Z_READING_REPRINTED', label: 'Z-Reading copy printed' },
    { id: 'TERMINAL_SHARED_USE_SUSPECTED', label: 'Till possibly used from two computers' },
];

export const JOURNAL_TYPES = [
    { id: 'INVOICE', label: 'Invoice' },
    { id: 'VOID', label: 'Void' },
    { id: 'REFUND', label: 'Refund' },
    { id: 'X_READING', label: 'X-Reading' },
    { id: 'Z_READING', label: 'Z-Reading' },
    { id: 'SHIFT_OPENED', label: 'Shift opened' },
    { id: 'SHIFT_CLOSED', label: 'Shift closed' },
    { id: 'CASH_IN', label: 'Cash in' },
    { id: 'CASH_OUT', label: 'Cash out' },
    { id: 'STOCK_ADJUSTED', label: 'Stock adjusted' },
];

const humanize = (type) => type.toLowerCase().replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
const movementLabel = (type) => MOVEMENT_TYPES.find((movement) => movement.id === type)?.label ?? humanize(String(type ?? 'movement'));
const money = (value) => (value === null || value === undefined ? '—' : formatMoney(value));

export function auditLabel(type) {
    return AUDIT_TYPES.find((entry) => entry.id === type)?.label ?? humanize(type);
}

/** A reprint is journalled as an INVOICE entry (the type list is closed); say so where it is shown. */
export function journalEntryLabel(entry) {
    return entry.event_type === 'INVOICE' && entry.payload_json?.is_reprint ? 'Invoice reprint' : journalLabel(entry.event_type);
}

export function journalLabel(type) {
    return JOURNAL_TYPES.find((entry) => entry.id === type)?.label ?? humanize(type);
}

const PRODUCT_FIELDS = {
    sku: 'SKU',
    barcode: 'barcode',
    name: 'name',
    description: 'description',
    category_id: 'category',
    brand_id: 'brand',
    menu_group_id: 'menu group',
    unit_of_measure: 'unit',
    cost: 'cost',
    selling_price: 'price',
    tax_class: 'tax class',
    track_inventory: 'stock tracking',
    reorder_level: 'reorder level',
    active: 'status',
};

function productValue(field, value) {
    if (value === null || value === undefined || value === '') {
        return 'none';
    }
    if (field === 'cost' || field === 'selling_price') {
        return formatMoney(value);
    }
    if (field === 'active') {
        return value ? 'active' : 'inactive';
    }
    if (field === 'track_inventory') {
        return value ? 'on' : 'off';
    }
    if (field === 'category_id' || field === 'brand_id' || field === 'menu_group_id') {
        return shortId(value);
    }
    return String(value);
}

/** "price ₱55.00 → ₱60.00, status active → inactive" from a product change's before and after. */
function describeProductChange(before = {}, after = {}) {
    const fields = Object.keys(before).filter((field) => field in PRODUCT_FIELDS);
    return fields.map((field) => `${PRODUCT_FIELDS[field]} ${productValue(field, before[field])} → ${productValue(field, after[field])}`).join(', ');
}

/** "Pack Case (24 units, barcode 4800…) added to product RIC-001" or, for a plain alias, "Barcode 4800… added to product RIC-001". */
function describePackaging(snapshot, verb, suffix) {
    const isPack = snapshot.name !== undefined || snapshot.units_per_base !== undefined;
    const what = isPack
        ? `Pack ${snapshot.name ?? '—'}${snapshot.units_per_base ? ` (${formatQuantity(snapshot.units_per_base)} units${snapshot.barcode ? `, barcode ${snapshot.barcode}` : ''})` : snapshot.barcode ? ` (barcode ${snapshot.barcode})` : ''}`
        : `Barcode ${snapshot.barcode ?? '—'}`;

    return `${what} ${verb} product ${snapshot.sku ?? '—'}${suffix && snapshot.barcode ? `; ${suffix}` : ''}`;
}

const USER_FIELDS = { name: 'name', email: 'e-mail', role: 'role' };

/** "role CASHIER → MANAGER, name …" for the fields of a user that differ. */
function describeUserChange(before, after) {
    return Object.entries(USER_FIELDS)
        .filter(([field]) => before[field] !== after[field])
        .map(([field, label]) => `${label} ${before[field] ?? '—'} → ${after[field] ?? '—'}`)
        .join(', ');
}

const EMPLOYEE_TYPE_NAMES = { DIRECT: 'TSC', SUBCONTRACTOR: 'SUB' };
const EMPLOYEE_NAME_FIELDS = { first_name: 'first name', middle_name: 'middle name', last_name: 'last name' };

/** "first name Maria → Marie" for the name parts of an employee that differ. */
function describeEmployeeChange(before = {}, after = {}) {
    return Object.keys(before)
        .filter((field) => field in EMPLOYEE_NAME_FIELDS)
        .map((field) => `${EMPLOYEE_NAME_FIELDS[field]} ${before[field] ?? '—'} → ${after[field] ?? '—'}`)
        .join(', ');
}

/** Why a manager approval was refused, for the audit screen. Never shown to the cashier. */
const OVERRIDE_REASONS = {
    WRONG_CODE: 'wrong code',
    APPROVER_LOCKED: 'the manager is locked out',
    SELF_APPROVAL: 'a person cannot approve their own sale',
    UNKNOWN_APPROVER: 'no such manager',
    APPROVER_INACTIVE: 'the manager is inactive',
    APPROVER_LACKS_CAPABILITY: 'that user cannot approve',
    NO_CODE: 'the manager has no code',
    TOKEN_UNKNOWN: 'unknown approval',
    WRONG_TERMINAL: 'approval was for another till',
    WRONG_EMPLOYEE: 'approval was for another employee',
    WRONG_CASHIER: 'approval was for another cashier',
    ALREADY_USED: 'approval already used',
    EXPIRED: 'approval expired',
    AMOUNT_ABOVE_CEILING: 'sale is more than was approved',
    APPROVER_NO_LONGER_ELIGIBLE: 'the manager can no longer approve',
};

const MENU_GROUP_FIELDS = { name: 'name', sort_order: 'position', active: 'status' };

/** "name Lunch → Lunch Meals, status active → hidden" from a menu group change's before and after. */
function describeMenuGroupChange(before = {}, after = {}) {
    return Object.keys(before)
        .filter((field) => field in MENU_GROUP_FIELDS)
        .map((field) => {
            const show = (value) => (field === 'active' ? (value ? 'active' : 'hidden') : String(value ?? 'none'));
            return `${MENU_GROUP_FIELDS[field]} ${show(before[field])} → ${show(after[field])}`;
        })
        .join(', ');
}

/** One plain sentence for an audit event, from what the event recorded. Unknown types fall back to their name. */
export function describeAudit(event) {
    const after = event.after_metadata ?? {};
    switch (event.event_type) {
        case 'SALE_FINALIZED':
            return `Sale finalized${after.invoice_number ? `, invoice ${after.invoice_number}` : ''}${after.slip_number ? `, slip ${after.slip_number}` : ''}`;
        case 'DISCOUNT_APPLIED':
            return `Discount of ${money(after.discount_total)} applied${after.invoice_number ? ` to invoice ${after.invoice_number}` : ''}${after.slip_number ? ` to slip ${after.slip_number}` : ''}`;
        case 'SLIP_REPRINTED':
            return `Slip ${after.slip_number ?? '—'} reprinted; no new number was issued`;
        case 'SALE_DOCUMENT_MODE_CHANGED':
            return `Sales are now documented by ${after.sale_document_mode === 'SLIP' ? 'slip' : 'invoice'} (was ${(event.before_metadata ?? {}).sale_document_mode === 'SLIP' ? 'slip' : 'invoice'})`;
        case 'INVOICE_REPRINTED':
            return `Invoice ${after.invoice_number ?? '—'} reprinted; no new number was issued`;
        case 'SETTINGS_CHANGED':
            return `Business details changed: ${Object.keys(after).map((field) => field.replaceAll('_', ' ')).join(', ') || 'nothing recorded'}`;
        case 'PRODUCT_CREATED':
            return `Product ${after.sku ?? '—'} created: ${after.name ?? 'unnamed'} at ${money(after.selling_price)}`;
        case 'PRODUCT_UPDATED':
            return `Product ${after.sku ?? '—'} changed: ${describeProductChange(event.before_metadata ?? {}, after) || 'no listed field'}`;
        case 'PRODUCT_IMPORTED':
            return `Product CSV import: ${after.created ?? 0} created, ${after.updated ?? 0} updated, ${after.unchanged ?? 0} unchanged${after.failed ? `, ${after.failed} skipped` : ''}`;
        case 'MENU_GROUP_CREATED':
            return `Menu group "${after.name ?? '—'}" created${after.parent_id ? ' as a sub-group' : ''}`;
        case 'MENU_GROUP_UPDATED':
            return `Menu group "${after.name ?? '—'}" changed: ${describeMenuGroupChange(event.before_metadata ?? {}, after) || 'no listed field'}`;
        case 'EMPLOYEE_CREATED':
            return `Employee ${after.employee_code ?? '—'} created: ${[after.first_name, after.middle_name, after.last_name].filter(Boolean).join(' ') || 'unnamed'} (${EMPLOYEE_TYPE_NAMES[after.employee_type] ?? after.employee_type ?? '—'})`;
        case 'EMPLOYEE_UPDATED':
            return `Employee ${after.employee_code ?? '—'} changed: ${describeEmployeeChange(event.before_metadata ?? {}, after) || 'no listed field'}`;
        case 'EMPLOYEE_DEACTIVATED':
            return `Employee ${after.employee_code ?? '—'}${after.name ? ` (${after.name})` : ''} deactivated; they can no longer be charged`;
        case 'EMPLOYEE_REACTIVATED':
            return `Employee ${after.employee_code ?? '—'}${after.name ? ` (${after.name})` : ''} reactivated`;
        case 'EMPLOYEES_IMPORTED':
            return `Employee CSV import: ${after.created ?? 0} created, ${after.skipped ?? 0} skipped as already enrolled${after.failed ? `, ${after.failed} with problems` : ''}`;
        case 'CREDIT_LIMIT_CHANGED':
            return `${EMPLOYEE_TYPE_NAMES[after.employee_type] ?? after.employee_type ?? '—'} credit limit ${money((event.before_metadata ?? {}).limit_per_cutoff)} → ${money(after.limit_per_cutoff)} per cut-off`;
        case 'CREDIT_OVERRIDE_GRANTED':
            return `A manager approved one credit sale of up to ${money(after.max_amount)} for ${after.employee_code ?? 'an employee'} (limit ${money(after.limit)}, used ${money(after.used)})`;
        case 'CREDIT_OVERRIDE_USED':
            return `A manager approval let a credit sale of ${money(after.amount)} for ${after.employee_code ?? 'an employee'} go past the limit of ${money(after.limit)} (used before: ${money(after.used)})`;
        case 'CREDIT_OVERRIDE_REFUSED':
            return `A manager approval was refused (${OVERRIDE_REASONS[event.reason ?? after.reason] ?? event.reason ?? after.reason ?? 'no reason recorded'})${after.employee_code ? ` for ${after.employee_code}` : ''}`;
        case 'CREDIT_OVERRIDE_LOCKED':
            return `A manager was locked out of approving for ${after.locked_minutes ?? 15} minutes after too many wrong codes`;
        case 'OVERRIDE_CODE_SET':
            return (event.before_metadata ?? {}).has_code ? 'Override code changed by its owner' : 'Override code set by its owner';
        case 'OVERRIDE_CODE_RESET':
            return `Override code of ${after.user_name ?? 'a user'}${after.role ? ` (${after.role})` : ''} cleared by an administrator${(event.before_metadata ?? {}).locked ? ' (it was locked)' : ''}`;
        case 'MENU_DISH_CREATED':
            return `Dish "${after.name ?? '—'}" created`;
        case 'MENU_DISH_RENAMED':
            return `Dish renamed: "${event.before_metadata?.name ?? '—'}" → "${after.name ?? '—'}"`;
        case 'MENU_DISH_UPDATED':
            return `Dish "${after.name ?? '—'}" changed: ${Object.keys(event.before_metadata ?? {}).join(', ') || 'no listed field'}`;
        case 'MENU_DISH_HIDDEN':
            return `Dish "${after.name ?? '—'}" hidden from the picker`;
        case 'MENU_DISH_SHOWN':
            return `Dish "${after.name ?? '—'}" shown in the picker`;
        case 'MENU_PRICE_CHANGED':
            return `Meal price ${event.before_metadata ? `${event.before_metadata.amount} → ` : 'set to '}${after.amount ?? '—'}, effective ${after.effective_from ?? '—'}${event.reason ? ` (${event.reason})` : ''}`;
        case 'MENU_STRUCTURE_CHANGED':
            return `Meal menu ${String(event.entity_type ?? '').replaceAll('_', ' ')} ${after.change ?? 'changed'}${after.name ?? after.label ? `: ${after.name ?? after.label}` : ''}`;
        case 'MENU_GROUP_REORDERED':
            return `Menu groups reordered: ${(after.order ?? []).length} ${after.parent_id ? 'sub-groups' : 'groups'} in a new order`;
        case 'PRODUCT_BARCODE_ADDED':
            return describePackaging(after, 'added to', 'it can now be scanned to find this product');
        case 'PRODUCT_BARCODE_REMOVED':
            return describePackaging(event.before_metadata ?? {}, 'removed from');
        case 'SALE_VOID_REQUESTED':
            return 'Void requested for a sale';
        case 'SALE_VOIDED':
            return 'Sale voided; every item went back into stock';
        case 'SALE_VOID_REJECTED':
            return 'Void request turned down';
        case 'REFUND_REQUESTED':
            return `Refund of ${money(after.refund_total)} requested`;
        case 'REFUND_CREATED':
            return `Refund of ${money(after.refund_total)} completed`;
        case 'REFUND_REJECTED':
            return 'Refund request turned down';
        case 'STOCK_ADJUSTED':
            return `${movementLabel(after.movement_type)}: ${after.quantity ? formatQuantity(after.quantity) : '—'}${
                after.packaging ? ` (${formatQuantity(after.packaging.packs)} x ${after.packaging.name ?? 'pack'} of ${formatQuantity(after.packaging.units_per_base)})` : ''
            }`;
        case 'STOCK_COUNT_POSTED':
            return `Stock count posted: ${after.lines_counted ?? 0} counted, ${after.lines_adjusted ?? 0} adjusted (${formatQuantity(after.units_found_over ?? '0')} over, ${formatQuantity(after.units_found_short ?? '0')} short)`;
        case 'STOCK_COUNT_CANCELLED':
            return `Stock count cancelled; ${after.lines_discarded ?? 0} counted ${after.lines_discarded === 1 ? 'line was' : 'lines were'} discarded and stock did not change`;
        case 'STOCK_TRANSFERRED':
            return `Stock transferred between locations: ${(after.items ?? []).length} ${(after.items ?? []).length === 1 ? 'product' : 'products'} moved`;
        case 'CASH_IN':
        case 'CASH_OUT':
            return `${event.event_type === 'CASH_IN' ? 'Cash added to' : 'Cash taken from'} the drawer: ${money(after.amount)}`;
        case 'PASSWORD_RESET':
            return `Password reset from the server for ${after.email ?? 'a user'}${after.reactivated ? ' (reactivated)' : ''}; their sessions were ended`;
        case 'USER_CREATED':
            return `User ${after.email ?? 'a user'} created as ${after.role ?? '—'}${after.password_set ? '' : ' (no password yet)'}`;
        case 'USER_UPDATED':
            return `User ${after.email ?? 'a user'} changed: ${describeUserChange(event.before_metadata ?? {}, after) || 'no listed field'}`;
        case 'USER_PASSWORD_CHANGED':
            return `Password changed for ${after.email ?? 'a user'}; ${after.sessions_ended ?? 0} signed-in ${after.sessions_ended === 1 ? 'browser was' : 'browsers were'} signed out`;
        case 'USER_DEACTIVATED':
            return `User ${after.email ?? 'a user'} deactivated; ${after.sessions_ended ?? 0} signed-in ${after.sessions_ended === 1 ? 'browser was' : 'browsers were'} signed out`;
        case 'USER_ACTIVATED':
            return `User ${after.email ?? 'a user'} reactivated`;
        case 'TERMINAL_CREATED':
            return `Till ${after.terminal_code ?? '—'} added${after.via === 'console' ? ' from the server' : ''}`;
        case 'TAX_REGISTRATION_CREATED':
            return `Tax registration ${after.registration_type ?? '—'} added, effective ${after.effective_from ?? '—'}${after.closed_previous_as_of ? `; the previous one ends ${after.closed_previous_as_of}` : ''}`;
        case 'FISCAL_INSTALLATION_CREATED':
            return `Fiscal installation added: ${after.deployment_model ?? '—'}, software ${after.software_version ?? '—'}`;
        case 'FISCAL_INSTALLATION_TERMINAL_ASSIGNED':
            return `Till ${after.terminal_code ?? '—'} assigned to a fiscal installation${event.before_metadata ? ' (it was under another)' : ''}`;
        case 'INVOICE_SERIES_CREATED':
            return `Invoice series ${after.prefix ?? ''}${after.series_code ?? '—'} created, starting at ${after.starting_number ?? '—'}`;
        case 'INVOICE_SERIES_CLOSED':
            return `Invoice series ${after.prefix ?? ''}${after.series_code ?? '—'} closed${after.last_number_issued ? `; the last number issued was ${after.last_number_issued}` : '; it had issued no number'}`;
        case 'INVENTORY_LOCATION_CREATED':
            return `Stock location "${after.name ?? '—'}" added${after.is_default ? ' as the default' : ''}`;
        case 'INVENTORY_LOCATION_UPDATED':
            return `Stock location changed: ${
                [
                    event.before_metadata?.name !== after.name ? `name ${event.before_metadata?.name ?? '—'} → ${after.name ?? '—'}` : null,
                    event.before_metadata?.is_default !== after.is_default && after.is_default ? 'made the default' : null,
                ]
                    .filter(Boolean)
                    .join(', ') || 'no listed field'
            }`;
        case 'TERMINAL_ENROLLMENT_TOKEN_ISSUED':
            return `Enrollment token issued for till ${after.terminal_code ?? '—'}`;
        case 'TERMINAL_PAIRING_REQUESTED':
            return `A computer asked to be approved as a till${after.ip_address ? ` (from ${after.ip_address})` : ''}`;
        case 'TERMINAL_PAIRING_APPROVED':
            return `A till request was approved: that computer became till ${after.terminal_code ?? '—'}${after.ip_address ? ` (from ${after.ip_address})` : ''}`;
        case 'TERMINAL_PAIRING_DECLINED':
            return `A till request was declined${after.ip_address ? ` (from ${after.ip_address})` : ''}`;
        case 'TERMINAL_PAIRING_CODE_REFUSED':
            return `A wrong code was entered for a till request (attempt ${after.attempts ?? '?'} of 5)${after.declined ? '; the request was declined' : ''}`;
        case 'TERMINAL_ENROLLED':
            return `A browser was enrolled as till ${after.terminal_code ?? '—'}${after.re_enrolled_after_revocation ? ' (after it had been revoked)' : ''}`;
        case 'TERMINAL_REVOKED':
            return `Till ${after.terminal_code ?? '—'} revoked; the browser holding its credential can no longer sell`;
        case 'FISCAL_CONFIGURATION_UNLOCKED':
            return `Fiscal setup unlocked from the server${after.unlocked_until ? ` until ${after.unlocked_until}` : ''}`;
        case 'FISCAL_CONFIGURATION_LOCKED':
            return 'Fiscal setup locked again';
        case 'TAX_REGISTRATION_CORRECTED':
            return `Tax registration that had not started was corrected: ${event.before_metadata?.effective_from ?? '—'} → ${after.effective_from ?? '—'}${after.registration_type ? ` (${after.registration_type})` : ''}`;
        case 'SHIFT_OPENED':
            return `Shift opened with ${money(after.opening_cash)} in the drawer`;
        case 'SHIFT_CLOSED':
            return `Shift closed, variance ${money(after.variance)}`;
        case 'X_READING_GENERATED':
            return 'X-Reading generated';
        case 'Z_READING_PRINTED':
            return `The original of Z-Reading #${after.z_counter ?? '?'} was printed`;
        case 'TERMINAL_SHARED_USE_SUSPECTED':
            return `Till ${after.terminal_code ?? '—'} was used from two different computers in turn${after.ip_address ? ` (${after.ip_address}${after.other_ip_address && after.other_ip_address !== after.ip_address ? ` and ${after.other_ip_address}` : ''})` : ''}; if that is not expected, re-enroll the till`;
        case 'Z_READING_REPRINTED':
            return `A copy of Z-Reading #${after.z_counter ?? '?'} was printed`;
        case 'Z_READING_GENERATED':
            return `Z-Reading #${after.z_counter ?? '?'} generated; the fiscal day closed`;
        default:
            return humanize(event.event_type);
    }
}

/** One plain sentence for a journal entry, from its payload snapshot. */
export function describeJournal(entry) {
    const payload = entry.payload_json ?? {};
    switch (entry.event_type) {
        case 'INVOICE':
            return `${payload.is_reprint ? 'Reprint of invoice' : 'Invoice'} ${payload.invoice_number ?? '—'} · ${money(payload.grand_total)}`;
        case 'VOID':
            return `Void of ${payload.slip_number ? `slip ${payload.slip_number}` : `invoice ${payload.invoice_number ?? '—'}`} · ${money(payload.grand_total)}`;
        case 'REFUND':
            return `Refund ${money(payload.refund_total)} on ${payload.slip_number ? `slip ${payload.slip_number}` : `invoice ${payload.invoice_number ?? '—'}`}`;
        case 'STOCK_ADJUSTED':
            return `${movementLabel(payload.movement_type)} ${payload.quantity ? formatQuantity(payload.quantity) : '—'}${payload.sku ? ` · ${payload.sku}` : ''}`;
        case 'CASH_IN':
        case 'CASH_OUT':
            return `${money(payload.amount)}${payload.reason ? ` · ${payload.reason}` : ''}`;
        case 'X_READING':
            return `X-Reading · ${payload.transaction_count ?? 0} sales, expected cash ${money(payload.expected_cash)}`;
        case 'Z_READING':
            return `Z-Reading #${payload.z_counter ?? '?'} · gross sales ${money(payload.gross_sales)}`;
        case 'SHIFT_OPENED':
            return `Shift opened · opening cash ${money(payload.opening_cash)}`;
        case 'SHIFT_CLOSED':
            return `Shift closed · variance ${money(payload.variance)}`;
        default:
            return journalLabel(entry.event_type);
    }
}

/** Values that are ids are shown by their random tail with the full id on hover. */
export function KeyValues({ data }) {
    const entries = Object.entries(data ?? {});
    if (entries.length === 0) {
        return <p className="text-xs text-slate-500">Nothing recorded.</p>;
    }
    return (
        <dl className="grid grid-cols-1 gap-x-4 gap-y-1 text-xs sm:grid-cols-[max-content_1fr]">
            {entries.map(([key, value]) => (
                <div key={key} className="contents">
                    <dt className="font-mono text-slate-500">{key}</dt>
                    <dd className="min-w-0 break-words text-slate-200">
                        {value === null || value === undefined ? (
                            <span className="text-slate-600">—</span>
                        ) : typeof value === 'object' ? (
                            <pre className="overflow-x-auto whitespace-pre-wrap font-mono text-[11px] text-slate-300">{JSON.stringify(value, null, 2)}</pre>
                        ) : /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(String(value)) ? (
                            <span className="font-mono" title={String(value)}>
                                {shortId(value)}
                            </span>
                        ) : (
                            <span className="font-mono">{String(value)}</span>
                        )}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

export function TypeBadge({ label }) {
    return <span className="inline-block whitespace-nowrap rounded border border-slate-700 bg-slate-800/60 px-1.5 py-0.5 font-mono text-[10px] font-semibold text-emerald-300">{label.toUpperCase()}</span>;
}
