# Operation Inventory — TindaFlow POS API (Stage 4)

## Status
DRAFT — Stage 4, remediation pass 5 (2026-09-16). This table is the review
surface before implementation and must match
[openapi.yaml](openapi.yaml) exactly — regenerate/diff this table whenever
the spec changes rather than letting the two drift. Validated against the
spec: **86 operations across 75 path templates** (78/67 through pass 2;
**+8 operations/+8 paths in pass 3**, the first endpoint additions across
any pass; unchanged in pass 4/5) — 53 GET, 30 POST, 3 PATCH, 0 PUT, 0
DELETE anywhere in the entire API. **Pass 1 closed the capability-naming
gap** (every "(catalog mgmt)"/"Admin only"/"or a dedicated capability"
placeholder below is now a concrete `Capability` enum value,
cross-checked programmatically against `x-capability` extensions in
openapi.yaml). **Pass 2 revised the Invoice reprint operation's response
schema** (`InvoiceReprintResult` replaces `InvoiceDetail`) and tightened
`invoice_number`'s pattern. **Pass 3 added the Void/Refund approval
workflow** (`voidList`/`voidGet`/`voidApprove`/`voidReject` and the
Refund equivalents — see the Sales section below) to close a real gap:
`SALE_VOID_APPROVE`/`SALE_REFUND_APPROVE` had no operation until then.
**Pass 4 verified approval-is-execution** against frozen text (confirmed,
no redesign) and corrected a wrong journal-entry claim plus a missing
`RefundSummary` field set. **Pass 5 corrects a real defect pass 4 itself
carried forward**: a failed approve attempt no longer auto-transitions
the request to `REJECTED` — it now leaves the request `REQUESTED`,
unchanged, with a stable `409`/`422`, for either Void or Refund. Void's
and Refund's stale-fiscal-day rules are now made explicit as distinct
(Void: permanent; Refund: no such rule at all). No new operations.

**Legend:** *Term. enrolled?* = requires a resolved terminal credential
(ADR-011), not just a user session — this is `false` for auth/reporting/
admin operations that make sense from any authenticated browser, and
`true` for anything attributing an action to a physical terminal.
*Idemp.?* = `Idempotency-Key` header required (ADR-010).

## Auth (3)

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| POST | /auth/login | authLogin | none | false | — | no | email+password | 200 UserSummary | `AUTHENTICATION_REQUIRED` (bad creds), 429 |
| POST | /auth/logout | authLogout | session | false | — | no | — | 204 | 401 |
| GET | /auth/me | authMe | session | false | — | no | — | 200 UserSummary | 401 |

## Terminal (6)

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| POST | /terminal-enrollment-tokens | terminalCreateEnrollmentToken | session | false | `TERMINAL_MANAGE` | no | terminal_id | 201 Token | 403, 404 |
| POST | /terminal/enroll | terminalEnroll | session (admin on this workstation) | false (this is what establishes it) | `TERMINAL_MANAGE` | no | token | 200 TerminalSummary | 409 (token used/expired/revoked) |
| POST | /terminal/pairing-requests | terminalPairingRequestCreate | session | false | — | no | — | 201 `{id, code, secret, expires_at}` | 401, 429 `PAIRING_REQUEST_LIMIT` |
| GET | /terminal/pairing-requests | terminalPairingRequestList | session | false | `TERMINAL_MANAGE` | no | — | 200 `{data: [...]}` (never the codes) | 401, 403 |
| POST | /terminal/pairing-requests/{requestId}/claim | terminalPairingRequestClaim | session | false | — | no | secret | 202 pending / 200 TerminalSummary + credential cookie | 401, 409 `PAIRING_REQUEST_INVALID` |
| POST | /terminal/pairing-requests/{requestId}/approve | terminalPairingRequestApprove | session | false | `TERMINAL_MANAGE` | no | code, terminal_id | 200 | 401, 403, 404 `TERMINAL_NOT_FOUND`, 409 `PAIRING_REQUEST_INVALID`/`TERMINAL_LIMIT_REACHED`, 422 |
| POST | /terminal/pairing-requests/{requestId}/reject | terminalPairingRequestReject | session | false | `TERMINAL_MANAGE` | no | — | 200 | 401, 403, 409 `PAIRING_REQUEST_INVALID` |
| GET | /terminal/current | terminalCurrent | session | true | — | no | — | 200 TerminalSummary | `TERMINAL_NOT_ENROLLED` |
| POST | /terminals | terminalCreate | session | false | `TERMINAL_MANAGE` | no | terminal_code | 201 TerminalSummary | 403, 409 `TERMINAL_LIMIT_REACHED`, 422 |
| GET | /terminals | terminalList | session | false | `TERMINAL_MANAGE` | no | — | 200 paginated | 403 |
| GET | /terminals/{terminalId} | terminalGet | session | false | `TERMINAL_MANAGE` | no | — | 200 TerminalSummary | 403, 404 |
| POST | /terminals/{terminalId}/revoke | terminalRevoke | session | false | `TERMINAL_MANAGE` | no | — | 200 TerminalSummary | 403, 404 |

## Catalog (13)

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /products | productList | session | false | — | no | query filters | 200 paginated | 401 |
| POST | /products | productCreate | session | false | `CATALOG_MANAGE` | no | ProductInput | 201 Product | 422 |
| GET | /products/{productId} | productGet | session | false | — | no | — | 200 Product | 404 |
| PATCH | /products/{productId} | productUpdate | session | false | `CATALOG_MANAGE` | no | ProductInput | 200 Product | 422, 404 |
| POST | /products/{productId}/activate | productActivate | session | false | `CATALOG_MANAGE` | no | — | 200 Product | 404 |
| POST | /products/{productId}/deactivate | productDeactivate | session | false | `CATALOG_MANAGE` | no | — | 200 Product | 404 |
| GET | /products/by-barcode/{barcode} | productLookupByBarcode | session | true | — | no | — | 200 Product | `BARCODE_NOT_FOUND` |
| GET | /categories | categoryList | session | false | — | no | — | 200 paginated | 401 |
| POST | /categories | categoryCreate | session | false | `CATALOG_MANAGE` | no | name | 201 Category | 422 |
| GET | /brands | brandList | session | false | — | no | — | 200 paginated | 401 |
| POST | /brands | brandCreate | session | false | `CATALOG_MANAGE` | no | name | 201 Brand | 422 |
| POST | /products/import | productImport | session | false | `CATALOG_MANAGE` | no | CSV | 202 ImportResult | 422 |
| GET | /products/export | productExport | session | false | — | no | — | 200 CSV | 401 |

### Menu groups (6) — ADR-016 slice 1.1 (2026-10-25), forward-committed

The till's two-level navigation tree (`docs/06-backend/canteen-build-plan.md` 1.1). Reads are session-only; every change is `CATALOG_MANAGE`; a group of another store is `MENU_GROUP_NOT_FOUND` (new code, 404). There is no delete (MENU-005). `productList` gains a `menu_group_id` filter (a top group includes its sub-groups) and `Product`/`ProductInput` gain a nullable `menu_group_id`.

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /menu-groups | menuGroupList | session | false | — | no | `active?` | 200 paginated MenuGroup | 401 |
| POST | /menu-groups | menuGroupCreate | session | false | `CATALOG_MANAGE` | no | name, parent_id?, sort_order? | 201 MenuGroup | 403, 422 (depth, duplicate name) |
| POST | /menu-groups/reorder | menuGroupReorder | session | false | `CATALOG_MANAGE` | no | parent_id?, ids | 200 MenuGroup list | 403, 422 |
| PATCH | /menu-groups/{menuGroupId} | menuGroupUpdate | session | false | `CATALOG_MANAGE` | no | name?, sort_order? | 200 MenuGroup | 403, `MENU_GROUP_NOT_FOUND`, 422 |
| POST | /menu-groups/{menuGroupId}/activate | menuGroupActivate | session | false | `CATALOG_MANAGE` | no | — | 200 MenuGroup | 403, `MENU_GROUP_NOT_FOUND` |
| POST | /menu-groups/{menuGroupId}/deactivate | menuGroupDeactivate | session | false | `CATALOG_MANAGE` | no | — | 200 MenuGroup | 403, `MENU_GROUP_NOT_FOUND` |

### Till menu (1) — ADR-016 slice 1.3 (2026-10-25), forward-committed

The till's read-only meal menu (`docs/06-backend/canteen-build-plan.md` 1.3): the active meals with their option groups, options, dish categories and the **current** price per dish category and add-on. Session only, no capability, scoped to the actor's store. It lists only what the checkout would accept (visible meal and menu group, active group/option/dish/category, a price in force). `saleFinalize` gains `items[].meal` (see Sales): the same ids this payload carries, never a price.

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /menu | menuGet | session | false | — | no | — | 200 TillMenu | 401 |

### Meal menu (32) — ADR-016 slice 1.2 (2026-10-25), forward-committed

