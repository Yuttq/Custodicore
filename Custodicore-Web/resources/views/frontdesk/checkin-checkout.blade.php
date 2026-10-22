@extends('layouts.frontdesk')

@section('title', 'Check-In / Check-Out')

@section('content')

{{-- Search lists for the manual check-in / check-out backups. --}}
<script>
    window.frontDeskExpectedVisitors = @json($manualCheckinVisitors);
    window.frontDeskInsideVisitors = @json($manualCheckoutVisitors);
</script>

<div class="space-y-lg">

    {{-- ============================================================
        MAIN OPERATION SWITCHER
    ============================================================ --}}
    <div class="cc-card p-sm">

        <div class="grid grid-cols-2 gap-sm">

            <button
                type="button"
                id="checkInModeBtn"
                class="cc-btn-primary w-full justify-center">


                <span>Check-In</span>

            </button>

            <button
                type="button"
                id="checkOutModeBtn"
                class="cc-btn-secondary w-full justify-center">


                <span>Check-Out</span>

            </button>

        </div>

    </div>


    {{-- ============================================================
        CHECK-IN WORKSPACE
    ============================================================ --}}
    <section id="checkInWorkspace">

        {{-- ========================================================
            CHECK-IN HEADER + PROGRESS
        ========================================================= --}}
        <div class="cc-card">

            <div class="flex items-center gap-sm">


                <div>

                    <p class="text-card-title">
                        Visitor Check-In
                    </p>

                    <p class="text-metadata text-text-secondary">
                        Process the visitor's arrival step by step.
                    </p>

                </div>

            </div>


            {{-- PROGRESS --}}
            <div class="mt-lg border-t border-border pt-lg">

                <div class="flex items-start">

                    <div id="checkinProgress1" class="checkin-progress-step min-w-0 flex-1 text-center">
                        <div class="checkin-progress-circle mx-auto flex h-9 w-9 items-center justify-center rounded-full bg-primary-navy text-sm font-semibold text-white">1</div>
                        <p class="mt-xs text-status-label font-semibold text-text-primary">Scan QR</p>
                    </div>

                    <span id="checkinConnector1" class="mt-4 h-0.5 flex-1 bg-border"></span>

                    <div id="checkinProgress2" class="checkin-progress-step min-w-0 flex-1 text-center">
                        <div class="checkin-progress-circle mx-auto flex h-9 w-9 items-center justify-center rounded-full border border-border bg-surface text-sm text-text-secondary">2</div>
                        <p class="mt-xs text-status-label text-text-secondary">Visitor Info</p>
                    </div>

                    <span id="checkinConnector2" class="mt-4 h-0.5 flex-1 bg-border"></span>

                    <div id="checkinProgress3" class="checkin-progress-step min-w-0 flex-1 text-center">
                        <div class="checkin-progress-circle mx-auto flex h-9 w-9 items-center justify-center rounded-full border border-border bg-surface text-sm text-text-secondary">3</div>
                        <p class="mt-xs text-status-label text-text-secondary">Verify ID</p>
                    </div>

                    <span id="checkinConnector3" class="mt-4 h-0.5 flex-1 bg-border"></span>

                    <div id="checkinProgress4" class="checkin-progress-step min-w-0 flex-1 text-center">
                        <div class="checkin-progress-circle mx-auto flex h-9 w-9 items-center justify-center rounded-full border border-border bg-surface text-sm text-text-secondary">4</div>
                        <p class="mt-xs text-status-label text-text-secondary">Summary</p>
                    </div>

                </div>

            </div>

        </div>


        {{-- ========================================================
            CHECK-IN STEP 1 — SCAN QR
        ========================================================= --}}
        <div
            id="checkinStep1"
            class="checkin-step mt-lg">

            <div class="cc-card">

                <div class="flex flex-col gap-md sm:flex-row sm:items-center sm:justify-between">

                    <div>

                        <p class="text-card-title">
                            Scan Visitor QR Code
                        </p>

                        <p class="text-metadata text-text-secondary">
                            Scan the visitor's QR code to retrieve the approved visit.
                        </p>

                    </div>


                    <div class="flex gap-sm">

                        <button
                            type="button"
                            id="startScannerBtn"
                            class="cc-btn-primary">


                            Start QR Scanner

                        </button>


                        <button
                            type="button"
                            id="stopScannerBtn"
                            class="cc-btn-secondary hidden">

                            Stop Scanner

                        </button>

                    </div>

                </div>

                {{-- SCANNER --}}
                <div
                    id="scannerSection"
                    class="hidden mt-lg">

                    <div class="grid grid-cols-1 gap-lg lg:grid-cols-2">

                        <div>

                            <div
                                id="qr-reader"
                                class="min-h-[280px] overflow-hidden rounded-xl border border-primary-blue bg-background">
                            </div>


                            <div
                                id="scannerStatus"
                                class="mt-md rounded-lg border border-border bg-surface p-md text-center text-metadata text-text-secondary">

                                Camera is ready. Please present the visitor's QR code.

                            </div>

                            <div
                                id="scannerError"
                                role="alert"
                                class="mt-md hidden rounded-lg border border-red-200 bg-red-50 p-md text-center text-metadata font-semibold text-red-700">
                            </div>

                        </div>


                        <div class="rounded-xl border border-border bg-background p-lg">

                            <p class="text-card-title">
                                Scanning Tips
                            </p>


                            <ul class="mt-md space-y-sm">

                                <li class="flex gap-sm text-metadata text-text-secondary">

                                    <span class="text-primary-teal">
                                        ✓
                                    </span>

                                    <span>
                                        Keep the QR code clear and readable.
                                    </span>

                                </li>


                                <li class="flex gap-sm text-metadata text-text-secondary">

                                    <span class="text-primary-teal">
                                        ✓
                                    </span>

                                    <span>
                                        Hold the code inside the camera frame.
                                    </span>

                                </li>


                                <li class="flex gap-sm text-metadata text-text-secondary">

                                    <span class="text-primary-teal">
                                        ✓
                                    </span>

                                    <span>
                                        Ensure good lighting.
                                    </span>

                                </li>


                                <li class="flex gap-sm text-metadata text-text-secondary">

                                    <span class="text-primary-teal">
                                        ✓
                                    </span>

                                    <span>
                                        Use Manual Check-In if scanning fails.
                                    </span>

                                </li>

                            </ul>

                        </div>

                    </div>

                </div>

            </div>


            {{-- ====================================================
                MANUAL CHECK-IN
            ===================================================== --}}
            <div class="cc-card mt-lg">

                <div class="flex flex-col gap-sm sm:flex-row sm:items-center sm:justify-between">

                    <div>

                        <p class="text-card-title">
                            Manual Check-In Backup
                        </p>

                        <p class="text-metadata text-text-secondary">
                            Use this option when the visitor's QR code cannot be scanned.
                        </p>

                    </div>


                    <button
                        type="button"
                        id="manualBackupBtn"
                        class="cc-btn-secondary">

                        Manual Check-In

                    </button>

                </div>


                <div
                    id="manualBackupPanel"
                    class="hidden mt-lg border-t border-border pt-lg">

                    @include('frontdesk.partials.manual-steps', ['wizard' => 'mci', 'steps' => ['Find Visitor', 'Visitor Info', 'Verify ID', 'Confirm']])


                    <label
                        for="manualVisitorSearch"
                        class="text-section-label text-text-secondary" data-mci="1">

                        SEARCH VISITOR

                    </label>


                    <div class="relative mt-xs" data-mci="1">

                        <input
                            type="text"
                            id="manualVisitorSearch"
                            autocomplete="off"
                            placeholder="Search by full name or Visitor ID number"
                            class="w-full rounded-lg border border-border bg-surface px-md py-sm text-body">

                        <div
                            id="manualSearchLoading"
                            class="pointer-events-none absolute right-md top-1/2 hidden -translate-y-1/2 text-metadata text-text-secondary">

                            Searching...

                        </div>

                    </div>


                    <div
                        id="manualSearchResults"
                        class="mt-sm hidden overflow-hidden rounded-lg border border-border bg-surface" data-mci="1">
                    </div>


                    <div
                        id="selectedManualVisitor"
                        class="hidden mt-md rounded-lg border border-border bg-background p-md" data-mci="2">

                        <div class="flex flex-col gap-md md:flex-row md:items-start md:justify-between">

                            <div>

                                <p class="text-section-label text-text-secondary">
                                    SELECTED VISITOR
                                </p>

                                <p
                                    id="selectedVisitorName"
                                    class="mt-xs text-lg font-semibold">
                                    —
                                </p>

                                <p
                                    id="selectedVisitorId"
                                    class="mt-xs text-metadata text-text-secondary">
                                    Visitor ID: —
                                </p>

                                <p
                                    id="selectedVisitorDetails"
                                    class="mt-xs text-metadata text-text-secondary">
                                    —
                                </p>

                                <p
                                    id="selectedVisitorSchedule"
                                    class="mt-xs text-metadata text-text-secondary">
                                    —
                                </p>

                            </div>


                            <button
                                type="button"
                                id="changeManualVisitorBtn"
                                class="cc-btn-secondary">

                                Change Visitor

                            </button>

                        </div>

                    </div>


                    <div class="mt-md flex justify-end" data-mci="2">
                        <button type="button" class="cc-btn-primary" data-wizard-next="mci">Next: Verify ID</button>
                    </div>

                    {{-- MANUAL VERIFICATION --}}
                    <div
                        id="manualVerificationSection"
                        class="hidden">

                        <div class="mt-lg">

                            <p class="text-card-title" data-mci="3">
                                Manual ID Verification
                            </p>


                            <div class="mt-md rounded-lg border border-border bg-background p-md" data-mci="3">

                                <div class="grid grid-cols-1 gap-md md:grid-cols-3">

                                    <div>

                                        <p class="text-section-label text-text-secondary">
                                            ID TYPE
                                        </p>

                                        <p
                                            id="manualRegisteredIdType"
                                            class="mt-xs text-body font-semibold">
                                            —
                                        </p>

                                    </div>


                                    <div>

                                        <p class="text-section-label text-text-secondary">
                                            ID NUMBER
                                        </p>

                                        <p
                                            id="manualRegisteredIdNumber"
                                            class="mt-xs text-body font-semibold">
                                            —
                                        </p>

                                    </div>


                                    <div>

                                        <p class="text-section-label text-text-secondary">
                                            STATUS
                                        </p>

                                        <p
                                            id="manualRegisteredIdStatus"
                                            class="mt-xs text-body font-semibold">
                                            —
                                        </p>

                                    </div>

                                </div>

                            </div>


                            <div class="mt-md" data-mci="3">

                                <label
                                    for="manualIdType"
                                    class="text-section-label text-text-secondary">

                                    ID TYPE SURRENDERED

                                </label>

                                <select
                                    id="manualIdType"
                                    class="mt-xs w-full rounded-lg border border-border bg-surface px-md py-sm text-body">
                                    <option value="">Select ID type</option>
                                    @foreach (\App\Models\VisitorId::TYPE_LABELS as $idTypeValue => $idTypeLabel)
                                        <option value="{{ $idTypeValue }}">{{ $idTypeLabel }}</option>
                                    @endforeach
                                </select>

                            </div>


                            <div class="mt-md" data-mci="3">

                                <label
                                    for="manualIdNumber"
                                    class="text-section-label text-text-secondary">

                                    ID NUMBER

                                </label>

                                <input
                                    type="text"
                                    id="manualIdNumber"
                                    placeholder="Enter the visitor's physical ID number"
                                    maxlength="50"
                                    autocomplete="off"
                                    spellcheck="false"
                                    autocapitalize="characters"
                                    class="mt-xs w-full rounded-lg border border-border bg-surface px-md py-sm text-body">

                            </div>


                            <div class="mt-md" data-mci="3">

                                <label
                                    for="overrideReason"
                                    class="text-section-label text-text-secondary">

                                    REASON FOR MANUAL OVERRIDE

                                </label>

                                <textarea
                                    id="overrideReason"
                                    rows="3"
                                    minlength="10"
                                    maxlength="255"
                                    placeholder="Example: Visitor QR code could not be scanned."
                                    class="mt-xs w-full rounded-lg border border-border bg-surface px-md py-sm text-body"></textarea>

                                <p class="mt-xs text-right text-metadata text-text-secondary" aria-live="polite"><span id="overrideReasonCount">0</span>/255 · at least 10 characters</p>

                            </div>


                            <div class="mt-md rounded-lg border border-border bg-background p-md" data-mci="3">

                                <label class="flex items-start gap-sm">

                                    <input
                                        type="checkbox"
                                        id="manualIdVerified"
                                        class="mt-1">

                                    <span class="text-body">
                                        I have physically verified the visitor's ID and confirmed that it belongs to the selected visitor.
                                    </span>

                                </label>

                            </div>


                            <p id="manualStepError" role="alert" class="mt-md hidden text-body font-semibold text-danger" data-mci="3"></p>
                            <div class="mt-md flex justify-between" data-mci="3">
                                <button type="button" class="cc-btn-secondary" data-wizard-back="mci">Back</button>
                                <button type="button" class="cc-btn-primary" data-wizard-next="mci">Next: Review</button>
                            </div>

                            {{-- Confirm: what will be recorded. --}}
                            <div class="rounded-lg border border-border bg-background p-md" data-mci="4">
                                <p class="text-card-title">Review &amp; Confirm</p>
                                <dl class="mt-md grid grid-cols-1 gap-md text-body md:grid-cols-2">
                                    <div><dt class="text-section-label text-text-secondary">VISITOR</dt><dd id="mciSumVisitor" class="mt-xs font-semibold">—</dd></div>
                                    <div><dt class="text-section-label text-text-secondary">VISIT</dt><dd id="mciSumVisit" class="mt-xs font-semibold">—</dd></div>
                                    <div><dt class="text-section-label text-text-secondary">ID SURRENDERED</dt><dd id="mciSumId" class="mt-xs font-semibold">—</dd></div>
                                    <div><dt class="text-section-label text-text-secondary">REASON FOR MANUAL CHECK-IN</dt><dd id="mciSumReason" class="mt-xs font-semibold">—</dd></div>
                                </dl>
                            </div>

                            <div
                                id="manualCheckInError"
                                role="alert"
                                class="mt-md hidden rounded-lg border border-red-200 bg-red-50 p-md text-body font-semibold text-red-700" data-mci="4">
                            </div>

                            <div class="mt-md flex justify-between" data-mci="4">
                                <button type="button" class="cc-btn-secondary" data-wizard-back="mci">Back</button>

                                <button
                                    type="button"
                                    id="manualCheckInBtn"
                                    class="cc-btn-primary">

                                    Verify & Check In Manually

                                </button>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>


        {{-- ========================================================
            CHECK-IN STEP 2
        ========================================================= --}}
        <div
            id="checkinStep2"
            class="checkin-step hidden mt-lg">

            <div class="cc-card">

                <div class="flex items-center gap-sm">

                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-blue text-sm font-bold text-white">
                        2
                    </span>

                    <div>

                        <p class="text-card-title">
                            Visitor Information
                        </p>

                        <p class="text-metadata text-text-secondary">
                            Review the information retrieved from the approved visit.
                        </p>

                    </div>

                </div>


                <div
                    id="scanResult"
                    class="mt-lg">

                    <div class="grid grid-cols-1 gap-lg lg:grid-cols-2">

                        <div class="rounded-xl border border-border bg-background p-md">

                            <p class="text-section-label text-text-secondary">
                                VISITOR INFORMATION
                            </p>

                            <p
                                id="visitorName"
                                class="mt-md text-lg font-semibold">
                                —
                            </p>

                            <p
                                id="visitorDetails"
                                class="mt-xs text-metadata text-text-secondary">
                                —
                            </p>


                            <div class="mt-lg grid grid-cols-2 gap-md">

                                <div>

                                    <p class="text-section-label text-text-secondary">
                                        VISITOR ID
                                    </p>

                                    <p
                                        id="resultVisitor"
                                        class="mt-xs text-body font-semibold">
                                        —
                                    </p>

                                </div>


                                <div>

                                    <p class="text-section-label text-text-secondary">
                                        RELATIONSHIP
                                    </p>

                                    <p
                                        id="resultRelationship"
                                        class="mt-xs text-body font-semibold">
                                        —
                                    </p>

                                </div>

                            </div>

                        </div>


                        <div class="rounded-xl border border-border bg-background p-md">

                            <p class="text-section-label text-text-secondary">
                                PDL INFORMATION
                            </p>

                            <p
                                id="resultPdl"
                                class="mt-md text-lg font-semibold">
                                —
                            </p>

                            <p class="mt-xs text-metadata text-text-secondary">
                                Person Deprived of Liberty
                            </p>


                            <div class="mt-lg grid grid-cols-2 gap-md">

                                <div>

                                    <p class="text-section-label text-text-secondary">
                                        PDL NUMBER
                                    </p>

                                    <p
                                        id="resultPdlNumber"
                                        class="mt-xs text-body font-semibold">
                                        —
                                    </p>

                                </div>


                                <div>

                                    <p class="text-section-label text-text-secondary">
                                        STATUS
                                    </p>

                                    <p
                                        id="resultPdlStatus"
                                        class="mt-xs text-body font-semibold text-primary-teal">
                                        Eligible
                                    </p>

                                </div>

                            </div>

                        </div>

                    </div>


                    <div class="mt-lg rounded-xl border border-border bg-background p-md">

                        <p class="text-section-label text-text-secondary">
                            APPROVED VISIT SCHEDULE
                        </p>

                        <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-2 lg:grid-cols-4">

                            <div>
                                <p class="text-section-label text-text-secondary">DATE</p>
                                <p id="resultSchedule" class="mt-xs text-body font-semibold">—</p>
                            </div>

                            <div>
                                <p class="text-section-label text-text-secondary">TIME</p>
                                <p id="resultScheduleTime" class="mt-xs text-body font-semibold">—</p>
                            </div>

                            <div>
                                <p class="text-section-label text-text-secondary">BUILDING</p>
                                <p id="resultScheduleBuilding" class="mt-xs text-body font-semibold">—</p>
                            </div>

                            <div>
                                <p class="text-section-label text-text-secondary">CLASSIFICATION</p>
                                <p id="resultScheduleClassification" class="mt-xs text-body font-semibold">—</p>
                            </div>

                        </div>

                        <div class="mt-md">
                            <p class="text-section-label text-text-secondary">VISIT STATUS</p>
                            <p id="resultVisitStatus" class="mt-xs text-body font-semibold text-primary-teal">Approved</p>
                        </div>

                    </div>


                    <div class="mt-lg rounded-xl border border-border bg-background p-md">

                        <p class="text-section-label text-text-secondary">
                            REGISTERED ID
                        </p>

                        <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-2">

                            <div>

                                <p class="text-section-label text-text-secondary">
                                    ID TYPE
                                </p>

                                <p
                                    id="registeredIdType"
                                    class="mt-xs text-body font-semibold">
                                    —
                                </p>

                            </div>


                            <div>

                                <p class="text-section-label text-text-secondary">
                                    ID NUMBER
                                </p>

                                <p
                                    id="registeredIdNumber"
                                    class="mt-xs text-body font-semibold">
                                    —
                                </p>

                            </div>

                        </div>


                        <div class="mt-md">

                            <p class="text-section-label text-text-secondary">
                                ID STATUS
                            </p>

                            <p
                                id="registeredIdStatus"
                                class="mt-xs text-body font-semibold text-primary-teal">
                                —
                            </p>

                        </div>

                    </div>


                    <div class="mt-lg flex flex-col-reverse gap-sm border-t border-border pt-lg sm:flex-row sm:justify-between">

                        <button type="button" id="backToScanBtn" class="cc-btn-secondary">
                            Back
                        </button>

                        <button type="button" id="visitorInfoNextBtn" class="cc-btn-primary">
                            Continue to ID Verification
                        </button>

                    </div>

                </div>

            </div>

        </div>


        {{-- ========================================================
            CHECK-IN STEP 3 — ID VERIFICATION
        ========================================================= --}}
        <div id="checkinStep3" class="checkin-step hidden mt-lg">
            <div class="cc-card">
                <div class="flex items-center gap-sm">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-blue text-sm font-bold text-white">3</span>
                    <div>
                        <p class="text-card-title">ID Verification</p>
                        <p class="text-metadata text-text-secondary">Compare the presented physical ID with the registered ID before check-in.</p>
                    </div>
                </div>

                <div class="mt-lg rounded-xl border border-border bg-background p-md">
                    <p class="text-section-label text-text-secondary">REGISTERED ID</p>
                    <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-2">
                        <div>
                            <p class="text-section-label text-text-secondary">ID TYPE</p>
                            <p id="verifyRegisteredIdType" class="mt-xs text-body font-semibold">—</p>
                        </div>
                        <div>
                            <p class="text-section-label text-text-secondary">ID NUMBER</p>
                            <p id="verifyRegisteredIdNumber" class="mt-xs text-body font-semibold">—</p>
                        </div>
                    </div>
                </div>

                <div class="mt-lg rounded-xl border border-border bg-background p-md">
                    <p class="text-card-title">Does the presented ID match the registered ID?</p>
                    <p class="mt-xs text-metadata text-text-secondary">Select the result after physically checking the visitor's identification.</p>

                    <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-2">
                        <button type="button" id="idMatchesBtn" aria-pressed="false" class="group cursor-pointer rounded-xl border-2 border-border bg-surface p-md text-left transition-all duration-150 hover:border-primary-blue hover:bg-background focus:outline-none focus:ring-2 focus:ring-primary-blue focus:ring-offset-2">
                            <div class="flex items-start gap-sm"><span id="idMatchesIndicator" class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-border text-xs font-bold text-white"></span><div><p class="text-body font-semibold">ID Matches Registered ID</p>
                            <p class="mt-xs text-metadata text-text-secondary">The ID type and number correspond to the registered record.</p></div></div>
                        </button>
                        <button type="button" id="idDoesNotMatchBtn" aria-pressed="false" class="group cursor-pointer rounded-xl border-2 border-border bg-surface p-md text-left transition-all duration-150 hover:border-primary-blue hover:bg-background focus:outline-none focus:ring-2 focus:ring-primary-blue focus:ring-offset-2">
                            <div class="flex items-start gap-sm"><span id="idDoesNotMatchIndicator" class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-border text-xs font-bold text-white"></span><div><p class="text-body font-semibold">ID Does Not Match</p>
                            <p class="mt-xs text-metadata text-text-secondary">The visitor presented a different identification document.</p></div></div>
                        </button>
                    </div>
                </div>

                <div id="newIdSection" class="hidden mt-lg rounded-xl border border-border bg-background p-md">
                    <p class="text-card-title">Different ID Presented</p>
                    <p class="mt-xs text-metadata text-text-secondary">Record the ID actually presented without deleting the previous registered ID.</p>

                    <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-2">
                        <div>
                            <label for="new_id_type" class="text-section-label text-text-secondary">NEW ID TYPE</label>
                            <select id="new_id_type" class="mt-xs w-full rounded-lg border border-border bg-surface px-md py-sm text-body">
                                <option value="">Select ID type</option>
                                @foreach (\App\Models\VisitorId::TYPE_LABELS as $idTypeValue => $idTypeLabel)
                                    <option value="{{ $idTypeValue }}">{{ $idTypeLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="new_id_number" class="text-section-label text-text-secondary">NEW ID NUMBER</label>
                            <input type="text" id="new_id_number" placeholder="e.g. 0111-2233-4455" maxlength="50" autocomplete="off" spellcheck="false" autocapitalize="characters" class="mt-xs w-full rounded-lg border border-border bg-surface px-md py-sm text-body">
                        </div>
                    </div>

                    <div class="mt-md">
                        <label for="new_id_reason" class="text-section-label text-text-secondary">REASON</label>
                        <select id="new_id_reason" class="mt-xs w-full rounded-lg border border-border bg-surface px-md py-sm text-body">
                            <option value="">Select reason</option>
                            <option value="lost">Lost / Misplaced</option>
                            <option value="expired">Expired</option>
                            <option value="damaged">Damaged</option>
                            <option value="updated">Updated / Replaced</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div id="otherReasonContainer" class="hidden mt-md">
                        <textarea id="new_id_other_reason" rows="3" maxlength="255" placeholder="Explain the reason for the new ID." class="w-full rounded-lg border border-border bg-surface px-md py-sm text-body"></textarea>
                    </div>

                    <div class="mt-md rounded-lg border border-border p-md">
                        <p class="text-section-label text-text-secondary">ID PHOTO <span class="normal-case font-normal">(optional, recommended)</span></p>
                        <p class="mt-xs text-metadata text-text-secondary">Helps the Records Officer verify this ID later. Hold the ID flat and close to the camera.</p>

                        <div id="newIdPhotoCamera" class="hidden mt-md">
                            <video id="newIdPhotoVideo" autoplay playsinline muted class="w-full max-w-md rounded-lg border border-border bg-black"></video>
                        </div>

                        <div id="newIdPhotoPreview" class="hidden mt-md">
                            <img id="newIdPhotoImage" alt="Captured photo of the new ID" class="w-full max-w-md rounded-lg border border-border">
                            <p class="mt-xs text-metadata font-semibold text-green-700">Photo attached</p>
                        </div>

                        <div class="mt-md flex flex-wrap gap-sm">
                            <button type="button" id="newIdPhotoStartBtn" class="cc-btn-secondary">Take Photo</button>
                            <button type="button" id="newIdPhotoCaptureBtn" class="hidden cc-btn-primary">Capture</button>
                            <button type="button" id="newIdPhotoCancelBtn" class="hidden cc-btn-secondary">Cancel</button>
                            <label class="cc-btn-secondary cursor-pointer" id="newIdPhotoUploadLabel">
                                Upload Photo
                                <input type="file" id="newIdPhotoFile" accept="image/jpeg,image/png" class="hidden">
                            </label>
                            <button type="button" id="newIdPhotoRemoveBtn" class="hidden cc-btn-secondary">Remove Photo</button>
                        </div>
                    </div>

                    <div
                        id="newIdError"
                        role="alert"
                        class="mt-md hidden rounded-lg border border-red-200 bg-red-50 p-md text-body font-semibold text-red-700">
                    </div>

                    <label class="mt-md flex items-start gap-sm rounded-lg border border-border p-md">
                        <input type="checkbox" id="newIdVerified" class="mt-1 h-4 w-4">
                        <span class="text-body">I have physically verified the new ID and confirmed that it belongs to the visitor.</span>
                    </label>

                    <div class="mt-md">
                        <button type="button" id="saveNewIdBtn" class="cc-btn-secondary">Verify & Add New ID</button>
                    </div>
                </div>

                <div class="mt-lg rounded-xl border border-border bg-background p-md">
                    <label class="flex items-start gap-sm">
                        <input type="checkbox" id="idSurrendered" class="mt-1 h-4 w-4">
                        <span>
                            <span class="block text-body font-semibold">ID has been physically surrendered.</span>
                            <span class="mt-xs block text-metadata text-text-secondary">Confirm that the physical identification card presented by the visitor is now held at the gate.</span>
                        </span>
                    </label>
                </div>

                <div class="mt-lg flex flex-col-reverse gap-sm border-t border-border pt-lg sm:flex-row sm:justify-between">
                    <button type="button" id="backToVisitorInfoBtn" class="cc-btn-secondary">Back</button>
                    <button type="button" id="continueToSummaryBtn" class="cc-btn-primary">
                        Continue to Summary
                    </button>
                </div>
            </div>
        </div>

        {{-- ========================================================
            CHECK-IN STEP 4 — SUMMARY
        ========================================================= --}}
        <div id="checkinStep4" class="checkin-step hidden mt-lg">
            <div class="cc-card">
                <div class="flex items-center gap-sm">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-blue text-sm font-bold text-white">4</span>
                    <div>
                        <p class="text-card-title">Check-In Summary</p>
                        <p class="text-metadata text-text-secondary">Review all visit, visitor, PDL, and ID details before confirming check-in.</p>
                    </div>
                </div>

                <div class="mt-lg grid grid-cols-1 gap-lg lg:grid-cols-2">
                    <div class="rounded-xl border border-border bg-background p-md">
                        <p class="text-section-label text-text-secondary">VISITOR</p>
                        <p id="summaryVisitor" class="mt-md text-lg font-semibold">—</p>
                        <div class="mt-lg grid grid-cols-2 gap-md">
                            <div><p class="text-section-label text-text-secondary">VISITOR ID</p><p id="summaryVisitorId" class="mt-xs text-body font-semibold">—</p></div>
                            <div><p class="text-section-label text-text-secondary">RELATIONSHIP</p><p id="summaryRelationship" class="mt-xs text-body font-semibold">—</p></div>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border bg-background p-md">
                        <p class="text-section-label text-text-secondary">PDL</p>
                        <p id="summaryPdl" class="mt-md text-lg font-semibold">—</p>
                        <div class="mt-lg grid grid-cols-2 gap-md">
                            <div><p class="text-section-label text-text-secondary">PDL NUMBER</p><p id="summaryPdlNumber" class="mt-xs text-body font-semibold">—</p></div>
                            <div><p class="text-section-label text-text-secondary">ELIGIBILITY</p><p id="summaryPdlEligibility" class="mt-xs text-body font-semibold">—</p></div>
                        </div>
                    </div>
                </div>

                <div class="mt-lg rounded-xl border border-border bg-background p-md">
                    <p class="text-section-label text-text-secondary">APPROVED VISIT SCHEDULE</p>
                    <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-2 lg:grid-cols-4">
                        <div><p class="text-section-label text-text-secondary">DATE</p><p id="summarySchedule" class="mt-xs text-body font-semibold">—</p></div>
                        <div><p class="text-section-label text-text-secondary">TIME</p><p id="summaryScheduleTime" class="mt-xs text-body font-semibold">—</p></div>
                        <div><p class="text-section-label text-text-secondary">BUILDING</p><p id="summaryScheduleBuilding" class="mt-xs text-body font-semibold">—</p></div>
                        <div><p class="text-section-label text-text-secondary">CLASSIFICATION</p><p id="summaryScheduleClassification" class="mt-xs text-body font-semibold">—</p></div>
                    </div>
                    <div class="mt-md"><p class="text-section-label text-text-secondary">VISIT STATUS</p><p id="summaryVisitStatus" class="mt-xs text-body font-semibold">—</p></div>
                </div>

                <div class="mt-lg rounded-xl border border-border bg-background p-md">
                    <p class="text-section-label text-text-secondary">VERIFICATION CHECKLIST</p>
                    <div id="summaryCheckOverall" role="status" class="mt-md hidden rounded-lg border p-md text-body font-semibold"></div>
                    <div class="mt-md grid grid-cols-1 gap-sm md:grid-cols-2">
                        @foreach ([
                            'Visitor' => 'Visitor identity',
                            'Schedule' => 'Schedule compliance',
                            'Pdl' => 'PDL eligibility',
                            'Id' => 'ID verification',
                        ] as $checkKey => $checkLabel)
                            <div id="summaryCheck{{ $checkKey }}" class="flex items-start gap-sm rounded-lg border border-border bg-surface p-md">
                                <span class="summary-status-icon mt-xs inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-gray-300 text-xs font-bold leading-none text-white" aria-hidden="true">–</span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-sm">
                                        <span class="text-body font-semibold text-text-primary">{{ $checkLabel }}</span>
                                        <span id="summaryCheck{{ $checkKey }}Text" class="text-right text-body font-semibold text-text-secondary">Pending</span>
                                    </div>
                                    <p id="summaryCheck{{ $checkKey }}Reason" class="mt-xs text-metadata text-text-secondary"></p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="mt-lg rounded-xl border border-border bg-background p-md">
                    <p class="text-section-label text-text-secondary">IDENTIFICATION</p>
                    <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-3">
                        <div><p class="text-section-label text-text-secondary">ID TYPE</p><p id="summaryIdType" class="mt-xs text-body font-semibold">—</p></div>
                        <div><p class="text-section-label text-text-secondary">ID NUMBER</p><p id="summaryIdNumber" class="mt-xs text-body font-semibold">—</p></div>
                        <div><p class="text-section-label text-text-secondary">STATUS</p><p id="summaryIdStatus" class="mt-xs text-body font-semibold">—</p></div>
                    </div>
                </div>

                <div
                    id="checkinSummaryError"
                    role="alert"
                    class="mt-lg hidden rounded-lg border border-red-200 bg-red-50 p-md text-body font-semibold text-red-700">
                </div>

                <div class="mt-lg flex flex-col-reverse gap-sm border-t border-border pt-lg sm:flex-row sm:justify-between">
                    <button type="button" id="backToIdVerificationBtn" class="cc-btn-secondary">Back</button>
                    <button type="button" id="confirmCheckInBtn" class="cc-btn-primary">
                        Confirm Check-In
                    </button>
                </div>
            </div>
        </div>


        {{-- ========================================================
            TODAY'S CHECK-INS
        ======================================================== --}}
        <div class="cc-card mt-lg">

            <div class="flex items-center justify-between gap-sm">
                <div>
                    <p class="text-card-title">Today's Check-Ins</p>
                    <p class="text-metadata text-text-secondary">Check-ins for today</p>
                </div>
                <span class="cc-chip cc-chip-info">{{ $todayHistory->count() }} today</span>
            </div>

            <div class="mt-md overflow-x-auto">
                    <table class="w-full text-left text-body">
                        <thead>
                            <tr class="border-b border-border text-section-label text-text-secondary">
                                <th class="py-sm pr-md">Visitor</th>
                                <th class="py-sm pr-md">PDL</th>
                                <th class="py-sm pr-md">ID Held</th>
                                <th class="py-sm pr-md">Checked In</th>
                                <th class="py-sm pr-md">Method</th>
                                <th class="py-sm pr-md">Checked In By</th>
                                <th class="py-sm pr-md">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse ($todayHistory as $checkin)
                                @php $visit = $checkin->visitRequest; @endphp
                                <tr>
                                    <td class="py-sm pr-md">
                                        <span class="font-semibold">{{ $visit?->visitor?->full_name ?? '—' }}</span>
                                        @if ($visit?->relationship)
                                            <span class="block text-metadata text-text-secondary">{{ $visit->relationship->relationshipLabel() }}</span>
                                        @endif
                                    </td>
                                    <td class="py-sm pr-md">
                                        {{ $visit?->pdl?->full_name ?? '—' }}
                                        @if ($visit?->pdl?->pdl_number)
                                            <span class="block text-metadata text-text-secondary">{{ $visit->pdl->pdl_number }}</span>
                                        @endif
                                    </td>
                                    <td class="py-sm pr-md">{{ $checkin->id_surrendered_type ? ucwords(str_replace('_', ' ', $checkin->id_surrendered_type)) : '—' }}</td>
                                    <td class="py-sm pr-md">{{ $checkin->check_in_time?->format('H:i') ?? '—' }}</td>
                                    <td class="py-sm pr-md">
                                        @if ($checkin->verification_method === 'manual_override')
                                            <span class="cc-chip cc-chip-warning" @if ($checkin->override_reason) title="{{ $checkin->override_reason }}" @endif>Manual</span>
                                        @else
                                            <span class="cc-chip cc-chip-info">QR</span>
                                        @endif
                                    </td>
                                    <td class="py-sm pr-md">{{ $checkin->checkInOfficer?->full_name ?? '—' }}</td>
                                    <td class="py-sm pr-md">
                                        @if ($checkin->isCheckedIn())
                                            <span class="font-semibold text-success">Inside</span>
                                        @else
                                            <span class="font-semibold text-text-secondary">Checked Out</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-md text-center text-text-secondary">No check-ins yet today.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

        </div>

    </section>


    {{-- ============================================================
        CHECK-OUT WORKSPACE
    ============================================================ --}}
    <section
        id="checkOutWorkspace"
        class="hidden">

        {{-- ========================================================
            CHECK-OUT HEADER + PROGRESS
        ========================================================= --}}
        <div class="cc-card">

            <div class="flex items-center gap-sm">


                <div>

                    <p class="text-card-title">
                        Visitor Check-Out
                    </p>

                    <p class="text-metadata text-text-secondary">
                        Process the visitor's departure step by step.
                    </p>

                </div>

            </div>


            {{-- PROGRESS --}}
            <div class="mt-lg border-t border-border pt-lg">

                <div class="flex items-start">

                    <div id="checkoutProgress1" class="checkout-progress-step min-w-0 flex-1 text-center">
                        <div class="checkout-progress-circle mx-auto flex h-9 w-9 items-center justify-center rounded-full bg-primary-navy text-sm font-semibold text-white">1</div>
                        <p class="mt-xs text-status-label font-semibold text-text-primary">Scan QR</p>
                    </div>

                    <span id="checkoutConnector1" class="mt-4 h-0.5 flex-1 bg-border"></span>

                    <div id="checkoutProgress2" class="checkout-progress-step min-w-0 flex-1 text-center">
                        <div class="checkout-progress-circle mx-auto flex h-9 w-9 items-center justify-center rounded-full border border-border bg-surface text-sm text-text-secondary">2</div>
                        <p class="mt-xs text-status-label text-text-secondary">Active Visit</p>
                    </div>

                    <span id="checkoutConnector2" class="mt-4 h-0.5 flex-1 bg-border"></span>

                    <div id="checkoutProgress3" class="checkout-progress-step min-w-0 flex-1 text-center">
                        <div class="checkout-progress-circle mx-auto flex h-9 w-9 items-center justify-center rounded-full border border-border bg-surface text-sm text-text-secondary">3</div>
                        <p class="mt-xs text-status-label text-text-secondary">Return ID</p>
                    </div>

                    <span id="checkoutConnector3" class="mt-4 h-0.5 flex-1 bg-border"></span>

                    <div id="checkoutProgress4" class="checkout-progress-step min-w-0 flex-1 text-center">
                        <div class="checkout-progress-circle mx-auto flex h-9 w-9 items-center justify-center rounded-full border border-border bg-surface text-sm text-text-secondary">4</div>
                        <p class="mt-xs text-status-label text-text-secondary">Summary</p>
                    </div>

                </div>

            </div>

        </div>


        {{-- ========================================================
            CHECKOUT STEP 1 — SCAN
        ========================================================= --}}

        <div
            id="checkoutStepContent1"
            class="checkout-content-step mt-lg">

            <div class="cc-card">

                <div class="flex flex-col gap-md sm:flex-row sm:items-center sm:justify-between">

                    <div>

                        <p class="text-card-title">
                            Scan Visitor QR Code
                        </p>

                        <p class="text-metadata text-text-secondary">
                            Scan the visitor's QR code to retrieve the active visit.
                        </p>

                    </div>


                    <div class="flex gap-sm">

                        <button
                            type="button"
                            id="checkoutScannerStartButton"
                            class="cc-btn-primary">


                            Start QR Scanner

                        </button>
                        <button
                            type="button"
                            id="checkoutScannerStopButton"
                            class="cc-btn-secondary hidden">

                            Stop Scanner

                        </button>

                    </div>

                </div>


                {{-- CHECKOUT SCANNER --}}
                <div
                    id="checkoutScannerSection"
                    class="hidden mt-lg">

                    <div class="grid grid-cols-1 gap-lg lg:grid-cols-2">

                        {{-- CAMERA --}}
                        <div>

                            <div
                                id="checkout-qr-reader"
                                class="min-h-[280px] overflow-hidden rounded-xl border border-primary-blue bg-background">
                            </div>


                            <div
                                id="checkoutScannerStatus"
                                class="mt-md rounded-lg border border-border bg-surface p-md text-center text-metadata text-text-secondary">

                                Camera is ready. Please present the visitor's QR code.

                            </div>

                        </div>


                        {{-- TIPS --}}
                        <div class="rounded-xl border border-border bg-background p-lg">

                            <p class="text-card-title">
                                Scanning Tips
                            </p>


                            <ul class="mt-md space-y-sm">

                                <li class="flex gap-sm text-metadata text-text-secondary">

                                    <span class="text-primary-teal">
                                        ✓
                                    </span>

                                    <span>
                                        Keep the QR code clear and readable.
                                    </span>

                                </li>


                                <li class="flex gap-sm text-metadata text-text-secondary">

                                    <span class="text-primary-teal">
                                        ✓
                                    </span>

                                    <span>
                                        Hold the code inside the camera frame.
                                    </span>

                                </li>


                                <li class="flex gap-sm text-metadata text-text-secondary">

                                    <span class="text-primary-teal">
                                        ✓
                                    </span>

                                    <span>
                                        Ensure good lighting.
                                    </span>

                                </li>


                                <li class="flex gap-sm text-metadata text-text-secondary">

                                    <span class="text-primary-teal">
                                        ✓
                                    </span>

                                    <span>
                                        Use Manual Check-Out if scanning fails.
                                    </span>

                                </li>

                            </ul>

                        </div>

                    </div>

                </div>

            </div>


            {{-- ====================================================
                MANUAL CHECK-OUT BACKUP
            ===================================================== --}}
            <div
                id="manualCheckoutCard"
                class="cc-card mt-lg">

                <div class="flex flex-col gap-sm sm:flex-row sm:items-center sm:justify-between">

                    <div>

                        <p class="text-card-title">
                            Manual Check-Out Backup
                        </p>

                        <p class="text-metadata text-text-secondary">
                            Use this option when the visitor's QR code cannot be scanned.
                        </p>

                    </div>


                    <button
                        type="button"
                        id="manualCheckoutBtn"
                        class="cc-btn-secondary">

                        Manual Check-Out

                    </button>

                </div>


                <div
                    id="manualCheckoutSection"
                    class="hidden mt-lg border-t border-border pt-lg">

                    @include('frontdesk.partials.manual-steps', ['wizard' => 'mco', 'steps' => ['Find Visitor', 'Active Visit', 'Return ID', 'Confirm']])


                    <label
                        for="manualCheckoutVisitorSearch"
                        class="text-section-label text-text-secondary" data-mco="1">

                        SEARCH VISITOR

                    </label>


                    <div class="relative mt-xs" data-mco="1">

                        <input
                            type="text"
                            id="manualCheckoutVisitorSearch"
                            autocomplete="off"
                            placeholder="Search by full name or Visitor ID number"
                            class="w-full rounded-lg border border-border bg-surface px-md py-sm text-body">


                        <div
                            id="manualCheckoutSearchLoading"
                            class="pointer-events-none absolute right-md top-1/2 hidden -translate-y-1/2 text-metadata text-text-secondary">

                            Searching...

                        </div>

                    </div>


                    <div
                        id="manualCheckoutSearchResults"
                        class="mt-sm hidden overflow-hidden rounded-lg border border-border bg-surface" data-mco="1">
                    </div>


                    <div
                        id="selectedCheckoutVisitor"
                        class="hidden mt-md rounded-lg border border-border bg-background p-md" data-mco="2">

                        <div class="flex flex-col gap-md md:flex-row md:items-start md:justify-between">

                            <div>

                                <p class="text-section-label text-text-secondary">
                                    SELECTED VISITOR
                                </p>

                                <p
                                    id="checkoutVisitorName"
                                    class="mt-xs text-lg font-semibold">
                                    —
                                </p>

                                <p
                                    id="checkoutVisitorId"
                                    class="mt-xs text-metadata text-text-secondary">
                                    Visitor ID: —
                                </p>

                            </div>


                            <button
                                type="button"
                                id="changeCheckoutVisitorBtn"
                                class="cc-btn-secondary">

                                Change Visitor

                            </button>

                        </div>

                    </div>


                    {{-- ACTIVE VISIT INFORMATION --}}
                    <div
                        id="checkoutActiveVisit"
                        class="hidden mt-lg">

                        <p class="text-card-title" data-mco="2">
                            Active Visit Information
                        </p>

                        <p class="mt-xs text-metadata text-text-secondary" data-mco="2">
                            Review the same visitor, PDL, schedule, and registered ID information retrieved during check-in.
                        </p>


                        {{-- VISITOR + PDL --}}
                        <div class="mt-lg grid grid-cols-1 gap-lg lg:grid-cols-2" data-mco="2">

                            <div class="rounded-xl border border-border bg-background p-md">

                                <p class="text-section-label text-text-secondary">
                                    VISITOR INFORMATION
                                </p>

                                <p
                                    id="checkoutInfoVisitor"
                                    class="mt-md text-lg font-semibold">
                                    —
                                </p>

                                <p
                                    id="checkoutInfoVisitorDetails"
                                    class="mt-xs text-metadata text-text-secondary">
                                    —
                                </p>


                                <div class="mt-lg grid grid-cols-2 gap-md">

                                    <div>

                                        <p class="text-section-label text-text-secondary">
                                            VISITOR ID
                                        </p>

                                        <p
                                            id="checkoutInfoVisitorId"
                                            class="mt-xs text-body font-semibold">
                                            —
                                        </p>

                                    </div>


                                    <div>

                                        <p class="text-section-label text-text-secondary">
                                            RELATIONSHIP
                                        </p>

                                        <p
                                            id="checkoutInfoRelationship"
                                            class="mt-xs text-body font-semibold">
                                            —
                                        </p>

                                    </div>

                                </div>

                            </div>


                            <div class="rounded-xl border border-border bg-background p-md">

                                <p class="text-section-label text-text-secondary">
                                    PDL INFORMATION
                                </p>

                                <p
                                    id="checkoutInfoPdl"
                                    class="mt-md text-lg font-semibold">
                                    —
                                </p>

                                <p class="mt-xs text-metadata text-text-secondary">
                                    Person Deprived of Liberty
                                </p>


                                <div class="mt-lg grid grid-cols-2 gap-md">

                                    <div>

                                        <p class="text-section-label text-text-secondary">
                                            PDL NUMBER
                                        </p>

                                        <p
                                            id="checkoutInfoPdlNumber"
                                            class="mt-xs text-body font-semibold">
                                            —
                                        </p>

                                    </div>


                                    <div>

                                        <p class="text-section-label text-text-secondary">
                                            STATUS
                                        </p>

                                        <p
                                            id="checkoutInfoPdlStatus"
                                            class="mt-xs text-body font-semibold text-primary-teal">
                                            Eligible
                                        </p>

                                    </div>

                                </div>

                            </div>

                        </div>


                        {{-- APPROVED VISIT SCHEDULE --}}
                        <div class="mt-lg rounded-xl border border-border bg-background p-md" data-mco="2">

                            <p class="text-section-label text-text-secondary">
                                APPROVED VISIT SCHEDULE
                            </p>

                            <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-2 lg:grid-cols-4">

                                <div>

                                    <p class="text-section-label text-text-secondary">
                                        DATE
                                    </p>

                                    <p
                                        id="checkoutInfoScheduleDate"
                                        class="mt-xs text-body font-semibold">
                                        —
                                    </p>

                                </div>


                                <div>

                                    <p class="text-section-label text-text-secondary">
                                        TIME
                                    </p>

                                    <p
                                        id="checkoutInfoScheduleTime"
                                        class="mt-xs text-body font-semibold">
                                        —
                                    </p>

                                </div>


                                <div>

                                    <p class="text-section-label text-text-secondary">
                                        BUILDING
                                    </p>

                                    <p
                                        id="checkoutInfoScheduleBuilding"
                                        class="mt-xs text-body font-semibold">
                                        —
                                    </p>

                                </div>


                                <div>

                                    <p class="text-section-label text-text-secondary">
                                        CLASSIFICATION
                                    </p>

                                    <p
                                        id="checkoutInfoScheduleClassification"
                                        class="mt-xs text-body font-semibold">
                                        —
                                    </p>

                                </div>

                            </div>


                            <div class="mt-md">

                                <p class="text-section-label text-text-secondary">
                                    VISIT STATUS
                                </p>

                                <p
                                    id="checkoutInfoVisitStatus"
                                    class="mt-xs text-body font-semibold text-primary-teal">
                                    Approved
                                </p>

                            </div>

                        </div>


                        {{-- REGISTERED ID --}}
                        <div class="mt-lg rounded-xl border border-border bg-background p-md" data-mco="2">

                            <p class="text-section-label text-text-secondary">
                                REGISTERED ID
                            </p>

                            <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-2">

                                <div>

                                    <p class="text-section-label text-text-secondary">
                                        ID TYPE
                                    </p>

                                    <p
                                        id="checkoutInfoRegisteredIdType"
                                        class="mt-xs text-body font-semibold">
                                        —
                                    </p>

                                </div>


                                <div>

                                    <p class="text-section-label text-text-secondary">
                                        ID NUMBER
                                    </p>

                                    <p
                                        id="checkoutInfoRegisteredIdNumber"
                                        class="mt-xs text-body font-semibold">
                                        —
                                    </p>

                                </div>

                            </div>


                            <div class="mt-md">

                                <p class="text-section-label text-text-secondary">
                                    ID STATUS
                                </p>

                                <p
                                    id="checkoutInfoRegisteredIdStatus"
                                    class="mt-xs text-body font-semibold text-primary-teal">
                                    —
                                </p>

                            </div>

                        </div>


                        {{-- CHECK-IN RECORD --}}
                        <div class="mt-lg grid grid-cols-1 gap-lg lg:grid-cols-2" data-mco="2">

                            <div class="rounded-xl border border-border bg-background p-md">

                                <p class="text-section-label text-text-secondary">
                                    CHECK-IN TIME
                                </p>

                                <p
                                    id="checkoutInfoCheckinTime"
                                    class="mt-xs text-body font-semibold">
                                    —
                                </p>

                            </div>


                            <div class="rounded-xl border border-border bg-background p-md">

                                <p class="text-section-label text-text-secondary">
                                    CURRENT STATUS
                                </p>

                                <p
                                    id="checkoutInfoStatus"
                                    class="mt-xs text-body font-semibold text-primary-teal">
                                    Inside Facility
                                </p>

                            </div>

                        </div>


                        <div class="mt-lg flex justify-between" data-mco="2">
                            <button type="button" class="cc-btn-secondary" data-wizard-back="mco">Back</button>
                            <button type="button" class="cc-btn-primary" data-wizard-next="mco">Next: Return ID</button>
                        </div>

                        {{-- RETURN ID --}}
                        <div class="mt-lg rounded-lg border border-border bg-background p-md" data-mco="3">

                            <p class="text-card-title">
                                Return ID
                            </p>

                            <p class="mt-xs text-metadata text-text-secondary">
                                Confirm that the surrendered identification card has been returned.
                            </p>


                            <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-2">

                                <div>

                                    <p class="text-section-label text-text-secondary">
                                        ID TYPE
                                    </p>

                                    <p
                                        id="checkoutIdType"
                                        class="mt-xs text-body font-semibold">
                                        —
                                    </p>

                                </div>


                                <div>

                                    <p class="text-section-label text-text-secondary">
                                        ID NUMBER
                                    </p>

                                    <p
                                        id="checkoutIdNumber"
                                        class="mt-xs text-body font-semibold">
                                        —
                                    </p>

                                </div>

                            </div>


                            <label class="mt-md flex items-start gap-sm">

                                <input
                                    type="checkbox"
                                    id="checkoutIdReturned"
                                    class="mt-1">

                                <span class="text-body">
                                    ID has been returned to the visitor.
                                </span>

                            </label>

                        </div>


                        <p id="mcoStepError" role="alert" class="mt-md hidden text-body font-semibold text-danger" data-mco="3"></p>
                        <div class="mt-lg flex justify-between" data-mco="3">
                            <button type="button" class="cc-btn-secondary" data-wizard-back="mco">Back</button>
                            <button type="button" class="cc-btn-primary" data-wizard-next="mco">Next: Review</button>
                        </div>

                        {{-- Confirm: what will be recorded. --}}
                        <div class="mt-lg rounded-lg border border-border bg-background p-md" data-mco="4">
                            <p class="text-card-title">Review &amp; Confirm</p>
                            <dl class="mt-md grid grid-cols-1 gap-md text-body md:grid-cols-2">
                                <div><dt class="text-section-label text-text-secondary">VISITOR</dt><dd id="mcoSumVisitor" class="mt-xs font-semibold">—</dd></div>
                                <div><dt class="text-section-label text-text-secondary">PDL</dt><dd id="mcoSumPdl" class="mt-xs font-semibold">—</dd></div>
                                <div><dt class="text-section-label text-text-secondary">CHECKED IN</dt><dd id="mcoSumCheckin" class="mt-xs font-semibold">—</dd></div>
                                <div><dt class="text-section-label text-text-secondary">ID RETURNED</dt><dd id="mcoSumId" class="mt-xs font-semibold">—</dd></div>
                            </dl>
                        </div>

                        <div class="mt-lg flex justify-between" data-mco="4">
                            <button type="button" class="cc-btn-secondary" data-wizard-back="mco">Back</button>

                            <button
                                type="button"
                                id="manualCheckoutConfirmBtn"
                                class="cc-btn-primary"
                                disabled>

                                Confirm Check-Out


                            </button>

                        </div>

                    </div>

                </div>

            </div>

        </div>


        {{-- ========================================================
            CHECKOUT STEP 2 — ACTIVE VISIT
        ========================================================= --}}
        <div
            id="checkoutStepContent2"
            class="checkout-content-step hidden mt-lg">

            <div class="cc-card">

                <div class="flex items-center gap-sm">

                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-blue text-sm font-bold text-white">
                        2
                    </span>

                    <div>

                        <p class="text-card-title">
                            Active Visit Information
                        </p>

                        <p class="text-metadata text-text-secondary">
                            Information retrieved from the visitor's active visit.
                        </p>

                    </div>

                </div>


                <div class="mt-lg grid grid-cols-1 gap-lg lg:grid-cols-2">

                    <div class="rounded-xl border border-border bg-background p-md">
                        <p class="text-section-label text-text-secondary">VISITOR INFORMATION</p>
                        <p id="qrCheckoutVisitorName" class="mt-md text-lg font-semibold">—</p>
                        <p id="qrCheckoutVisitorDetails" class="mt-xs text-metadata text-text-secondary">—</p>
                        <div class="mt-lg grid grid-cols-2 gap-md">
                            <div>
                                <p class="text-section-label text-text-secondary">VISITOR ID</p>
                                <p id="qrCheckoutVisitorId" class="mt-xs text-body font-semibold">—</p>
                            </div>
                            <div>
                                <p class="text-section-label text-text-secondary">RELATIONSHIP</p>
                                <p id="qrCheckoutRelationship" class="mt-xs text-body font-semibold">—</p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-border bg-background p-md">
                        <p class="text-section-label text-text-secondary">PDL INFORMATION</p>
                        <p id="qrCheckoutPdlName" class="mt-md text-lg font-semibold">—</p>
                        <p class="mt-xs text-metadata text-text-secondary">Person Deprived of Liberty</p>
                        <div class="mt-lg grid grid-cols-2 gap-md">
                            <div>
                                <p class="text-section-label text-text-secondary">PDL NUMBER</p>
                                <p id="qrCheckoutPdlNumber" class="mt-xs text-body font-semibold">—</p>
                            </div>
                            <div>
                                <p class="text-section-label text-text-secondary">STATUS</p>
                                <p id="qrCheckoutPdlStatus" class="mt-xs text-body font-semibold text-primary-teal">Eligible</p>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="mt-lg rounded-xl border border-border bg-background p-md">
                    <p class="text-section-label text-text-secondary">APPROVED VISIT SCHEDULE</p>
                    <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-2 lg:grid-cols-4">
                        <div><p class="text-section-label text-text-secondary">DATE</p><p id="qrCheckoutScheduleDate" class="mt-xs text-body font-semibold">—</p></div>
                        <div><p class="text-section-label text-text-secondary">TIME</p><p id="qrCheckoutScheduleTime" class="mt-xs text-body font-semibold">—</p></div>
                        <div><p class="text-section-label text-text-secondary">BUILDING</p><p id="qrCheckoutScheduleBuilding" class="mt-xs text-body font-semibold">—</p></div>
                        <div><p class="text-section-label text-text-secondary">CLASSIFICATION</p><p id="qrCheckoutScheduleClassification" class="mt-xs text-body font-semibold">—</p></div>
                    </div>
                    <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-2">
                        <div><p class="text-section-label text-text-secondary">VISIT STATUS</p><p id="qrCheckoutVisitStatus" class="mt-xs text-body font-semibold text-primary-teal">Approved</p></div>
                        <div><p class="text-section-label text-text-secondary">CHECK-IN TIME</p><p id="qrCheckoutCheckinTime" class="mt-xs text-body font-semibold">—</p></div>
                    </div>
                </div>

                <div class="mt-lg flex justify-end">
                    <button type="button" id="checkoutToReturnIdBtn" class="cc-btn-primary">
                        Continue to Return ID
                    </button>
                </div>



            </div>

        </div>


        {{-- ========================================================
            CHECKOUT STEP 3 — RETURN ID
        ========================================================= --}}
        <div
            id="checkoutStepContent3"
            class="checkout-content-step hidden mt-lg">

            <div class="cc-card">

                <div class="flex items-center gap-sm">

                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-blue text-sm font-bold text-white">
                        3
                    </span>

                    <div>

                        <p class="text-card-title">
                            ID Return Confirmation
                        </p>

                        <p class="text-metadata text-text-secondary">
                            Confirm that the visitor's surrendered ID has been returned.
                        </p>

                    </div>

                </div>


                <div class="mt-lg rounded-lg border border-border bg-background p-md">

                    <div class="grid grid-cols-1 gap-md md:grid-cols-2">

                        <div>

                            <p class="text-section-label text-text-secondary">
                                ID TYPE
                            </p>

                            <p
                                id="qrCheckoutIdType"
                                class="mt-xs text-body font-semibold">
                                —
                            </p>

                        </div>


                        <div>

                            <p class="text-section-label text-text-secondary">
                                ID NUMBER
                            </p>

                            <p
                                id="qrCheckoutIdNumber"
                                class="mt-xs text-body font-semibold">
                                —
                            </p>

                        </div>

                    </div>


                    <label class="mt-md flex items-start gap-sm">

                        <input
                            type="checkbox"
                            id="qrCheckoutIdReturned"
                            class="mt-1">

                        <span class="text-body">
                            ID has been returned to the visitor.
                        </span>

                    </label>

                </div>


                <div class="mt-lg flex flex-col-reverse gap-sm border-t border-border pt-lg sm:flex-row sm:justify-between">

                    <button
                        type="button"
                        id="backCheckoutToActiveVisitBtn"
                        class="cc-btn-secondary">

                        Back

                    </button>


                    <button
                        type="button"
                        id="checkoutToConfirmBtn"
                        class="cc-btn-primary">

                        Continue to Confirmation


                    </button>

                </div>

            </div>

        </div>


        {{-- ========================================================
            CHECKOUT STEP 4 — CONFIRM
        ========================================================= --}}
        <div
            id="checkoutStepContent4"
            class="checkout-content-step hidden mt-lg">

            <div class="cc-card">

                <div class="flex items-center gap-sm">

                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-blue text-sm font-bold text-white">
                        4
                    </span>

                    <div>

                        <p class="text-card-title">
                            Confirm Check-Out
                        </p>

                        <p class="text-metadata text-text-secondary">
                            Review the visit details before completing check-out.
                        </p>

                    </div>

                </div>


                <div class="mt-lg rounded-xl border border-border bg-background p-lg">

                    <div class="grid grid-cols-1 gap-lg md:grid-cols-3">

                        <div>

                            <p class="text-section-label text-text-secondary">
                                VISITOR
                            </p>

                            <p
                                id="confirmCheckoutVisitor"
                                class="mt-xs text-body font-semibold">
                                —
                            </p>

                        </div>


                        <div>

                            <p class="text-section-label text-text-secondary">
                                VISITING PDL
                            </p>

                            <p
                                id="confirmCheckoutPdl"
                                class="mt-xs text-body font-semibold">
                                —
                            </p>

                        </div>


                        <div>

                            <p class="text-section-label text-text-secondary">
                                CHECK-IN TIME
                            </p>

                            <p
                                id="confirmCheckoutTime"
                                class="mt-xs text-body font-semibold">
                                —
                            </p>

                        </div>

                    </div>

                </div>


                <div
                    id="checkoutFormContainer"
                    class="mt-lg">
                </div>

            </div>

        </div>


        {{-- CHECKOUT ERROR --}}
        <div
            id="checkoutScannerError"
            class="hidden mt-lg rounded-lg border border-red-200 bg-red-50 p-md text-body text-red-700">
        </div>


        {{-- ========================================================
            TODAY'S CHECK-OUTS
        ======================================================== --}}
        <div class="cc-card mt-lg">

            <div class="flex items-center justify-between gap-sm">
                <div>
                    <p class="text-card-title">Today's Check-Outs</p>
                    <p class="text-metadata text-text-secondary">Check-outs for today</p>
                </div>
                <span class="cc-chip cc-chip-info">{{ $todayCheckouts->count() }} today</span>
            </div>

            <div class="mt-md overflow-x-auto">
                    <table class="w-full text-left text-body">
                        <thead>
                            <tr class="border-b border-border text-section-label text-text-secondary">
                                <th class="py-sm pr-md">Visitor</th>
                                <th class="py-sm pr-md">PDL</th>
                                <th class="py-sm pr-md">Checked In</th>
                                <th class="py-sm pr-md">Checked Out</th>
                                <th class="py-sm pr-md">Duration</th>
                                <th class="py-sm pr-md">ID Returned</th>
                                <th class="py-sm pr-md">Checked Out By</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse ($todayCheckouts as $checkin)
                                @php
                                    $visit = $checkin->visitRequest;
                                    $minutes = $checkin->check_in_time && $checkin->check_out_time
                                        ? (int) $checkin->check_in_time->diffInMinutes($checkin->check_out_time)
                                        : null;
                                @endphp
                                <tr>
                                    <td class="py-sm pr-md">
                                        <span class="font-semibold">{{ $visit?->visitor?->full_name ?? '—' }}</span>
                                        @if ($visit?->relationship)
                                            <span class="block text-metadata text-text-secondary">{{ $visit->relationship->relationshipLabel() }}</span>
                                        @endif
                                    </td>
                                    <td class="py-sm pr-md">
                                        {{ $visit?->pdl?->full_name ?? '—' }}
                                        @if ($visit?->pdl?->pdl_number)
                                            <span class="block text-metadata text-text-secondary">{{ $visit->pdl->pdl_number }}</span>
                                        @endif
                                    </td>
                                    <td class="py-sm pr-md">
                                        {{ $checkin->check_in_time?->format('H:i') ?? '—' }}
                                        @if ($checkin->check_in_time && ! $checkin->check_in_time->isToday())
                                            <span class="block text-metadata text-text-secondary">{{ $checkin->check_in_time->format('M j') }}</span>
                                        @endif
                                    </td>
                                    <td class="py-sm pr-md">{{ $checkin->check_out_time->format('H:i') }}</td>
                                    <td class="py-sm pr-md">{{ $minutes === null ? '—' : ($minutes >= 60 ? intdiv($minutes, 60).'h '.($minutes % 60).'m' : $minutes.'m') }}</td>
                                    <td class="py-sm pr-md">
                                        @if ($checkin->id_returned_time)
                                            <span class="cc-chip cc-chip-success">{{ $checkin->id_returned_time->format('H:i') }}</span>
                                        @elseif ($checkin->id_surrendered_type)
                                            <span class="cc-chip cc-chip-danger">Not returned</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="py-sm pr-md">{{ $checkin->checkOutOfficer?->full_name ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-md text-center text-text-secondary">No check-outs yet today.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

        </div>

    </section>

</div>


{{-- ============================================================
    WORKFLOW JAVASCRIPT
============================================================ --}}
@push('scripts')

<script>
document.addEventListener('DOMContentLoaded', function () {

    /* ============================================================
       MAIN ELEMENTS
    ============================================================ */

    const checkInModeBtn =
        document.getElementById('checkInModeBtn');

    const checkOutModeBtn =
        document.getElementById('checkOutModeBtn');

    const checkInWorkspace =
        document.getElementById('checkInWorkspace');

    const checkOutWorkspace =
        document.getElementById('checkOutWorkspace');


    /* ============================================================
       CHECK-IN ELEMENTS
    ============================================================ */

    const checkinSteps = [
        document.getElementById('checkinStep1'),
        document.getElementById('checkinStep2'),
        document.getElementById('checkinStep3'),
        document.getElementById('checkinStep4')
    ];


    /* ============================================================
       CHECKOUT CONTENT STEPS
    ============================================================ */

    const checkoutContentSteps = [
        document.getElementById('checkoutStepContent1'),
        document.getElementById('checkoutStepContent2'),
        document.getElementById('checkoutStepContent3'),
        document.getElementById('checkoutStepContent4')
    ];


    /* ============================================================
   CHECK-IN PROGRESS
============================================================ */

function updateCheckinProgress(step) {

    /*
     * Connected horizontal stepper
     *
     * Step 1 = Scan QR
     * Step 2 = Visitor & PDL
     * Step 3 = Verify ID
     * Step 4 = Summary
     */

    for (let i = 1; i <= 4; i++) {

        const wrapper =
            document.getElementById(
                'checkinProgress' + i
            );

        if (!wrapper) {
            continue;
        }

        const circle =
            wrapper.querySelector(
                '.checkin-progress-circle'
            );

        const label =
            wrapper.querySelector('p');


        /*
         * COMPLETED STEP
         */
        if (i < step) {

            wrapper.classList.remove(
                'checkin-progress-current'
            );

            wrapper.classList.add(
                'checkin-progress-completed'
            );


            circle?.classList.remove(
                'border',
                'border-border',
                'bg-surface',
                'text-text-secondary',
                'bg-primary-navy'
            );

            circle?.classList.add(
                'bg-primary-navy',
                'text-white'
            );


            /*
             * Show check mark instead of number
             */
            if (circle) {

                circle.innerHTML = '✓';

                circle.classList.add(
                    'font-bold'
                );

            }


            label?.classList.remove(
                'text-text-secondary'
            );

            label?.classList.add(
                'font-semibold',
                'text-text-primary'
            );

        }


        /*
         * CURRENT STEP
         */
        else if (i === step) {

            wrapper.classList.remove(
                'checkin-progress-completed'
            );

            wrapper.classList.add(
                'checkin-progress-current'
            );


            circle?.classList.remove(
                'border',
                'border-border',
                'bg-surface',
                'text-text-secondary',
                'bg-primary-navy'
            );

            circle?.classList.add(
                'bg-primary-navy',
                'text-white'
            );


            /*
             * Keep the original step number
             */
            if (circle) {

                circle.innerHTML = i;

                circle.classList.remove(
                    'font-bold'
                );

            }


            label?.classList.remove(
                'text-text-secondary'
            );

            label?.classList.add(
                'font-semibold',
                'text-text-primary'
            );

        }


        /*
         * UPCOMING STEP
         */
        else {

            wrapper.classList.remove(
                'checkin-progress-completed',
                'checkin-progress-current'
            );


            circle?.classList.remove(
                'bg-primary-navy',
                'text-white',
                'font-bold'
            );

            circle?.classList.add(
                'border',
                'border-border',
                'bg-surface',
                'text-text-secondary'
            );


            /*
             * Restore the step number
             */
            if (circle) {

                circle.innerHTML = i;

            }


            label?.classList.remove(
                'font-semibold',
                'text-text-primary'
            );

            label?.classList.add(
                'text-text-secondary'
            );

        }

    }


    /*
     * Update the connecting lines.
     *
     * Each connector represents the progress between
     * the four steps.
     */
    for (let i = 1; i <= 3; i++) {

        const connector =
            document.getElementById(
                'checkinConnector' + i
            );

        if (!connector) {
            continue;
        }


        if (i < step) {

            connector.classList.remove(
                'bg-border'
            );

            connector.classList.add(
                'bg-primary-navy'
            );

        } else {

            connector.classList.remove(
                'bg-primary-navy'
            );

            connector.classList.add(
                'bg-border'
            );

        }

    }

}


function showCheckinStep(step) {

    checkinSteps.forEach(function (element) {

        element?.classList.add('hidden');

    });


    const target =
        checkinSteps[step - 1];


    if (target) {

        target.classList.remove('hidden');

        target.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });

    }


    /*
     * This already controls the progress bar.
     * Keep this call exactly here.
     */
    updateCheckinProgress(step);

}


    /* ============================================================
       CHECKOUT PROGRESS
    ============================================================ */

    function updateCheckoutProgress(step) {

        /*
         * CHECK-OUT PROGRESS
         *
         * This matches the actual checkout markup:
         * checkoutProgress1 ... checkoutProgress4
         * .checkout-progress-circle
         */

        for (let i = 1; i <= 4; i++) {

            const wrapper =
                document.getElementById(
                    'checkoutProgress' + i
                );

            if (!wrapper) {
                continue;
            }

            const circle =
                wrapper.querySelector(
                    '.checkout-progress-circle'
                );

            const label =
                wrapper.querySelector('p');


            /* COMPLETED STEP */
            if (i < step) {

                wrapper.classList.remove(
                    'checkout-progress-current'
                );

                wrapper.classList.add(
                    'checkout-progress-completed'
                );

                circle?.classList.remove(
                    'border',
                    'border-border',
                    'bg-surface',
                    'text-text-secondary'
                );

                circle?.classList.add(
                    'bg-primary-navy',
                    'text-white'
                );

                if (circle) {
                    circle.textContent = '✓';

                    circle.classList.add(
                        'font-bold'
                    );
                }

                label?.classList.remove(
                    'text-text-secondary'
                );

                label?.classList.add(
                    'font-semibold',
                    'text-text-primary'
                );
            }


            /* CURRENT STEP */
            else if (i === step) {

                wrapper.classList.remove(
                    'checkout-progress-completed'
                );

                wrapper.classList.add(
                    'checkout-progress-current'
                );

                circle?.classList.remove(
                    'border',
                    'border-border',
                    'bg-surface',
                    'text-text-secondary'
                );

                circle?.classList.add(
                    'bg-primary-navy',
                    'text-white'
                );

                if (circle) {
                    circle.textContent = String(i);

                    circle.classList.remove(
                        'font-bold'
                    );
                }

                label?.classList.remove(
                    'text-text-secondary'
                );

                label?.classList.add(
                    'font-semibold',
                    'text-text-primary'
                );
            }


            /* UPCOMING STEP */
            else {

                wrapper.classList.remove(
                    'checkout-progress-completed',
                    'checkout-progress-current'
                );

                circle?.classList.remove(
                    'bg-primary-navy',
                    'text-white',
                    'font-bold'
                );

                circle?.classList.add(
                    'border',
                    'border-border',
                    'bg-surface',
                    'text-text-secondary'
                );

                if (circle) {
                    circle.textContent = String(i);
                }

                label?.classList.remove(
                    'font-semibold',
                    'text-text-primary'
                );

                label?.classList.add(
                    'text-text-secondary'
                );
            }
        }


        /*
         * Connected horizontal lines.
         * These are optional; the code simply ignores them
         * if the Blade does not contain connector elements.
         */

        for (let i = 1; i <= 3; i++) {

            const connector =
                document.getElementById(
                    'checkoutConnector' + i
                );

            if (!connector) {
                continue;
            }

            if (i < step) {

                connector.classList.remove(
                    'bg-border'
                );

                connector.classList.add(
                    'bg-primary-navy'
                );

            } else {

                connector.classList.remove(
                    'bg-primary-navy'
                );

                connector.classList.add(
                    'bg-border'
                );
            }
        }
    }


    function showCheckoutStep(step) {

        checkoutContentSteps.forEach(function (element) {

            element?.classList.add('hidden');

        });


        const target =
            checkoutContentSteps[step - 1];


        if (target) {

            target.classList.remove('hidden');

            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });

        }


        updateCheckoutProgress(step);

    }


    /*
     * CHECKOUT STEP EVENT BRIDGE
     *
     * The QR scanner lives in frontdesk-checkin.js, which is
     * loaded as a JavaScript module. Its functions are not
     * directly visible inside this Blade script.
     *
     * When the scanner successfully finds an active visit,
     * it dispatches custodicore:checkout-progress.
     *
     * This listener receives that event and actually changes
     * the visible checkout page and progress bar.
     */
    window.addEventListener(
        'custodicore:checkout-progress',
        function (event) {

            const step =
                Number(
                    event.detail?.step || 1
                );

            if (event.detail?.checkinId) {
                selectedCheckinId = event.detail.checkinId;
            }

            showCheckoutStep(step);

        }
    );


    /* ============================================================
       MAIN MODE SWITCHING
    ============================================================ */

    function activateCheckIn(scroll = false) {

        checkInWorkspace?.classList.remove('hidden');

        checkOutWorkspace?.classList.add('hidden');


        checkInModeBtn?.classList.remove(
            'cc-btn-secondary'
        );

        checkInModeBtn?.classList.add(
            'cc-btn-primary'
        );


        checkOutModeBtn?.classList.remove(
            'cc-btn-primary'
        );

        checkOutModeBtn?.classList.add(
            'cc-btn-secondary'
        );


        if (scroll) {

            checkInWorkspace?.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });

        }

    }


    function activateCheckOut(scroll = false) {

        checkInWorkspace?.classList.add('hidden');

        checkOutWorkspace?.classList.remove('hidden');


        checkOutModeBtn?.classList.remove(
            'cc-btn-secondary'
        );

        checkOutModeBtn?.classList.add(
            'cc-btn-primary'
        );


        checkInModeBtn?.classList.remove(
            'cc-btn-primary'
        );

        checkInModeBtn?.classList.add(
            'cc-btn-secondary'
        );


        if (scroll) {

            checkOutWorkspace?.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });

        }

    }


    checkInModeBtn?.addEventListener(
        'click',
        function () {

            activateCheckIn(true);

            showCheckinStep(1);

        }
    );


    checkOutModeBtn?.addEventListener(
        'click',
        function () {

            activateCheckOut(true);

            showCheckoutStep(1);

        }
    );


    /* ============================================================
       CHECK-IN SCANNER UI
    ============================================================ */

    const startScannerBtn =
        document.getElementById('startScannerBtn');

    const stopScannerBtn =
        document.getElementById('stopScannerBtn');

    const scannerSection =
        document.getElementById('scannerSection');


    startScannerBtn?.addEventListener(
        'click',
        function () {

            scannerSection?.classList.remove(
                'hidden'
            );

            startScannerBtn?.classList.add(
                'hidden'
            );

            stopScannerBtn?.classList.remove(
                'hidden'
            );


            window.dispatchEvent(
                new CustomEvent(
                    'custodicore:start-checkin-scanner'
                )
            );

        }
    );


    stopScannerBtn?.addEventListener(
        'click',
        function () {

            scannerSection?.classList.add(
                'hidden'
            );

            stopScannerBtn?.classList.add(
                'hidden'
            );

            startScannerBtn?.classList.remove(
                'hidden'
            );


            window.dispatchEvent(
                new CustomEvent(
                    'custodicore:stop-checkin-scanner'
                )
            );

        }
    );


    /* ============================================================
       CHECK-IN MANUAL BACKUP
    ============================================================ */

    const manualBackupBtn =
        document.getElementById(
            'manualBackupBtn'
        );

    const manualBackupPanel =
        document.getElementById(
            'manualBackupPanel'
        );

    const manualVisitorSearch =
        document.getElementById(
            'manualVisitorSearch'
        );


    manualBackupBtn?.addEventListener(
        'click',
        function () {

            manualBackupPanel?.classList.toggle(
                'hidden'
            );


            if (
                !manualBackupPanel?.classList.contains(
                    'hidden'
                )
            ) {

                setTimeout(
                    function () {

                        manualVisitorSearch?.focus();

                    },
                    100
                );

            }

        }
    );


    /* ============================================================
       CHANGE MANUAL CHECK-IN VISITOR
    ============================================================ */

    const changeManualVisitorBtn =
        document.getElementById(
            'changeManualVisitorBtn'
        );

    const selectedManualVisitor =
        document.getElementById(
            'selectedManualVisitor'
        );

    const manualVerificationSection =
        document.getElementById(
            'manualVerificationSection'
        );


    changeManualVisitorBtn?.addEventListener(
        'click',
        function () {

            selectedManualVisitor?.classList.add(
                'hidden'
            );

            manualVerificationSection?.classList.add(
                'hidden'
            );


            if (manualVisitorSearch) {

                manualVisitorSearch.value = '';

                manualVisitorSearch.focus();

            }

        }
    );


    /* ============================================================
       NEW ID REASON
    ============================================================ */

    const newIdReason =
        document.getElementById(
            'new_id_reason'
        );

    const otherReasonContainer =
        document.getElementById(
            'otherReasonContainer'
        );


    newIdReason?.addEventListener(
        'change',
        function () {

            if (this.value === 'other') {

                otherReasonContainer?.classList.remove(
                    'hidden'
                );

            } else {

                otherReasonContainer?.classList.add(
                    'hidden'
                );

            }

        }
    );


    /* ============================================================
       CHECK-IN STEP 2 → STEP 3
    ============================================================ */

    document.getElementById('backToScanBtn')?.addEventListener('click', function () {
        showCheckinStep(1);
    });

    document.getElementById('visitorInfoNextBtn')?.addEventListener('click', function () {
        const verifyType = document.getElementById('verifyRegisteredIdType');
        const verifyNumber = document.getElementById('verifyRegisteredIdNumber');
        const registeredType = document.getElementById('registeredIdType')?.textContent.trim() || '—';
        const registeredNumber = document.getElementById('registeredIdNumber')?.textContent.trim() || '—';

        if (verifyType) verifyType.textContent = registeredType;
        if (verifyNumber) verifyNumber.textContent = registeredNumber;

        showCheckinStep(3);
    });

    /* ============================================================
       CHECK-IN STEP 3 — ID VERIFICATION → SUMMARY
    ============================================================ */

    document.getElementById('backToVisitorInfoBtn')?.addEventListener('click', function () {
        showCheckinStep(2);
    });

    document.getElementById('continueToSummaryBtn')?.addEventListener('click', function () {
        window.dispatchEvent(new CustomEvent('custodicore:prepare-checkin-summary'));
    });

    document.getElementById('backToIdVerificationBtn')?.addEventListener('click', function () {
        showCheckinStep(3);
    });

    window.addEventListener('custodicore:prepare-checkin-summary', function () {
        const match = document.getElementById('idMatchesBtn');
        const mismatch = document.getElementById('idDoesNotMatchBtn');
        const surrendered = document.getElementById('idSurrendered');
        const idMatchStatus = window.frontDeskCheckinIdMatchStatus;

        if (!match || !mismatch) {
            showCheckinStep(3);
            return;
        }

        if (!idMatchStatus) {
            match?.classList.add('ring-2', 'ring-red-500', 'ring-offset-2');
            setTimeout(() => {
                match?.classList.remove('ring-2', 'ring-red-500', 'ring-offset-2');
            }, 1200);
            match?.focus();
            return;
        }

        if (!surrendered?.checked) {
            surrendered?.focus();
            return;
        }

        if (idMatchStatus === 'replaced' && !window.frontDeskCheckinNewIdSaved) {
            document.getElementById('saveNewIdBtn')?.focus();
            return;
        }

        const text = id => document.getElementById(id)?.textContent.trim() || '—';
        const set = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = value || '—'; };

        set('summaryVisitor', text('visitorName'));
        set('summaryVisitorId', text('resultVisitor'));
        set('summaryRelationship', text('resultRelationship'));
        set('summaryPdl', text('resultPdl'));
        set('summaryPdlNumber', text('resultPdlNumber'));
        set('summaryPdlEligibility', text('resultPdlStatus'));
        set('summarySchedule', text('resultSchedule'));
        set('summaryScheduleTime', text('resultScheduleTime'));
        set('summaryScheduleBuilding', text('resultScheduleBuilding'));
        set('summaryScheduleClassification', text('resultScheduleClassification'));
        set('summaryVisitStatus', text('resultVisitStatus'));

        let idType = '—';
        let idNumber = '—';
        let idStatus = 'Verified';

        if (idMatchStatus === 'replaced') {
            idType = document.getElementById('new_id_type')?.selectedOptions?.[0]?.textContent.trim() || '—';
            idNumber = document.getElementById('new_id_number')?.value.trim() || '—';
            idStatus = 'Different ID verified & surrendered';
        } else {
            idType = text('verifyRegisteredIdType');
            idNumber = text('verifyRegisteredIdNumber');
            idStatus = 'Matched & surrendered';
        }

        set('summaryIdType', idType);
        set('summaryIdNumber', idNumber);
        set('summaryIdStatus', idStatus);

        /*
         * Verification checklist. Each check is pass, warn (allowed, but
         * flagged for review) or fail (blocks Confirm Check-In), with a
         * short reason so the officer knows what to do.
         */
        const details = window.frontDeskCheckinDetails;
        const visitStatus = text('resultVisitStatus');
        const pdlStatus = text('resultPdlStatus');

        const checks = [
            {
                key: 'Visitor',
                ...(details && details.visitor_verified === false
                    ? { state: 'fail', label: 'Not verified', reason: 'The visitor account has not been approved by the Records Officer.' }
                    : { state: 'pass', label: 'Verified', reason: details ? 'Approved account · QR pass matched' : 'QR pass matched' }),
            },
            {
                key: 'Schedule',
                ...(['confirmed', 'approved'].includes(visitStatus.toLowerCase())
                    ? { state: 'pass', label: 'On schedule', reason: `Confirmed visit · ${text('resultScheduleTime')}` }
                    : { state: 'fail', label: 'Failed', reason: `Visit status is "${visitStatus}", not confirmed.` }),
            },
            {
                key: 'Pdl',
                ...(/^eligible$/i.test(pdlStatus)
                    ? { state: 'pass', label: 'Eligible', reason: 'No active restrictions' }
                    : { state: 'fail', label: 'Not eligible', reason: `PDL status: ${pdlStatus}. Visits are not allowed.` }),
            },
            {
                key: 'Id',
                ...(idMatchStatus === 'replaced'
                    ? { state: 'warn', label: 'Pending review', reason: `New ${idType} recorded · the Records Officer will verify it.` }
                    : { state: 'pass', label: 'Matched', reason: `${idType} matched and surrendered` }),
            },
        ];

        // Literal class strings so Tailwind keeps them in the build.
        const checkStyles = {
            pass: { box: ['border-green-200', 'bg-green-50'], icon: ['bg-green-600'], text: ['text-green-700'], symbol: '✓' },
            warn: { box: ['border-amber-200', 'bg-amber-50'], icon: ['bg-amber-500'], text: ['text-amber-700'], symbol: '!' },
            fail: { box: ['border-red-200', 'bg-red-50'], icon: ['bg-red-600'], text: ['text-red-700'], symbol: '✕' },
        };
        const allStyleClasses = Object.values(checkStyles).flatMap(st => [...st.box, ...st.icon, ...st.text]);

        checks.forEach(check => {
            const style = checkStyles[check.state];
            const box = document.getElementById(`summaryCheck${check.key}`);
            const icon = box?.querySelector('.summary-status-icon');
            const status = document.getElementById(`summaryCheck${check.key}Text`);
            const reason = document.getElementById(`summaryCheck${check.key}Reason`);

            box?.classList.remove('border-border', 'bg-surface', ...allStyleClasses);
            box?.classList.add(...style.box);
            icon?.classList.remove('bg-gray-300', ...allStyleClasses);
            icon?.classList.add(...style.icon);
            if (icon) icon.textContent = style.symbol;
            status?.classList.remove('text-text-secondary', ...allStyleClasses);
            status?.classList.add(...style.text);
            if (status) status.textContent = check.label;
            if (reason) reason.textContent = check.reason;
        });

        const failed = checks.filter(check => check.state === 'fail').length;
        const warned = checks.filter(check => check.state === 'warn').length;
        const overall = document.getElementById('summaryCheckOverall');
        const overallState = failed ? 'fail' : (warned ? 'warn' : 'pass');

        if (overall) {
            overall.classList.remove('hidden', ...allStyleClasses);
            overall.classList.add(...checkStyles[overallState].box, ...checkStyles[overallState].text);
            overall.textContent = failed
                ? `✕ ${failed} check${failed > 1 ? 's' : ''} failed. Check-in is blocked until ${failed > 1 ? 'they are' : 'it is'} resolved.`
                : (warned
                    ? '! Ready to check in. One item is flagged for Records Officer review.'
                    : '✓ All checks passed. Ready to check in.');
        }

        const confirmBtn = document.getElementById('confirmCheckInBtn');
        if (confirmBtn && confirmBtn.textContent.trim() !== 'Processing...') {
            confirmBtn.disabled = failed > 0;
            confirmBtn.title = failed > 0 ? 'Resolve the failed checks before checking in.' : '';
            confirmBtn.classList.toggle('opacity-50', failed > 0);
            confirmBtn.classList.toggle('cursor-not-allowed', failed > 0);
        }

        showCheckinStep(4);
    });

    /* ============================================================
       CHECKOUT SCANNER
       IMPORTANT:
       The Blade page controls the scanner UI.
       frontdesk-checkin.js receives these events and performs
       the actual camera scanning.
    ============================================================ */

    const checkoutScannerStartButton =
        document.getElementById(
            'checkoutScannerStartButton'
        );

    const checkoutScannerStopButton =
        document.getElementById(
            'checkoutScannerStopButton'
        );

    const checkoutScannerSection =
        document.getElementById(
            'checkoutScannerSection'
        );


    checkoutScannerStartButton?.addEventListener(
        'click',
        function () {

            checkoutScannerSection?.classList.remove(
                'hidden'
            );

            checkoutScannerStartButton?.classList.add(
                'hidden'
            );

            checkoutScannerStopButton?.classList.remove(
                'hidden'
            );


            const status =
                document.getElementById(
                    'checkoutScannerStatus'
                );


            if (status) {

                status.textContent =
                    'Starting camera... Please allow camera access if prompted.';

            }


            window.dispatchEvent(
                new CustomEvent(
                    'custodicore:start-checkout-scanner'
                )
            );

        }
    );


    checkoutScannerStopButton?.addEventListener(
        'click',
        function () {

            checkoutScannerSection?.classList.add(
                'hidden'
            );

            checkoutScannerStopButton?.classList.add(
                'hidden'
            );

            checkoutScannerStartButton?.classList.remove(
                'hidden'
            );


            window.dispatchEvent(
                new CustomEvent(
                    'custodicore:stop-checkout-scanner'
                )
            );


            const status =
                document.getElementById(
                    'checkoutScannerStatus'
                );


            if (status) {

                status.textContent =
                    'Scanner stopped. Click Start QR Scanner to try again.';

            }

        }
    );


    /* ============================================================
       CHECKOUT MANUAL BACKUP
    ============================================================ */

    const manualCheckoutBtn =
        document.getElementById(
            'manualCheckoutBtn'
        );

    const manualCheckoutSection =
        document.getElementById(
            'manualCheckoutSection'
        );

    const manualCheckoutVisitorSearch =
        document.getElementById(
            'manualCheckoutVisitorSearch'
        );


    manualCheckoutBtn?.addEventListener(
        'click',
        function () {

            manualCheckoutSection?.classList.toggle(
                'hidden'
            );


            if (
                !manualCheckoutSection?.classList.contains(
                    'hidden'
                )
            ) {

                setTimeout(
                    function () {

                        manualCheckoutVisitorSearch?.focus();

                    },
                    100
                );

            }

        }
    );


    /* ============================================================
       CHANGE CHECKOUT VISITOR
    ============================================================ */

    const changeCheckoutVisitorBtn =
        document.getElementById(
            'changeCheckoutVisitorBtn'
        );


    changeCheckoutVisitorBtn?.addEventListener(
        'click',
        function () {

            document
                .getElementById(
                    'selectedCheckoutVisitor'
                )
                ?.classList.add('hidden');


            document
                .getElementById(
                    'checkoutActiveVisit'
                )
                ?.classList.add('hidden');


            if (manualCheckoutVisitorSearch) {

                manualCheckoutVisitorSearch.value = '';

                manualCheckoutVisitorSearch.focus();

            }


            selectedCheckinId = null;

            const returned = document.getElementById('checkoutIdReturned');

            if (returned) {
                returned.checked = false;
            }

            const confirmBtn = document.getElementById('manualCheckoutConfirmBtn');

            if (confirmBtn) {
                confirmBtn.disabled = true;
            }

        }
    );


    /* ============================================================
       MANUAL CHECKOUT SEARCH
       Filters visitors currently inside (window.frontDeskInsideVisitors)
       by name or Visitor ID; picking one fills the active-visit panel.
    ============================================================ */

    const manualCheckoutSearchResults =
        document.getElementById(
            'manualCheckoutSearchResults'
        );

    const insideVisitors =
        Array.isArray(window.frontDeskInsideVisitors)
            ? window.frontDeskInsideVisitors
            : [];

    const escapeCheckoutHtml = (value) =>
        String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

    function hideManualCheckoutResults() {

        if (manualCheckoutSearchResults) {
            manualCheckoutSearchResults.innerHTML = '';
            manualCheckoutSearchResults.classList.add('hidden');
        }

    }

    function renderManualCheckoutResults(query) {

        if (!manualCheckoutSearchResults) {
            return;
        }

        const q = query.trim().toLowerCase();

        if (!q) {
            hideManualCheckoutResults();
            return;
        }

        const matches = insideVisitors.filter(function (visitor) {
            return String(visitor.visitorName || '').toLowerCase().includes(q)
                || String(visitor.visitorId || '').toLowerCase().includes(q);
        });

        manualCheckoutSearchResults.innerHTML = '';

        if (matches.length === 0) {

            manualCheckoutSearchResults.innerHTML = `
                <div class="p-md text-metadata text-text-secondary">
                    ${insideVisitors.length === 0
                        ? 'No visitors are currently inside the facility.'
                        : 'No checked-in visitor matches this search.'}
                </div>
            `;

            manualCheckoutSearchResults.classList.remove('hidden');

            return;
        }

        matches.forEach(function (visitor) {

            const result = document.createElement('button');

            result.type = 'button';

            result.className =
                'block w-full border-b border-border px-md py-md text-left transition hover:bg-background';

            result.innerHTML = `
                <div class="flex items-center justify-between gap-md">
                    <div class="min-w-0">
                        <p class="text-body font-semibold text-text-primary">${escapeCheckoutHtml(visitor.visitorName)}</p>
                        <p class="mt-xs text-metadata text-text-secondary">Visitor ID: ${escapeCheckoutHtml(visitor.visitorId ?? '—')}</p>
                        <p class="mt-xs text-metadata text-text-secondary">Visiting ${escapeCheckoutHtml(visitor.pdlName)} (${escapeCheckoutHtml(visitor.pdlNumber)})</p>
                        <p class="mt-xs text-metadata text-text-secondary">Checked in ${escapeCheckoutHtml(visitor.checkinTime)}</p>
                    </div>
                    <span class="shrink-0 text-metadata font-semibold text-text-secondary">Select</span>
                </div>
            `;

            result.addEventListener('click', function () {

                hideManualCheckoutResults();

                if (manualCheckoutVisitorSearch) {
                    manualCheckoutVisitorSearch.value = visitor.visitorName;
                }

                window.dispatchEvent(
                    new CustomEvent(
                        'custodicore:checkout-manual-selected',
                        { detail: visitor }
                    )
                );

            });

            manualCheckoutSearchResults.appendChild(result);

        });

        manualCheckoutSearchResults.classList.remove('hidden');

    }

    manualCheckoutVisitorSearch?.addEventListener(
        'input',
        function () {

            renderManualCheckoutResults(this.value);

        }
    );


    /* ============================================================
       CHECKOUT ID RETURN
    ============================================================ */

    const checkoutIdReturned =
        document.getElementById(
            'checkoutIdReturned'
        );

    const manualCheckoutConfirmBtn =
        document.getElementById(
            'manualCheckoutConfirmBtn'
        );


    checkoutIdReturned?.addEventListener(
        'change',
        function () {

            if (manualCheckoutConfirmBtn) {

                manualCheckoutConfirmBtn.disabled =
                    !this.checked;

            }

        }
    );


    const qrCheckoutIdReturned =
        document.getElementById(
            'qrCheckoutIdReturned'
        );


    const qrConfirmCheckoutBtn =
        document.getElementById(
            'qrConfirmCheckoutBtn'
        );


    qrCheckoutIdReturned?.addEventListener(
        'change',
        function () {

            if (qrConfirmCheckoutBtn) {

                qrConfirmCheckoutBtn.disabled =
                    !this.checked;

            }

        }
    );


    /* ============================================================
       CHECKOUT STEP 2
    ============================================================ */

    document
        .getElementById(
            'checkoutToReturnIdBtn'
        )
        ?.addEventListener(
            'click',
            function () {

                copyCheckoutInformation();

                showCheckoutStep(3);

            }
        );


    /* ============================================================
       CHECKOUT STEP 3
    ============================================================ */

    document
        .getElementById(
            'checkoutToConfirmBtn'
        )
        ?.addEventListener(
            'click',
            function () {

                const returned =
                    document.getElementById(
                        'qrCheckoutIdReturned'
                    );


                const manualReturned =
                    document.getElementById(
                        'checkoutIdReturned'
                    );


                if (
                    !returned?.checked &&
                    !manualReturned?.checked
                ) {

                    alert(
                        'Please confirm that the visitor ID has been returned before continuing.'
                    );

                    return;

                }


                copyCheckoutConfirmation();

                showCheckoutStep(4);

                buildCheckoutForm();

            }
        );


    /* ============================================================
       BACK TO ACTIVE VISIT
    ============================================================ */

    document
        .getElementById(
            'backCheckoutToActiveVisitBtn'
        )
        ?.addEventListener(
            'click',
            function () {

                showCheckoutStep(2);

            }
        );


    /* ============================================================
       CHECKOUT INFORMATION HELPERS
    ============================================================ */

    function copyCheckoutInformation() {

        const visitorName =
            document.getElementById(
                'qrCheckoutVisitorName'
            )?.textContent.trim() || '—';


        const visitorId =
            document.getElementById(
                'qrCheckoutVisitorId'
            )?.textContent.trim() || '—';


        const pdlName =
            document.getElementById(
                'qrCheckoutPdlName'
            )?.textContent.trim() || '—';


        const pdlNumber =
            document.getElementById(
                'qrCheckoutPdlNumber'
            )?.textContent.trim() || '—';


        const checkinTime =
            document.getElementById(
                'qrCheckoutCheckinTime'
            )?.textContent.trim() || '—';


        const idType =
            document.getElementById(
                'qrCheckoutIdType'
            )?.textContent.trim() || '—';


        const idNumber =
            document.getElementById(
                'qrCheckoutIdNumber'
            )?.textContent.trim() || '—';


        const fields = {

            checkoutInfoVisitor: visitorName,

            checkoutInfoVisitorId: visitorId,

            checkoutInfoPdl: pdlName,

            checkoutInfoPdlNumber: pdlNumber,

            checkoutInfoCheckinTime: checkinTime,

            checkoutIdType: idType,

            checkoutIdNumber: idNumber

        };


        Object.entries(fields).forEach(
            function ([id, value]) {

                const element =
                    document.getElementById(id);

                if (element) {

                    element.textContent = value;

                }

            }
        );

    }


    function copyCheckoutConfirmation() {

        const visitor =
            document.getElementById(
                'checkoutInfoVisitor'
            )?.textContent.trim() || '—';


        const pdl =
            document.getElementById(
                'checkoutInfoPdl'
            )?.textContent.trim() || '—';


        const time =
            document.getElementById(
                'checkoutInfoCheckinTime'
            )?.textContent.trim() || '—';


        document.getElementById(
            'confirmCheckoutVisitor'
        ).textContent = visitor;


        document.getElementById(
            'confirmCheckoutPdl'
        ).textContent = pdl;


        document.getElementById(
            'confirmCheckoutTime'
        ).textContent = time;

    }


    /* ============================================================
       BUILD CHECKOUT FORM
    ============================================================ */

    let selectedCheckinId = null;


    function buildCheckoutForm() {

        const container =
            document.getElementById(
                'checkoutFormContainer'
            );


        if (!container) {
            return;
        }


        if (!selectedCheckinId) {

            container.innerHTML = `
                <div class="rounded-xl border border-border bg-surface p-md">

                    <p class="text-body font-semibold">
                        Ready to complete check-out
                    </p>

                    <p class="mt-xs text-metadata text-text-secondary">
                        The visitor's ID return has been confirmed.
                    </p>

                </div>
            `;

            return;

        }


        const routeTemplate =
            @json(route(
                'frontdesk.checkin-checkout.check-out',
                ['checkin' => '__CHECKIN_ID__']
            ));


        const action =
            routeTemplate.replace(
                '__CHECKIN_ID__',
                selectedCheckinId
            );


        container.innerHTML = `
            <form
                method="POST"
                action="${action}"
                data-confirm="Complete check-out for this visitor?">

                <input
                    type="hidden"
                    name="_token"
                    value="{{ csrf_token() }}">


                <div class="rounded-xl border border-border bg-surface p-md">

                    <p class="text-body font-semibold">
                        Ready to complete check-out
                    </p>

                    <p class="mt-xs text-metadata text-text-secondary">
                        The visitor's ID return has been confirmed.
                    </p>

                </div>


                <div class="mt-md flex justify-end">

                    <button
                        type="submit"
                        class="cc-btn-primary">

                        Confirm Check-Out


                    </button>

                </div>

            </form>
        `;

    }


    /* ============================================================
       MANUAL CHECKOUT CONFIRM
    ============================================================ */

    manualCheckoutConfirmBtn?.addEventListener(
        'click',
        function () {

            if (!checkoutIdReturned?.checked) {

                alert(
                    'Please confirm that the visitor ID has been returned.'
                );

                return;

            }


            // The manual panel already holds this visitor's details.
            copyCheckoutConfirmation();

            showCheckoutStep(4);

            buildCheckoutForm();

        }
    );


    /* ============================================================
       QR CHECKOUT CONFIRM
    ============================================================ */

    qrConfirmCheckoutBtn?.addEventListener(
        'click',
        function () {

            if (!qrCheckoutIdReturned?.checked) {

                alert(
                    'Please confirm that the visitor ID has been returned.'
                );

                return;

            }


            copyCheckoutInformation();

            copyCheckoutConfirmation();

            showCheckoutStep(4);

            buildCheckoutForm();

        }
    );


    /* ============================================================
       CHECKOUT SCANNER EVENTS
    ============================================================ */

    window.addEventListener(
        'custodicore:checkout-scanned',
        function (event) {

            const data =
                event.detail || {};


            if (data.checkinId) {

                selectedCheckinId =
                    data.checkinId;

            }


            const mappings = {

                qrCheckoutVisitorName:
                    data.visitorName,

                qrCheckoutVisitorId:
                    data.visitorId,

                qrCheckoutPdlName:
                    data.pdlName,

                qrCheckoutPdlNumber:
                    data.pdlNumber,

                qrCheckoutCheckinTime:
                    data.checkinTime,

                qrCheckoutStatus:
                    data.status,

                qrCheckoutIdType:
                    data.idType,

                qrCheckoutIdNumber:
                    data.idNumber

            };


            Object.entries(mappings).forEach(
                function ([id, value]) {

                    const element =
                        document.getElementById(id);

                    if (
                        element &&
                        value !== undefined &&
                        value !== null
                    ) {

                        element.textContent =
                            value;

                    }

                }
            );


            const scannerSection =
                document.getElementById(
                    'checkoutScannerSection'
                );


            scannerSection?.classList.add(
                'hidden'
            );


            checkoutScannerStopButton?.classList.add(
                'hidden'
            );


            checkoutScannerStartButton?.classList.remove(
                'hidden'
            );


            window.dispatchEvent(
                new CustomEvent(
                    'custodicore:stop-checkout-scanner'
                )
            );


            showCheckoutStep(2);

        }
    );


    /* ============================================================
       CHECKOUT PROGRESS EVENTS
    ============================================================ */

    window.addEventListener(
        'custodicore:checkout-progress',
        function (event) {

            const step =
                Number(
                    event.detail?.step || 1
                );


            if (
                step >= 1 &&
                step <= 4
            ) {

                showCheckoutStep(step);

            }

        }
    );


    /* ============================================================
       CHECK-IN PROGRESS EVENTS
    ============================================================ */

    window.addEventListener(
        'custodicore:checkin-progress',
        function (event) {

            const step =
                Number(
                    event.detail?.step || 1
                );


            if (
                step >= 1 &&
                step <= 4
            ) {

                showCheckinStep(step);

            }

        }
    );


    /* ============================================================
       CHECK-IN SCAN RESULT OBSERVER
    ============================================================ */

    const scanResult =
        document.getElementById(
            'scanResult'
        );


    if (scanResult) {

        const observer =
            new MutationObserver(
                function () {

                    const hasData =
                        scanResult.textContent
                            .replace(/\s+/g, ' ')
                            .trim()
                            .length > 10;


                    if (
                        hasData &&
                        !scanResult.classList.contains(
                            'hidden'
                        )
                    ) {

                        showCheckinStep(2);

                    }

                }
            );


        observer.observe(
            scanResult,
            {
                attributes: true,
                childList: true,
                subtree: true
            }
        );

    }


    /* ============================================================
       CHECKOUT MANUAL SEARCH RESULT EVENT
    ============================================================ */

    window.addEventListener(
        'custodicore:checkout-manual-selected',
        function (event) {

            const data =
                event.detail || {};


            if (data.checkinId) {

                selectedCheckinId =
                    data.checkinId;

            }


            const selectedVisitor =
                document.getElementById(
                    'selectedCheckoutVisitor'
                );


            selectedVisitor?.classList.remove(
                'hidden'
            );


            const activeVisit =
                document.getElementById(
                    'checkoutActiveVisit'
                );


            activeVisit?.classList.remove(
                'hidden'
            );


            const mappings = {

                checkoutVisitorName:
                    data.visitorName,

                checkoutVisitorId:
                    data.visitorId,

                checkoutInfoVisitor:
                    data.visitorName,

                checkoutInfoVisitorId:
                    data.visitorId,

                checkoutInfoPdl:
                    data.pdlName,

                checkoutInfoPdlNumber:
                    data.pdlNumber,

                checkoutInfoCheckinTime:
                    data.checkinTime,

                checkoutIdType:
                    data.idType,

                checkoutIdNumber:
                    data.idNumber,

                checkoutInfoVisitorDetails:
                    data.pdlName
                        ? `Visiting ${data.pdlName} (${data.pdlNumber})`
                        : undefined,

                checkoutInfoRelationship:
                    data.relationship,

                checkoutInfoPdlStatus:
                    data.pdlStatus,

                checkoutInfoScheduleDate:
                    data.scheduleDate,

                checkoutInfoScheduleTime:
                    data.scheduleTime,

                checkoutInfoScheduleBuilding:
                    data.building,

                checkoutInfoScheduleClassification:
                    data.classification,

                checkoutInfoVisitStatus:
                    data.visitStatus,

                checkoutInfoRegisteredIdType:
                    data.registeredIdType,

                checkoutInfoRegisteredIdNumber:
                    data.registeredIdNumber,

                checkoutInfoRegisteredIdStatus:
                    data.registeredIdStatus,

                checkoutInfoStatus:
                    data.status

            };


            Object.entries(mappings).forEach(
                function ([id, value]) {

                    const element =
                        document.getElementById(id);


                    if (
                        element &&
                        value !== undefined &&
                        value !== null
                    ) {

                        element.textContent =
                            value;

                    }

                }
            );

        }
    );


    /* ============================================================
       CHECK-IN SCANNER START EVENT
    ============================================================ */

    window.addEventListener(
        'custodicore:checkin-scanner-started',
        function () {

            scannerSection?.classList.remove(
                'hidden'
            );


            startScannerBtn?.classList.add(
                'hidden'
            );


            stopScannerBtn?.classList.remove(
                'hidden'
            );

        }
    );


    /* ============================================================
       CHECK-IN SCANNER STOP EVENT
    ============================================================ */

    window.addEventListener(
        'custodicore:checkin-scanner-stopped',
        function () {

            scannerSection?.classList.add(
                'hidden'
            );


            stopScannerBtn?.classList.add(
                'hidden'
            );


            startScannerBtn?.classList.remove(
                'hidden'
            );

        }
    );


    /* ============================================================
       CHECKOUT SCANNER START EVENT
    ============================================================ */

    window.addEventListener(
        'custodicore:checkout-scanner-started',
        function () {

            checkoutScannerSection?.classList.remove(
                'hidden'
            );


            checkoutScannerStartButton?.classList.add(
                'hidden'
            );


            checkoutScannerStopButton?.classList.remove(
                'hidden'
            );

        }
    );


    /* ============================================================
       CHECKOUT SCANNER STOP EVENT
    ============================================================ */

    window.addEventListener(
        'custodicore:checkout-scanner-stopped',
        function () {

            checkoutScannerSection?.classList.add(
                'hidden'
            );


            checkoutScannerStopButton?.classList.add(
                'hidden'
            );


            checkoutScannerStartButton?.classList.remove(
                'hidden'
            );

        }
    );


    /* ============================================================
       CHECKOUT ERROR
    ============================================================ */

    window.addEventListener(
        'custodicore:checkout-scanner-error',
        function (event) {

            const errorBox =
                document.getElementById(
                    'checkoutScannerError'
                );


            if (!errorBox) {
                return;
            }


            const message =
                event.detail?.message ||
                'Unable to scan the visitor QR code. Please try again or use Manual Check-Out.';


            errorBox.textContent =
                message;


            errorBox.classList.remove(
                'hidden'
            );

        }
    );


    /* ============================================================
       INITIAL STATE
    ============================================================ */

    activateCheckIn(false);

    showCheckinStep(1);

    showCheckoutStep(1);

});
</script>


