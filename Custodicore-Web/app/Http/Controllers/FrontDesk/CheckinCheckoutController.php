<?php

namespace App\Http\Controllers\FrontDesk;

use App\Http\Controllers\Controller;
use App\Http\Requests\FrontDesk\ManualCheckInRequest;
use App\Http\Requests\FrontDesk\QrCheckInRequest;
use App\Http\Requests\FrontDesk\ScanQrRequest;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\QrCode;
use App\Models\VisitCheckin;
use App\Models\VisitRequest;
use App\Models\VisitorId;
use App\Models\VisitSchedule;
use App\Services\VisitGateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
 *
 * A visit may have a morning and an afternoon session (whole-day visit).
 * Each check-in enters one session (see VisitGateService); a check-out with
 * a later session still ahead is the midday exit and leaves the visit
 * confirmed, the last one completes it.
 */
class CheckinCheckoutController extends Controller
{
    // check_in_officer_id / check_out_officer_id: the signed-in officer.
    use \App\Http\Controllers\Concerns\ResolvesActingStaff;

    public function __construct(
        private VisitGateService $gate
    ) {
    }

    /**
     * ---------------------------------------------------------------
     * DISPLAY CHECK-IN / CHECK-OUT PAGE
     * ---------------------------------------------------------------
     */
    public function index(): View
    {
        /*
         * Visitors expected today: not inside now, with a session still
         * to enter (includes a whole-day visitor out for the midday break).
         */
        $expected = $this->expectedToday();

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
                'visitRequest.relationship',
                'visitRequest.schedule',
                'qrCode',
                'checkInOfficer',
                'checkOutOfficer',
            ])
            ->orderByDesc('check_in_time')
            ->get();

        /*
         * Today's check-outs (the visitor may have entered on an earlier day).
         */
        $todayCheckouts = VisitCheckin::whereDate(
                'check_out_time',
                today()
            )
            ->with([
                'visitRequest.visitor',
                'visitRequest.pdl',
                'visitRequest.relationship',
                'checkInOfficer',
                'checkOutOfficer',
            ])
            ->orderByDesc('check_out_time')
            ->get();

        /*
         * Search lists for the manual (no-QR) check-in and check-out
         * backups, read by frontdesk-checkin.js and the page script.
         */
        $manualCheckinVisitors = $expected->map(function (VisitRequest $visit) {
            $session = $this->gate->remainingSessions($visit)->first() ?? $visit->schedule;
            $registered = $this->registeredIds($visit->visitor?->visitor_id)->first() ?? [];

            return [
                'visitRequestId' => $visit->visit_request_id,
                'registeredIdType' => $registered['type_label'] ?? 'No verified ID on file',
                'registeredIdNumber' => $registered['number'] ?? '—',
                'registeredIdStatus' => isset($registered['status']) ? ucfirst($registered['status']) : '—',
                'visitorId' => $visit->visitor?->visitor_id,
                'visitorName' => $visit->visitor?->full_name ?? '—',
                'pdlName' => $visit->pdl?->full_name ?? '—',
                'pdlNumber' => $visit->pdl?->pdl_number ?? '—',
                'scheduleDate' => $session ? Carbon::parse($session->schedule_date)->format('M d, Y') : null,
                'scheduleStart' => $session ? Carbon::parse($session->time_slot_start)->format('h:i A') : null,
                'scheduleEnd' => $session ? Carbon::parse($session->time_slot_end)->format('h:i A') : null,
            ];
        })->values();

        $manualCheckoutVisitors = $insideNow->filter(fn (VisitCheckin $c) => $c->visitRequest)
            ->map(function (VisitCheckin $checkin) {
                $visit = $checkin->visitRequest;
                $details = $this->visitDetails($visit, 'Checked in');
                $registeredIds = $this->registeredIds($visit->visitor?->visitor_id);
                $registered = $registeredIds->first() ?? [];
                $surrendered = $this->surrenderedId($checkin);
                $session = VisitSchedule::find($checkin->schedule_id) ?? $visit->schedule;

                return [
                    'checkinId' => $checkin->checkin_id,
                    'visitorId' => $visit->visitor?->visitor_id,
                    'visitorName' => $visit->visitor?->full_name ?? '—',
                    'pdlName' => $visit->pdl?->full_name ?? '—',
                    'pdlNumber' => $visit->pdl?->pdl_number ?? '—',
                    'relationship' => $details['relationship'],
                    'pdlStatus' => $details['pdl_status'],
                    'building' => $details['building'],
                    'classification' => $details['classification'],
                    'visitStatus' => $details['visit_status'],
                    'scheduleDate' => $session ? Carbon::parse($session->schedule_date)->format('M d, Y') : '—',
                    'scheduleTime' => $session
                        ? Carbon::parse($session->time_slot_start)->format('h:i A').' – '
                            .Carbon::parse($session->time_slot_end)->format('h:i A')
                            .' ('.ucfirst($this->gate->sessionName($session)).')'
                        : '—',
                    'checkinTime' => $checkin->check_in_time
                        ? Carbon::parse($checkin->check_in_time)->format('M d, Y h:i A')
                        : '—',
                    'status' => 'Inside Facility',
                    'registeredIdType' => $registered['type_label'] ?? '—',
                    'registeredIdNumber' => $registered['number'] ?? '—',
                    'registeredIdStatus' => isset($registered['status']) ? ucfirst($registered['status']) : '—',
                    'idType' => $surrendered['type_label'],
                    'idNumber' => $surrendered['number'],
                ];
            })->values();

        return view(
            'frontdesk.checkin-checkout',
            compact(
                'expected',
                'insideNow',
                'todayHistory',
                'todayCheckouts',
                'manualCheckinVisitors',
                'manualCheckoutVisitors'
            )
        );
    }

    /**
     * Confirmed visits today whose visitor is not inside and that still have
     * a session to enter. Shared with the Front Desk dashboard.
     *
     * @return \Illuminate\Support\Collection<int, VisitRequest>
     */
    public static function expectedToday()
    {
        $gate = app(VisitGateService::class);

        return VisitRequest::where('status', 'confirmed')
            ->whereHas('schedule', function ($q) {
                $q->whereDate('schedule_date', today());
            })
            ->whereDoesntHave('checkins', fn ($q) => $q->where('status', 'checked_in'))
            ->with([
                'visitor',
                'pdl',
                'schedule',
                'qrCode',
            ])
            ->orderBy('confirmed_at')
            ->get()
            ->filter(fn (VisitRequest $visit) => $gate->remainingSessions($visit)->isNotEmpty())
            ->values();
    }

    /**
     * Schedule block of a scan response for the session being entered.
     */
    private function sessionPayload(VisitRequest $visitRequest, VisitSchedule $session): array
    {
        $date = Carbon::parse($session->schedule_date);
        $start = Carbon::parse($session->time_slot_start)->format('h:i A');
        $end = Carbon::parse($session->time_slot_end)->format('h:i A');
        $isReentry = $visitRequest->checkins()->exists();
        $name = ucfirst($this->gate->sessionName($session));

        return [
            'date' => $date->format('M d, Y'),
            'start' => $start,
            'end' => $end,
            'session' => $name,
            'is_reentry' => $isReentry,
            'whole_day' => $visitRequest->sessions()->count() > 1,
            'display' => $date->format('M d, Y').' · '.$start.' - '.$end
                .' · '.$name.($isReentry ? ' (re-entry)' : ''),
        ];
    }

    /**
     * Visit details shown on the scan result panels (check-in and
     * check-out): relationship, PDL eligibility, housing, classification
     * and visit status.
     */
    private function visitDetails(VisitRequest $visitRequest, string $visitStatus): array
    {
        $visitRequest->loadMissing(['relationship', 'pdl.activeRestrictions']);
        $pdl = $visitRequest->pdl;

        $pdlStatus = match (true) {
            ! $pdl => '—',
            $pdl->custody_status !== 'active' => ucfirst((string) $pdl->custody_status),
            $pdl->activeRestrictions->isNotEmpty() => 'Restricted',
            default => 'Eligible',
        };

        return [
            'visitor_id' => $visitRequest->visitor?->visitor_id,
            'visitor_verified' => (bool) $visitRequest->visitor?->isVerified(),
            'relationship' => $visitRequest->relationship?->relationshipLabel() ?? '—',
            'pdl_status' => $pdlStatus,
            'building' => $pdl?->cell_block ?: '—',
            'classification' => match ($pdl?->classification) {
                'drug_related' => 'Drug-related',
                'non_drug_related' => 'Non-drug-related',
                default => '—',
            },
            'visit_status' => $visitStatus,
        ];
    }

    /**
     * Decodes a `data:image/jpeg|png;base64,...` ID photo. Returns the image
     * bytes and file extension, or null when it is not a valid JPG/PNG
     * under 5 MB.
     *
     * @return array{contents: string, extension: string}|null
     */
    private function decodeIdPhoto(string $dataUrl): ?array
    {
        if (! preg_match('#^data:image/(jpeg|png);base64,(.+)$#s', $dataUrl, $m)) {
            return null;
        }

        $contents = base64_decode($m[2], true);

        if ($contents === false || strlen($contents) > 5 * 1024 * 1024) {
            return null;
        }

        $info = @getimagesizefromstring($contents);

        if (! $info || ! in_array($info['mime'] ?? null, ['image/jpeg', 'image/png'], true)) {
            return null;
        }

        return [
            'contents' => $contents,
            'extension' => $info['mime'] === 'image/png' ? 'png' : 'jpg',
        ];
    }

    /**
     * The ID a visitor surrendered at check-in: the newest record of that
     * type (a replacement ID is still pending review), as label + number.
     *
     * @return array{type: ?string, type_label: string, number: string}
     */
    private function surrenderedId(VisitCheckin $checkin): array
    {
        $type = $checkin->id_surrendered_type;
        $visitorId = $checkin->visitRequest?->visitor_id;

        $record = $type && $visitorId
            ? VisitorId::where('visitor_id', $visitorId)
                ->where('id_type', $type)
                ->orderByDesc('uploaded_at')
                ->first()
            : null;

        return [
            'type' => $type,
            'type_label' => $type ? (new VisitorId(['id_type' => $type]))->typeLabel() : '—',
            'number' => $record?->id_number ?? '—',
        ];
    }

    /**
     * Visitor's verified IDs, newest first, for the scan result panels.
     */
    private function registeredIds(?int $visitorId): \Illuminate\Support\Collection
    {
        return VisitorId::where('visitor_id', $visitorId)
            ->whereIn('verification_status', ['verified', 'approved'])
            ->orderByDesc('verified_at')
            ->get()
            ->map(fn (VisitorId $id) => [
                'id' => $id->visitor_id_doc_id,
                'type' => $id->id_type,
                'type_label' => $id->typeLabel(),
                'number' => $id->id_number,
                'status' => $id->verification_status,
                'reason' => $id->reason,
            ])
            ->values();
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
    public function scanQr(ScanQrRequest $request): JsonResponse
    {
        // Cleaned and validated by ScanQrRequest.
        $data = $request->validated();

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
                $checkin = $visitRequest ? $this->gate->activeCheckin($visitRequest) : null;

                if (! $visitRequest || ! $checkin || $checkin->status !== 'checked_in') {
                    return response()->json([
                        'success' => false,
                        'message' => 'This visitor is not currently checked in.',
                        'status' => $checkin?->status,
                    ], 422);
                }

                $registeredIds = $this->registeredIds($visitRequest->visitor?->visitor_id);
                $session = VisitSchedule::find($checkin->schedule_id) ?? $visitRequest->schedule;

                return response()->json([
                    'success' => true,
                    'message' => 'Checked-in visitor found.',
                    'visit_request_id' => $visitRequest->visit_request_id,
                    'checkin_id' => $checkin->checkin_id,
                    'status_label' => 'Inside Facility',
                    'visitor' => [
                        'visitor_id' => $visitRequest->visitor?->visitor_id,
                        'name' => $visitRequest->visitor?->full_name ?? '—',
                        'pdl' => $visitRequest->pdl?->full_name ?? '—',
                        'pdl_number' => $visitRequest->pdl?->pdl_number ?? '—',
                    ],
                    'details' => $this->visitDetails($visitRequest, 'Checked in'),
                    'schedule' => $session ? $this->sessionPayload($visitRequest, $session) : null,
                    'registered_ids' => $registeredIds,
                    'surrendered_id' => $this->surrenderedId($checkin),
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
            if ($this->gate->activeCheckin($visitRequest)) {
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
             * The session this entry is for (morning, or the afternoon
             * re-entry of a whole-day visit).
             */
            [$session, $sessionError] = $this->gate->enterableSession($visitRequest);

            if (! $session) {
                return response()->json([
                    'success' => false,
                    'message' => $sessionError,
                ], 422);
            }

            /*
             * Get visitor's registered IDs.
             */
            $registeredIds = $this->registeredIds($visitRequest->visitor?->visitor_id);

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

                'schedule' =>
                    $this->sessionPayload($visitRequest, $session),

                'details' =>
                    $this->visitDetails($visitRequest, 'Confirmed'),

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
                    'Could not process the QR code. Please scan again, or use Manual Check-In if it keeps failing.',
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
        QrCheckInRequest $request
    ): JsonResponse {

        // Cleaned and validated by QrCheckInRequest (a different ID requires its type, number and reason).
        $data = $request->validated();

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return response()->json([
                'success' => false,
                'message' => self::NO_STAFF_PROFILE_MESSAGE,
            ], 403);
        }

        $newIdPhoto = null;

        if ($data['id_match_status'] === 'replaced' && ! empty($data['new_id_photo'])) {
            $newIdPhoto = $this->decodeIdPhoto($data['new_id_photo']);

            if (! $newIdPhoto) {
                return response()->json([
                    'success' => false,
                    'message' => 'The ID photo could not be read. Retake it as a JPG or PNG image under 5 MB, or remove it.',
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
            if ($this->gate->activeCheckin($visitRequest)) {
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

            [$session, $sessionError] = $this->gate->enterableSession($visitRequest);

            if (! $session) {
                return response()->json([
                    'success' => false,
                    'message' => $sessionError,
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

                $photoPath = $newIdPhoto
                    ? 'visitor-ids/'.Str::random(40).'.'.$newIdPhoto['extension']
                    : null;

                if ($photoPath) {
                    Storage::disk('public')->put($photoPath, $newIdPhoto['contents']);
                }

                VisitorId::create([
                    'visitor_id' =>
                        $visitRequest->visitor?->visitor_id,

                    'id_type' =>
                        $data['new_id_type'],

                    'id_number' =>
                        $data['new_id_number'],

                    'file_path' =>
                        $photoPath,

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

                    'schedule_id' =>
                        $session->schedule_id,
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
                        $staffId,

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
             * Mark QR as used once no later session is left to enter.
             * A whole-day visitor keeps the same QR for the afternoon
             * re-entry.
             */
            if ($this->gate->isLastSession($visitRequest, $session)) {
                $qr->update([
                    'status' => 'used',
                ]);
            }

            $sessionName = $this->gate->sessionName($session);

            /*
             * Audit trail.
             */
            try {

                AuditLog::record(
                    'check_in',
                    'visit_checkins',
                    $checkin->checkin_id,

                    "QR checked in visitor {$visitRequest->visitor?->full_name} for visit request #{$visitRequest->visit_request_id} ({$sessionName})",

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

                    'session' =>
                        ucfirst($sessionName),
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
                    'Could not complete the check-in. Please try again, or contact the system administrator if it keeps happening.',
            ], 500);
        }
    }

    /**
     * ---------------------------------------------------------------
     * MANUAL CHECK-IN BACKUP
     * ---------------------------------------------------------------
     */
    public function checkIn(
        ManualCheckInRequest $request,
        VisitRequest $visitRequest
    ): RedirectResponse {

        // Cleaned and validated by ManualCheckInRequest.
        $data = $request->validated();

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
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
            if ($this->gate->activeCheckin($visitRequest)) {
                return back()->with(
                    'error',
                    'This visitor is already checked in.'
                );
            }

            [$session, $sessionError] = $this->gate->enterableSession($visitRequest);

            if (! $session) {
                return back()->with(
                    'error',
                    $sessionError
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

                    'schedule_id' =>
                        $session->schedule_id,
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
                        $staffId,

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
             * Mark QR as used once no later session is left to enter.
             */
            if (
                $qr->status !== 'used' &&
                $this->gate->isLastSession($visitRequest, $session)
            ) {
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
                'Could not check this visitor in. Please try again, or contact the system administrator if it keeps happening.'
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

                "Manually checked in visitor {$visitRequest->visitor?->full_name} for visit request #{$visitRequest->visit_request_id} ({$this->gate->sessionName($session)}). "
                    .'Surrendered '.(new VisitorId(['id_type' => $data['id_surrendered_type']]))->typeLabel()." {$data['id_number']}. Reason: {$data['override_reason']}",

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

        $staffId = $this->actingStaffId();
        if ($staffId === null) {
            return back()->with('error', self::NO_STAFF_PROFILE_MESSAGE);
        }

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

            $visitRequest = $checkin->visitRequest;

            /*
             * Leaving with a later session of the visit still ahead is
             * the midday exit: the visit stays confirmed and the same QR
             * admits the visitor again for the afternoon session.
             */
            $temporaryExit = $visitRequest
                && $this->gate->hasReturnSession($visitRequest, $checkin);

            $checkin->update([
                'check_out_time' =>
                    now(),

                'check_out_officer_id' =>
                    $staffId,

                /*
                 * Return the surrendered ID.
                 */
                'id_returned_time' =>
                    now(),

                'status' =>
                    'checked_out',
            ]);

            /*
             * Final check-out completes the visit and retires its QR.
             */
            if ($visitRequest && ! $temporaryExit) {
                $visitRequest->update([
                    'status' =>
                        'completed',
                ]);

                $visitRequest->qrCode()
                    ->where('status', 'active')
                    ->update(['status' => 'used']);
            }

        } catch (\Throwable $e) {

            Log::error(
                'Check-out failed: ' .
                $e->getMessage()
            );

            return back()->with(
                'error',
                'Could not check this visitor out. Please try again, or contact the system administrator if it keeps happening.'
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

                $temporaryExit
                    ? "Visitor {$visitRequest->visitor?->full_name} exited for the midday break (visit request #{$visitRequest->visit_request_id} continues in the afternoon session)"
                    : "Checked out visitor {$checkin->visitRequest?->visitor?->full_name}",

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
                $temporaryExit
                    ? 'Visitor exited for the midday break. The same QR pass admits them for the afternoon session.'
                    : 'Visitor checked out successfully.'
            );
    }
}