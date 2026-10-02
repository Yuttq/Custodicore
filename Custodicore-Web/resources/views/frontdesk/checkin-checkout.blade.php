@extends('layouts.frontdesk')

@section('title', 'Check-In / Check-Out')

@section('content')

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

                @include('admin.partials.icon', [
                    'name' => 'login',
                    'class' => 'h-4 w-4'
                ])

                <span>Check-In</span>

            </button>

            <button
                type="button"
                id="checkOutModeBtn"
                class="cc-btn-secondary w-full justify-center">

                @include('admin.partials.icon', [
                    'name' => 'logout',
                    'class' => 'h-4 w-4'
                ])

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

                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-navy text-sm font-bold text-white">
                    1
                </span>

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

                <div class="grid grid-cols-4 gap-sm">

                    <div
                        id="checkinProgress1"
                        class="checkin-progress-step text-center">

                        <div class="checkin-progress-circle mx-auto flex h-8 w-8 items-center justify-center rounded-full bg-primary-navy text-sm font-semibold text-white">
                            1
                        </div>

                        <p class="mt-xs text-status-label font-semibold text-text-primary">
                            Scan QR
                        </p>

                    </div>


                    <div
                        id="checkinProgress2"
                        class="checkin-progress-step text-center">

                        <div class="checkin-progress-circle mx-auto flex h-8 w-8 items-center justify-center rounded-full border border-border bg-surface text-sm text-text-secondary">
                            2
                        </div>

                        <p class="mt-xs text-status-label text-text-secondary">
                            Visitor Info
                        </p>

                    </div>


                    <div
                        id="checkinProgress3"
                        class="checkin-progress-step text-center">

                        <div class="checkin-progress-circle mx-auto flex h-8 w-8 items-center justify-center rounded-full border border-border bg-surface text-sm text-text-secondary">
                            3
                        </div>

                        <p class="mt-xs text-status-label text-text-secondary">
                            Verify Visitor
                        </p>

                    </div>


                    <div
                        id="checkinProgress4"
                        class="checkin-progress-step text-center">

                        <div class="checkin-progress-circle mx-auto flex h-8 w-8 items-center justify-center rounded-full border border-border bg-surface text-sm text-text-secondary">
                            4
                        </div>

                        <p class="mt-xs text-status-label text-text-secondary">
                            Verify ID
                        </p>

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

                            @include('admin.partials.icon', [
                                'name' => 'qrcode',
                                'class' => 'h-4 w-4'
                            ])

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

                    <label
                        for="manualVisitorSearch"
                        class="text-section-label text-text-secondary">

                        SEARCH VISITOR

                    </label>


                    <div class="relative mt-xs">

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
                        class="mt-sm hidden overflow-hidden rounded-lg border border-border bg-surface">
                    </div>


                    <div
                        id="selectedManualVisitor"
                        class="hidden mt-md rounded-lg border border-border bg-background p-md">

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


                    {{-- MANUAL VERIFICATION --}}
                    <div
                        id="manualVerificationSection"
                        class="hidden">

                        <div class="mt-lg border-t border-border pt-lg">

                            <p class="text-card-title">
                                Manual ID Verification
                            </p>


                            <div class="mt-md rounded-lg border border-border bg-background p-md">

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


                            <div class="mt-md">

                                <label
                                    for="manualIdType"
                                    class="text-section-label text-text-secondary">

                                    ID TYPE SURRENDERED

                                </label>

                                <select
                                    id="manualIdType"
                                    class="mt-xs w-full rounded-lg border border-border bg-surface px-md py-sm text-body">

                                    <option value="">
                                        Select ID type
                                    </option>

                                    <option value="national_id">
                                        National ID
                                    </option>

                                    <option value="drivers_license">
                                        Driver's License
                                    </option>

                                    <option value="passport">
                                        Passport
                                    </option>

                                    <option value="umid">
                                        UMID
                                    </option>

                                    <option value="philhealth_id">
                                        PhilHealth ID
                                    </option>

                                    <option value="voters_id">
                                        Voter's ID
                                    </option>

                                    <option value="other">
                                        Other
                                    </option>

                                </select>

                            </div>


                            <div class="mt-md">

                                <label
                                    for="manualIdNumber"
                                    class="text-section-label text-text-secondary">

                                    ID NUMBER

                                </label>

                                <input
                                    type="text"
                                    id="manualIdNumber"
                                    placeholder="Enter the visitor's physical ID number"
                                    class="mt-xs w-full rounded-lg border border-border bg-surface px-md py-sm text-body">

                            </div>


                            <div class="mt-md">

                                <label
                                    for="overrideReason"
                                    class="text-section-label text-text-secondary">

                                    REASON FOR MANUAL OVERRIDE

                                </label>

                                <textarea
                                    id="overrideReason"
                                    rows="3"
                                    placeholder="Example: Visitor QR code could not be scanned."
                                    class="mt-xs w-full rounded-lg border border-border bg-surface px-md py-sm text-body"></textarea>

                            </div>


                            <div class="mt-md rounded-lg border border-border bg-background p-md">

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


                            <div class="mt-md flex justify-end">

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

                        <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-2">

                            <div>

                                <p class="text-section-label text-text-secondary">
                                    DATE
                                </p>

                                <p
                                    id="resultSchedule"
                                    class="mt-xs text-body font-semibold">
                                    —
                                </p>

                            </div>


                            <div>

                                <p class="text-section-label text-text-secondary">
                                    TIME
                                </p>

                                <p
                                    id="resultScheduleTime"
                                    class="mt-xs text-body font-semibold">
                                    —
                                </p>

                            </div>

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


                    <div class="mt-lg flex justify-end">

                        <button
                            type="button"
                            id="visitorInfoNextBtn"
                            class="cc-btn-primary">

                            Continue to Verification

                            @include('admin.partials.icon', [
                                'name' => 'arrow-right',
                                'class' => 'h-4 w-4'
                            ])

                        </button>

                    </div>

                </div>

            </div>

        </div>


        {{-- ========================================================
            CHECK-IN STEP 3
        ========================================================= --}}
        <div
            id="checkinStep3"
            class="checkin-step hidden mt-lg">

            <div class="cc-card">

                <div class="flex items-center gap-sm">

                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-blue text-sm font-bold text-white">
                        3
                    </span>

                    <div>

                        <p class="text-card-title">
                            Verify Visitor
                        </p>

                        <p class="text-metadata text-text-secondary">
                            Confirm that the visitor and visit meet the required conditions.
                        </p>

                    </div>

                </div>


                <div class="mt-lg space-y-sm">

                    <div class="rounded-xl border border-border bg-background p-md">

                        <div class="flex items-start gap-md">

                            <input
                                type="checkbox"
                                id="verifyVisitorIdentity"
                                class="mt-1 h-4 w-4">

                            <div>

                                <p class="text-body font-semibold">
                                    Visitor Identity
                                </p>

                                <p class="mt-xs text-metadata text-text-secondary">
                                    Visitor matches the registered visitor account.
                                </p>

                            </div>

                        </div>

                    </div>


                    <div class="rounded-xl border border-border bg-background p-md">

                        <div class="flex items-start gap-md">

                            <input
                                type="checkbox"
                                id="verifySchedule"
                                class="mt-1 h-4 w-4">

                            <div>

                                <p class="text-body font-semibold">
                                    Schedule Verification
                                </p>

                                <p class="mt-xs text-metadata text-text-secondary">
                                    The visit is approved for the current schedule.
                                </p>

                            </div>

                        </div>

                    </div>


                    <div class="rounded-xl border border-border bg-background p-md">

                        <div class="flex items-start gap-md">

                            <input
                                type="checkbox"
                                id="verifyPdlEligibility"
                                class="mt-1 h-4 w-4">

                            <div>

                                <p class="text-body font-semibold">
                                    PDL Eligibility
                                </p>

                                <p class="mt-xs text-metadata text-text-secondary">
                                    The PDL is eligible to receive the visitor.
                                </p>

                            </div>

                        </div>

                    </div>

                </div>


                <div class="mt-lg flex justify-end">

                    <button
                        type="button"
                        id="visitorVerificationNextBtn"
                        class="cc-btn-primary">

                        Continue to ID Verification

                        @include('admin.partials.icon', [
                            'name' => 'arrow-right',
                            'class' => 'h-4 w-4'
                        ])

                    </button>

                </div>

            </div>

        </div>


        {{-- ========================================================
            CHECK-IN STEP 4
        ========================================================= --}}
        <div
            id="checkinStep4"
            class="checkin-step hidden mt-lg">

            <div class="cc-card">

                <div class="flex items-center gap-sm">

                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-blue text-sm font-bold text-white">
                        4
                    </span>

                    <div>

                        <p class="text-card-title">
                            ID Verification
                        </p>

                        <p class="text-metadata text-text-secondary">
                            Verify and record the visitor's surrendered identification.
                        </p>

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
                                id="verifyRegisteredIdType"
                                class="mt-xs text-body font-semibold">
                                —
                            </p>

                        </div>


                        <div>

                            <p class="text-section-label text-text-secondary">
                                ID NUMBER
                            </p>

                            <p
                                id="verifyRegisteredIdNumber"
                                class="mt-xs text-body font-semibold">
                                —
                            </p>

                        </div>

                    </div>

                </div>


                <div class="mt-lg rounded-xl border border-border bg-background p-md">

                    <label class="flex items-start gap-sm">

                        <input
                            type="checkbox"
                            id="idSurrendered"
                            class="mt-1 h-4 w-4">

                        <span>

                            <span class="block text-body font-semibold">
                                ID has been physically surrendered.
                            </span>

                            <span class="mt-xs block text-metadata text-text-secondary">
                                Confirm that the physical identification card is now held at the gate.
                            </span>

                        </span>

                    </label>

                </div>


                <div
                    id="newIdSection"
                    class="hidden mt-lg border-t border-border pt-lg">

                    <p class="text-card-title">
                        New ID Presented
                    </p>

                    <p class="mt-xs text-metadata text-text-secondary">
                        Record the new physical ID without deleting the previous registered ID.
                    </p>


                    <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-2">

                        <div>

                            <label
                                for="new_id_type"
                                class="text-section-label text-text-secondary">

                                NEW ID TYPE

                            </label>

                            <select
                                id="new_id_type"
                                class="mt-xs w-full rounded-lg border border-border bg-surface px-md py-sm text-body">

                                <option value="">
                                    Select ID type
                                </option>

                                <option value="national_id">
                                    National ID
                                </option>

                                <option value="drivers_license">
                                    Driver's License
                                </option>

                                <option value="passport">
                                    Passport
                                </option>

                                <option value="umid">
                                    UMID
                                </option>

                                <option value="philhealth_id">
                                    PhilHealth ID
                                </option>

                                <option value="voters_id">
                                    Voter's ID
                                </option>

                                <option value="other">
                                    Other
                                </option>

                            </select>

                        </div>


                        <div>

                            <label
                                for="new_id_number"
                                class="text-section-label text-text-secondary">

                                NEW ID NUMBER

                            </label>

                            <input
                                type="text"
                                id="new_id_number"
                                placeholder="Enter the new ID number"
                                class="mt-xs w-full rounded-lg border border-border bg-surface px-md py-sm text-body">

                        </div>

                    </div>


                    <div class="mt-md">

                        <label
                            for="new_id_reason"
                            class="text-section-label text-text-secondary">

                            REASON

                        </label>

                        <select
                            id="new_id_reason"
                            class="mt-xs w-full rounded-lg border border-border bg-surface px-md py-sm text-body">

                            <option value="">
                                Select reason
                            </option>

                            <option value="lost">
                                Lost / Misplaced
                            </option>

                            <option value="expired">
                                Expired
                            </option>

                            <option value="damaged">
                                Damaged
                            </option>

                            <option value="updated">
                                Updated / Replaced
                            </option>

                            <option value="other">
                                Other
                            </option>

                        </select>

                    </div>


                    <div
                        id="otherReasonContainer"
                        class="hidden mt-md">

                        <textarea
                            id="new_id_other_reason"
                            rows="3"
                            placeholder="Explain the reason for the new ID."
                            class="w-full rounded-lg border border-border bg-surface px-md py-sm text-body"></textarea>

                    </div>


                    <div class="mt-md rounded-lg border border-border bg-background p-md">

                        <label class="flex items-start gap-sm">

                            <input
                                type="checkbox"
                                id="newIdVerified"
                                class="mt-1">

                            <span class="text-body">
                                I have physically verified the new ID and confirmed that it belongs to the visitor.
                            </span>

                        </label>

                    </div>


                    <div class="mt-md">

                        <button
                            type="button"
                            id="saveNewIdBtn"
                            class="cc-btn-secondary">

                            Verify & Add New ID

                        </button>

                    </div>

                </div>


                <div class="mt-lg flex flex-col-reverse gap-sm border-t border-border pt-lg sm:flex-row sm:justify-between">

                    <button
                        type="button"
                        id="backToVisitorVerificationBtn"
                        class="cc-btn-secondary">

                        Back

                    </button>


                    <button
                        type="button"
                        id="confirmCheckInBtn"
                        class="cc-btn-primary">

                        Confirm Check-In

                        @include('admin.partials.icon', [
                            'name' => 'arrow-right',
                            'class' => 'h-4 w-4'
                        ])

                    </button>

                </div>

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

                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-navy text-sm font-bold text-white">
                    1
                </span>

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

                <div class="grid grid-cols-4 gap-sm">

                    <div class="text-center">

                        <div
                            id="checkoutCircle1"
                            class="mx-auto flex h-8 w-8 items-center justify-center rounded-full bg-primary-navy text-sm font-semibold text-white">

                            1

                        </div>

                        <p
                            id="checkoutStep1Label"
                            class="mt-xs text-status-label font-semibold text-text-primary">

                            Scan QR

                        </p>

                    </div>


                    <div class="text-center">

                        <div
                            id="checkoutCircle2"
                            class="mx-auto flex h-8 w-8 items-center justify-center rounded-full border border-border bg-surface text-sm text-text-secondary">

                            2

                        </div>

                        <p
                            id="checkoutStep2Label"
                            class="mt-xs text-status-label text-text-secondary">

                            Active Visit

                        </p>

                    </div>


                    <div class="text-center">

                        <div
                            id="checkoutCircle3"
                            class="mx-auto flex h-8 w-8 items-center justify-center rounded-full border border-border bg-surface text-sm text-text-secondary">

                            3

                        </div>

                        <p
                            id="checkoutStep3Label"
                            class="mt-xs text-status-label text-text-secondary">

                            Return ID

                        </p>

                    </div>


                    <div class="text-center">

                        <div
                            id="checkoutCircle4"
                            class="mx-auto flex h-8 w-8 items-center justify-center rounded-full border border-border bg-surface text-sm text-text-secondary">

                            4

                        </div>

                        <p
                            id="checkoutStep4Label"
                            class="mt-xs text-status-label text-text-secondary">

                            Confirm

                        </p>

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

                            @include('admin.partials.icon', [
                                'name' => 'qrcode',
                                'class' => 'h-4 w-4'
                            ])

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

                    <label
                        for="manualCheckoutVisitorSearch"
                        class="text-section-label text-text-secondary">

                        SEARCH VISITOR

                    </label>


                    <div class="relative mt-xs">

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
                        class="mt-sm hidden overflow-hidden rounded-lg border border-border bg-surface">
                    </div>


                    <div
                        id="selectedCheckoutVisitor"
                        class="hidden mt-md rounded-lg border border-border bg-background p-md">

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
                        class="hidden mt-lg border-t border-border pt-lg">

                        <p class="text-card-title">
                            Active Visit Information
                        </p>

                        <p class="mt-xs text-metadata text-text-secondary">
                            Confirm the visitor's active visit before returning the ID.
                        </p>


                        <div class="mt-md grid grid-cols-1 gap-md lg:grid-cols-2">

                            <div class="rounded-lg border border-border bg-background p-md">

                                <p class="text-section-label text-text-secondary">
                                    VISITOR
                                </p>

                                <p
                                    id="checkoutInfoVisitor"
                                    class="mt-xs text-body font-semibold">
                                    —
                                </p>

                            </div>


                            <div class="rounded-lg border border-border bg-background p-md">

                                <p class="text-section-label text-text-secondary">
                                    VISITOR ID
                                </p>

                                <p
                                    id="checkoutInfoVisitorId"
                                    class="mt-xs text-body font-semibold">
                                    —
                                </p>

                            </div>


                            <div class="rounded-lg border border-border bg-background p-md">

                                <p class="text-section-label text-text-secondary">
                                    VISITING PDL
                                </p>

                                <p
                                    id="checkoutInfoPdl"
                                    class="mt-xs text-body font-semibold">
                                    —
                                </p>

                            </div>


                            <div class="rounded-lg border border-border bg-background p-md">

                                <p class="text-section-label text-text-secondary">
                                    PDL NUMBER
                                </p>

                                <p
                                    id="checkoutInfoPdlNumber"
                                    class="mt-xs text-body font-semibold">
                                    —
                                </p>

                            </div>


                            <div class="rounded-lg border border-border bg-background p-md">

                                <p class="text-section-label text-text-secondary">
                                    CHECK-IN TIME
                                </p>

                                <p
                                    id="checkoutInfoCheckinTime"
                                    class="mt-xs text-body font-semibold">
                                    —
                                </p>

                            </div>


                            <div class="rounded-lg border border-border bg-background p-md">

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


                        {{-- RETURN ID --}}
                        <div class="mt-lg rounded-lg border border-border bg-background p-md">

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


                        <div class="mt-lg flex justify-end">

                            <button
                                type="button"
                                id="manualCheckoutConfirmBtn"
                                class="cc-btn-primary"
                                disabled>

                                Confirm Check-Out

                                @include('admin.partials.icon', [
                                    'name' => 'arrow-right',
                                    'class' => 'h-4 w-4'
                                ])

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

                        <p class="text-section-label text-text-secondary">
                            VISITOR INFORMATION
                        </p>

                        <p
                            id="qrCheckoutVisitorName"
                            class="mt-md text-lg font-semibold">
                            —
                        </p>

                        <p
                            id="qrCheckoutVisitorId"
                            class="mt-xs text-metadata text-text-secondary">
                            Visitor ID: —
                        </p>

                    </div>


                    <div class="rounded-xl border border-border bg-background p-md">

                        <p class="text-section-label text-text-secondary">
                            VISITING PDL
                        </p>

                        <p
                            id="qrCheckoutPdlName"
                            class="mt-md text-lg font-semibold">
                            —
                        </p>

                        <p
                            id="qrCheckoutPdlNumber"
                            class="mt-xs text-metadata text-text-secondary">
                            PDL No.: —
                        </p>

                    </div>


                    <div class="rounded-xl border border-border bg-background p-md">

                        <p class="text-section-label text-text-secondary">
                            CHECK-IN TIME
                        </p>

                        <p
                            id="qrCheckoutCheckinTime"
                            class="mt-xs text-body font-semibold">
                            —
                        </p>

                    </div>


                    <div class="rounded-xl border border-border bg-background p-md">

                        <p class="text-section-label text-text-secondary">
                            CURRENT STATUS
                        </p>

                        <p
                            id="qrCheckoutStatus"
                            class="mt-xs text-body font-semibold text-primary-teal">
                            Inside Facility
                        </p>

                    </div>

                </div>


                <div class="mt-lg flex justify-end">

                    <button
                        type="button"
                        id="checkoutToReturnIdBtn"
                        class="cc-btn-primary">

                        Continue to Return ID

                        @include('admin.partials.icon', [
                            'name' => 'arrow-right',
                            'class' => 'h-4 w-4'
                        ])

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

                        @include('admin.partials.icon', [
                            'name' => 'arrow-right',
                            'class' => 'h-4 w-4'
                        ])

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


            if (i <= step) {

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

                label?.classList.remove(
                    'text-text-secondary'
                );

                label?.classList.add(
                    'font-semibold',
                    'text-text-primary'
                );

            } else {

                circle?.classList.remove(
                    'bg-primary-navy',
                    'text-white'
                );

                circle?.classList.add(
                    'border',
                    'border-border',
                    'bg-surface',
                    'text-text-secondary'
                );

                label?.classList.remove(
                    'font-semibold',
                    'text-text-primary'
                );

                label?.classList.add(
                    'text-text-secondary'
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


        updateCheckinProgress(step);

    }


    /* ============================================================
       CHECKOUT PROGRESS
    ============================================================ */

    function updateCheckoutProgress(step) {

        for (let i = 1; i <= 4; i++) {

            const circle =
                document.getElementById(
                    'checkoutCircle' + i
                );

            const label =
                document.getElementById(
                    'checkoutStep' + i + 'Label'
                );


            if (!circle) {
                continue;
            }


            if (i <= step) {

                circle.classList.remove(
                    'border',
                    'border-border',
                    'bg-surface',
                    'text-text-secondary'
                );

                circle.classList.add(
                    'bg-primary-navy',
                    'text-white'
                );


                label?.classList.remove(
                    'text-text-secondary'
                );

                label?.classList.add(
                    'font-semibold',
                    'text-text-primary'
                );

            } else {

                circle.classList.remove(
                    'bg-primary-navy',
                    'text-white'
                );

                circle.classList.add(
                    'border',
                    'border-border',
                    'bg-surface',
                    'text-text-secondary'
                );


                label?.classList.remove(
                    'font-semibold',
                    'text-text-primary'
                );

                label?.classList.add(
                    'text-text-secondary'
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
       CHECK-IN STEP 2
    ============================================================ */

    document
        .getElementById('visitorInfoNextBtn')
        ?.addEventListener(
            'click',
            function () {

                showCheckinStep(3);


                window.dispatchEvent(
                    new CustomEvent(
                        'custodicore:checkin-progress',
                        {
                            detail: {
                                step: 3
                            }
                        }
                    )
                );

            }
        );


    /* ============================================================
       CHECK-IN STEP 3
    ============================================================ */

    document
        .getElementById(
            'visitorVerificationNextBtn'
        )
        ?.addEventListener(
            'click',
            function () {

                const identity =
                    document.getElementById(
                        'verifyVisitorIdentity'
                    );

                const schedule =
                    document.getElementById(
                        'verifySchedule'
                    );

                const eligibility =
                    document.getElementById(
                        'verifyPdlEligibility'
                    );


                if (
                    !identity?.checked ||
                    !schedule?.checked ||
                    !eligibility?.checked
                ) {

                    alert(
                        'Please complete all visitor verification items before continuing.'
                    );

                    return;

                }


                const registeredType =
                    document.getElementById(
                        'registeredIdType'
                    )?.textContent;


                const registeredNumber =
                    document.getElementById(
                        'registeredIdNumber'
                    )?.textContent;


                const verifyType =
                    document.getElementById(
                        'verifyRegisteredIdType'
                    );

                const verifyNumber =
                    document.getElementById(
                        'verifyRegisteredIdNumber'
                    );


                if (verifyType) {

                    verifyType.textContent =
                        registeredType?.trim() || '—';

                }


                if (verifyNumber) {

                    verifyNumber.textContent =
                        registeredNumber?.trim() || '—';

                }


                showCheckinStep(4);

            }
        );


    /* ============================================================
       BACK TO VISITOR VERIFICATION
    ============================================================ */

    document
        .getElementById(
            'backToVisitorVerificationBtn'
        )
        ?.addEventListener(
            'click',
            function () {

                showCheckinStep(3);

            }
        );


    /* ============================================================
       CONFIRM CHECK-IN
    ============================================================ */

    document
        .getElementById('confirmCheckInBtn')
        ?.addEventListener(
            'click',
            function () {

                const surrendered =
                    document.getElementById(
                        'idSurrendered'
                    );


                if (!surrendered?.checked) {

                    alert(
                        'Please confirm that the visitor ID has been surrendered before completing check-in.'
                    );

                    return;

                }


                window.dispatchEvent(
                    new CustomEvent(
                        'custodicore:confirm-checkin'
                    )
                );

            }
        );


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

                        @include('admin.partials.icon', [
                            'name' => 'arrow-right',
                            'class' => 'h-4 w-4'
                        ])

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


            copyCheckoutInformation();

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

@endsection