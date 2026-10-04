<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\QrCode;
use App\Models\VisitCheckin;
use App\Models\VisitRequest;
use App\Models\VisitorId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Front Desk Check-In / Check-Out
 *
 * Handles:
 * - QR-code based visitor verification
 * - Registered ID verification
 * - Replacement/new ID recording
 * - Manual check-in backup
 * - Visitor check-out
 * - Today's gate history
 */
class CheckinCheckoutController extends Controller
{
    /**
     * TEMPORARY STAFF IDENTIFICATION
     *
     * This currently uses the first staff profile because the real
     * authentication-to-staff-profile connection is not wired yet.
     *
     * Replace later with:
     *
     * auth()->user()->staffProfile->staff_id
     */
    private function currentStaffId(): int
    {
        $staff = \App\Models\StaffProfile::first();

        if (! $staff) {
            throw new \RuntimeException(
                'No staff_profiles row exists yet. Run the database seeder first.'
            );
        }

        return $staff->staff_id;
    }

    /**
     * ---------------------------------------------------------------
     * DISPLAY CHECK-IN / CHECK-OUT PAGE
     * ---------------------------------------------------------------
     */
    public function index(): View
    {
        /*
         * Visitors expected today who have not checked in yet.
         */
        $expected = VisitRequest::where('status', 'confirmed')
            ->whereHas('schedule', function ($q) {
                $q->whereDate('schedule_date', today());
            })
            ->whereDoesntHave('checkin')
            ->with([
                'visitor',
                'pdl',
                'schedule',
                'qrCode',
            ])
            ->orderBy('confirmed_at')
            ->get();

        /*
         * Visitors currently inside the facility.
         */
        $insideNow = VisitCheckin::where('status', 'checked_in')
            ->with([
                'visitRequest.visitor',
                'visitRequest.pdl',
                'visitRequest.schedule',
                'qrCode',
            ])
            ->orderByDesc('check_in_time')
            ->get();

        /*
         * Today's complete gate history.
         */
        $todayHistory = VisitCheckin::whereDate(
                'check_in_time',
                today()
            )
            ->with([
                'visitRequest.visitor',
                'visitRequest.pdl',
                'visitRequest.schedule',
                'qrCode',
            ])
            ->orderByDesc('check_in_time')
            ->get();

        return view(
            'frontdesk.checkin-checkout',
            compact(
                'expected',
                'insideNow',
                'todayHistory'
            )
        );
    }