@endpush


{{-- ============================================================
    EXISTING QR SCANNER SCRIPT
============================================================ --}}
@vite('resources/js/frontdesk-checkin.js')

@push('scripts')
<script>
    /*
     * Manual (no QR) check-in and check-out: one step at a time, with the
     * same four-step progress bar as the QR flow. The steps only show and
     * hide the panels' existing fields; recording the check-in / check-out
     * is unchanged (frontdesk-checkin.js and the checkout script above).
     */
    (() => {
        const tone = {
            done: ['border-success', 'bg-success', 'text-white'],
            current: ['border-warning', 'bg-warning/10', 'text-warning'],
            todo: ['border-border', 'bg-white', 'text-text-secondary'],
        };
        const text = { done: 'text-success', current: 'text-warning', todo: 'text-text-secondary' };
        const $ = id => document.getElementById(id);
        const value = id => ($(id)?.textContent || '').replace(/\s+/g, ' ').trim() || '—';

        function wizard(name, { canLeave, onEnter } = {}) {
            const progress = document.querySelector(`[data-wizard-progress="${name}"]`);
            let step = 1;

            const show = n => {
                step = Math.min(Math.max(n, 1), 4);
                document.querySelectorAll(`[data-${name}]`).forEach(el => {
                    el.style.display = Number(el.dataset[name]) === step ? '' : 'none';
                });
                progress?.querySelectorAll('[data-wizard-step]').forEach(li => {
                    const i = Number(li.dataset.wizardStep);
                    const state = i < step ? 'done' : (i === step ? 'current' : 'todo');
                    const circle = li.querySelector('[data-wizard-circle]');
                    const label = li.querySelector('[data-wizard-label]');
                    const line = li.querySelector('[data-wizard-line]');
                    circle.classList.remove(...tone.done, ...tone.current, ...tone.todo);
                    circle.classList.add(...tone[state]);
                    circle.textContent = state === 'done' ? '✓' : i;
                    label.classList.remove(...Object.values(text));
                    label.classList.add(text[state]);
                    line?.classList.toggle('bg-success', i < step);
                    line?.classList.toggle('bg-border', i >= step);
                    if (state === 'current') li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
                });
                onEnter?.(step);
            };

            document.querySelectorAll(`[data-wizard-next="${name}"]`).forEach(button => button.addEventListener('click', () => {
                if (canLeave && canLeave(step) === false) return;
                show(step + 1);
            }));
            document.querySelectorAll(`[data-wizard-back="${name}"]`).forEach(button => button.addEventListener('click', () => show(step - 1)));

            show(1);
            return { show, step: () => step };
        }

        // Follows the selected-visitor card: shown on select, hidden on "Change Visitor".
        function followSelection(cardId, flow) {
            const card = $(cardId);
            if (!card) return;
            new MutationObserver(() => {
                const selected = !card.classList.contains('hidden');
                if (selected && flow.step() === 1) flow.show(2);
                if (!selected && flow.step() > 1) flow.show(1);
            }).observe(card, { attributes: true, attributeFilter: ['class'] });
        }

        function stepError(id, message) {
            const box = $(id);
            if (!box) return;
            box.textContent = message || '';
            box.classList.toggle('hidden', !message);
        }

        // ---- Manual check-in: Find Visitor → Visitor Info → Verify ID → Confirm ----
        const checkin = wizard('mci', {
            canLeave(step) {
                if (step !== 3) return true;
                const idNumber = ($('manualIdNumber')?.value || '').replace(/[^A-Za-z0-9]/g, '');
                const reason = ($('overrideReason')?.value || '').replace(/\s+/g, ' ').trim();
                const problem =
                    !$('manualIdType')?.value ? 'Select the type of ID the visitor surrendered.'
                    : idNumber.length < 4 ? "Enter the visitor's ID number."
                    : reason.length < 10 ? 'Describe the reason for the manual check-in in at least 10 characters.'
                    : !$('manualIdVerified')?.checked ? "Confirm that you physically verified the visitor's ID."
                    : null;
                stepError('manualStepError', problem);
                return !problem;
            },
            onEnter(step) {
                if (step !== 4) return;
                const type = $('manualIdType');
                $('mciSumVisitor').textContent = `${value('selectedVisitorName')} · ${value('selectedVisitorId')}`;
                $('mciSumVisit').textContent = `${value('selectedVisitorDetails')} · ${value('selectedVisitorSchedule')}`;
                $('mciSumId').textContent = `${type?.selectedOptions[0]?.textContent.trim() || '—'} · ${($('manualIdNumber')?.value || '—').toUpperCase()}`;
                $('mciSumReason').textContent = ($('overrideReason')?.value || '—').trim();
            },
        });
        followSelection('selectedManualVisitor', checkin);

        // ---- Manual check-out: Find Visitor → Active Visit → Return ID → Confirm ----
        const checkout = wizard('mco', {
            canLeave(step) {
                if (step !== 3) return true;
                const returned = $('checkoutIdReturned')?.checked;
                stepError('mcoStepError', returned ? null : 'Confirm that the ID has been returned to the visitor.');
                return !!returned;
            },
            onEnter(step) {
                if (step !== 4) return;
                $('mcoSumVisitor').textContent = value('checkoutInfoVisitor');
                $('mcoSumPdl').textContent = `${value('checkoutInfoPdl')} (${value('checkoutInfoPdlNumber')})`;
                $('mcoSumCheckin').textContent = value('checkoutInfoCheckinTime');
                $('mcoSumId').textContent = `${value('checkoutIdType')} · ${value('checkoutIdNumber')}`;
            },
        });
        followSelection('selectedCheckoutVisitor', checkout);
        window.addEventListener('custodicore:checkout-manual-selected', () => setTimeout(() => checkout.show(2), 0));
    })();
</script>

<script>
    // ?checkin=Name (from a booked walk-in): open Manual Check-In with that visitor searched.
    setTimeout(() => {
        const name = new URLSearchParams(location.search).get('checkin');
        const search = document.getElementById('manualVisitorSearch');
        if (!name || !search) return;
        if (document.getElementById('manualBackupPanel')?.classList.contains('hidden')) {
            document.getElementById('manualBackupBtn')?.click();
        }
        search.value = name;
        search.dispatchEvent(new Event('input', { bubbles: true }));
    }, 0);
</script>
@endpush

@endsection