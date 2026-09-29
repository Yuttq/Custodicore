<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\QrCode;
use App\Models\VisitCheckin;
use App\Models\VisitRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * The real gate operation (section 5.5): scan a visitor's QR (or manual ID
 * surrender override) to check them in, then check them out when their
 * visit ends.
 */
class CheckinCheckoutController extends Controller
{
    /**
     * TEMPORARY STAND-IN — not real auth. Same pattern/caveat as
     * \App\Http\Controllers\PdlController::currentStaffId(): auth() isn't
     * wired to accounts/staff_profiles yet, so this grabs the first staff
     * row. Swap for auth()->user()->staffProfile->staff_id once that
     * exists.
     */
    private function currentStaffId(): int
    {
        $staff = \App\Models\StaffProfile::first();

        if (! $staff) {
            throw new \RuntimeException('No staff_profiles row exists yet. Run the database seeder first.');
        }

        return $staff->staff_id;
    }

    public function index(): View
    {
        $expected = VisitRequest::where('status', 'confirmed')
            ->whereHas('schedule', fn ($q) => $q->whereDate('schedule_date', today()))
            ->whereDoesntHave('checkin')
            ->with(['visitor', 'pdl'])
            ->orderBy('confirmed_at')
            ->get();

        $insideNow = VisitCheckin::where('status', 'checked_in')
            ->with('visitRequest.visitor')
            ->orderByDesc('check_in_time')
            ->get();

        $todayHistory = VisitCheckin::whereDate('check_in_time', today())
            ->with('visitRequest.visitor')
            ->orderByDesc('check_in_time')
            ->get();

        return view('frontdesk.checkin-checkout', compact('expected', 'insideNow', 'todayHistory'));
    }

    public function checkIn(Request $request, VisitRequest $visitRequest): RedirectResponse
    {
        $data = $request->validate([
            'verification_method' => ['nullable', 'in:qr_scan,manual_override'],
            'override_reason' => ['nullable', 'string', 'max:255'],
            'id_surrendered_type' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            // qr_codes.visit_request_id has a NOT NULL FK on visit_checkins —
            // generate one on the fly if this visit request never got a QR
            // (e.g. it's being checked in via manual override instead of a scan).
            $qr = $visitRequest->qrCode ?? QrCode::generateFor($visitRequest);
            if ($qr->status !== 'used') {
                $qr->update(['status' => 'used']);
            }

            VisitCheckin::updateOrCreate(
                ['visit_request_id' => $visitRequest->visit_request_id],
                [
                    'qr_code_id' => $qr->qr_code_id,
                    'id_surrendered_type' => $data['id_surrendered_type'] ?? null,
                    'id_surrender_time' => ($data['id_surrendered_type'] ?? null) ? now() : null,
                    'check_in_time' => now(),
                    'check_in_officer_id' => $this->currentStaffId(),
                    'verification_method' => $data['verification_method'] ?? 'qr_scan',
                    'override_reason' => $data['override_reason'] ?? null,
                    'status' => 'checked_in',
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Check-in failed: ' . $e->getMessage());
            return back()->with('error', 'Could not check this visitor in. ' . $e->getMessage());
        }

        try {
            AuditLog::record('check_in', 'visit_checkins', $visitRequest->visit_request_id,
                "Checked in visitor {$visitRequest->visitor?->full_name} for visit request #{$visitRequest->visit_request_id}",
                Module::CODE_CHECKIN_CHECKOUT);
        } catch (\Throwable $e) {
            Log::warning('Audit log write failed: ' . $e->getMessage());
        }

        return redirect()->route('frontdesk.checkin-checkout')->with('status', 'Visitor checked in.');
    }

    public function checkOut(VisitCheckin $checkin): RedirectResponse
    {
        try {
            $checkin->update([
                'check_out_time' => now(),
                'check_out_officer_id' => $this->currentStaffId(),
                'id_returned_time' => now(),
                'status' => 'checked_out',
            ]);

            $checkin->visitRequest?->update(['status' => 'completed']);
        } catch (\Throwable $e) {
            Log::error('Check-out failed: ' . $e->getMessage());
            return back()->with('error', 'Could not check this visitor out. ' . $e->getMessage());
        }

        try {
            AuditLog::record('check_out', 'visit_checkins', $checkin->checkin_id,
                "Checked out visitor {$checkin->visitRequest?->visitor?->full_name}",
                Module::CODE_CHECKIN_CHECKOUT);
        } catch (\Throwable $e) {
            Log::warning('Audit log write failed: ' . $e->getMessage());
        }

        return redirect()->route('frontdesk.checkin-checkout')->with('status', 'Visitor checked out.');
    }
}