    /**
     * ---------------------------------------------------------------
     * QR SCANNER
     * ---------------------------------------------------------------
     *
     * This endpoint ONLY verifies the QR code.
     *
     * It does NOT create a check-in.
     * It does NOT mark the QR as used.
     */
    public function scanQr(Request $request): JsonResponse
    {
        $data = $request->validate([
            'qr_token' => [
                'required',
                'string',
                'max:255',
            ],
        ]);

        try {

            $qr = QrCode::where(
                'qr_token',
                $data['qr_token']
            )
                ->with([
                    'visitRequest.visitor',
                    'visitRequest.pdl',
                    'visitRequest.schedule',
                    'visitRequest.checkin',
                ])
                ->first();

            if (! $qr) {
                return response()->json([
                    'success' => false,
                    'message' => 'QR code not recognized.',
                ], 404);
            }

            /*
             * CHECK-OUT SCAN (frontdesk-checkin.js sends mode=checkout).
             *
             * Check-in marks the QR as `used`, so the exit scan must accept
             * a used QR — but only to look up the visitor's ACTIVE check-in.
             * The actual check-out still goes through checkOut(), which
             * re-validates the check-in status.
             */
            if ($request->input('mode') === 'checkout') {
                $visitRequest = $qr->visitRequest;
                $checkin = $visitRequest?->checkin;

                if (! $visitRequest || ! $checkin || $checkin->status !== 'checked_in') {
                    return response()->json([
                        'success' => false,
                        'message' => 'This visitor is not currently checked in.',
                        'status' => $checkin?->status,
                    ], 422);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Checked-in visitor found.',
                    'visit_request_id' => $visitRequest->visit_request_id,
                    'checkin_id' => $checkin->checkin_id,
                    'visitor' => [
                        'visitor_id' => $visitRequest->visitor?->visitor_id,
                        'name' => $visitRequest->visitor?->full_name ?? '—',
                        'pdl' => $visitRequest->pdl?->full_name ?? '—',
                        'pdl_number' => $visitRequest->pdl?->pdl_number ?? '—',
                    ],
                    'checkin' => [
                        'checkin_id' => $checkin->checkin_id,
                        'visitor_name' => $visitRequest->visitor?->full_name ?? '—',
                        'pdl_name' => $visitRequest->pdl?->full_name ?? '—',
                        'pdl_number' => $visitRequest->pdl?->pdl_number ?? '—',
                        'check_in_time' => $checkin->check_in_time
                            ? Carbon::parse($checkin->check_in_time)->format('M d, Y h:i A')
                            : null,
                        'id_surrendered_type' => $checkin->id_surrendered_type,
                        'status' => 'checked_in',
                    ],
                ]);
            }

            /*
             * QR must still be active.
             */
            if ($qr->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'This QR code is no longer active.',
                    'status' => $qr->status,
                ], 422);
            }

            /*
             * Check QR expiration.
             */
            if (
                $qr->expires_at &&
                $qr->expires_at->isPast()
            ) {
                $qr->update([
                    'status' => 'expired',
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'This QR code has expired.',
                ], 422);
            }

            $visitRequest = $qr->visitRequest;

            if (! $visitRequest) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'This QR code is not linked to a valid visit.',
                ], 422);
            }

            /*
             * Only confirmed visits can proceed.
             */
            if ($visitRequest->status !== 'confirmed') {
                return response()->json([
                    'success' => false,
                    'message' => 'This visit is not confirmed.',
                    'visit_status' => $visitRequest->status,
                ], 422);
            }

            /*
             * Prevent scanning an already checked-in visitor.
             */
            if (
                $visitRequest->checkin &&
                $visitRequest->checkin->status === 'checked_in'
            ) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'This visitor is already checked in.',
                ], 422);
            }

            /*
             * Make sure there is a schedule.
             */
            if (
                ! $visitRequest->schedule ||
                ! $visitRequest->schedule->schedule_date
            ) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'No valid visit schedule was found.',
                ], 422);
            }

            /*
             * QR is only valid for today's scheduled visit.
             */
            $scheduleDate = Carbon::parse(
                $visitRequest->schedule->schedule_date
            );

            if (! $scheduleDate->isToday()) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'This QR code is not for today\'s visit.',
                    'schedule_date' =>
                        $scheduleDate->format('M d, Y'),
                ], 422);
            }

            /*
             * Schedule times.
             */
            $scheduleStart = $visitRequest->schedule->time_slot_start
                ? Carbon::parse(
                    $visitRequest->schedule->time_slot_start
                )->format('h:i A')
                : '—';

            $scheduleEnd = $visitRequest->schedule->time_slot_end
                ? Carbon::parse(
                    $visitRequest->schedule->time_slot_end
                )->format('h:i A')
                : '—';

            /*
             * Get visitor's registered IDs.
             */
            $registeredIds = VisitorId::where(
                'visitor_id',
                $visitRequest->visitor?->visitor_id
            )
                ->whereIn(
                    'verification_status',
                    [
                        'verified',
                        'approved',
                    ]
                )
                ->orderByDesc('verified_at')
                ->get()
                ->map(function ($id) {
                    return [
                        'id' =>
                            $id->visitor_id_doc_id,

                        'type' =>
                            $id->id_type,

                        'type_label' =>
                            $id->typeLabel(),

                        'number' =>
                            $id->id_number,

                        'status' =>
                            $id->verification_status,

                        'reason' =>
                            $id->reason,
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,

                'message' =>
                    'QR code verified successfully.',

                'visit_request_id' =>
                    $visitRequest->visit_request_id,

                'qr_code_id' =>
                    $qr->qr_code_id,

                'visitor' => [
                    'visitor_id' =>
                        $visitRequest->visitor?->visitor_id,

                    'name' =>
                        $visitRequest->visitor?->full_name ?? '—',

                    'pdl' =>
                        $visitRequest->pdl?->full_name ?? '—',

                    'pdl_number' =>
                        $visitRequest->pdl?->pdl_number ?? '—',
                ],

                /*
                 * Registered ID information.
                 */
                'registered_ids' =>
                    $registeredIds,

                'schedule' => [
                    'date' =>
                        $scheduleDate->format('M d, Y'),

                    'start' =>
                        $scheduleStart,

                    'end' =>
                        $scheduleEnd,

                    'display' =>
                        $scheduleDate->format('M d, Y')
                        . ' · '
                        . $scheduleStart
                        . ' - '
                        . $scheduleEnd,
                ],

                'checkin' => null,
            ]);

        } catch (\Throwable $e) {

            Log::error(
                'QR verification failed: ' .
                $e->getMessage()
            );

            return response()->json([
                'success' => false,
                'message' =>
                    'Could not process the QR code.',
            ], 500);
        }
    }

    /**
     * ---------------------------------------------------------------
     * CONFIRM QR CHECK-IN
     * ---------------------------------------------------------------
     *
     * This method:
     *
     * 1. Re-validates the QR
     * 2. Checks the visitor's ID
     * 3. Optionally records a replacement/new ID
     * 4. Creates the check-in
     * 5. Marks QR as used
     *
     * IMPORTANT:
     *
     * A replacement/new ID is NOT automatically verified.
     *
     * It is stored as pending verification for the Records Officer.
     */
    public function confirmQrCheckIn(
        Request $request
    ): JsonResponse {

        $data = $request->validate([
            'qr_token' => [
                'required',
                'string',
                'max:255',
            ],

            'id_surrendered_type' => [
                'required',
                'string',
                'max:50',
            ],

            /*
             * Existing registered ID matched?
             */
            'id_match_status' => [
                'required',
                'in:matched,replaced',
            ],

            /*
             * New/replacement ID information.
             */
            'new_id_type' => [
                'nullable',
                'string',
                'max:50',
            ],

            'new_id_number' => [
                'nullable',
                'string',
                'max:100',
            ],

            'new_id_reason' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        /*
         * If the officer says the registered ID was replaced,
         * require the new ID details and reason.
         */
        if ($data['id_match_status'] === 'replaced') {

            if (
                empty($data['new_id_type']) ||
                empty($data['new_id_number']) ||
                empty($data['new_id_reason'])
            ) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'New ID type, new ID number, and reason are required when the registered ID does not match.',
                ], 422);
            }
        }

        try {

            $qr = QrCode::where(
                'qr_token',
                $data['qr_token']
            )
                ->with([
                    'visitRequest.visitor',
                    'visitRequest.pdl',
                    'visitRequest.schedule',
                    'visitRequest.checkin',
                ])
                ->first();

            if (! $qr) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'QR code not recognized.',
                ], 404);
            }

            /*
             * QR must still be active.
             */
            if ($qr->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'This QR code is no longer active.',
                ], 422);
            }

            /*
             * Check expiration.
             */
            if (
                $qr->expires_at &&
                $qr->expires_at->isPast()
            ) {
                $qr->update([
                    'status' => 'expired',
                ]);

                return response()->json([
                    'success' => false,
                    'message' =>
                        'This QR code has expired.',
                ], 422);
            }

            $visitRequest = $qr->visitRequest;

            if (! $visitRequest) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'This QR code is not linked to a valid visit.',
                ], 422);
            }

            /*
             * Visit must still be confirmed.
             */
            if ($visitRequest->status !== 'confirmed') {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'This visit is no longer confirmed.',
                ], 422);
            }

            /*
             * Prevent duplicate check-in.
             */
            if (
                $visitRequest->checkin &&
                $visitRequest->checkin->status === 'checked_in'
            ) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'This visitor is already checked in.',
                ], 422);
            }

            /*
             * Validate today's schedule.
             */
            if (
                ! $visitRequest->schedule ||
                ! $visitRequest->schedule->schedule_date
            ) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'No valid visit schedule was found.',
                ], 422);
            }

            $scheduleDate = Carbon::parse(
                $visitRequest->schedule->schedule_date
            );

            if (! $scheduleDate->isToday()) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'This visit is not scheduled for today.',
                ], 422);
            }

            /*
             * -------------------------------------------------------
             * SAVE REPLACEMENT ID
             * -------------------------------------------------------
             *
             * The new ID is stored under the same visitor.
             *
             * It is NOT automatically verified.
             *
             * Records Officer will review it later.
             */
            if (
                $data['id_match_status'] === 'replaced'
            ) {

                VisitorId::create([
                    'visitor_id' =>
                        $visitRequest->visitor?->visitor_id,

                    'id_type' =>
                        $data['new_id_type'],

                    'id_number' =>
                        $data['new_id_number'],

                    'file_path' =>
                        null,

                    /*
                     * IMPORTANT:
                     *
                     * Front Desk records the ID but does not
                     * approve it.
                     */
                    'verification_status' =>
                        'pending',

                    'reason' =>
                        $data['new_id_reason'],

                    'verified_by' =>
                        null,

                    'verified_at' =>
                        null,
                ]);
            }

            /*
             * -------------------------------------------------------
             * ACTUAL CHECK-IN
             * -------------------------------------------------------
             */
            $checkin = VisitCheckin::updateOrCreate(
                [
                    'visit_request_id' =>
                        $visitRequest->visit_request_id,
                ],
                [
                    'qr_code_id' =>
                        $qr->qr_code_id,

                    'id_surrendered_type' =>
                        $data['id_surrendered_type'],

                    'id_surrender_time' =>
                        now(),

                    'check_in_time' =>
                        now(),

                    'check_in_officer_id' =>
                        $this->currentStaffId(),

                    'verification_method' =>
                        'qr_scan',

                    'override_reason' =>
                        $data['id_match_status'] === 'replaced'
                            ? $data['new_id_reason']
                            : null,

                    'status' =>
                        'checked_in',
                ]
            );

            /*
             * Mark QR as used.
             */
            $qr->update([
                'status' => 'used',
            ]);

            /*
             * Audit trail.
             */
            try {

                AuditLog::record(
                    'check_in',
                    'visit_checkins',
                    $checkin->checkin_id,

                    "QR checked in visitor {$visitRequest->visitor?->full_name} for visit request #{$visitRequest->visit_request_id}",

                    Module::CODE_CHECKIN_CHECKOUT
                );

                /*
                 * Additional audit record if a replacement ID
                 * was submitted.
                 */
                if (
                    $data['id_match_status'] === 'replaced'
                ) {

                    AuditLog::record(
                        'visitor_id_update',
                        'visitor_ids',
                        null,

                        "Replacement ID submitted for visitor {$visitRequest->visitor?->full_name}. Reason: {$data['new_id_reason']}. Awaiting Records Officer verification.",

                        Module::CODE_CHECKIN_CHECKOUT
                    );
                }

            } catch (\Throwable $e) {

                Log::warning(
                    'Audit log write failed: ' .
                    $e->getMessage()
                );
            }

            /*
             * Return success.
             */
            return response()->json([
                'success' => true,

                'message' =>
                    $data['id_match_status'] === 'replaced'
                        ? 'Visitor checked in. The replacement ID has been recorded and is pending Records Officer verification.'
                        : 'Visitor checked in successfully.',

                'visitor' => [
                    'name' =>
                        $visitRequest->visitor?->full_name ?? '—',

                    'pdl' =>
                        $visitRequest->pdl?->full_name ?? '—',

                    'pdl_number' =>
                        $visitRequest->pdl?->pdl_number ?? '—',
                ],

                'checkin' => [
                    'id' =>
                        $checkin->checkin_id,

                    'time' =>
                        $checkin->check_in_time?->format(
                            'h:i A'
                        ),

                    'method' =>
                        'QR Scan',
                ],

                'new_id_pending' =>
                    $data['id_match_status'] === 'replaced',
            ]);

        } catch (\Throwable $e) {

            Log::error(
                'QR check-in confirmation failed: ' .
                $e->getMessage()
            );

            return response()->json([
                'success' => false,
                'message' =>
                    'Could not complete the check-in.',
            ], 500);
        }
    }

    /**
     * ---------------------------------------------------------------
     * MANUAL CHECK-IN BACKUP
     * ---------------------------------------------------------------
     */
    public function checkIn(
        Request $request,
        VisitRequest $visitRequest
    ): RedirectResponse {

        $data = $request->validate([
            'verification_method' => [
                'nullable',
                'in:qr_scan,manual_override',
            ],

            'override_reason' => [
                'nullable',
                'string',
                'max:255',
            ],

            'id_surrendered_type' => [
                'nullable',
                'string',
                'max:50',
            ],

            /*
             * New replacement ID.
             */
            'id_match_status' => [
                'nullable',
                'in:matched,replaced',
            ],

            'new_id_type' => [
                'nullable',
                'string',
                'max:50',
            ],

            'new_id_number' => [
                'nullable',
                'string',
                'max:100',
            ],

            'new_id_reason' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        /*
         * If a replacement ID is being submitted,
         * require complete replacement information.
         */
        if (
            ($data['id_match_status'] ?? null) === 'replaced'
        ) {

            if (
                empty($data['new_id_type']) ||
                empty($data['new_id_number']) ||
                empty($data['new_id_reason'])
            ) {
                return back()->with(
                    'error',
                    'New ID type, new ID number, and reason are required for a replacement ID.'
                );
            }
        }

        try {

            /*
             * Only confirmed visits.
             */
            if ($visitRequest->status !== 'confirmed') {
                return back()->with(
                    'error',
                    'This visit is not confirmed.'
                );
            }

            /*
             * Make sure the visit is scheduled for today.
             */
            if (
                ! $visitRequest->schedule ||
                ! $visitRequest->schedule->schedule_date
            ) {
                return back()->with(
                    'error',
                    'No valid visit schedule was found.'
                );
            }

            if (
                ! Carbon::parse(
                    $visitRequest->schedule->schedule_date
                )->isToday()
            ) {
                return back()->with(
                    'error',
                    'This visit is not scheduled for today.'
                );
            }

            /*
             * Prevent duplicate check-in.
             */
            $existingCheckin = $visitRequest->checkin;

            if (
                $existingCheckin &&
                $existingCheckin->status === 'checked_in'
            ) {
                return back()->with(
                    'error',
                    'This visitor is already checked in.'
                );
            }

            /*
             * Make sure visitor exists.
             */
            if (! $visitRequest->visitor) {
                return back()->with(
                    'error',
                    'No visitor profile is linked to this visit.'
                );
            }

            /*
             * -------------------------------------------------------
             * SAVE REPLACEMENT ID
             * -------------------------------------------------------
             */
            if (
                ($data['id_match_status'] ?? null) === 'replaced'
            ) {

                VisitorId::create([
                    'visitor_id' =>
                        $visitRequest->visitor->visitor_id,

                    'id_type' =>
                        $data['new_id_type'],

                    'id_number' =>
                        $data['new_id_number'],

                    'file_path' =>
                        null,

                    'verification_status' =>
                        'pending',

                    'reason' =>
                        $data['new_id_reason'],

                    'verified_by' =>
                        null,

                    'verified_at' =>
                        null,
                ]);
            }

            /*
             * Make sure visitor has a QR record.
             */
            $qr = $visitRequest->qrCode
                ?? QrCode::generateFor($visitRequest);

            /*
             * Create/update check-in.
             */
            $checkin = VisitCheckin::updateOrCreate(
                [
                    'visit_request_id' =>
                        $visitRequest->visit_request_id,
                ],
                [
                    'qr_code_id' =>
                        $qr->qr_code_id,

                    'id_surrendered_type' =>
                        $data['id_surrendered_type'] ?? null,

                    'id_surrender_time' =>
                        ! empty(
                            $data['id_surrendered_type']
                        )
                            ? now()
                            : null,

                    'check_in_time' =>
                        now(),

                    'check_in_officer_id' =>
                        $this->currentStaffId(),

                    'verification_method' =>
                        $data['verification_method']
                        ?? 'manual_override',

                    'override_reason' =>
                        ($data['id_match_status'] ?? null) === 'replaced'
                            ? $data['new_id_reason']
                            : (
                                $data['override_reason']
                                ?? 'Manual Front Desk check-in'
                            ),

                    'status' =>
                        'checked_in',
                ]
            );

            /*
             * Mark QR as used.
             */
            if ($qr->status !== 'used') {
                $qr->update([
                    'status' => 'used',
                ]);
            }

        } catch (\Throwable $e) {

            Log::error(
                'Manual check-in failed: ' .
                $e->getMessage()
            );

            return back()->with(
                'error',
                'Could not check this visitor in. ' .
                $e->getMessage()
            );
        }

        /*
         * Audit trail.
         */
        try {

            AuditLog::record(
                'check_in',
                'visit_checkins',
                $checkin->checkin_id,

                "Manually checked in visitor {$visitRequest->visitor?->full_name} for visit request #{$visitRequest->visit_request_id}",

                Module::CODE_CHECKIN_CHECKOUT
            );

        } catch (\Throwable $e) {

            Log::warning(
                'Audit log write failed: ' .
                $e->getMessage()
            );
        }

        return redirect()
            ->route('frontdesk.checkin-checkout')
            ->with(
                'status',
                'Visitor checked in manually.'
            );
    }

    /**
     * ---------------------------------------------------------------
     * CHECK OUT
     * ---------------------------------------------------------------
     */
    public function checkOut(
        VisitCheckin $checkin
    ): RedirectResponse {

        try {

            /*
             * Prevent checking out an already completed visit.
             */
            if ($checkin->status !== 'checked_in') {
                return back()->with(
                    'error',
                    'This visitor is not currently checked in.'
                );
            }

            $checkin->update([
                'check_out_time' =>
                    now(),

                'check_out_officer_id' =>
                    $this->currentStaffId(),

                /*
                 * Return the surrendered ID.
                 */
                'id_returned_time' =>
                    now(),

                'status' =>
                    'checked_out',
            ]);

            /*
             * Complete the visit request.
             */
            $checkin->visitRequest?->update([
                'status' =>
                    'completed',
            ]);

        } catch (\Throwable $e) {

            Log::error(
                'Check-out failed: ' .
                $e->getMessage()
            );

            return back()->with(
                'error',
                'Could not check this visitor out. ' .
                $e->getMessage()
            );
        }

        /*
         * Audit trail.
         */
        try {

            AuditLog::record(
                'check_out',
                'visit_checkins',
                $checkin->checkin_id,

                "Checked out visitor {$checkin->visitRequest?->visitor?->full_name}",

                Module::CODE_CHECKIN_CHECKOUT
            );

        } catch (\Throwable $e) {

            Log::warning(
                'Audit log write failed: ' .
                $e->getMessage()
            );
        }

        return redirect()
            ->route('frontdesk.checkin-checkout')
            ->with(
                'status',
                'Visitor checked out successfully.'
            );
    }
}