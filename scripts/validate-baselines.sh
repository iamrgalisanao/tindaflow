#!/usr/bin/env bash
# Validates the two invariants required of every frozen stage baseline
# in this repository (see PROJECT-MANIFEST.md's "Stage Baseline Rule"):
#
#   1. Ordering:   stage-N-baseline is a git ancestor of stage-(N+1)-baseline.
#   2. Isolation:  stage-N-baseline contains no path owned by any later stage.
#
# Run this after moving any stage-N-baseline tag, and before tagging a new
# stage's baseline. Exits non-zero (and prints exactly what failed) if either
# invariant is violated for any stage pair.
#
# Usage: scripts/validate-baselines.sh

set -u
cd "$(git rev-parse --show-toplevel)" || exit 1

STAGES=(stage-1-baseline stage-2-baseline stage-3-baseline stage-4-baseline stage-5-baseline stage-6a-baseline stage-6b-baseline)

# Path-ownership map. Each stage's pattern matches paths that stage (and only
# that stage) introduces — keep this in sync with PROJECT-MANIFEST.md's own
# "Path-ownership map used throughout" section; the two must never diverge.
OWNERSHIP_stage_1_baseline='^docs/00-product/|^docs/01-research/|^docs/06-ui/'
OWNERSHIP_stage_2_baseline='^docs/02-domain/|^docs/03-architecture/erd\.md$'
OWNERSHIP_stage_3_baseline='^docs/03-architecture/architecture\.md$|^docs/03-architecture/deployment\.md$|^docs/03-architecture/offline-strategy\.md$|^docs/03-architecture/decisions/'
OWNERSHIP_stage_4_baseline='^docs/05-api/'
OWNERSHIP_stage_5_baseline='^docs/04-database/|^database/|^app/Models/|^app/Http/Controllers/Controller\.php$|^app/Providers/AppServiceProvider\.php$|^tests/TestCase\.php$|^tests/Feature/ExampleTest\.php$|^tests/Unit/ExampleTest\.php$|^tests/Database/(ConstraintValidationTest|ContextIntegrityTest|FactorySmokeTest|InvoiceContextIntegrityTest|InvoiceSeriesCounterUpgradeTest|InvoiceSeriesFiscalInstallationUpgradeTest|NonVatSalesTest|PostgresSchemaTestCase|PrecisionCoercionTest)\.php$|^(artisan|boost\.json|composer\.json|composer\.lock|package\.json|phpunit\.xml|vite\.config\.js|\.env\.example|\.gitattributes|\.npmrc|\.editorconfig|README\.md|AGENTS\.md|CLAUDE\.md)$|^bootstrap/|^config/|^public/|^resources/|^routes/|^storage/'
# NOTE: .gitignore is NOT listed here even though Stage 5 substantially
# expands it (Laravel's full ignore list) — the PATH already exists at
# Stage 1 (a minimal .gitignore ships with the very first commit), so later
# stages legitimately modify its CONTENT without "introducing" it. Path-based
# isolation checks a path's first appearance, not every later edit to it —
# same as docs/02-domain/domain-model.md staying Stage-2-owned despite many
# amendment passes touching it.
OWNERSHIP_stage_6a_baseline='^app/Domain/Exceptions/(ConcurrencyConflict|Domain|FiscalDayClosed|IdempotencyKeyReused|InvalidTaxConfiguration|RefundExceedsRemainingAmount|RefundExceedsRemainingQuantity|SaleNotVoidable|ShiftNotOpen)Exception\.php$|^app/Domain/Financial/|^app/Domain/Money\.php$|^app/Domain/Quantity\.php$|^app/Services/Idempotency/|^app/Support/|^docs/06-backend/stage-6a-transaction-foundation\.md$|^tests/Database/(DatabaseExceptionTranslatorTest|IdempotencyConcurrencyTest|IdempotencyServiceTest)\.php$|^tests/Database/support/idempotency_race_worker\.php$|^tests/Unit/Domain/|^tests/Unit/Services/CanonicalRequestHasherTest\.php$|^tests/Unit/Support/'
OWNERSHIP_stage_6b_baseline='^app/Domain/Exceptions/InvoiceSeries(Exhausted|Resolution)Exception\.php$|^app/Services/InvoiceNumbering/|^docs/06-backend/stage-6b-invoice-series-allocation\.md$|^tests/Database/InvoiceSeriesAllocator(Concurrency)?Test\.php$|^tests/Database/support/invoice_series_allocation_worker\.php$|^tests/Unit/Services/InvoiceNumbering/'

fail=0

echo "== 1. Ordering: each stage-N-baseline must be an ancestor of stage-(N+1)-baseline =="
for i in "${!STAGES[@]}"; do
  [ "$i" -eq 0 ] && continue
  prev="${STAGES[$((i-1))]}"
  curr="${STAGES[$i]}"
  if git rev-parse --verify -q "$prev" >/dev/null && git rev-parse --verify -q "$curr" >/dev/null; then
    if git merge-base --is-ancestor "$prev" "$curr" 2>/dev/null; then
      echo "  PASS: $prev -> $curr"
    else
      echo "  FAIL: $prev is NOT an ancestor of $curr"
      fail=1
    fi
  else
    echo "  SKIP: $prev or $curr does not exist yet"
  fi
done

echo ""
echo "== 2. Isolation: each stage-N-baseline must contain nothing owned by a later stage =="
for i in "${!STAGES[@]}"; do
  curr="${STAGES[$i]}"
  git rev-parse --verify -q "$curr" >/dev/null || { echo "  SKIP: $curr does not exist yet"; continue; }

  # Build the combined pattern of every LATER stage's ownership.
  later_pattern=""
  for j in "${!STAGES[@]}"; do
    [ "$j" -le "$i" ] && continue
    varname="OWNERSHIP_$(echo "${STAGES[$j]}" | tr '-' '_')"
    later_pattern="${later_pattern}${later_pattern:+|}${!varname}"
  done
  [ -z "$later_pattern" ] && { echo "  N/A:  $curr is the last stage, nothing later to leak"; continue; }

  leaked=$(git ls-tree -r --name-only "$curr" | grep -E "$later_pattern")
  if [ -z "$leaked" ]; then
    echo "  PASS: $curr contains nothing from a later stage"
  else
    echo "  FAIL: $curr leaks later-stage content:"
    echo "$leaked" | sed 's/^/          /'
    fail=1
  fi
done

echo ""
if [ "$fail" -eq 0 ]; then
  echo "All baseline invariants hold."
else
  echo "One or more baseline invariants FAILED. Do not treat any baseline tag as trustworthy until fixed."
fi
exit "$fail"