Configuration behind the till's meal picker (`docs/06-backend/canteen-build-plan.md` 1.2): dish categories, dishes, meals (each with a hidden backing product and a pricing option group), option groups, options, and the append-only price history. Every operation, reads included, is `CATALOG_MANAGE` (the till's own read-only `GET /menu` payload is slice 1.3, `menuGet` above). Records of another store are `DISH_CATEGORY_NOT_FOUND`, `DISH_NOT_FOUND`, `MEAL_NOT_FOUND`, `MEAL_OPTION_GROUP_NOT_FOUND`, `MEAL_OPTION_NOT_FOUND` (new codes, 404). There is no delete anywhere (MENU-005): hiding is `deactivate`. A price edit appends a `menu_prices` row (MENU-004); there is no price update or delete. Tag in `openapi.yaml`: `Menu`.

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /dish-categories | dishCategoryList | session | false | `CATALOG_MANAGE` | no | active? | 200 paginated DishCategory | 403 |
| POST | /dish-categories | dishCategoryCreate | session | false | `CATALOG_MANAGE` | no | name, sort_order | 201 DishCategory | 403, 422 |
| POST | /dish-categories/reorder | dishCategoryReorder | session | false | `CATALOG_MANAGE` | no | ids | 200 DishCategory list | 403, 422 |
| PATCH | /dish-categories/{dishCategoryId} | dishCategoryUpdate | session | false | `CATALOG_MANAGE` | no | name, sort_order | 200 DishCategory | 403, `DISH_CATEGORY_NOT_FOUND`, 422 |
| POST | /dish-categories/{dishCategoryId}/activate | dishCategoryActivate | session | false | `CATALOG_MANAGE` | no | — | 200 DishCategory | 403, `DISH_CATEGORY_NOT_FOUND` |
| POST | /dish-categories/{dishCategoryId}/deactivate | dishCategoryDeactivate | session | false | `CATALOG_MANAGE` | no | — | 200 DishCategory | 403, `DISH_CATEGORY_NOT_FOUND` |
| GET | /dishes | dishList | session | false | `CATALOG_MANAGE` | no | dish_category_id, active, search? | 200 paginated Dish | 403 |
| POST | /dishes | dishCreate | session | false | `CATALOG_MANAGE` | no | name, dish_category_id, sort_order | 201 Dish | 403, 422 |
| POST | /dishes/reorder | dishReorder | session | false | `CATALOG_MANAGE` | no | dish_category_id, ids | 200 Dish list | 403, 422 |
| PATCH | /dishes/{dishId} | dishUpdate | session | false | `CATALOG_MANAGE` | no | name, dish_category_id, sort_order | 200 Dish | 403, `DISH_NOT_FOUND`, 422 |
| POST | /dishes/{dishId}/activate | dishActivate | session | false | `CATALOG_MANAGE` | no | — | 200 Dish | 403, `DISH_NOT_FOUND` |
| POST | /dishes/{dishId}/deactivate | dishDeactivate | session | false | `CATALOG_MANAGE` | no | — | 200 Dish | 403, `DISH_NOT_FOUND` |
| GET | /meals | mealList | session | false | `CATALOG_MANAGE` | no | active, menu_group_id? | 200 paginated Meal | 403 |
| POST | /meals | mealCreate | session | false | `CATALOG_MANAGE` | no | name, menu_group_id, sort_order, pricing_group_name | 201 Meal | 403, 422 |
| POST | /meals/reorder | mealReorder | session | false | `CATALOG_MANAGE` | no | ids | 200 Meal list | 403, 422 |
| GET | /meals/{mealId} | mealGet | session | false | `CATALOG_MANAGE` | no | — | 200 Meal | 403, `MEAL_NOT_FOUND` |
| PATCH | /meals/{mealId} | mealUpdate | session | false | `CATALOG_MANAGE` | no | name, menu_group_id, sort_order | 200 Meal | 403, `MEAL_NOT_FOUND`, 422 |
| POST | /meals/{mealId}/activate | mealActivate | session | false | `CATALOG_MANAGE` | no | — | 200 Meal | 403, `MEAL_NOT_FOUND` |
| POST | /meals/{mealId}/deactivate | mealDeactivate | session | false | `CATALOG_MANAGE` | no | — | 200 Meal | 403, `MEAL_NOT_FOUND` |
| POST | /meals/{mealId}/option-groups | mealOptionGroupCreate | session | false | `CATALOG_MANAGE` | no | name, kind, min_select, max_select, popup_after_choice, step_order | 201 MealOptionGroup | 403, `MEAL_NOT_FOUND`, 422 |
| POST | /meals/{mealId}/option-groups/reorder | mealOptionGroupReorder | session | false | `CATALOG_MANAGE` | no | ids | 200 MealOptionGroup list | 403, `MEAL_NOT_FOUND`, 422 |
| PATCH | /meal-option-groups/{groupId} | mealOptionGroupUpdate | session | false | `CATALOG_MANAGE` | no | name, min_select, max_select, popup_after_choice, step_order, prices_the_meal | 200 MealOptionGroup | 403, `MEAL_OPTION_GROUP_NOT_FOUND`, 422 |
| POST | /meal-option-groups/{groupId}/activate | mealOptionGroupActivate | session | false | `CATALOG_MANAGE` | no | — | 200 MealOptionGroup | 403, `MEAL_OPTION_GROUP_NOT_FOUND` |
| POST | /meal-option-groups/{groupId}/deactivate | mealOptionGroupDeactivate | session | false | `CATALOG_MANAGE` | no | — | 200 MealOptionGroup | 403, `MEAL_OPTION_GROUP_NOT_FOUND`, 422 |
| POST | /meal-option-groups/{groupId}/options | mealOptionCreate | session | false | `CATALOG_MANAGE` | no | dish_id, label, sort_order | 201 MealOption | 403, `MEAL_OPTION_GROUP_NOT_FOUND`, 422 |
| POST | /meal-option-groups/{groupId}/options/reorder | mealOptionReorder | session | false | `CATALOG_MANAGE` | no | ids | 200 MealOption list | 403, `MEAL_OPTION_GROUP_NOT_FOUND`, 422 |
| PATCH | /meal-options/{optionId} | mealOptionUpdate | session | false | `CATALOG_MANAGE` | no | label, sort_order | 200 MealOption | 403, `MEAL_OPTION_NOT_FOUND`, 422 |
| POST | /meal-options/{optionId}/activate | mealOptionActivate | session | false | `CATALOG_MANAGE` | no | — | 200 MealOption | 403, `MEAL_OPTION_NOT_FOUND` |
| POST | /meal-options/{optionId}/deactivate | mealOptionDeactivate | session | false | `CATALOG_MANAGE` | no | — | 200 MealOption | 403, `MEAL_OPTION_NOT_FOUND` |
| GET | /meals/{mealId}/prices | menuPriceGrid | session | false | `CATALOG_MANAGE` | no | — | 200 MenuPriceGrid | 403, `MEAL_NOT_FOUND` |
| POST | /meals/{mealId}/prices | menuPriceSet | session | false | `CATALOG_MANAGE` | no | dish_category_id, meal_option_id, amount, effective_from, reason | 201 MenuPrice | 403, `MEAL_NOT_FOUND`, 422 |
| GET | /meals/{mealId}/prices/history | menuPriceHistory | session | false | `CATALOG_MANAGE` | no | dish_category_id, meal_option_id? | 200 paginated MenuPrice | 403, `MEAL_NOT_FOUND` |

### Alternate barcodes and packs (3) — stage 26 (2026-09-20), forward-committed, no prior draft at any stage; extended in stage 29 (2026-09-21)

**Stage 29:** a row is now a *packaging*: the barcode is optional, and a row may carry a `name` ("Case") and `units_per_base` (how many single units one holds; 1 for a plain alias), plus `can_receive`. `productBarcodeCreate` accepts `name`, `units_per_base` and `can_receive` and needs at least one of `barcode` and `name`; a duplicate name on the same product is a `422` on `name`. Stock is always kept in the base unit. See `docs/06-backend/stage-29-packaging-and-pack-receiving.md`.

`productLookupByBarcode` has matched a product's alternate `product_barcodes` rows since stage 20, but nothing could create them. These three operations manage them. A barcode identifies at most one product per store across a product's own `barcode` and every alternate; a code already in use is the catalog's usual `422 VALIDATION_FAILED` field error on `barcode`, so **no new error code** was needed. Reads need only the session like the other catalog reads; adding and removing are `CATALOG_MANAGE`. See `docs/06-backend/stage-26-alternate-barcodes.md`.

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /products/{productId}/barcodes | productBarcodeList | session | false | — | no | — | 200 paginated ProductBarcode | 401, `PRODUCT_NOT_FOUND` |
| POST | /products/{productId}/barcodes | productBarcodeCreate | session | false | `CATALOG_MANAGE` | no | barcode?, name?, units_per_base?, can_receive? (one of barcode, name) | 201 ProductBarcode | 403, `PRODUCT_NOT_FOUND`, 422 |
| DELETE | /products/{productId}/barcodes/{barcodeId} | productBarcodeDelete | session | false | `CATALOG_MANAGE` | no | — | 204 (also when nothing was there) | 403, `PRODUCT_NOT_FOUND` |

## Inventory (5)

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /inventory/stock | inventoryStockList | session | false | — | no | — | 200 paginated (rows also carry `shelf_life_days`, `last_received_on`, `check_freshness`, `days_old`; optional `?check_freshness=true` filter. ADR-017 slice C) | 401 |
| GET | /inventory/low-stock | inventoryLowStockList | session | false | — | no | — | 200 paginated (each row carries `low_stock_threshold`: the product's `reorder_level`, else the store default; a row is listed when on hand `<=` it. ADR-017 slice A) | 401 |
| GET | /inventory/movements | inventoryMovementList | session | false | — | no | filters | 200 paginated | 401 |
| POST | /inventory/receipts | inventoryReceiptCreate | session | true | `STOCK_ADJUST` | **yes** | product/qty/type | 201 StockMovement | 404, 422 |
| POST | /inventory/adjustments | inventoryAdjustmentCreate | session | true | `STOCK_ADJUST` | **yes** | product/qty/type/reason | 201 StockMovement | `STOCK_ADJUSTMENT_REASON_REQUIRED`, 404 |

### Stock counts and transfers (10) — stage 25 (2026-09-20), forward-committed, no prior draft at any stage

All ten need `STOCK_ADJUST` and are scoped to the caller's store (another store's record is its own `404`, never a `403`). The two that write the ledger, `stockCountPost` and `stockTransferCreate`, are terminal-scoped and idempotent like receipts and adjustments; drafting a count writes nothing to the ledger and needs only the session. `scope.md` lists stocktake and inter-store transfers as Phase 2; the owner chose on 2026-09-20 to build counts and transfers **between one store's own locations** now (transfers between stores stay Phase 2 and need multi-store tenancy). See `docs/06-backend/stage-25-stock-counts-and-transfers.md`.

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /inventory/counts | stockCountList | session | false | `STOCK_ADJUST` | no | filters | 200 paginated | 403 |
| POST | /inventory/counts | stockCountCreate | session | false | `STOCK_ADJUST` | no | location_id?, note? | 201 StockCount | 403, `INVENTORY_LOCATION_NOT_FOUND`, 409 `STOCK_COUNT_ALREADY_OPEN` |
| GET | /inventory/counts/{stockCountId} | stockCountGet | session | false | `STOCK_ADJUST` | no | — | 200 StockCount (with lines) | 403, `STOCK_COUNT_NOT_FOUND` |
| PUT | /inventory/counts/{stockCountId}/lines | stockCountLinesRecord | session | false | `STOCK_ADJUST` | no | lines[product_id, counted_quantity] | 200 StockCount | 403, 404, 409 `STOCK_COUNT_NOT_OPEN`, 422 |
| DELETE | /inventory/counts/{stockCountId}/lines/{productId} | stockCountLineRemove | session | false | `STOCK_ADJUST` | no | — | 200 StockCount | 403, `STOCK_COUNT_NOT_FOUND`, 409 `STOCK_COUNT_NOT_OPEN` |
| POST | /inventory/counts/{stockCountId}/post | stockCountPost | session | true | `STOCK_ADJUST` | **yes** | — | 200 StockCount | 403, `STOCK_COUNT_NOT_FOUND`, 409 `STOCK_COUNT_NOT_OPEN`, 422 |
| POST | /inventory/counts/{stockCountId}/cancel | stockCountCancel | session | false | `STOCK_ADJUST` | no | — | 200 StockCount | 403, `STOCK_COUNT_NOT_FOUND`, 409 `STOCK_COUNT_NOT_OPEN` |
| GET | /inventory/transfers | stockTransferList | session | false | `STOCK_ADJUST` | no | filters | 200 paginated | 403 |
| POST | /inventory/transfers | stockTransferCreate | session | true | `STOCK_ADJUST` | **yes** | from, to, items, note? | 201 StockTransfer | 403, `INVENTORY_LOCATION_NOT_FOUND`, `PRODUCT_NOT_FOUND`, 422 |
| GET | /inventory/transfers/{stockTransferId} | stockTransferGet | session | false | `STOCK_ADJUST` | no | — | 200 StockTransfer (with lines) | 403, `STOCK_TRANSFER_NOT_FOUND` |

