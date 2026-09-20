<?php

namespace App\Services\Invoicing\Renderers;

/**
 * Renders `invoice_snapshot_json` schema_version 1, the shape CheckoutService wrote until snapshot version 2. It uses
 * the shared layout unchanged (no machine, beneficiary, payment or authority blocks), so every invoice already issued
 * in this shape prints exactly as it always has (ADR-006).
 */
final class InvoiceSnapshotV1Renderer extends InvoiceSnapshotRenderer {}
