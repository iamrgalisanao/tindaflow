<?php

namespace App\Http\Controllers;

use App\Domain\Exceptions\LicenseCodeRefusedException;
use App\Http\Requests\LicenseCodeRequest;
use App\Models\Shift;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\License\LicenseStatusReport;
use App\Services\License\OfflineCodeRefused;
use App\Services\License\OfflineCodeService;
use App\Services\License\Renewal\RenewalResult;
use App\Services\License\Renewal\RenewalService;
use App\Services\License\TillNotice;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml License tag (ADR-018 decision 10): the Manager area's license screen. Its three operations need LICENSE_MANAGE
 * (MANAGER and ADMIN), a session and no terminal. They do exactly what the console commands do, through the same services; who
 * asked is recorded in the audit log. The license is the installation's, which has one store.
 */
class LicenseController extends Controller
{
    public function status(LicenseStatusReport $report): JsonResponse
    {
        return response()->json($report->build());
    }

    /**
     * The till's warning (openapi `licenseTillNotice`): what whoever is selling needs to know, and nothing else. It needs no
     * LICENSE_MANAGE, unlike the rest here, because a cashier is the one who would meet the refusal.
     */
    public function tillNotice(Request $request, TillNotice $notice): JsonResponse
    {
        $openedAt = Shift::where('terminal_id', $request->attributes->get('terminal')->id)->where('status', 'OPEN')->value('opened_at');

        return response()->json(['notice' => $notice->for($openedAt === null ? null : new DateTimeImmutable((string) $openedAt))]);
    }

    /** "Check license now". A failed or skipped renewal is a normal answer (200) that says what happened. */
    public function renew(RenewalService $renewals, LicenseStatusReport $report): JsonResponse
    {
        $user = $this->actor();
        $result = $renewals->renew();

        if ($result->outcome !== RenewalResult::SKIPPED) {
            AuditTrail::record($user->store_id, 'LICENSE_CHECK_REQUESTED', $user->id, 'store', $user->store_id, null, ['outcome' => $result->outcome]);
        }

        return response()->json(['outcome' => $result->outcome, 'message' => $result->message, 'license' => $report->build()]);
    }

    public function applyCode(LicenseCodeRequest $request, OfflineCodeService $codes, LicenseStatusReport $report): JsonResponse
    {
        $user = $this->actor();

        try {
            $message = $codes->apply((string) $request->validated('code'), actorUserId: $user->id);
        } catch (OfflineCodeRefused $refused) {
            // Recorded outside the code's own transaction, which has rolled back. Never the code itself.
            AuditTrail::record($user->store_id, 'LICENSE_CODE_REFUSED', $user->id, 'store', $user->store_id, null, ['reason' => $refused->getMessage()]);

            throw LicenseCodeRefusedException::make($refused->getMessage());
        }

        return response()->json(['message' => $message, 'license' => $report->build()]);
    }

    private function actor(): User
    {
        return Auth::guard('web')->user();
    }
}