## Sales (13, was 5 — +8 in remediation pass 3) — the core financial surface

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /sales | saleList | session | false | — | no | filters | 200 paginated | 401 |
| **POST** | **/sales** | **saleFinalize** | session | **true** | — (any cashier with an open shift) | **yes** | SaleFinalizeRequest (items: `product_id` or, ADR-016 slice 1.3, `meal`) | 201 SaleDetail | `SHIFT_REQUIRED`, `FISCAL_DAY_NOT_OPEN`, `PRODUCT_NOT_FOUND`, `PRODUCT_INACTIVE`, `MEAL_NOT_AVAILABLE` (409), `MEAL_SELECTION_INVALID` (422), `INSUFFICIENT_PAYMENT`, `INVOICE_SERIES_EXHAUSTED`, `IDEMPOTENCY_KEY_REUSED`; credit (ADR-016 slice 3.2): `CREDIT_LIMIT_EXCEEDED` (409), `CREDIT_MIXED_TENDER` (422), `CREDIT_EMPLOYEE_REQUIRED` (422), `EMPLOYEE_NOT_FOUND` (404), `EMPLOYEE_INACTIVE` (409); stock (ADR-017 slice A, only when the store blocks sales without stock): `INSUFFICIENT_STOCK` (409), `STOCK_OVERRIDE_INVALID` (422), `STOCK_OVERRIDE_EXPIRED` (409), `STOCK_OVERRIDE_NOT_ALLOWED` (403) |
| GET | /sales/{saleId} | saleGet | session | false | — | no | — | 200 SaleDetail | 404 |
| POST | /sales/{saleId}/void | saleVoid | session | **true** | `SALE_VOID` (+`SALE_VOID_APPROVE`) | **yes** | reason | 201 VoidResult | `SALE_NOT_VOIDABLE`, `SALE_ALREADY_VOIDED`, `FISCAL_DAY_CLOSED`, `REFUND_NOT_ALLOWED` (already refunded), 403 |
| POST | /sales/{saleId}/refunds | saleRefund | session | **true** | `SALE_REFUND` (+`SALE_REFUND_APPROVE`) | **yes** | RefundRequest | 201 RefundResult | `REFUND_EXCEEDS_REMAINING_QUANTITY`, `REFUND_EXCEEDS_REMAINING_AMOUNT`, `REFUND_SETTLEMENT_MISMATCH`, `REFUND_NOT_ALLOWED` (sale voided), `FISCAL_DAY_CLOSED` (processing day), `SHIFT_NOT_OPEN` (processing shift) |
| GET | /voids | **voidList** | session | false | — | no | filters (`status`, `sale_id`) | 200 paginated VoidSummary | 401 |
| GET | /voids/{voidId} | **voidGet** | session | false | — | no | — | 200 VoidResult | 404 |
| POST | /voids/{voidId}/approve | **voidApprove** | session | **true** | `SALE_VOID_APPROVE` | **yes** | — | 200 VoidResult (VOIDED only) | `VOID_NOT_PENDING_APPROVAL`, `SALE_NOT_VOIDABLE`, `FISCAL_DAY_CLOSED`, `SHIFT_NOT_OPEN` (all leave the void REQUESTED, unchanged — pass 5), 403, 404 |
| POST | /voids/{voidId}/reject | **voidReject** | session | false | `SALE_VOID_APPROVE` | **yes** | reason | 200 VoidResult (REJECTED) | `VOID_NOT_PENDING_APPROVAL`, 403, 404, 422 |
| GET | /refunds | **refundList** | session | false | — | no | filters (`status`, `sale_id`) | 200 paginated RefundSummary | 401 |
| GET | /refunds/{refundId} | **refundGet** | session | false | — | no | — | 200 RefundResult | 404 |
| POST | /refunds/{refundId}/approve | **refundApprove** | session | **true** | `SALE_REFUND_APPROVE` | **yes** | — | 200 RefundResult (COMPLETED) | `REFUND_NOT_PENDING_APPROVAL`, `FISCAL_DAY_CLOSED`, `SHIFT_NOT_OPEN`, `REFUND_EXCEEDS_REMAINING_QUANTITY`/`_AMOUNT` (re-validated at execution time, pass 4), 403, 404 |
| POST | /refunds/{refundId}/reject | **refundReject** | session | false | `SALE_REFUND_APPROVE` | **yes** | reason | 200 RefundResult (REJECTED) | `REFUND_NOT_PENDING_APPROVAL`, 403, 404, 422 |

**Why these eight exist (remediation pass 3 — see api-design.md §12.1 for
the full reasoning):** `SALE_VOID_APPROVE`/`SALE_REFUND_APPROVE` were
canonical capabilities with no operation that used them — `saleVoid`/
`saleRefund` only ever executed immediately (requester already held the
approve capability) or left the row `REQUESTED` with no path back out
for a different, later actor. `sale.status` stays `COMPLETED` while a
void/refund sits `REQUESTED` (state-machines.md §1–§3), so `voidList`/
`refundList` are the *only* way to discover one pending approval — this
backs the Stage 1 sitemap's `/back-office/void-refund/approvals` and
`/back-office/void-refund/:id` screens, which existed since Stage 1 with
no backing endpoint until now. `voidApprove`/`refundApprove` perform the
frozen `APPROVED → VOIDED`/`APPROVED → COMPLETED` execution atomically
within the same call (Stage 2's own diagrams show no separately-observed
resting state between them). ~~`voidApprove` may legitimately return
`status: REJECTED` in its `200` body~~ **corrected in pass 5 — see
below: this was a real defect, not a documented, deliberate design.**

**Pass 4 verification — approval IS execution, confirmed against frozen
text (api-design.md §12.1):** before tagging the baseline, the owner
asked whether `REQUESTED → APPROVED` means the fiscal adjustment is
already finalized, or whether `APPROVED` is only authorization for a
later, separate execution step. `state-machines.md` §2/§3 show `APPROVED`
immediately followed by system execution in the same diagram, and
`invariants.md` #24 names exactly three void audit events for four
lifecycle moments — there is no `SALE_VOID_APPROVED` event. **Confirmed:
`APPROVED` is not an independently-effective resting state; `voidApprove`/
`refundApprove` are fiscal execution commands**, exactly as pass 3
already implemented (no redesign needed). Pass 4 corrects two
description-accuracy issues this check surfaced:
- `voidReject`/`refundReject` (and `voidApprove`'s system-auto-`REJECTED`
  path) were described as writing `electronic_journal_entry` — wrong,
  since a rejection is never a fiscally-journalable event
  (domain-model.md §2.10). They now correctly write `audit_event` only.
- `RefundSummary` was missing `requested_by`/`approved_by`/
  `requested_at`/`resolved_at` (`VoidSummary` always had them) — added,
  additive-only, since `refundReject` needed to state explicitly who
  rejected a request and when.

Four stale-request scenarios were also added in pass 4 (later revised to
five in pass 5).

**Pass 5 correction — failed approval is not automatic rejection
(api-design.md §12.1):** on further owner review, pass 4's own citation
was found to be the actual defect. `state-machines.md` §2 explicitly
said a failed execution-time eligibility recheck moves the void to
`REJECTED` ("system-rejected") — that conflates *"this attempt didn't
succeed"* with *"an authorized decision has been made that this request
should never proceed."* **`state-machines.md` §2 has been corrected**
(Stage 2 amendment, disclosed, committed separately): a failed recheck
now leaves the void `REQUESTED`, unchanged, and `voidApprove` returns a
stable `409` instead of a `200` with `status: REJECTED`. This applies to
every execution-time failure mode for both Void and Refund — the
previously-described "asymmetry" between them is retired; they now share
the same failure-handling shape.

**Void's and Refund's stale-fiscal-day rules, made explicit and
distinct:**
- **Void** requires the *originating* sale's own `fiscal_day` to still
  be `OPEN`. Once that specific day Z-closes, this is a **permanent**
  failure (`409 SALE_NOT_VOIDABLE`) — every future approve attempt fails
  identically, resolvable only by an explicit reject. The void is never
  attributed to a later, currently-open fiscal_day merely to make it
  executable.
