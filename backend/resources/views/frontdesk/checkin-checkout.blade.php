@extends('layouts.frontdesk')

@section('title', 'Check-In / Check-Out')

@section('content')

    {{-- =========================================================
        PAGE INTRO
    ========================================================== --}}
    <div class="flex flex-col gap-sm sm:flex-row sm:items-center sm:justify-between">

        <div>
            <p class="text-body text-text-secondary">
                Verify visitors and manage their check-in and check-out process.
            </p>
        </div>

        <div class="rounded-chip border border-border bg-card px-md py-sm">

            <p class="text-status-label uppercase tracking-wide text-text-secondary">
                Gate Status
            </p>

            <p class="text-body font-semibold text-success">
                Open
            </p>

        </div>

    </div>


    {{-- =========================================================
        CHECK-IN / CHECK-OUT WORKFLOW
    ========================================================== --}}
    <div class="grid grid-cols-1 gap-lg xl:grid-cols-3">


        {{-- =====================================================
            LEFT: QR SCANNER
        ====================================================== --}}
        <section class="cc-card xl:col-span-1">

            <div class="mb-md">

                <h2 class="text-card-title font-semibold text-text-primary">
                    QR Code Scanner
                </h2>

                <p class="mt-xs text-status-label text-text-secondary">
                    Scan the visitor's QR code to begin verification.
                </p>

            </div>


            {{-- Scanner Area --}}
            <div
                id="scanner-area"
                class="relative flex min-h-[280px] items-center justify-center overflow-hidden rounded-sm border-2 border-dashed border-border bg-background"
            >

                {{-- Scanner Corners --}}
                <div class="pointer-events-none absolute inset-8 border-2 border-primary-teal/30"></div>

                <div class="relative flex flex-col items-center text-center">

                    @include('admin.partials.icon', [
                        'name' => 'qrcode',
                        'class' => 'h-16 w-16 text-primary-navy'
                    ])

                    <p class="mt-md text-body font-semibold text-text-primary">
                        Ready to Scan
                    </p>

                    <p class="mt-xs max-w-xs text-status-label text-text-secondary">
                        Position the visitor's QR code inside the scanning area.
                    </p>

                </div>

            </div>


            {{-- Scan Button --}}
            <button
                type="button"
                id="scan-button"
                class="mt-md flex w-full items-center justify-center gap-sm rounded-chip bg-primary-navy px-md py-sm text-body font-semibold text-white transition hover:opacity-90"
            >

                @include('admin.partials.icon', [
                    'name' => 'qrcode',
                    'class' => 'h-5 w-5'
                ])

                <span id="scan-button-text">
                    Scan QR Code
                </span>

            </button>


            <p
                id="scanner-message"
                class="mt-sm text-center text-status-label text-text-secondary"
            >
                Scanner is ready.
            </p>

        </section>


        {{-- =====================================================
            RIGHT: VISITOR VERIFICATION
        ====================================================== --}}
        <section class="cc-card xl:col-span-2">

            <div class="mb-md">

                <h2 class="text-card-title font-semibold text-text-primary">
                    Visitor Verification
                </h2>

                <p class="mt-xs text-status-label text-text-secondary">
                    Verify visitor information before allowing entry.
                </p>

            </div>


            {{-- Visitor Information --}}
            <div class="rounded-sm border border-border bg-background p-md">

                <div class="mb-md flex items-center justify-between">

                    <div>
                        <p class="text-status-label uppercase tracking-wide text-text-secondary">
                            Visitor Information
                        </p>

                        <p class="mt-xs text-body font-semibold text-text-primary">
                            Maria Santos
                        </p>
                    </div>

                    <span class="inline-flex rounded-chip bg-success/10 px-sm py-xs text-status-label font-semibold text-success">
                        Eligible
                    </span>

                </div>


                <div class="grid grid-cols-1 gap-md sm:grid-cols-2 lg:grid-cols-4">

                    <div>
                        <p class="text-status-label uppercase text-text-secondary">
                            Visitor ID
                        </p>

                        <p class="mt-xs text-body font-medium text-text-primary">
                            VIS-00124
                        </p>
                    </div>


                    <div>
                        <p class="text-status-label uppercase text-text-secondary">
                            Contact Number
                        </p>

                        <p class="mt-xs text-body font-medium text-text-primary">
                            0917 123 4567
                        </p>
                    </div>


                    <div>
                        <p class="text-status-label uppercase text-text-secondary">
                            Relationship
                        </p>

                        <p class="mt-xs text-body font-medium text-text-primary">
                            Sister
                        </p>
                    </div>


                    <div>
                        <p class="text-status-label uppercase text-text-secondary">
                            Visit Type
                        </p>

                        <p class="mt-xs text-body font-medium text-text-primary">
                            Regular Visit
                        </p>
                    </div>

                </div>

            </div>


            {{-- PDL Information --}}
            <div class="mt-md rounded-sm border border-border bg-background p-md">

                <p class="text-status-label uppercase tracking-wide text-text-secondary">
                    Person Deprived of Liberty
                </p>

                <div class="mt-md grid grid-cols-1 gap-md sm:grid-cols-2">

                    <div>
                        <p class="text-status-label text-text-secondary">
                            PDL Name
                        </p>

                        <p class="mt-xs text-body font-semibold text-text-primary">
                            Juan Dela Cruz
                        </p>

                    </div>


                    <div>
                        <p class="text-status-label text-text-secondary">
                            PDL ID
                        </p>

                        <p class="mt-xs text-body font-semibold text-text-primary">
                            PDL-00045
                        </p>

                    </div>

                </div>

            </div>


            {{-- Scheduled Visit --}}
            <div class="mt-md rounded-sm border border-border bg-background p-md">

                <p class="text-status-label uppercase tracking-wide text-text-secondary">
                    Scheduled Visit
                </p>

                <div class="mt-md grid grid-cols-1 gap-md sm:grid-cols-3">

                    <div>
                        <p class="text-status-label text-text-secondary">
                            Date
                        </p>

                        <p class="mt-xs text-body font-medium text-text-primary">
                            {{ date('F d, Y') }}
                        </p>
                    </div>


                    <div>
                        <p class="text-status-label text-text-secondary">
                            Schedule
                        </p>

                        <p class="mt-xs text-body font-medium text-text-primary">
                            9:00 AM – 10:00 AM
                        </p>
                    </div>


                    <div>
                        <p class="text-status-label text-text-secondary">
                            Status
                        </p>

                        <span class="mt-xs inline-flex rounded-chip bg-success/10 px-sm py-xs text-status-label font-semibold text-success">
                            Approved
                        </span>
                    </div>

                </div>

            </div>

        </section>

    </div>


    {{-- =========================================================
        ID SURRENDER / CHECK-IN
    ========================================================== --}}
    <section class="cc-card">

        <div class="mb-md">

            <h2 class="text-card-title font-semibold text-text-primary">
                Check-In
            </h2>

            <p class="mt-xs text-status-label text-text-secondary">
                Record the visitor's entry and ID surrender.
            </p>

        </div>


        <div class="grid grid-cols-1 gap-lg lg:grid-cols-2">


            {{-- ID Surrender --}}
            <div class="rounded-sm border border-border bg-background p-md">

                <div class="flex items-start gap-md">

                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-chip bg-primary-navy/10 text-primary-navy">

                        @include('admin.partials.icon', [
                            'name' => 'id-card',
                            'class' => 'h-5 w-5'
                        ])

                    </div>


                    <div>

                        <h3 class="text-body font-semibold text-text-primary">
                            ID Surrender
                        </h3>

                        <p class="mt-xs text-status-label text-text-secondary">
                            Confirm that the visitor has surrendered a valid identification card.
                        </p>

                    </div>

                </div>


                <label class="mt-md flex cursor-pointer items-center gap-sm">

                    <input
                        type="checkbox"
                        id="id-surrendered"
                        class="h-4 w-4 rounded border-border text-primary-navy focus:ring-primary-teal"
                    >

                    <span class="text-body text-text-primary">
                        Visitor ID surrendered
                    </span>

                </label>

            </div>


            {{-- Check-In --}}
            <div class="rounded-sm border border-border bg-background p-md">

                <div class="flex items-start gap-md">

                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-chip bg-success/10 text-success">

                        @include('admin.partials.icon', [
                            'name' => 'check',
                            'class' => 'h-5 w-5'
                        ])

                    </div>


                    <div>

                        <h3 class="text-body font-semibold text-text-primary">
                            Visitor Check-In
                        </h3>

                        <p class="mt-xs text-status-label text-text-secondary">
                            Allow the visitor to enter after verification is complete.
                        </p>

                    </div>

                </div>


                <button
                    type="button"
                    id="check-in-button"
                    disabled
                    class="mt-md flex w-full items-center justify-center gap-sm rounded-chip bg-success px-md py-sm text-body font-semibold text-white opacity-50 transition"
                >

                    @include('admin.partials.icon', [
                        'name' => 'login',
                        'class' => 'h-5 w-5'
                    ])

                    Check In Visitor

                </button>

            </div>

        </div>

    </section>


    {{-- =========================================================
        CURRENTLY CHECKED-IN VISITOR
    ========================================================== --}}
    <section class="cc-card">

        <div class="mb-md flex flex-col gap-sm sm:flex-row sm:items-center sm:justify-between">

            <div>

                <h2 class="text-card-title font-semibold text-text-primary">
                    Currently Checked-In
                </h2>

                <p class="mt-xs text-status-label text-text-secondary">
                    Visitors currently inside the facility.
                </p>

            </div>

            <span class="inline-flex w-fit rounded-chip bg-success/10 px-sm py-xs text-status-label font-semibold text-success">
                1 Visitor Inside
            </span>

        </div>


        <div class="overflow-x-auto">

            <table class="w-full text-left">

                <thead class="border-b border-border bg-background">

                    <tr>

                        <th class="px-lg py-md text-status-label font-semibold uppercase tracking-wide text-text-secondary">
                            Visitor
                        </th>

                        <th class="px-lg py-md text-status-label font-semibold uppercase tracking-wide text-text-secondary">
                            PDL
                        </th>

                        <th class="px-lg py-md text-status-label font-semibold uppercase tracking-wide text-text-secondary">
                            Check-In
                        </th>

                        <th class="px-lg py-md text-status-label font-semibold uppercase tracking-wide text-text-secondary">
                            ID Status
                        </th>

                        <th class="px-lg py-md text-status-label font-semibold uppercase tracking-wide text-text-secondary">
                            Action
                        </th>

                    </tr>

                </thead>


                <tbody class="divide-y divide-border">

                    <tr class="transition hover:bg-background">

                        <td class="px-lg py-md">

                            <p class="text-body font-semibold text-text-primary">
                                Maria Santos
                            </p>

                            <p class="text-status-label text-text-secondary">
                                VIS-00124
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body text-text-primary">
                                Juan Dela Cruz
                            </p>

                            <p class="text-status-label text-text-secondary">
                                PDL-00045
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body font-medium text-text-primary">
                                8:52 AM
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <span class="inline-flex rounded-chip bg-warning/10 px-sm py-xs text-status-label font-semibold text-warning">
                                Surrendered
                            </span>

                        </td>


                        <td class="px-lg py-md">

                            <button
                                type="button"
                                id="check-out-button"
                                class="inline-flex items-center gap-xs rounded-chip bg-primary-navy px-md py-xs text-status-label font-semibold text-white transition hover:opacity-90"
                            >

                                @include('admin.partials.icon', [
                                    'name' => 'logout',
                                    'class' => 'h-4 w-4'
                                ])

                                Check Out

                            </button>

                        </td>

                    </tr>

                </tbody>

            </table>

        </div>

    </section>


    {{-- =========================================================
        CHECK-OUT INFORMATION
    ========================================================== --}}
    <section
        id="checkout-panel"
        class="cc-card hidden"
    >

        <div class="mb-md">

            <h2 class="text-card-title font-semibold text-text-primary">
                Check-Out Visitor
            </h2>

            <p class="mt-xs text-status-label text-text-secondary">
                Confirm the visitor's departure and return the surrendered ID.
            </p>

        </div>


        <div class="grid grid-cols-1 gap-lg lg:grid-cols-2">


            {{-- Visit Summary --}}
            <div class="rounded-sm border border-border bg-background p-md">

                <p class="text-status-label uppercase tracking-wide text-text-secondary">
                    Visit Summary
                </p>

                <div class="mt-md space-y-sm">

                    <div class="flex items-center justify-between gap-md">

                        <span class="text-status-label text-text-secondary">
                            Visitor
                        </span>

                        <span class="text-body font-semibold text-text-primary">
                            Maria Santos
                        </span>

                    </div>


                    <div class="flex items-center justify-between gap-md">

                        <span class="text-status-label text-text-secondary">
                            PDL
                        </span>

                        <span class="text-body text-text-primary">
                            Juan Dela Cruz
                        </span>

                    </div>


                    <div class="flex items-center justify-between gap-md">

                        <span class="text-status-label text-text-secondary">
                            Check-In
                        </span>

                        <span class="text-body text-text-primary">
                            8:52 AM
                        </span>

                    </div>

                </div>

            </div>


            {{-- ID Return --}}
            <div class="rounded-sm border border-border bg-background p-md">

                <div class="flex items-start gap-md">

                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-chip bg-success/10 text-success">

                        @include('admin.partials.icon', [
                            'name' => 'id-card',
                            'class' => 'h-5 w-5'
                        ])

                    </div>


                    <div>

                        <h3 class="text-body font-semibold text-text-primary">
                            Return Visitor ID
                        </h3>

                        <p class="mt-xs text-status-label text-text-secondary">
                            Confirm that the surrendered ID has been returned to the visitor.
                        </p>

                    </div>

                </div>


                <label class="mt-md flex cursor-pointer items-center gap-sm">

                    <input
                        type="checkbox"
                        id="id-returned"
                        class="h-4 w-4 rounded border-border text-primary-navy focus:ring-primary-teal"
                    >

                    <span class="text-body text-text-primary">
                        Visitor ID returned
                    </span>

                </label>


                <button
                    type="button"
                    id="complete-checkout-button"
                    disabled
                    class="mt-md flex w-full items-center justify-center gap-sm rounded-chip bg-primary-navy px-md py-sm text-body font-semibold text-white opacity-50 transition"
                >

                    @include('admin.partials.icon', [
                        'name' => 'logout',
                        'class' => 'h-5 w-5'
                    ])

                    Complete Check-Out

                </button>

            </div>

        </div>

    </section>


    {{-- =========================================================
        JAVASCRIPT
    ========================================================== --}}
    @push('scripts')

        <script>

            document.addEventListener('DOMContentLoaded', function () {

                /*
                |--------------------------------------------------------------------------
                | QR SCANNER SIMULATION
                |--------------------------------------------------------------------------
                */

                const scanButton = document.getElementById('scan-button');
                const scanButtonText = document.getElementById('scan-button-text');
                const scannerMessage = document.getElementById('scanner-message');

                if (scanButton) {

                    scanButton.addEventListener('click', function () {

                        scanButton.disabled = true;

                        scanButtonText.textContent = 'Scanning...';

                        scannerMessage.textContent =
                            'Scanning visitor QR code...';

                        setTimeout(function () {

                            scanButton.disabled = false;

                            scanButtonText.textContent = 'Scan QR Code';

                            scannerMessage.textContent =
                                'QR code detected. Visitor information verified.';

                        }, 1500);

                    });

                }


                /*
                |--------------------------------------------------------------------------
                | ID SURRENDER / CHECK-IN
                |--------------------------------------------------------------------------
                */

                const idSurrendered =
                    document.getElementById('id-surrendered');

                const checkInButton =
                    document.getElementById('check-in-button');

                if (idSurrendered && checkInButton) {

                    idSurrendered.addEventListener('change', function () {

                        if (this.checked) {

                            checkInButton.disabled = false;

                            checkInButton.classList.remove('opacity-50');

                        } else {

                            checkInButton.disabled = true;

                            checkInButton.classList.add('opacity-50');

                        }

                    });


                    checkInButton.addEventListener('click', function () {

                        if (!idSurrendered.checked) {
                            return;
                        }

                        checkInButton.textContent =
                            'Visitor Checked In';

                        checkInButton.disabled = true;

                        checkInButton.classList.remove('opacity-50');

                    });

                }


                /*
                |--------------------------------------------------------------------------
                | CHECK-OUT PANEL
                |--------------------------------------------------------------------------
                */

                const checkOutButton =
                    document.getElementById('check-out-button');

                const checkoutPanel =
                    document.getElementById('checkout-panel');

                if (checkOutButton && checkoutPanel) {

                    checkOutButton.addEventListener('click', function () {

                        checkoutPanel.classList.remove('hidden');

                        checkoutPanel.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });

                    });

                }


                /*
                |--------------------------------------------------------------------------
                | ID RETURN / COMPLETE CHECK-OUT
                |--------------------------------------------------------------------------
                */

                const idReturned =
                    document.getElementById('id-returned');

                const completeCheckoutButton =
                    document.getElementById('complete-checkout-button');

                if (idReturned && completeCheckoutButton) {

                    idReturned.addEventListener('change', function () {

                        if (this.checked) {

                            completeCheckoutButton.disabled = false;

                            completeCheckoutButton.classList.remove(
                                'opacity-50'
                            );

                        } else {

                            completeCheckoutButton.disabled = true;

                            completeCheckoutButton.classList.add(
                                'opacity-50'
                            );

                        }

                    });


                    completeCheckoutButton.addEventListener('click', function () {

                        if (!idReturned.checked) {
                            return;
                        }

                        completeCheckoutButton.textContent =
                            'Check-Out Completed';

                        completeCheckoutButton.disabled = true;

                        completeCheckoutButton.classList.remove(
                            'opacity-50'
                        );

                    });

                }

            });

        </script>

    @endpush

@endsection