- **Refund** has no such condition (`state-machines.md` §3: *"Unlike
  Void, Refund has no eligibility condition requiring the original
  sale's fiscal day to still be open"*) — it may legitimately execute
  under a much later fiscal_day, permanently referencing the original
  Sale/SaleItems while its processing context is entirely current.
- Both separately re-check the *executing terminal's own* open
  shift/fiscal_day, which is **transient** for both — opening a shift
  and retrying (with the same Idempotency-Key) resolves it.

Five stale-request scenarios now cover this
([examples/approval-stale-context-scenarios.json](examples/approval-stale-context-scenarios.json)):
(A) no current open shift — transient, `409`, `REQUESTED` unchanged; (B)
Void's originating fiscal_day closed — permanent, `409
SALE_NOT_VOIDABLE`, `REQUESTED` unchanged; (C) Refund against an old sale
approved in a later valid fiscal_day — succeeds; (D) a concurrent refund
consumes the quantity first — `422`, `REQUESTED` unchanged; (E) a refund
completes before a pending void's approval — permanent, `409
SALE_NOT_VOIDABLE`, `REQUESTED` unchanged. None of the five shows an
automatic rejection.

**Idempotency after a failed approval attempt (pass 5):** a `409`/`422`
response commits no fiscal mutation, so no idempotency_record is durably
resolved for that attempt — the same Idempotency-Key may be reused for a
fresh attempt once the underlying condition is corrected (e.g. a shift
is opened). This is a Stage 4 clarification of how ADR-010 applies to
these operations, not a change to ADR-010 itself.

## Invoices (2)

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /invoices/{invoiceId} | invoiceGet | session | false | — | no | — | 200 InvoiceDetail | `INVOICE_NOT_FOUND` |
| POST | /invoices/{invoiceId}/reprints | invoiceReprint | session | true | — (any store staff; V1 does not gate reprint behind a dedicated capability, per the brief's "cashier: invoice reprint when permitted" baseline) | **yes** | — | 201 InvoiceReprintResult | 404 |

**Reprint semantics (see api-design.md §17 for the full BIR crosswalk;
resource shape revised in remediation pass 2):** the response is
`InvoiceReprintResult` — a wrapper (`reprint_event_id`, `invoice`,
`is_reprint: true`, `reprinted_at`, `requested_by`) around the unchanged
`InvoiceDetail` — **not** `InvoiceDetail` itself, because an Invoice may
be reprinted any number of times and `is_reprint`/`reprinted_at` describe
one reprint occurrence, never a property of the immutable Invoice
resource. The wrapped `invoice` preserves the original `invoice_number`,
`sale_id`, and full `invoice_snapshot_json`-derived content, identical to
what `GET /invoices/{invoiceId}` returns; the operation never allocates a
number, never alters `Sale` totals or `invoice_series` state, never
touches a Z-Reading counter.

## Sale slips (2) — ADR-016 slice 2.2a (2026-10-25), forward-committed, no prior draft at any stage

The slip-mode twin of Invoices: the document of a sale finalized in `SLIP` mode, with the same two rules (`saleSlipGet` is always the plain original; `saleSlipReprint` is the only way to print another copy, and it is marked and recorded). No `x-capability`, like the invoice pair. A reprint writes a `SLIP_REPRINTED` audit event (its id is the reprint occurrence) and **no** electronic journal entry (the journal is an invoice-mode artifact). Idempotent per (terminal, key) via the new `SLIP_REPRINT` operation type.

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /sale-slips/{slipId} | saleSlipGet | session | false | — | no | — | 200 SaleSlipDetail | `SLIP_NOT_FOUND` |
| POST | /sale-slips/{slipId}/reprints | saleSlipReprint | session | true | — (any store staff, like invoiceReprint) | **yes** | — | 201 SaleSlipReprintResult | 400, 403, `SLIP_NOT_FOUND` |

**Changed existing operations (additive, ADR-016 slice 3.2, 2026-10-26):** `saleFinalize` accepts one `CREDIT` payment plus a top-level `employee_id` (the employee id from `employeeLookup`); the recorded CREDIT amount is always the server's grand total. `saleRefund` accepts `CREDIT` settlements for, and only for, a sale paid with credit (`CREDIT_REFUND_SETTLEMENT_INVALID` 422 otherwise). `saleVoid` of a credit sale and a completed credit refund write a REVERSAL ledger entry in the ORIGINAL charge's cut-off period. `saleFinalize`, `saleGet`, `saleList`, `saleVoid` and `saleRefund` results carry `employee` ({id, employee_code, name}) on a credit sale and nothing on any other; `saleList` gains `employee_code` and `payment_method=CREDIT`. No new capability.

**Changed existing operations (additive, ADR-016 slice 2.2a):** `saleFinalize`, `saleGet`, `saleList`, `saleVoid` and `saleRefund` results carry `document_mode` (`INVOICE`|`SLIP`) and `document_reference` (the invoice number, or the slip number) on the sale; `SaleDetail` also carries `slip` (null for an invoice sale; `invoice` is null for a slip sale). `saleList` gains the `slip_number` and `document_number` filters (`document_number` matches either kind of number). `storeSetupReadinessGet` and `storeSetupOverviewGet` carry `document_mode`; in `SLIP` mode `fiscal_installation`, `invoice_series` and `tax_registration` read `true` (they do not apply). `fiscalDayClose` and `fiscalDayZReadingGet` carry `document_mode` on the reading (`SLIP`: the internal end-of-day report). `saleVoid`/`saleRefund` of a slip sale write their `VOID`/`REFUND` journal entry as before with `slip_number` in the payload and `invoice_number` null.

## Employees and credit limits (12) — ADR-016 slice 3.1 (2026-10-25), forward-committed, no prior draft at any stage

Tag `Employees`. The people who may be charged on credit (`docs/06-backend/canteen-build-plan.md` 3.1). **`EMPLOYEE_MANAGE` is held by ADMIN and MANAGER** (ADR-016 section 5, amended 2026-10-26) and covers everything here except two ADMIN-only operations, `employeeImport` and `creditPolicySetLimit`, which need **`EMPLOYEE_POLICY_MANAGE`** (a manager gets 403 on them but may read the limits with `creditPolicyList`). A cashier gets 403 on every operation here except `employeeLookup`, which is session-only (any signed-in till user) and returns one employee by exact code (`EMPLOYEE_NOT_FOUND` 404 for an unknown code, `EMPLOYEE_INACTIVE` 409 for a deactivated one) with only id, code, type, name and active. The code is generated by the server and never sent by the client (a body containing `employee_code`, or `employee_type` on an update, is a 422). There is no delete. A change that changes nothing records nothing. Audit events: `EMPLOYEE_CREATED`, `EMPLOYEE_UPDATED`, `EMPLOYEE_DEACTIVATED`, `EMPLOYEE_REACTIVATED`, `EMPLOYEES_IMPORTED`, `CREDIT_LIMIT_CHANGED`.

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /employees | employeeList | session | false | `EMPLOYEE_MANAGE` | no | `search?`, `employee_type?`, `active?`, page | 200 paginated Employee | 403, 422 |
| POST | /employees | employeeCreate | session | false | `EMPLOYEE_MANAGE` | no | EmployeeInput | 201 Employee | 403, 422 |
| POST | /employees/import | employeeImport | session | false | `EMPLOYEE_POLICY_MANAGE` | no | CSV, `dry_run?` | 202 EmployeeImportResult | 403, 422 (`file`) |
| GET | /employees/id-cards | employeeIdCards | session | false | `EMPLOYEE_MANAGE` | no | `search?`, `employee_type?`, `ids?` | 200 EmployeeLookupResult list | 403, 422 |
| GET | /employees/lookup | employeeLookup | session | false | — (any signed-in user) | no | `code` | 200 EmployeeCreditLookupResult (lookup + `credit`: limit, used, remaining, period for the current cut-off, slice 3.2) | `EMPLOYEE_NOT_FOUND` (404), `EMPLOYEE_INACTIVE` (409), 422, `RATE_LIMITED` (429, named limiter `employee-lookup`: 30 a minute per signed-in user by default, `tindaflow.employee_lookup_throttle`; codes are sequential) |
| GET | /employees/{employeeId}/credit-status | employeeCreditStatusGet | session | false | — (any signed-in user) | no | — | 200 EmployeeCreditLookupResult | `EMPLOYEE_NOT_FOUND` (404, also another store's), `EMPLOYEE_INACTIVE` (409), `RATE_LIMITED` (429, same limiter as `employeeLookup`) |
| GET | /employees/{employeeId} | employeeGet | session | false | `EMPLOYEE_MANAGE` | no | — | 200 Employee | 403, `EMPLOYEE_NOT_FOUND` |
| PATCH | /employees/{employeeId} | employeeUpdate | session | false | `EMPLOYEE_MANAGE` | no | names | 200 Employee | 403, `EMPLOYEE_NOT_FOUND`, 422 |
| POST | /employees/{employeeId}/activate | employeeActivate | session | false | `EMPLOYEE_MANAGE` | no | — | 200 Employee | 403, `EMPLOYEE_NOT_FOUND` |
| POST | /employees/{employeeId}/deactivate | employeeDeactivate | session | false | `EMPLOYEE_MANAGE` | no | — | 200 Employee | 403, `EMPLOYEE_NOT_FOUND` |
| GET | /credit-policies | creditPolicyList | session | false | `EMPLOYEE_MANAGE` | no | — | 200 CreditPolicy list | 403 |
| PUT | /credit-policies/{employeeType} | creditPolicySetLimit | session | false | `EMPLOYEE_POLICY_MANAGE` | no | `limit_per_cutoff` | 200 CreditPolicy | 403, 422 |

## Supervisor override of the credit limit (6) — ADR-016 section 6, slice 3.3 (2026-10-26), forward-committed

Tag `CreditOverride`. **`CREDIT_LIMIT_OVERRIDE` is held by MANAGER and ADMIN, never CASHIER.** A cashier whose credit sale is refused as `CREDIT_LIMIT_EXCEEDED` asks a manager: the manager is picked BY NAME from `creditOverrideApproverList` and types their own code on the till; `creditOverrideGrant` verifies it and returns a single-use approval token (shown once, stored only as a SHA-256) bound to the terminal, the cashier, the employee and a ceiling amount, valid 5 minutes. `saleFinalize` then accepts that token as `credit_override_token`; it is consumed only if the charge really exceeds the limit, under the employee lock, in the sale's transaction. The code is never returned, logged or audited. Five wrong codes lock that approver for 15 minutes (committed on their own); the verify endpoint is also limited to 10 requests in 15 minutes per terminal and per signed-in user (`RATE_LIMITED` 429, named limiter `credit-override`); setting or clearing a code is limited to 10 in 15 minutes per user (`override-code`). Audit events: `CREDIT_OVERRIDE_GRANTED`, `CREDIT_OVERRIDE_USED`, `CREDIT_OVERRIDE_REFUSED` (with a `reason`), `CREDIT_OVERRIDE_LOCKED`, `OVERRIDE_CODE_SET`, `OVERRIDE_CODE_RESET`.

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /credit-override-approvers | creditOverrideApproverList | session | false | — (any signed-in user) | no | — | 200 `{data: [{id, name}]}` (active users of the store who hold the capability and have a code, the caller left out; names only) | 401 |
| POST | /credit-overrides | creditOverrideGrant | session | **true** | — (any signed-in user on an enrolled till) | no | `approver_id`, `code` (6 to 8 digits), `employee_id`, `amount` (the sale total) | 201 CreditOverrideGrantResult (`token` once, `grant_id`, `expires_at`, `max_amount`, `approver`) | `CREDIT_OVERRIDE_INVALID` (422), `CREDIT_OVERRIDE_LOCKED` (423), `CREDIT_OVERRIDE_NOT_ALLOWED` (403, approver = cashier), `EMPLOYEE_NOT_FOUND`, `EMPLOYEE_INACTIVE`, `TERMINAL_NOT_ENROLLED`, 422, `RATE_LIMITED` (429) |
| GET | /credit-override-code | creditOverrideCodeGet | session | false | `CREDIT_LIMIT_OVERRIDE` | no | — | 200 OverrideCodeStatus (`has_code`, `locked`, `locked_until`, `set_at`; never the code) | 403 |
| PUT | /credit-override-code | creditOverrideCodeSet | session | false | `CREDIT_LIMIT_OVERRIDE` | no | `password` (the account password, re-entered), `code` | 200 OverrideCodeStatus | 403, 422 (`password` wrong; `code` malformed, trivial, or equal to the password), `RATE_LIMITED` (429) |
| GET | /credit-override-codes | creditOverrideCodeList | session | false | `USER_MANAGE` | no | — | 200 `{data: [{user_id, name, role, active, has_code, locked, locked_until, set_at}]}` (users who could hold a code) | 403 |
| POST | /users/{userId}/override-code/reset | creditOverrideCodeReset | session | false | `USER_MANAGE` | no | `password` (the administrator's own) | 200 OverrideCodeStatus (the code is cleared and any lock lifted; the user sets a new one themselves) | 403, `USER_NOT_FOUND` (404, also another store's), 422 (`password` wrong), `RATE_LIMITED` (429) |

**Changed existing operation (additive, ADR-016 slice 3.3):** `saleFinalize` accepts an optional `credit_override_token` (64 hex characters) on a credit sale; with CASH or any non-credit tender it is `CREDIT_EMPLOYEE_REQUIRED` (422). The token is part of the idempotency hash. New refusals: `CREDIT_OVERRIDE_INVALID` (422), `CREDIT_OVERRIDE_EXPIRED` (409), `CREDIT_OVERRIDE_NOT_ALLOWED` (403). `UserSummary.capabilities` can now include `CREDIT_LIMIT_OVERRIDE`.

## Stock that blocks sales (5) — ADR-017 slice A (2026-10-26)

Tags `Inventory`, `StockRules`, `StockOverride`. A store setting `block_sale_without_stock` (default OFF; with it OFF nothing here changes and stock may go negative as before) makes `saleFinalize` refuse a sale that demands more of a TRACKED product than is on hand (`INSUFFICIENT_STOCK`, 409, every short line listed, the whole sale refused, nothing written, no idempotency result stored; a product with no stock record counts as zero; a meal and rice demand nothing). Void and refund are never blocked. **`STOCK_SALE_OVERRIDE` is held by MANAGER and ADMIN, never CASHIER** and lets a named manager approve ONE blocked sale with their personal override code (the same code, lockout and `RATE_LIMITED` budget as the credit override). The rules are changed by `STORE_SETTINGS_MANAGE` (ADMIN) only. Audit events: `STOCK_RULES_CHANGED`, `STOCK_OVERRIDE_GRANTED`, `STOCK_OVERRIDE_REFUSED`, `STOCK_OVERRIDE_LOCKED`, `STOCK_OVERRIDE_USED` (the record that a sale was oversold).

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /stock/availability | stockAvailabilityGet | session | false | — (any signed-in user) | no | — | 200 StockAvailability (`blocking`, `override_available`, `low_stock_default_threshold`, `items[]` of `{product_id, remaining, threshold, low, sold_out}` for active tracked products; quantities only, no costs) | 401 |
| GET | /stock-rules | stockRulesGet | session | false | `STOCK_ADJUST` (slice B; was `STORE_SETTINGS_MANAGE`) | no | — | 200 StockRules (the three values and `preflight`: `unsellable_count`, `tracked_count`, `sample`) | 403 |
| PUT | /stock-rules | stockRulesSet | session | false | `STOCK_ADJUST` (slice B; was `STORE_SETTINGS_MANAGE`) | no | `block_sale_without_stock`, `stock_override_enabled`, `low_stock_default_threshold` (0 to 1000), `stock_day_cutover_hour` (0 to 6, optional) | 200 StockRules (audited `STOCK_RULES_CHANGED`; a repeat writes nothing) | 403, 422 |
| GET | /stock-override-approvers | stockOverrideApproverList | session | false | — (any signed-in user) | no | — | 200 `{data: [{id, name}]}` (active users of the store who hold `STOCK_SALE_OVERRIDE` and have a code, the caller left out; names only) | 401 |
| POST | /stock-overrides | stockOverrideGrant | session | **true** | — (any signed-in user on an enrolled till) | no | `approver_id`, `code` (6 to 8 digits), `items[]` (`product_id`, `quantity`: the cart) | 201 StockOverrideGrantResult (`token` once, `grant_id`, `expires_at`, `approver`, `shortfalls`) | `STOCK_OVERRIDE_INVALID` (422), `STOCK_OVERRIDE_LOCKED` (423), `STOCK_OVERRIDE_NOT_ALLOWED` (403: approver is the cashier, nothing is short, or overrides are switched off), `PRODUCT_NOT_FOUND`, `TERMINAL_NOT_ENROLLED`, 422, `RATE_LIMITED` (429) |

**Changed existing operations (additive, ADR-017 slice A):** `saleFinalize` accepts an optional `stock_override_token` (64 hex characters; part of the idempotency hash; ignored while blocking is off; consumed only if the sale really is short under the stock row locks) and has the new refusals above. `inventoryStockList` and `inventoryLowStockList` rows gain `low_stock_threshold`. `reorder_level` on `Product` and `ProductInput` becomes nullable (`null` = the store default; the product CSV writes a blank). `UserSummary.capabilities` can now include `STOCK_SALE_OVERRIDE`.

## Daily stock for cooked items (7) — ADR-017 slice B (2026-10-27)

Tags `DailyStock`, `Menu`, `Reports`. Dishes and daily products are counted per STOCK DATE (the store-local date less `stock_day_cutover_hour`; the canteen's day runs 05:00 to 05:00). **`STOCK_ADJUST` (MANAGER, ADMIN) enters directly; a CASHIER enters only with a manager's single-use code approval.** Cooked food is never put back: a void or refund of a line that drew from daily stock writes no stock movement and records `DISPOSED`. The stock rules (`stockRulesGet/Set`) are `STOCK_ADJUST` since this slice. Audit events: `DAILY_STOCK_ENTERED` (entered_by, approved_by, every line), `STOCK_ENTRY_APPROVED`, `STOCK_ENTRY_APPROVAL_REFUSED`, `STOCK_ENTRY_APPROVAL_LOCKED`, `ALA_CARTE_ITEMS_CREATED`.

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /daily-stock | dailyStockGet | session | false | — (any signed-in user) | no | — | 200 DailyStockGrid (`stock_date`, `can_enter_directly`, `rows[]` of `{kind, id, name, group, cooked, sold, remaining, yesterday_cooked}`) | 401 |
| GET | /daily-stock/approvers | dailyStockApproverList | session | false | — (any signed-in user) | no | — | 200 `{data: [{id, name}]}` (managers who hold `STOCK_ADJUST` and have a code, the caller left out) | 401 |
| POST | /daily-stock/approvals | dailyStockApprovalCreate | session | **true** | — (any signed-in user on an enrolled till) | no | `approver_id`, `code` (6 to 8 digits) | 201 `{grant_id, token (once), stock_date, expires_at, approver}` | `STOCK_ENTRY_APPROVAL_INVALID` (422), `_LOCKED` (423), `_NOT_ALLOWED` (403), `TERMINAL_NOT_ENROLLED`, `RATE_LIMITED` (429) |
| POST | /daily-stock/entries | dailyStockEntryCreate | session | **true** | `STOCK_ADJUST`, or a cashier with `stock_entry_token` | **yes** | `lines[]` (`dish_id` or `product_id`, and `cooked` or `add`), `reason` (when lowering), `stock_entry_token` | 201 DailyStockEntryResult (the lines as recorded and the refreshed grid) | `DAILY_STOCK_BELOW_SOLD` (422), `STOCK_ENTRY_APPROVAL_REQUIRED` (403), `_INVALID` (422), `_EXPIRED` (409), `_NOT_ALLOWED` (403), `IDEMPOTENCY_KEY_REQUIRED`, `TERMINAL_NOT_ENROLLED`, 422 |
| POST | /dishes/ala-carte | dishAlaCarteCreate | session | false | `CATALOG_MANAGE` | no (idempotent by design: a dish that has an item is skipped) | `menu_group_id`, `stock_portion` (default 2), `category_prices{}`, `items[]` (`dish_id`, `price`, `name`) | 201 `{created[], skipped[]}` | 403, 422 (a missing price) |
| GET | /reports/daily-stock | reportDailyStock | session | false | `REPORT_VIEW` | no | `from`, `to` (stock dates) | 200 ReportResult / CSV (`stock_date, item_type, item_name, category, cooked, removed, sold, returned, leftover, oversold`) | 403 |
| GET | /stock/availability | stockAvailabilityGet | session | false | — | no | — | **extended:** `stock_date`, `daily_configured`, `daily_entered`, `items[]` with `mode`, `dish_id`, `stock_portion`, and `dishes[]` of `{dish_id, name, remaining, sold_out}` | 401 |

**Changed existing operations (additive, ADR-017 slice B):** `productCreate/Update` accept `stock_mode` (`NONE|RUNNING|DAILY`), `dish_id`, `stock_portion` (refused with `STOCK_MODE_CHANGE_BLOCKED` while the product holds stock); `dishCreate/Update` accept `stock_mode` (`NONE|DAILY`, same refusal); `mealOptionUpdate/Create` accept `stock_portion` (a dish option only); `saleFinalize` draws from the dish pools as above and `stockOverrideGrant` accepts meal items; `INSUFFICIENT_STOCK` lines may be `kind: DISH`; `inventoryMovementList` rows gain `stock_date` and `approved_by` and the two new movement types; `refund` lines of cooked food are recorded `DISPOSED`; the running Stock, Low Stock and Inventory On Hand lists and reports and the blocking pre-flight list RUNNING products only; receipts, adjustments, counts and transfers refuse a DAILY product (422).

## Billing (4) — ADR-016 section 8, slice 4.1 (2026-10-26), forward-committed

Tag `Billing`. **`BILLING_MANAGE` is held by MANAGER and ADMIN, never CASHIER** and gates everything here, reads and CSV exports included. The statement is a READ over the credit ledger and the sale snapshots: `billingStatementGet` recomputes it on every call for a named cut-off (`cutoff_month` + `cutoff_half`) or any `from`/`to` (Manila calendar dates, inclusive, at most 366 days), optionally for one `employee_type` or one `employee_id`, "as of" any instant (`as_of`, default now). Account (employee) -> Meal type -> Item/dish, with returned quantities and amounts and flagged adjustments (voids and refunds count in the range of the ORIGINAL sale). The total must equal the ledger's CHARGE minus REVERSAL or the call fails with `BILLING_RECONCILIATION_FAILED` (500) and nothing is shown or saved. `billingRunCreate` saves the statement as the next numbered, append-only run (`BR-000001`, gap-free per store); it has no `Idempotency-Key` (a back-office action has no terminal; every call is meant to create a new run). `Accept: text/csv` on `billingStatementGet` and `billingRunGet` returns the CSV (`csv-export-contract.md`). Audit events: `BILLING_RUN_CREATED`, `BILLING_EXPORTED`.

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /billing/statement | billingStatementGet | session | false | `BILLING_MANAGE` | no | `cutoff_month`+`cutoff_half` or `from`+`to`; `employee_type?`, `employee_id?`, `as_of?` | 200 `{data: BillingStatement}` (or CSV) | 403, 422, `BILLING_RECONCILIATION_FAILED` (500) |
| GET | /billing-runs | billingRunList | session | false | `BILLING_MANAGE` | no | `page`, `per_page` | 200 Paginated BillingRun (no statement) | 403 |
| POST | /billing-runs | billingRunCreate | session | false | `BILLING_MANAGE` | no | as billingStatementGet, in the body | 201 `{data: BillingRunWithStatement}` | 403, 422, `BILLING_RECONCILIATION_FAILED` (500) |
| GET | /billing-runs/{runId} | billingRunGet | session | false | `BILLING_MANAGE` | no | — | 200 `{data: BillingRunWithStatement}` (`statement_intact`) or CSV | 403, `BILLING_RUN_NOT_FOUND` (404, also another store's) |
| GET | /billing/balance | billingBalanceGet | session | false | `BILLING_MANAGE` | no | `as_of?` | 200 `{data: BillingBalance}` (or CSV) | 403, 422 |
| GET | /billing-cheques | billingChequeList | session | false | `BILLING_MANAGE` | no | `status?`, `page`, `per_page` | 200 Paginated BillingCheque (or CSV) | 403, 422 |
| POST | /billing-cheques | billingChequeRecord | session | false | `BILLING_MANAGE` | no (see below) | `cheque_number`, `bank`, `cheque_date`, `amount`, `received_on`, `notes?`, `allocations[{period_start, amount}]` | 201 `{data: BillingCheque}` | 403, 422, `CHEQUE_DUPLICATE` (409), `CHEQUE_ALLOCATION_EXCEEDS_OWED` (409) |
| GET | /billing-cheques/{chequeId} | billingChequeGet | session | false | `BILLING_MANAGE` | no | — | 200 `{data: BillingCheque}` | 403, `CHEQUE_NOT_FOUND` (404, also another store's) |
| POST | /billing-cheques/{chequeId}/return | billingChequeReturn | session | false | `BILLING_MANAGE` | no (see below) | `returned_on`, `reason` | 200 `{data: BillingCheque}` | 403, 422, `CHEQUE_NOT_FOUND` (404), `CHEQUE_ALREADY_RETURNED` (409) |
| GET | /billing-write-offs | billingWriteOffList | session | false | `BILLING_MANAGE` | no | `page`, `per_page` | 200 Paginated BillingWriteOff (both kinds, newest first) | 403, 422 |
| POST | /billing-write-offs | billingWriteOffRecord | session | false | `BILLING_MANAGE` | optional `request_id` (see below) | `period_start`, `amount`, `reason`, `cheque_id?`, `request_id?` | 201 `{data: BillingWriteOff}` (200 on a repeated `request_id`) | 403, 422, `WRITE_OFF_EXCEEDS_OWED`, `WRITE_OFF_CHEQUE_INVALID`, `WRITE_OFF_REQUEST_REUSED` (409) |
| POST | /billing-write-offs/{writeOffId}/reverse | billingWriteOffReverse | session | false | `BILLING_MANAGE` | no (see below) | `reason` | 201 `{data: BillingWriteOff}` (the REVERSAL row) | 403, 422, `WRITE_OFF_NOT_FOUND` (404), `WRITE_OFF_ALREADY_REVERSED`, `WRITE_OFF_NOT_REVERSIBLE` (409) |

**Cheques against what is owed (slice 4.2, 2026-10-26).** The owed amount per cut-off period is DERIVED: billed (the credit ledger's CHARGE minus REVERSAL for the period, the figure the 4.1 statement reconciles with) minus the allocations of cheques that are not RETURNED. A cheque is allocated in full to one or more real cut-off periods (the 1st or the 16th is the `period_start`), each at most what the period currently owes, checked under a lock on the billing party; it is never a cash movement and never touches a shift. A period paid more than it now owes (a refund arrived after the cheque) is `OVERPAID` with a negative owed amount, shown as a credit. **No `Idempotency-Key` on the two cheque POSTs** (deviation from the plan's "two operation types added to `IdempotencyOperationType`"): the idempotency records are keyed by terminal and a back-office action has none; the unique RECEIVED bank + number (`CHEQUE_DUPLICATE`) and the one-way RECEIVED -> RETURNED (`CHEQUE_ALREADY_RETURNED`) refuse a repeat with a clear 409, which is a stronger guard than replaying a stored response. Audit events: `BILLING_CHEQUE_RECORDED`, `BILLING_CHEQUE_RETURNED`; the two CSV exports write `BILLING_EXPORTED` with `report` = `balance` | `cheques`.

**Write off an unpaid amount (slice 4.3, 2026-10-26).** A write-off is bad debt, NOT money received: it takes no cash, makes no payment, and changes no billing statement; it lowers the derived owed amount of one cut-off period (`owed = billed - received - net written off`), never below zero (`WRITE_OFF_EXCEEDS_OWED`), checked under the same billing-party lock a cheque takes. `billing_write_offs` is append-only: a mistake is cancelled by a REVERSAL row (once; a reversal cannot be reversed). A reason is required on both. The balance gains `written_off_amount`, `credit_amount` and `write_off_excess_amount` per period and `written_off_amount` / `write_off_excess_amount` in totals, and the statuses `WRITTEN_OFF` and `OVER_WRITTEN_OFF`. **Idempotency:** no `Idempotency-Key` (no terminal); the write-off POST takes an optional client `request_id` (a repeat with the same content returns the original, HTTP 200); the reversal is refused the second time by a unique row. Audit events: `BILLING_WRITE_OFF_RECORDED`, `BILLING_WRITE_OFF_REVERSED`. The balance CSV gains two appended columns.

## Shifts (8)

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /shifts/current | shiftCurrentGet | session | true | — | no | — | 200 Shift | `SHIFT_NOT_OPEN` |
| POST | /shifts/open | shiftOpen | session | true | — | **yes** | opening_cash | 201 ShiftOpenResult | `SHIFT_ALREADY_OPEN` |
| GET | /shifts/{shiftId} | shiftGet | session | false | — | no | — | 200 Shift | 404 — **not implemented this pass** (pure historical browsing; see FiscalDay note below) |
| GET | /shifts | shiftList | session | false | — | no | filters | 200 paginated | 401 — **not implemented this pass** |
| POST | /shifts/{shiftId}/cash-movements | shiftCashMovementCreate | session | true | `CASH_OUT` (for CASH_OUT above threshold) | **yes** | type/amount/reason | 201 CashMovement | `SHIFT_NOT_FOUND`, `SHIFT_NOT_OPEN`, 403 |
| GET | /shifts/{shiftId}/x-readings | shiftXReadingList | session | false | — | no | — | 200 array | `SHIFT_NOT_FOUND` |
| POST | /shifts/{shiftId}/x-readings | shiftXReadingCreate | session | true | — | no | — | 201 XReading | `SHIFT_NOT_FOUND` (no 409 exists in the frozen contract for this operation, so a CLOSED shift is still readable, never rejected) |
| POST | /shifts/{shiftId}/close | shiftClose | session | true | — | **yes** | declared_cash | 200 ShiftCloseResult | `SHIFT_NOT_FOUND`, `SHIFT_ALREADY_CLOSED` |

**Implementation status (2026-09-19, shift-close module)**: `shiftClose`/`shiftCashMovementCreate`/`shiftXReadingList`/`shiftXReadingCreate` are now implemented, closing the lifecycle `shiftOpen`/`shiftCurrentGet` started. `shiftGet`/`shiftList` remain unimplemented — pure historical browsing with no bearing on the operational open→close flow, deferred to a future Reports module. `SHIFT_NOT_FOUND` (404, sixth baseline reconstruction) replaces the generic `NotFound` these operations' already-frozen responses previously carried.

### X-Reading BIR crosswalk (RMO 24-2023 — Cashier's Accountability / End-of-Shift Report)

| RMO 24-2023 concept | Schema field | Notes |
|---|---|---|
| Shift | `XReading.shift_id` | — |
| Cashier | `XReading.cashier_id` | — |
| Terminal | `XReading.terminal_id` | — |
| Reading timestamp / as-of point | `XReading.generated_at`, `from_at`/`to_at` | Range covered, not just an instant |
| Accountability totals (opening/expected cash) | `XReadingTotalsSnapshot.opening_cash`, `.expected_cash`, `.variance` (closing reading only — null on an interim pull) | — |
| Sales/payment figures | `XReadingTotalsSnapshot.cash_sales`, `.non_cash_sales`, `.payment_breakdown` | Keyed by `PaymentMethod` |
| Adjustment figures | `XReadingTotalsSnapshot.refunds_total`, `.cash_in_total`, `.cash_out_total` | — |
| Multiple readings per shift ("as required by management") | `Shift 1--0..N XReading` (Stage 2 invariant #43, erd.md `SHIFT ||--o{ X_READING`) | Interim pulls never close/reset the shift; `is_closing_reading` distinguishes the automatic end-of-shift one |
| Not reused for Z-Reading semantics | `XReadingTotalsSnapshot` is a distinct schema from `ZReadingTotalsSnapshot` — no accumulated-grand-total, tax-category, or counter fields on X | Confirmed by schema inspection, not just convention |

## FiscalDay (5)

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /fiscal-days/current | fiscalDayCurrentGet | session | true | — | no | — | 200 FiscalDay | `FISCAL_DAY_NOT_OPEN` — **not implemented this pass** |
| GET | /fiscal-days | fiscalDayList | session | false | — | no | filters | 200 paginated | 401 — **not implemented this pass** |
| GET | /fiscal-days/{fiscalDayId} | fiscalDayGet | session | false | — | no | — | 200 FiscalDay | 404 — **not implemented this pass** |
| GET | /fiscal-days/{fiscalDayId}/z-reading | fiscalDayZReadingGet | session | false | — | no | — | 200 ZReading | `FISCAL_DAY_NOT_FOUND` (non-enumerating: doesn't exist, wrong terminal, or not closed yet) |
| POST | /fiscal-days/{fiscalDayId}/z-reading/prints | fiscalDayZReadingPrint | session | false | `REPORT_VIEW` | no | — | 201 `{print_event_id, render_html, is_reprint, printed_at, requested_by}` (first print = original, later = marked copy) | 401, 403, 404 `FISCAL_DAY_NOT_FOUND`, 429 |
| POST | /fiscal-days/{fiscalDayId}/close | fiscalDayClose | session | true | `FISCAL_DAY_CLOSE` | **yes** | — | 200 FiscalDayCloseResult | `FISCAL_DAY_NOT_FOUND`, `FISCAL_DAY_HAS_OPEN_SHIFT`, `FISCAL_DAY_CLOSED` |

**Implementation status (2026-09-19, shift-close module)**: `fiscalDayClose`/`fiscalDayZReadingGet` are now implemented. `fiscalDayCurrentGet`/`fiscalDayList`/`fiscalDayGet` remain unimplemented this pass -- deliberately: `fiscalDayCurrentGet`'s own frozen `404` is descriptively labeled `FISCAL_DAY_NOT_OPEN`, the same already-frozen-and-mislabeled shape as `shiftCurrentGet` before it (see the `NO_CURRENT_SHIFT` entry in error-catalog.md), so implementing it now would require minting yet another new code via the reconstruction procedure; the frontend instead obtains the `fiscal_day_id` it needs to close from the shift lifecycle it already holds in state (`shiftOpen`/`shiftCurrentGet` both return it inline), so this is not required to reach a working close flow. `fiscalDayGet`/`fiscalDayList` are pure historical browsing, deferred to a future Reports module alongside `shiftGet`/`shiftList`. `FISCAL_DAY_NOT_FOUND` (404, sixth baseline reconstruction) backs the two operations built this pass.

### Z-Reading BIR crosswalk (RMO 24-2023 — End-of-Day Report)

| RMO 24-2023 concept | Schema field | Notes |
|---|---|---|
| FiscalDay / business date | `ZReading.fiscal_day_id`, `.business_date` | — |
| Terminal | `ZReading.terminal_id` | — |
| Generation timestamp | `ZReading.generated_at`, `from_at`/`to_at` | — |
| Previous accumulated grand total sales | `ZReadingTotalsSnapshot.accumulated_grand_total_sales_before` | `AccumulatedMoney` schema, not `Money` — see §12 below |
| Present/current accumulated grand total sales | `ZReadingTotalsSnapshot.accumulated_grand_total_sales_after` | `= before + gross_sales`, exactly |
| VATable Sales | `ZReadingTotalsSnapshot.taxable_sales` | — |
| VAT-Exempt Sales | `ZReadingTotalsSnapshot.vat_exempt_sales` | — |
| Zero-Rated Sales | `ZReadingTotalsSnapshot.zero_rated_sales` | — |
| VAT Amount | `ZReadingTotalsSnapshot.vat_amount` | — |
| Non-VAT Sales | `ZReadingTotalsSnapshot.non_vat_sales` | Added 2026-09-17 (Stage 6A NON_VAT gap, domain-model.md 4a) — not an RMO 24-2023 line item by that name; TindaFlow reports it here to keep Non-VAT sales distinct from VAT-Exempt Sales rather than folding one into the other. `0.00` for a VAT-registered store's fiscal day; equals `gross_sales` for a NON_VAT-registered store's fiscal day. |
| Payment-mode breakdown | `ZReadingTotalsSnapshot.payment_breakdown` | Keyed by `PaymentMethod` |
| Z Counter | `ZReadingTotalsSnapshot.z_counter` | Per-terminal running sequence, ever-incrementing |
| Reset Counter | `ZReadingTotalsSnapshot.reset_counter` | Present for accreditation-demonstration completeness; expected to remain `0` — TindaFlow has no user-facing reset function |
| Sales-adjustment summaries (voids/refunds) | `ZReadingTotalsSnapshot.void_total`, `.refund_total` | Voided sales excluded from `gross_sales`, reported separately for audit transparency |
| Exactly one per FiscalDay | `FiscalDay 1--0..1 ZReading` (Stage 2 invariant #42, erd.md `FISCAL_DAY ||--o| Z_READING`) | Created atomically with the `OPEN→CLOSED` transition |

## Reports (20) — all `REPORT_VIEW`

`reportSalesByHour` and `reportGrossProfit` are **wholly new operations forward-committed in stage 32
(2026-09-25)**, no prior draft at any stage — same shape and error surface as the other 15 (no
domain-specific failure mode, only 401/403), so no reconstruction was needed. See
`docs/06-backend/stage-32-hourly-and-gross-profit-reports.md`. `reportGrossProfitByProduct` and
`reportProductVelocity` are the same, forward-committed in **stage 34 (2026-09-26)** — see
`docs/06-backend/stage-34-product-velocity-and-profit-by-product.md`. `reportGrossProfitByCategory`
closes the same family in **stage 35 (2026-09-26)** — see
`docs/06-backend/stage-35-gross-profit-by-category.md`.

| Method | Path | operationId |
|---|---|---|
| GET | /reports/daily-sales-summary | reportDailySalesSummary |
| GET | /reports/sales-by-date-range | reportSalesByDateRange |
| GET | /reports/sales-by-product | reportSalesByProduct |
| GET | /reports/sales-by-category | reportSalesByCategory |
| GET | /reports/sales-by-cashier | reportSalesByCashier |
| GET | /reports/sales-by-payment-method | reportSalesByPaymentMethod |
| GET | /reports/tax-breakdown | reportTaxBreakdown |
| GET | /reports/voids | reportVoids |
| GET | /reports/refunds | reportRefunds |
| GET | /reports/discounts | reportDiscounts |
| GET | /reports/sales-by-hour | reportSalesByHour |
| GET | /reports/gross-profit | reportGrossProfit |
| GET | /reports/gross-profit-by-product | reportGrossProfitByProduct |
| GET | /reports/product-velocity | reportProductVelocity |
| GET | /reports/gross-profit-by-category | reportGrossProfitByCategory |
| GET | /reports/inventory-on-hand | reportInventoryOnHand |
| GET | /reports/low-stock | reportLowStock |
| GET | /reports/inventory-movement | reportInventoryMovement |
| GET | /reports/shifts | reportShifts |
| GET | /reports/cash-variance | reportCashVariance |

All 20: session auth, no terminal enrollment required, `REPORT_VIEW`
capability, no idempotency (read-only), `Accept: application/json|text/csv`
content negotiation, success = 200 `ReportResult` or CSV, errors = 401/403
only (no domain-specific failure modes — reports never fail on business
state, only on auth). **CSV column layout for each report (and for the
Electronic Journal's CSV export) is pinned exactly in
[csv-export-contract.md](csv-export-contract.md)** — closed during this
remediation pass rather than left to Stage 5/6 to invent independently.

## StoreSettings (4)

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /store-settings | storeSettingsGet | session | false | — | no | — | 200 StoreSettings | 401 |
| PATCH | /store-settings | storeSettingsUpdate | session | false | `STORE_SETTINGS_MANAGE` | no | StoreSettingsInput | 200 StoreSettings | 403, 422 |
| GET | /tax-registrations | taxRegistrationList | session | false | — | no | — | 200 array | 401 |
| POST | /tax-registrations | taxRegistrationCreate | session | false | `FISCAL_CONFIGURATION_MANAGE` | no | type+effective_from | 201 TaxRegistration | 403, 422 |

## SaleDocumentSettings (3) — ADR-016 slice 2.2a (2026-10-25), forward-committed, no prior draft at any stage

How the store documents a sale. The mode switch is the ADR-014 vendor unlock; the footer is ordinary store configuration. The settings live beside `StoreSettings` but in their own resource so the existing `StoreSettings` response is unchanged.

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /sale-document-settings | saleDocumentSettingsGet | session | false | — | no | — | 200 SaleDocumentSettings | 401 |
| PUT | /sale-document-settings/mode | saleDocumentModeSet | session | false | `FISCAL_CONFIGURATION_MANAGE` | no (naturally idempotent: the same mode again changes nothing) | SaleDocumentModeInput | 200 SaleDocumentSettings | 403, 409 `SALE_DOCUMENT_MODE_CHANGE_BLOCKED`, 422 |
| PUT | /sale-document-settings/slip-footer | slipFooterSet | session | false | `STORE_SETTINGS_MANAGE` | no (naturally idempotent) | SlipFooterInput | 200 SaleDocumentSettings | 403, 422 |

## FiscalInstallation (3)

**HTTP shape (see api-design.md §16 for the full crosswalk):** `Accreditation`
and `PermitToUse` are distinct nested objects on `FiscalInstallation`
(`accreditation: {...}`, `permit_to_use: {...}`), never collapsed into one
generic `compliance_status` field — matching ADR-009's independent-
lifecycles decision. The database realization (two column-pairs vs. two
child tables) remains a Stage 5 decision this HTTP shape does not
prejudge.

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /fiscal-installations | fiscalInstallationList | session | false | `STORE_SETTINGS_MANAGE` | no | — | 200 array | 401, 403 |
| POST | /fiscal-installations | fiscalInstallationCreate | session | false | `FISCAL_CONFIGURATION_MANAGE` | no | FiscalInstallationInput (`accreditation`/`permit_to_use` objects) | 201 FiscalInstallation | 403, 422 |
| POST | /fiscal-installations/{fiscalInstallationId}/terminals | fiscalInstallationAssignTerminal | session | false | `FISCAL_CONFIGURATION_MANAGE` | no | terminal_id+effective_from? | 201 TerminalFiscalInstallation | 403, 404, 422, 409 |

`fiscalInstallationGet` (declared above the table this section documents) remains **unimplemented** — the store-setup pass (2026-09-18) implemented `List`/`Create`/`AssignTerminal` only; the admin screens built against this operation-inventory entry read full `FiscalInstallation` objects from the list response inline, so a separate get-by-id fetch was never needed. Implementing it later would complete a gap in an *already-frozen* `404` response (`fiscalInstallationGet`'s response already existed before this pass), which this project's frozen-corpus discipline treats as a Stage 4 content change requiring the full reconstruction procedure — distinct from `AssignTerminal` above, a wholly new operation forward-committed without one.

## InvoiceSeries (3) — store-setup pass (2026-09-18), no prior draft at any stage

The table `InvoiceSeriesAllocator::allocateForFiscalInstallation` reads at checkout time. `invoice_series_one_active_per_installation` allows at most one ACTIVE row per fiscal installation — `Create` conflicts (409) rather than auto-superseding; `Close` is the only way to retire the current one first.

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /invoice-series | invoiceSeriesList | session | false | `STORE_SETTINGS_MANAGE` | no | fiscal_installation_id? (query) | 200 paginated array | 401, 403 |
| POST | /invoice-series | invoiceSeriesCreate | session | false | `FISCAL_CONFIGURATION_MANAGE` | no | InvoiceSeriesInput | 201 InvoiceSeries | 403, 409 `INVOICE_SERIES_ALREADY_ACTIVE`, 422 |
| POST | /invoice-series/{invoiceSeriesId}/close | invoiceSeriesClose | session | false | `FISCAL_CONFIGURATION_MANAGE` | no | — | 200 InvoiceSeries | 403, 404, 409 `INVOICE_SERIES_ALREADY_CLOSED` |

## InventoryLocation (3) — store-setup pass (2026-09-18), tagged `Inventory` (existing tag), no prior draft at any stage

**ADR-014 (2026-10-03):** creating and editing a location now needs `STORE_SETTINGS_MANAGE` (a shop's own stock locations are not part of the locked fiscal configuration); `fiscalInstallationList` and `invoiceSeriesList` stay administrator-readable (`STORE_SETTINGS_MANAGE`), no longer `FISCAL_CONFIGURATION_MANAGE`.

**Loosened 2026-09-20 (stage 25):** `inventoryLocationList` is now session-only, no capability (it was `FISCAL_CONFIGURATION_MANAGE`). A location is just a name, and a MANAGER (`STOCK_ADJUST` without `FISCAL_CONFIGURATION_MANAGE`) needs the list for the stock, count and transfer screens; before this the Stock page could not show a manager where stock was. Create and update are unchanged.

The table `InventoryLocationResolver::resolveDefaultForStore` reads at checkout time. A store's first location is always made the default (regardless of the submitted `is_default`), so a fresh store never needs a second call just to pass the checkout-time check. `Update`'s `is_default` may only be submitted as `true` — a location is promoted (transactionally demoting the current default), never explicitly demoted on its own, so this endpoint can never leave a store with zero defaults.

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /inventory-locations | inventoryLocationList | session | false | — | no | — | 200 paginated array | 401 |
| POST | /inventory-locations | inventoryLocationCreate | session | false | `STORE_SETTINGS_MANAGE` | no | InventoryLocationInput | 201 InventoryLocation | 403, 422 |
| PATCH | /inventory-locations/{inventoryLocationId} | inventoryLocationUpdate | session | false | `STORE_SETTINGS_MANAGE` | no | name?+is_default? (true only) | 200 InventoryLocation | 403, 404, 422 |

## StoreSetup (1) — store-setup pass (2026-09-18), no prior draft at any stage

Read-only, non-authoritative aggregate across the four checkout-time resolver prerequisites (`FiscalInstallationResolver`, `InvoiceSeriesAllocator`, `InventoryLocationResolver`, `TaxRegistrationResolver`) — independent re-implementations of each resolver's own lookup, not a call into `app/Services/Checkout/*` (frozen under the Stage 6C addendum). Terminal-scoped like `shiftCurrentGet`, deliberately **no x-capability** — a cashier on an enrolled terminal needs to see the blocked state too, not just an admin.

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /store-setup/readiness | storeSetupReadinessGet | session+terminal | true | — | no | — | 200 StoreSetupReadiness | 401, 403 |
| GET | /store-setup/overview | storeSetupOverviewGet | session | false | `STORE_SETTINGS_MANAGE` | no | — | 200 StoreSetupOverview | 401, 403 |

## Audit (2) — read-only

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /audit-events | auditEventList | session | false | `AUDIT_VIEW` | no | filters | 200 paginated | 403 |
| GET | /audit-events/{auditEventId} | auditEventGet | session | false | `AUDIT_VIEW` | no | — | 200 AuditEvent | 403, 404 |

## ElectronicJournal (2) — read-only

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /electronic-journal-entries | journalEntryList | session | false | `JOURNAL_VIEW` | no | filters | 200 paginated / CSV | 403 |
| GET | /electronic-journal-entries/{journalEntryId} | journalEntryGet | session | false | `JOURNAL_VIEW` | no | — | 200 entry | 403, 404 |

## Users (6)

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /users | userList | session | false | `USER_MANAGE` | no | filters | 200 paginated | 403 |
| POST | /users | userCreate | session | false | `USER_MANAGE` | no | UserInput | 201 UserSummary | 403, 422 |
| GET | /users/{userId} | userGet | session | false | `USER_MANAGE` | no | — | 200 UserSummary | 403, `USER_NOT_FOUND` |
| PATCH | /users/{userId} | userUpdate | session | false | `USER_MANAGE` | no | UserInput | 200 UserSummary | 403, 422, `USER_NOT_FOUND` |
| POST | /users/{userId}/deactivate | userDeactivate | session | false | `USER_MANAGE` | no | — | 200 UserSummary | 403, `USER_NOT_FOUND` |
| POST | /users/{userId}/activate | userActivate | session | false | `USER_MANAGE` | no | — | 200 UserSummary | 403, `USER_NOT_FOUND` |

`userActivate` is a **wholly new operation** forward-committed on 2026-09-19 (no prior draft at any stage; owner-approved when the gap was raised): `userDeactivate` had no inverse, so a deactivation was permanent. The same pass registered `USER_NOT_FOUND` for the four `404`s above; three of those (`userGet`/`userUpdate`/`userDeactivate`) already existed, so under the strict rule this was reconstruction territory — the owner approved forward-committing it instead, as a recorded exception (see `error-catalog.md`'s governance note and `docs/PROJECT-MANIFEST.md`'s Stage 13 section).

## Idempotency inventory (cross-checked programmatically against openapi.yaml — item 10)

Exactly **14** operations require `Idempotency-Key` (10 through pass 2;
**+4 in pass 3**) — regenerated directly from the spec (not counted by
hand):

| operationId | Method | Path |
|---|---|---|
| `saleFinalize` | POST | /sales |
| `saleVoid` | POST | /sales/{saleId}/void |
| `saleRefund` | POST | /sales/{saleId}/refunds |
| `invoiceReprint` | POST | /invoices/{invoiceId}/reprints |
| `saleSlipReprint` | POST | /sale-slips/{slipId}/reprints |
| `shiftOpen` | POST | /shifts/open |
| `shiftCashMovementCreate` | POST | /shifts/{shiftId}/cash-movements |
| `shiftClose` | POST | /shifts/{shiftId}/close |
| `inventoryReceiptCreate` | POST | /inventory/receipts |
| `inventoryAdjustmentCreate` | POST | /inventory/adjustments |
| `fiscalDayClose` | POST | /fiscal-days/{fiscalDayId}/close |
| `voidApprove` | POST | /voids/{voidId}/approve |
| `voidReject` | POST | /voids/{voidId}/reject |
| `refundApprove` | POST | /refunds/{refundId}/approve |
| `refundReject` | POST | /refunds/{refundId}/reject |

**+2 in stage 25 (2026-09-20), so 16 in total:** `stockCountPost` (POST /inventory/counts/{stockCountId}/post) and `stockTransferCreate` (POST /inventory/transfers). The `idempotency_records_operation_type_check` constraint and `IdempotencyOperationType` were extended to match (`STOCK_COUNT_POST`, `STOCK_TRANSFER`); the 14 original values are unchanged.

**+1 in ADR-016 slice 2.2a, so 17 in total:** `saleSlipReprint`. The constraint and `IdempotencyOperationType` gained `SLIP_REPRINT`; every earlier value is unchanged.

For each, all four required properties hold (verified against openapi.yaml
directly, not asserted narratively): `Idempotency-Key` required (parameter
present via `$ref` to the shared `IdempotencyKeyHeader` component,
`required: true`); terminal-scoped identity (ADR-010 — `terminal_id`
resolved server-side, never a request field on any of the 14); request-
hash conflict semantics (`409`/`IDEMPOTENCY_KEY_REUSED` documented
identically for all 14 via the shared `Conflict` response component);
original-result replay semantics (same behavior description in each
operation, inherited from ADR-010's single canonical definition rather
than restated ad hoc per operation). **`invoiceReprint` is confirmed
idempotent** — included in the 10 from the original draft, not added by
this remediation; its idempotency prevents a network retry from creating
a duplicate `INVOICE_REPRINTED` audit/journal event. Concretely, a replay
with the same `Idempotency-Key` returns the **same** `reprint_event_id`
(not a newly-minted one) — the original `InvoiceReprintResult`, byte-for-
byte, per ADR-010's original-result-replay guarantee. **The four pass-3
additions need it for the same reason**: a network-dropped
approve/reject response must never risk a second execution (a duplicate
`SALE_VOIDED`/refund completion) or a duplicate rejection audit event on
retry.

## Enum reconciliation table

Every enum exposed by the API is checked against its Stage 2 source —
none is invented or renamed for API convenience.

| API enum (`components/schemas`) | Domain source | Values |
|---|---|---|
| `SaleStatus` | Stage 2 `sale.status` (domain-model.md §2.7) | `COMPLETED`, `VOIDED`, `PARTIALLY_REFUNDED`, `REFUNDED` — no `DRAFT`, matching Stage 2's explicit removal |
| `RefundDisposition` | Stage 2 `refund_item.disposition` (§2.9) | `RETURN_TO_STOCK`, `DAMAGED`, `EXPIRED`, `DISPOSED` |
| `StockMovementType` | Stage 2 `stock_movement.movement_type` (§2.4) | `OPENING_STOCK`, `PURCHASE_RECEIPT`, `SALE`, `SALE_RETURN`, `STOCK_ADJUSTMENT_IN`, `STOCK_ADJUSTMENT_OUT`, `DAMAGE`, `EXPIRED`, `TRANSFER_IN`, `TRANSFER_OUT` |
| `PaymentMethod` | Stage 2 `payment.method` / `refund_settlement.payment_method` (§2.7/§2.9) | `CASH`, `GCASH`, `MAYA`, `CARD`, `OTHER`, `CREDIT` (ADR-016 slice 3.2; `BANK_TRANSFER` is slice 2.1, not built) |
| `TaxClassification` | Stage 2 `product.tax_class` / `sale_item.tax_classification_snapshot` (§2.3/§2.7) | `VATABLE`, `VAT_EXEMPT`, `ZERO_RATED`, `NON_VAT` |
| `TaxRegistrationType` | Stage 2 `tax_registration.registration_type` (§2.1) | `VAT`, `NON_VAT` |
| `TerminalStatus` | Stage 2 `terminal.status` (§2.1) | `ACTIVE`, `INACTIVE`, `DECOMMISSIONED` |
| `UserRole` | Stage 2 `user.role` (§2.2) | `ADMIN`, `MANAGER`, `CASHIER` |
| `Capability` | Stage 2 capability catalog (§2.2) — full 17-value catalog, including 6 additions closed in pass 1 and **synced back into `domain-model.md` §2.2 itself in pass 2**, so this enum and its domain source are now identical, not just cross-referenced | `SALE_VOID`, `SALE_VOID_APPROVE`, `SALE_REFUND`, `SALE_REFUND_APPROVE`, `PRICE_OVERRIDE`, `DISCOUNT_OVERRIDE`, `STOCK_ADJUST`, `CASH_OUT`, `REPORT_VIEW`, `STORE_SETTINGS_MANAGE`, `FISCAL_DAY_CLOSE`, `CATALOG_MANAGE`, `AUDIT_VIEW`, `JOURNAL_VIEW`, `USER_MANAGE`, `TERMINAL_MANAGE`, `FISCAL_CONFIGURATION_MANAGE` |
| `Shift.status` / `FiscalDay.status` | Stage 2 (§2.5) | `OPEN`, `CLOSED` |
| `Void.status` (inline enum) | Stage 2 `void.status` (§2.8) | `REQUESTED`, `APPROVED`, `REJECTED`, `VOIDED` — reused identically on `voidList`'s new `status` query filter (pass 3) |
| `Refund.status` (inline enum) | Stage 2 `refund.status` (§2.9) | `REQUESTED`, `APPROVED`, `REJECTED`, `COMPLETED` — reused identically on `refundList`'s new `status` query filter (pass 3) |
| `FiscalInstallationInput.deployment_model` (inline enum) | Stage 2/ADR-009 `fiscal_installation.deployment_model` | `STANDALONE`, `SERVER_CONNECTED` |
| Cash movement `type` (inline enum) | Stage 2 `cash_movement.type` (§2.5) | `CASH_IN`, `CASH_OUT` |

No enum in the contract introduces a value the domain doesn't already
define, and no domain enum value is renamed for API readability.

See [api-design.md](api-design.md) §4 for the fixed V1 role→capability
mapping (which roles get which of the 17 capabilities above) — kept there,
not duplicated here, to avoid the two documents drifting apart.

## Capability-to-operation coverage (both directions, new pass 3)

Checking `x-capability ⊆ Capability enum` alone (as prior passes did)
only proves the contract invents no unknown capability — it says nothing
about whether every canonical capability is actually reachable. Pass 3
ran the reverse check too:

| Capability | Operation(s) | 
|---|---|
| `SALE_VOID` | `saleVoid` |
| `SALE_VOID_APPROVE` | `voidApprove`, `voidReject` — **new pass 3, closing the exact gap found on review** |
| `SALE_REFUND` | `saleRefund` |
| `SALE_REFUND_APPROVE` | `refundApprove`, `refundReject` — **new pass 3** |
| `PRICE_OVERRIDE` | *(none directly)* — conditional field inside `saleFinalize`'s `SaleItemInput.override_reason`, pre-existing |
| `DISCOUNT_OVERRIDE` | *(none directly)* — same field, same endpoint, pre-existing |
| `STOCK_ADJUST` | `inventoryReceiptCreate`, `inventoryAdjustmentCreate`, and (stage 25) the 10 stock count and transfer operations |
| `CASH_OUT` | *(none directly)* — conditional inside `shiftCashMovementCreate` ("above a configurable threshold"), pre-existing |
| `REPORT_VIEW` | all 15 report operations |
| `STORE_SETTINGS_MANAGE` | `storeSettingsUpdate`, `slipFooterSet` (ADR-016) |
| `FISCAL_DAY_CLOSE` | `fiscalDayClose` |
| `CATALOG_MANAGE` | 7 catalog-mutation operations, plus (stage 26) `productBarcodeCreate` and `productBarcodeDelete` |
| `AUDIT_VIEW` | `auditEventList`, `auditEventGet` |
| `JOURNAL_VIEW` | `journalEntryList`, `journalEntryGet` |
| `USER_MANAGE` | 6 user-management operations (5 original + `userActivate`) |
| `TERMINAL_MANAGE` | 5 terminal-management operations |
| `FISCAL_CONFIGURATION_MANAGE` | 4 fiscal-configuration operations, plus `saleDocumentModeSet` (ADR-016) |
| `EMPLOYEE_MANAGE` | 8 operations: `employeeList`, `employeeCreate`, `employeeIdCards`, `employeeGet`, `employeeUpdate`, `employeeActivate`, `employeeDeactivate`, `creditPolicyList` (ADR-016 slice 3.1; ADMIN and MANAGER from 2026-10-26). `employeeLookup` needs only the session |
| `EMPLOYEE_POLICY_MANAGE` | 2 operations: `employeeImport`, `creditPolicySetLimit` (ADR-016 amendment 2026-10-26; ADMIN only) |
| `BILLING_MANAGE` | 12 operations: `billingStatementGet`, `billingRunList`, `billingRunCreate`, `billingRunGet` (ADR-016 slice 4.1), `billingBalanceGet`, `billingChequeList`, `billingChequeRecord`, `billingChequeGet`, `billingChequeReturn` (slice 4.2), `billingWriteOffList`, `billingWriteOffRecord`, `billingWriteOffReverse` (slice 4.3); MANAGER and ADMIN |

Three capabilities show "(none directly)" for a documented reason, not an
oversight — see api-design.md §4 for why a capability that only gates
*some* request shapes on a shared endpoint cannot be that endpoint's
single `x-capability` value. `SALE_VOID_APPROVE`/`SALE_REFUND_APPROVE`
were the only capabilities that had **no** operation and **no** documented
reason — that was the real gap this pass closes.

**Changed existing operations (additive, ADR-017 slice C):** `Product` and `ProductInput` gain `shelf_life_days` (integer 1 to 3650, nullable; `productCreate`/`productUpdate` keep their `CATALOG_MANAGE` gate, so ADMIN and MANAGER may set it and a cashier gets 403; a bad value is 422 `VALIDATION_FAILED` on `shelf_life_days`). `inventoryStockList` and `inventoryLowStockList` rows gain `shelf_life_days`, `last_received_on`, `check_freshness` and `days_old`, and both accept `?check_freshness=true`. `stockAvailabilityGet` RUNNING items gain `check_freshness` (a flag only; the shelf life itself is not sent to the till). The product CSV gains a trailing `shelf_life_days` column. No new operation, capability or error code.
