import { Html5Qrcode } from "html5-qrcode";

function initializeFrontDeskScanner() {

    /*
    |--------------------------------------------------------------------------
    | ELEMENTS — CHECK-IN
    |--------------------------------------------------------------------------
    */

    const startScannerBtn =
        document.getElementById("startScannerBtn");

    const stopScannerBtn =
        document.getElementById("stopScannerBtn");

    const scannerSection =
        document.getElementById("scannerSection");

    const scannerStatus =
        document.getElementById("scannerStatus");

    const scannerError =
        document.getElementById("scannerError");

    // Optional replacement-ID photo (JPEG data URL) and its camera stream.
    let newIdPhoto =
        null;

    let newIdPhotoStream =
        null;

    const scanResult =
        document.getElementById("scanResult");

    const visitorName =
        document.getElementById("visitorName");

    const visitorDetails =
        document.getElementById("visitorDetails");

    const resultVisitor =
        document.getElementById("resultVisitor");

    const resultPdl =
        document.getElementById("resultPdl");

    const resultSchedule =
        document.getElementById("resultSchedule");


    /*
    |--------------------------------------------------------------------------
    | CHECK-IN PROGRESS
    |--------------------------------------------------------------------------
    */

    const progressScan =
        document.getElementById("progressScan");

    const progressVerify =
        document.getElementById("progressVerify");

    const progressId =
        document.getElementById("progressId");

    const progressConfirm =
        document.getElementById("progressConfirm");


    /*
    |--------------------------------------------------------------------------
    | REGISTERED ID
    |--------------------------------------------------------------------------
    */

    const registeredIdType =
        document.getElementById("registeredIdType");

    const registeredIdNumber =
        document.getElementById("registeredIdNumber");

    const registeredIdStatus =
        document.getElementById("registeredIdStatus");


    /*
    |--------------------------------------------------------------------------
    | CHECK-IN ID MATCH / REPLACEMENT
    |--------------------------------------------------------------------------
    */

    const idMatchesBtn =
        document.getElementById("idMatchesBtn");

    const idDoesNotMatchBtn =
        document.getElementById("idDoesNotMatchBtn");

    const matchedIdSection =
        document.getElementById("matchedIdSection");

    const newIdSection =
        document.getElementById("newIdSection");

    const idSurrenderedType =
        document.getElementById("id_surrendered_type");

    const newIdType =
        document.getElementById("new_id_type");

    const newIdNumber =
        document.getElementById("new_id_number");

    const newIdReason =
        document.getElementById("new_id_reason");

    const newIdOtherReason =
        document.getElementById("new_id_other_reason");

    const otherReasonContainer =
        document.getElementById("otherReasonContainer");

    const newIdVerified =
        document.getElementById("newIdVerified");

    const saveNewIdBtn =
        document.getElementById("saveNewIdBtn");

    const confirmCheckInBtn =
        document.getElementById("confirmCheckInBtn");

    const cancelScanBtn =
        document.getElementById("cancelScanBtn");


    /*
    |--------------------------------------------------------------------------
    | MANUAL CHECK-IN
    |--------------------------------------------------------------------------
    */

    const manualVisitorSearch =
        document.getElementById("manualVisitorSearch");

    const manualSearchResults =
        document.getElementById("manualSearchResults");

    const selectedManualVisitor =
        document.getElementById("selectedManualVisitor");

    const selectedVisitorName =
        document.getElementById("selectedVisitorName");

    const selectedVisitorDetails =
        document.getElementById("selectedVisitorDetails");

    const selectedVisitorSchedule =
        document.getElementById("selectedVisitorSchedule");

    const changeManualVisitorBtn =
        document.getElementById("changeManualVisitorBtn");

    const manualVerificationSection =
        document.getElementById("manualVerificationSection");

    const manualIdType =
        document.getElementById("manualIdType");

    const manualIdNumber =
        document.getElementById("manualIdNumber");

    const overrideReason =
        document.getElementById("overrideReason");

    const manualCheckInBtn =
        document.getElementById("manualCheckInBtn");


    /*
    |--------------------------------------------------------------------------
    | CHECK-OUT ELEMENTS
    |--------------------------------------------------------------------------
    |
    | Your current Blade uses:
    |
    | checkoutScannerStartButton
    | checkoutScannerSection
    | checkout-qr-reader
    | checkoutScannerStatus
    | checkoutScannerError
    | checkoutScanResult
    |
    | Older versions used:
    |
    | startCheckoutScannerBtn
    |
    | This JS supports both.
    |--------------------------------------------------------------------------
    */

    const checkoutScannerStartButton =
        document.getElementById(
            "checkoutScannerStartButton"
        ) ||
        document.getElementById(
            "startCheckoutScannerBtn"
        );


    const checkoutScannerSection =
        document.getElementById(
            "checkoutScannerSection"
        );


    const checkoutQrReader =
        document.getElementById(
            "checkout-qr-reader"
        );


    /*
     * If the newer checkout scanner elements are not present,
     * support the older shared scanner container.
     */

    const checkoutReaderElement =
        checkoutQrReader ||
        document.getElementById(
            "qr-reader"
        );


    const checkoutSectionElement =
        checkoutScannerSection ||
        document.getElementById(
            "scannerSection"
        );


    const checkoutScannerStatus =
        document.getElementById(
            "checkoutScannerStatus"
        ) ||
        document.getElementById(
            "scannerStatus"
        );


    const checkoutScannerError =
        document.getElementById(
            "checkoutScannerError"
        ) ||
        document.getElementById(
            "scannerError"
        );


    const checkoutScanResult =
        document.getElementById(
            "checkoutScanResult"
        );


    /*
    |--------------------------------------------------------------------------
    | CHECK-OUT RESULT ELEMENTS
    |--------------------------------------------------------------------------
    */

    const qrCheckoutVisitorName =
        document.getElementById(
            "qrCheckoutVisitorName"
        );

    const qrCheckoutVisitorId =
        document.getElementById(
            "qrCheckoutVisitorId"
        );

    const qrCheckoutPdlName =
        document.getElementById(
            "qrCheckoutPdlName"
        );

    const qrCheckoutPdlNumber =
        document.getElementById(
            "qrCheckoutPdlNumber"
        );

    const qrCheckoutCheckinTime =
        document.getElementById(
            "qrCheckoutCheckinTime"
        );

    const qrCheckoutStatus =
        document.getElementById(
            "qrCheckoutStatus"
        );

    const qrCheckoutIdType =
        document.getElementById(
            "qrCheckoutIdType"
        );

    const qrCheckoutIdNumber =
        document.getElementById(
            "qrCheckoutIdNumber"
        );

    const qrCheckoutIdReturned =
        document.getElementById(
            "qrCheckoutIdReturned"
        );

    const qrConfirmCheckoutBtn =
        document.getElementById(
            "qrConfirmCheckoutBtn"
        );


    /*
    |--------------------------------------------------------------------------
    | CHECK-OUT ALTERNATE ELEMENTS
    |--------------------------------------------------------------------------
    */

    const checkoutInfoVisitor =
        document.getElementById(
            "checkoutInfoVisitor"
        );

    const checkoutInfoVisitorId =
        document.getElementById(
            "checkoutInfoVisitorId"
        );

    const checkoutInfoPdl =
        document.getElementById(
            "checkoutInfoPdl"
        );

    const checkoutInfoPdlNumber =
        document.getElementById(
            "checkoutInfoPdlNumber"
        );

    const checkoutInfoCheckinTime =
        document.getElementById(
            "checkoutInfoCheckinTime"
        );

    const checkoutInfoStatus =
        document.getElementById(
            "checkoutInfoStatus"
        );

    const checkoutIdType =
        document.getElementById(
            "checkoutIdType"
        );

    const checkoutIdNumber =
        document.getElementById(
            "checkoutIdNumber"
        );

    const checkoutIdReturned =
        document.getElementById(
            "checkoutIdReturned"
        );

    const confirmCheckoutBtn =
        document.getElementById(
            "confirmCheckoutBtn"
        );

    const cancelCheckoutBtn =
        document.getElementById(
            "cancelCheckoutBtn"
        );


    /*
    |--------------------------------------------------------------------------
    | CHECK-OUT SCANNER STOP BUTTON
    |--------------------------------------------------------------------------
    |
    | Your current Blade has the Start button but does not have a dedicated
    | Stop button. We create it automatically so you do NOT have to edit
    | the Blade just to make Stop Scanner work.
    |--------------------------------------------------------------------------
    */

    let checkoutScannerStopButton =
        document.getElementById(
            "checkoutScannerStopButton"
        );


    if (
        checkoutScannerStartButton &&
        !checkoutScannerStopButton
    ) {

        checkoutScannerStopButton =
            document.createElement("button");

        checkoutScannerStopButton.type =
            "button";

        checkoutScannerStopButton.id =
            "checkoutScannerStopButton";

        checkoutScannerStopButton.className =
            "cc-btn-secondary hidden";

        checkoutScannerStopButton.textContent =
            "Stop Scanner";


        checkoutScannerStartButton
            .parentElement
            ?.appendChild(
                checkoutScannerStopButton
            );
    }


    /*
    |--------------------------------------------------------------------------
    | STATE
    |--------------------------------------------------------------------------
    */

    let qrScanner = null;

    let checkoutQrScanner = null;

    let scannerRunning = false;

    let checkoutScannerRunning = false;

    let activeScannerMode = null;

    let scannedToken = null;

    let scannedVisitRequestId = null;

    let idMatchStatus = null;

    let registeredIds = [];

    let selectedManualVisitRequestId = null;

    let newIdSaved = false;

    // Shared state for the Blade summary step.
    window.frontDeskCheckinIdMatchStatus = null;
    window.frontDeskCheckinNewIdSaved = false;


    /*
    |--------------------------------------------------------------------------
    | CHECK-OUT STATE
    |--------------------------------------------------------------------------
    */

    let checkoutScannedToken = null;

    let checkoutCheckinId = null;

    let checkoutScanData = null;


    /*
    |--------------------------------------------------------------------------
    | MANUAL VISITORS
    |--------------------------------------------------------------------------
    */

    const manualVisitors =
        Array.isArray(
            window.frontDeskExpectedVisitors
        )
            ? window.frontDeskExpectedVisitors
            : [];


    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /*
     * Each check-in section (scanner, new ID, summary, manual) has its own
     * error box; a message shows in the ones whose section is on screen,
     * so it appears where the officer clicked.
     */
    const errorBoxes = () => [
        scannerError,
        document.getElementById("newIdError"),
        document.getElementById("checkinSummaryError"),
        document.getElementById("manualCheckInError"),
    ].filter(Boolean);


    function showError(message) {

        const boxes =
            errorBoxes();

        const onScreen =
            boxes.filter((box) => box.parentElement?.offsetParent);

        (onScreen.length ? onScreen : boxes).forEach((box) => {
            box.textContent =
                message;

            box.classList.remove(
                "hidden"
            );
        });
    }


    function hideError() {

        errorBoxes().forEach((box) => {
            box.textContent =
                "";

            box.classList.add(
                "hidden"
            );
        });
    }


    /*
    |--------------------------------------------------------------------------
    | FIELD VALIDATION
    |--------------------------------------------------------------------------
    | Same rules as the server (App\Http\Requests\FrontDesk, App\Support\
    | Sanitizer), so the officer sees every problem on its field before the
    | request is sent. The server still validates everything.
    */

    const ID_NUMBER_PATTERN =
        /^[A-Z0-9](?:[A-Z0-9 /-]*[A-Z0-9])?$/;

    /** "a12 - 345  678" -> "A12-345 678", like Sanitizer::idNumber(). */
    function cleanIdNumber(value) {

        return String(value ?? "")
            .replace(/<[^>]*>/g, "")
            .toUpperCase()
            .replace(/\s+/g, " ")
            .replace(/\s*([/-])\s*/g, "$1")
            .trim();
    }

    /** Problem with an ID number, or null when it is fine. */
    function idNumberProblem(value) {

        if (!value) {
            return "Enter the ID number.";
        }

        if (value.length < 4) {
            return "The ID number is too short. Check it against the physical ID.";
        }

        if (!ID_NUMBER_PATTERN.test(value)) {
            return "Use only letters, numbers, spaces, hyphens and slashes.";
        }

        return null;
    }

    function setFieldError(input, message) {

        if (!input) {
            return;
        }

        const errorId =
            `${input.id}FieldError`;

        let note =
            document.getElementById(errorId);

        if (!note) {

            note = document.createElement("p");
            note.id = errorId;
            note.className = "mt-xs text-metadata font-semibold text-red-700";

            // A checkbox sits inside its label; put the note under the whole line.
            const anchor =
                input.type === "checkbox"
                    ? (input.closest("label") ?? input)
                    : input;

            anchor.insertAdjacentElement("afterend", note);
        }

        note.textContent = message;

        input.classList.add("border-red-500");
        input.setAttribute("aria-invalid", "true");
        input.setAttribute("aria-describedby", errorId);
    }

    function clearFieldError(input) {

        if (!input) {
            return;
        }

        document.getElementById(`${input.id}FieldError`)?.remove();

        input.classList.remove("border-red-500");
        input.removeAttribute("aria-invalid");
        input.removeAttribute("aria-describedby");
    }

    /**
     * Marks each [input, problem] pair; returns true when all are fine.
     * Otherwise shows a summary and focuses the first problem.
     */
    function checkFields(pairs) {

        let first = null;

        pairs.forEach(([input, problem]) => {

            if (problem) {

                setFieldError(input, problem);
                first ??= input;

            } else {

                clearFieldError(input);
            }
        });

        if (first) {

            const count =
                pairs.filter(([, problem]) => problem).length;

            showError(
                count === 1
                    ? pairs.find(([, problem]) => problem)[1]
                    : `Please fix the ${count} highlighted fields.`
            );

            first.focus();

            return false;
        }

        return true;
    }


    function setScannerStatus(message) {

        if (scannerStatus) {

            scannerStatus.textContent =
                message;
        }
    }


    function setCheckoutScannerStatus(message) {

        if (checkoutScannerStatus) {

            checkoutScannerStatus.textContent =
                message;
        }
    }


    function showCheckoutError(message) {

        if (!checkoutScannerError) {
            return;
        }

        checkoutScannerError.textContent =
            message;

        checkoutScannerError.classList.remove(
            "hidden"
        );
    }


    function hideCheckoutError() {

        if (!checkoutScannerError) {
            return;
        }

        checkoutScannerError.textContent =
            "";

        checkoutScannerError.classList.add(
            "hidden"
        );
    }


    function getCsrfToken() {

        return document
            .querySelector(
                'meta[name="csrf-token"]'
            )
            ?.getAttribute("content") || "";
    }


    function setText(element, value) {

        if (!element) {
            return;
        }

        element.textContent =
            value ??
            "—";
    }


    /*
    |--------------------------------------------------------------------------
    | PROGRESS
    |--------------------------------------------------------------------------
    */

    function updateProgress(step) {

        const steps = [
            progressScan,
            progressVerify,
            progressId,
            progressConfirm,
        ];


        steps.forEach(
            (element, index) => {

                if (!element) {
                    return;
                }


                const currentStep =
                    index + 1;


                element.classList.remove(
                    "text-text-primary",
                    "text-text-secondary",
                    "font-semibold",
                    "opacity-50"
                );


                if (
                    currentStep <= step
                ) {

                    element.classList.add(
                        "text-text-primary",
                        "font-semibold"
                    );

                } else {

                    element.classList.add(
                        "text-text-secondary",
                        "opacity-50"
                    );
                }
            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | RESET CHECK-IN RESULT
    |--------------------------------------------------------------------------
    */

    function resetScanResult() {

        scanResult?.classList.add(
            "hidden"
        );


        setText(
            visitorName,
            "—"
        );

        setText(
            visitorDetails,
            "—"
        );

        setText(
            resultVisitor,
            "—"
        );

        setText(
            resultPdl,
            "—"
        );

        setText(
            resultSchedule,
            "—"
        );

        [
            "resultRelationship",
            "resultPdlNumber",
            "resultPdlStatus",
            "resultScheduleTime",
            "resultScheduleBuilding",
            "resultScheduleClassification",
            "resultVisitStatus",
        ].forEach((id) =>
            setText(
                document.getElementById(id),
                "—"
            )
        );


        setText(
            registeredIdType,
            "—"
        );

        setText(
            registeredIdNumber,
            "—"
        );

        setText(
            registeredIdStatus,
            "—"
        );


        matchedIdSection?.classList.add(
            "hidden"
        );

        newIdSection?.classList.add(
            "hidden"
        );


        idMatchesBtn?.classList.remove(
            "ring-2",
            "ring-offset-2"
        );

        idDoesNotMatchBtn?.classList.remove(
            "ring-2",
            "ring-offset-2"
        );


        if (idSurrenderedType) {
            idSurrenderedType.value =
                "";
        }

        if (newIdType) {
            newIdType.value =
                "";
        }

        if (newIdNumber) {
            newIdNumber.value =
                "";
        }

        if (newIdReason) {
            newIdReason.value =
                "";
        }

        if (newIdOtherReason) {
            newIdOtherReason.value =
                "";
        }

        if (newIdVerified) {
            newIdVerified.checked =
                false;
        }


        otherReasonContainer?.classList.add(
            "hidden"
        );


        if (saveNewIdBtn) {

            saveNewIdBtn.disabled =
                false;

            saveNewIdBtn.textContent =
                "Verify & Add New ID";
        }


        if (confirmCheckInBtn) {

            confirmCheckInBtn.disabled =
                false;

            confirmCheckInBtn.textContent =
                "Confirm Check-In";
        }


        scannedToken =
            null;

        scannedVisitRequestId =
            null;

        idMatchStatus =
            null;

        window.frontDeskCheckinIdMatchStatus = null;
        window.frontDeskCheckinNewIdSaved = false;
        window.frontDeskCheckinDetails = null;

        registeredIds =
            [];

        newIdSaved =
            false;

        [newIdType, newIdNumber, newIdReason, newIdOtherReason, newIdVerified].forEach(clearFieldError);

        clearNewIdPhoto();


        updateProgress(1);
    }


    /*
    |--------------------------------------------------------------------------
    | DISPLAY REGISTERED IDS
    |--------------------------------------------------------------------------
    */

    function displayRegisteredIds(ids) {

        registeredIds =
            Array.isArray(ids)
                ? ids
                : [];


        if (
            registeredIds.length === 0
        ) {

            setText(
                registeredIdType,
                "No verified ID on file"
            );

            setText(
                registeredIdNumber,
                "—"
            );

            setText(
                registeredIdStatus,
                "No registered ID"
            );

            return;
        }


        const id =
            registeredIds[0];


        setText(
            registeredIdType,
            id.type_label ||
            id.type ||
            "—"
        );


        setText(
            registeredIdNumber,
            id.number ||
            "—"
        );


        setText(
            registeredIdStatus,
            id.status
                ? id.status.charAt(0).toUpperCase() +
                  id.status.slice(1)
                : "Verified"
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SHOW CHECK-IN SCAN RESULT
    |--------------------------------------------------------------------------
    */

    function showScanResult(data) {

        const visitor =
            data.visitor ??
            {};


        setText(
            visitorName,
            visitor.name
        );


        setText(
            visitorDetails,
            `Visiting ${visitor.pdl ?? "—"} (${visitor.pdl_number ?? "—"})`
        );


        const details =
            data.details ??
            {};

        const schedule =
            data.schedule ??
            {};

        // Read by the summary checklist (Blade script).
        window.frontDeskCheckinDetails =
            data.details ??
            null;


        setText(
            resultVisitor,
            details.visitor_id ??
            visitor.visitor_id
        );


        setText(
            resultPdl,
            visitor.pdl
        );


        setText(
            resultSchedule,
            schedule.date ??
            "Today"
        );


        const setById = (id, value) =>
            setText(
                document.getElementById(id),
                value
            );

        setById("resultRelationship", details.relationship);
        setById("resultPdlNumber", visitor.pdl_number);
        setById("resultPdlStatus", details.pdl_status);
        setById(
            "resultScheduleTime",
            schedule.start
                ? `${schedule.start} – ${schedule.end} (${schedule.session}${schedule.is_reentry ? ", re-entry" : ""})`
                : null
        );
        setById("resultScheduleBuilding", details.building);
        setById("resultScheduleClassification", details.classification);
        setById("resultVisitStatus", details.visit_status);


        displayRegisteredIds(
            data.registered_ids
        );


        scanResult?.classList.remove(
            "hidden"
        );


        updateProgress(2);


        setScannerStatus(
            "Visitor verified. Please review the visit details and compare the physical ID with the registered ID."
        );


        window.dispatchEvent(
            new CustomEvent(
                "custodicore:checkin-progress",
                {
                    detail: {
                        step: 2
                    }
                }
            )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | STOP CHECK-IN SCANNER
    |--------------------------------------------------------------------------
    */

    async function stopScanner() {

        if (!qrScanner) {

            scannerRunning =
                false;

            if (
                activeScannerMode ===
                "checkin"
            ) {
                activeScannerMode =
                    null;
            }

            scannerSection?.classList.add(
                "hidden"
            );

            startScannerBtn?.classList.remove(
                "hidden"
            );

            stopScannerBtn?.classList.add(
                "hidden"
            );

            return;
        }


        try {

            if (scannerRunning) {

                await qrScanner.stop();
            }

        } catch (error) {

            console.warn(
                "QR scanner stop error:",
                error
            );
        }


        try {

            await qrScanner.clear();

        } catch (error) {

            console.warn(
                "QR scanner clear error:",
                error
            );
        }


        scannerRunning =
            false;

        qrScanner =
            null;


        if (
            activeScannerMode ===
            "checkin"
        ) {

            activeScannerMode =
                null;
        }


        scannerSection?.classList.add(
            "hidden"
        );

        startScannerBtn?.classList.remove(
            "hidden"
        );

        stopScannerBtn?.classList.add(
            "hidden"
        );


        setScannerStatus(
            "Camera stopped."
        );
    }


    /*
    |--------------------------------------------------------------------------
    | STOP CHECK-OUT SCANNER
    |--------------------------------------------------------------------------
    */

    async function stopCheckoutScanner() {

        if (!checkoutQrScanner) {

            checkoutScannerRunning =
                false;

            if (
                activeScannerMode ===
                "checkout"
            ) {
                activeScannerMode =
                    null;
            }

            checkoutSectionElement?.classList.add(
                "hidden"
            );

            checkoutScannerStartButton?.classList.remove(
                "hidden"
            );

            checkoutScannerStopButton?.classList.add(
                "hidden"
            );

            return;
        }


        try {

            if (
                checkoutScannerRunning
            ) {

                await checkoutQrScanner.stop();
            }

        } catch (error) {

            console.warn(
                "Checkout QR scanner stop error:",
                error
            );
        }


        try {

            await checkoutQrScanner.clear();

        } catch (error) {

            console.warn(
                "Checkout QR scanner clear error:",
                error
            );
        }


        checkoutScannerRunning =
            false;

        checkoutQrScanner =
            null;


        if (
            activeScannerMode ===
            "checkout"
        ) {

            activeScannerMode =
                null;
        }


        checkoutSectionElement?.classList.add(
            "hidden"
        );

        checkoutScannerStartButton?.classList.remove(
            "hidden"
        );

        checkoutScannerStopButton?.classList.add(
            "hidden"
        );


        setCheckoutScannerStatus(
            "Camera stopped."
        );
    }


    /*
    |--------------------------------------------------------------------------
    | STOP ANY ACTIVE SCANNER
    |--------------------------------------------------------------------------
    */

    async function stopAnyActiveScanner() {

        if (
            activeScannerMode ===
            "checkin"
        ) {

            await stopScanner();

        } else if (
            activeScannerMode ===
            "checkout"
        ) {

            await stopCheckoutScanner();
        }
    }


    /*
    |--------------------------------------------------------------------------
    | PROCESS CHECK-IN QR TOKEN
    |--------------------------------------------------------------------------
    */

    async function processQrToken(
        qrToken
    ) {

        if (
            !qrToken ||
            !scannerRunning
        ) {
            return;
        }


        scannerRunning =
            false;


        await stopScanner();

        hideError();


        setScannerStatus(
            "QR code detected. Verifying visitor..."
        );


        try {

            const response =
                await fetch(
                    "/front-desk/checkin-checkout/scan",
                    {
                        method: "POST",

                        headers: {

                            "Content-Type":
                                "application/json",

                            "Accept":
                                "application/json",

                            "X-CSRF-TOKEN":
                                getCsrfToken(),
                        },

                        body:
                            JSON.stringify({

                                qr_token:
                                    qrToken,

                                mode:
                                    "checkin",
                            }),
                    }
                );


            const data =
                await response.json();


            if (
                !response.ok ||
                !data.success
            ) {

                throw new Error(
                    data.message ||
                    "The QR code could not be verified."
                );
            }


            scannedToken =
                qrToken;


            scannedVisitRequestId =
                data.visit_request_id ??
                data.visitRequestId ??
                data.visit_request?.id ??
                null;


            showScanResult(
                data
            );


        } catch (error) {

            console.error(
                "QR verification failed:",
                error
            );


            showError(
                error.message ||
                "Unable to verify the QR code."
            );


            setScannerStatus(
                "Verification failed. Please scan the QR code again."
            );


            startScannerBtn?.classList.remove(
                "hidden"
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | START CHECK-IN SCANNER
    |--------------------------------------------------------------------------
    */

    async function startScanner() {

        hideError();

        resetScanResult();


        /*
         * Make sure checkout camera is not using the device.
         */

        if (
            activeScannerMode ===
            "checkout"
        ) {

            await stopCheckoutScanner();
        }


        scannerSection?.classList.remove(
            "hidden"
        );


        startScannerBtn?.classList.add(
            "hidden"
        );


        stopScannerBtn?.classList.remove(
            "hidden"
        );


        updateProgress(1);


        setScannerStatus(
            "Starting camera..."
        );


        try {

            if (qrScanner) {

                try {

                    await qrScanner.stop();

                } catch (error) {

                    console.warn(
                        "Previous scanner stop:",
                        error
                    );
                }


                try {

                    await qrScanner.clear();

                } catch (error) {

                    console.warn(
                        "Previous scanner clear:",
                        error
                    );
                }


                qrScanner =
                    null;
            }


            qrScanner =
                new Html5Qrcode(
                    "qr-reader"
                );


            const config = {

                fps: 10,

                qrbox: {
                    width: 250,
                    height: 250,
                },

                aspectRatio: 1.0,
            };


            activeScannerMode =
                "checkin";


            await qrScanner.start(

                {
                    facingMode:
                        "environment",
                },

                config,

                async (
                    decodedText
                ) => {

                    if (
                        !scannerRunning
                    ) {
                        return;
                    }


                    await processQrToken(
                        decodedText
                    );
                },

                () => {
                    /*
                     * QR code not found in this frame.
                     */
                }
            );


            scannerRunning =
                true;


            setScannerStatus(
                "Camera is ready. Please present the visitor's QR code."
            );


        } catch (error) {

            console.error(
                "Unable to start QR scanner:",
                error
            );


            scannerRunning =
                false;

            activeScannerMode =
                null;


            try {

                if (qrScanner) {

                    await qrScanner.clear();
                }

            } catch (clearError) {

                console.warn(
                    "Scanner cleanup error:",
                    clearError
                );
            }


            qrScanner =
                null;


            scannerSection?.classList.add(
                "hidden"
            );

            startScannerBtn?.classList.remove(
                "hidden"
            );

            stopScannerBtn?.classList.add(
                "hidden"
            );


            showError(
                "Unable to access the camera. Please make sure camera permission is allowed and that no other scanner is currently using the camera."
            );


            setScannerStatus(
                "Camera could not be started."
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | PROCESS CHECK-OUT QR TOKEN
    |--------------------------------------------------------------------------
    */

    async function processCheckoutQrToken(
        qrToken
    ) {

        if (
            !qrToken ||
            !checkoutScannerRunning
        ) {
            return;
        }


        checkoutScannerRunning =
            false;


        await stopCheckoutScanner();

        hideCheckoutError();


        setCheckoutScannerStatus(
            "QR code detected. Looking for the visitor's active visit..."
        );


        try {

            /*
             * We use the same scanner verification endpoint as check-in.
             *
             * The important difference is:
             *
             * mode: checkout
             *
             * This lets the backend know that the visitor must already
             * have an active check-in.
             */

            const response =
                await fetch(
                    "/front-desk/checkin-checkout/scan",
                    {
                        method: "POST",

                        headers: {

                            "Content-Type":
                                "application/json",

                            "Accept":
                                "application/json",

                            "X-CSRF-TOKEN":
                                getCsrfToken(),
                        },

                        body:
                            JSON.stringify({

                                qr_token:
                                    qrToken,

                                mode:
                                    "checkout",
                            }),
                    }
                );


            const data =
                await response.json();


            if (
                !response.ok ||
                !data.success
            ) {

                throw new Error(
                    data.message ||
                    "No active visit was found for this QR code."
                );
            }


            /*
             * Try all reasonable response shapes.
             *
             * This makes the frontend compatible with:
             *
             * data.checkin_id
             * data.checkin.id
             * data.checkin.checkin_id
             * data.active_visit.id
             * data.active_visit.checkin_id
             * data.activeVisit.id
             * data.activeVisit.checkin_id
             */

            const activeVisit =
                data.active_visit ||
                data.activeVisit ||
                data.checkin ||
                data.active_checkin ||
                {};


            checkoutCheckinId =
                data.checkin_id ??
                data.checkinId ??
                data.active_checkin_id ??
                data.activeCheckinId ??
                activeVisit.checkin_id ??
                activeVisit.checkinId ??
                activeVisit.id ??
                null;


            checkoutScannedToken =
                qrToken;


            checkoutScanData =
                data;


            /*
             * If the backend successfully verifies the visitor but does
             * not return the check-in record ID, we cannot safely submit
             * the checkout because the existing checkout route requires
             * the checkin ID.
             */

            if (
                !checkoutCheckinId
            ) {

                throw new Error(
                    "The QR code was verified, but the active check-in record ID was not returned by the server. Please update the scan response to include checkin_id."
                );
            }


            showCheckoutScanResult(
                data,
                activeVisit
            );


        } catch (error) {

            console.error(
                "Checkout QR verification failed:",
                error
            );


            showCheckoutError(
                error.message ||
                "Unable to find the visitor's active visit."
            );


            setCheckoutScannerStatus(
                "Verification failed. Please scan the visitor's QR code again."
            );


            checkoutScannerStartButton?.classList.remove(
                "hidden"
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SHOW CHECK-OUT SCAN RESULT
    |--------------------------------------------------------------------------
    */

    function showCheckoutScanResult(
        data,
        activeVisit
    ) {

        const visitRequest =
            data.visit_request ||
            data.visitRequest ||
            activeVisit.visit_request ||
            activeVisit.visitRequest ||
            {};


        const visitor =
            data.visitor ||
            activeVisit.visitor ||
            visitRequest.visitor ||
            {};


        const pdl =
            data.pdl ||
            activeVisit.pdl ||
            visitRequest.pdl ||
            {};


        const visitorNameValue =
            visitor.full_name ||
            visitor.name ||
            data.visitor_name ||
            activeVisit.visitor_name ||
            "—";


        const visitorIdValue =
            visitor.visitor_id ||
            visitor.id_number ||
            data.visitor_id ||
            activeVisit.visitor_id ||
            "—";


        const pdlNameValue =
            pdl.full_name ||
            pdl.name ||
            data.pdl_name ||
            activeVisit.pdl_name ||
            "—";


        const pdlNumberValue =
            pdl.pdl_number ||
            pdl.number ||
            data.pdl_number ||
            activeVisit.pdl_number ||
            "—";


        const checkinTimeValue =
            data.check_in_time ||
            data.checkin_time ||
            activeVisit.check_in_time ||
            activeVisit.checkin_time ||
            "—";


        const statusValue =
            data.status_label ||
            data.status ||
            "Inside Facility";


        const surrenderedId =
            data.surrendered_id ||
            data.surrenderedId ||
            activeVisit.surrendered_id ||
            activeVisit.surrenderedId ||
            data.id ||
            {};


        const idTypeValue =
            surrenderedId.type_label ||
            surrenderedId.type ||
            data.id_surrendered_type ||
            data.id_type ||
            activeVisit.id_surrendered_type ||
            activeVisit.id_type ||
            "—";


        const idNumberValue =
            surrenderedId.number ||
            data.id_number ||
            activeVisit.id_number ||
            "—";


        /*
         * New scanner result panel.
         */

        setText(
            qrCheckoutVisitorName,
            visitorNameValue
        );


        setText(
            qrCheckoutVisitorId,
            `Visitor ID: ${visitorIdValue}`
        );


        setText(
            qrCheckoutPdlName,
            pdlNameValue
        );


        setText(
            qrCheckoutPdlNumber,
            `PDL No.: ${pdlNumberValue}`
        );


        setText(
            qrCheckoutCheckinTime,
            checkinTimeValue
        );


        setText(
            qrCheckoutStatus,
            statusValue
        );


        setText(
            qrCheckoutIdType,
            idTypeValue
        );


        setText(
            qrCheckoutIdNumber,
            idNumberValue
        );


        /*
         * Alternate checkout information panel.
         */

        setText(
            checkoutInfoVisitor,
            visitorNameValue
        );


        setText(
            checkoutInfoVisitorId,
            visitorIdValue
        );


        setText(
            checkoutInfoPdl,
            pdlNameValue
        );


        setText(
            checkoutInfoPdlNumber,
            pdlNumberValue
        );


        setText(
            checkoutInfoCheckinTime,
            checkinTimeValue
        );


        setText(
            checkoutInfoStatus,
            statusValue
        );


        setText(
            checkoutIdType,
            idTypeValue
        );


        setText(
            checkoutIdNumber,
            idNumberValue
        );


        /*
         * Visit details (relationship, PDL status, schedule) on both
         * checkout panels.
         */

        const details =
            data.details ||
            {};

        const schedule =
            data.schedule ||
            {};

        const registeredId =
            (data.registered_ids || [])[0] ||
            {};

        const detailFields = {
            VisitorDetails: `Visiting ${pdlNameValue} (${pdlNumberValue})`,
            Relationship: details.relationship,
            PdlStatus: details.pdl_status,
            ScheduleDate: schedule.date,
            ScheduleTime: schedule.start
                ? `${schedule.start} – ${schedule.end} (${schedule.session})`
                : null,
            ScheduleBuilding: details.building,
            ScheduleClassification: details.classification,
            VisitStatus: details.visit_status,
        };

        Object.entries(detailFields).forEach(([suffix, value]) => {
            setText(document.getElementById(`qrCheckout${suffix}`), value);
            setText(document.getElementById(`checkoutInfo${suffix}`), value);
        });

        setText(
            document.getElementById("checkoutInfoRegisteredIdType"),
            registeredId.type_label || registeredId.type
        );

        setText(
            document.getElementById("checkoutInfoRegisteredIdNumber"),
            registeredId.number
        );

        setText(
            document.getElementById("checkoutInfoRegisteredIdStatus"),
            registeredId.status
                ? registeredId.status.charAt(0).toUpperCase() + registeredId.status.slice(1)
                : null
        );


        /*
         * Reset ID return checkbox.
         */

        if (qrCheckoutIdReturned) {

            qrCheckoutIdReturned.checked =
                false;
        }


        if (checkoutIdReturned) {

            checkoutIdReturned.checked =
                false;
        }


        if (qrConfirmCheckoutBtn) {

            qrConfirmCheckoutBtn.disabled =
                true;
        }


        if (confirmCheckoutBtn) {

            confirmCheckoutBtn.disabled =
                true;
        }


        /*
         * Show the result.
         */

        checkoutScanResult?.classList.remove(
            "hidden"
        );


        /*
         * Hide checkout scanner.
         */

        checkoutSectionElement?.classList.add(
            "hidden"
        );


        checkoutScannerStartButton?.classList.remove(
            "hidden"
        );


        checkoutScannerStopButton?.classList.add(
            "hidden"
        );


        /*
         * Move to active visit / step 2.
         */

        setCheckoutScannerStatus(
            "Active visit found. Please review the visitor information."
        );


        window.dispatchEvent(
            new CustomEvent(
                "custodicore:checkout-progress",
                {
                    detail: {
                        step: 2,
                        data: data,
                        checkinId:
                            checkoutCheckinId
                    }
                }
            )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | START CHECK-OUT SCANNER
    |--------------------------------------------------------------------------
    */

    async function startCheckoutScanner() {

        hideCheckoutError();


        /*
         * Reset previous checkout scan.
         */

        checkoutScannedToken =
            null;

        checkoutCheckinId =
            null;

        checkoutScanData =
            null;


        checkoutScanResult?.classList.add(
            "hidden"
        );


        if (qrCheckoutIdReturned) {

            qrCheckoutIdReturned.checked =
                false;
        }


        if (checkoutIdReturned) {

            checkoutIdReturned.checked =
                false;
        }


        if (qrConfirmCheckoutBtn) {

            qrConfirmCheckoutBtn.disabled =
                true;
        }


        if (confirmCheckoutBtn) {

            confirmCheckoutBtn.disabled =
                true;
        }


        /*
         * Stop check-in scanner first.
         *
         * This is VERY important because browsers generally do not allow
         * two camera streams from the same device at the same time.
         */

        if (
            activeScannerMode ===
            "checkin"
        ) {

            await stopScanner();
        }


        if (!checkoutReaderElement) {

            showCheckoutError(
                "The checkout QR scanner camera area was not found. Make sure the Blade file contains #checkout-qr-reader."
            );

            return;
        }


        /*
         * Show scanner.
         */

        checkoutSectionElement?.classList.remove(
            "hidden"
        );


        checkoutScannerStartButton?.classList.add(
            "hidden"
        );


        checkoutScannerStopButton?.classList.remove(
            "hidden"
        );


        setCheckoutScannerStatus(
            "Starting camera..."
        );


        try {

            /*
             * Clean up an old scanner if one exists.
             */

            if (
                checkoutQrScanner
            ) {

                try {

                    await checkoutQrScanner.stop();

                } catch (error) {

                    console.warn(
                        "Previous checkout scanner stop:",
                        error
                    );
                }


                try {

                    await checkoutQrScanner.clear();

                } catch (error) {

                    console.warn(
                        "Previous checkout scanner clear:",
                        error
                    );
                }


                checkoutQrScanner =
                    null;
            }


            /*
             * Create checkout scanner.
             */

            checkoutQrScanner =
                new Html5Qrcode(
                    checkoutReaderElement.id
                );


            const config = {

                fps: 10,

                qrbox: {
                    width: 250,
                    height: 250,
                },

                aspectRatio: 1.0,
            };


            activeScannerMode =
                "checkout";


            /*
             * START CAMERA.
             *
             * This is the missing part from your previous code.
             */

            await checkoutQrScanner.start(

                {
                    facingMode:
                        "environment",
                },

                config,

                async (
                    decodedText
                ) => {

                    if (
                        !checkoutScannerRunning
                    ) {
                        return;
                    }


                    await processCheckoutQrToken(
                        decodedText
                    );
                },

                () => {
                    /*
                     * QR code not detected in this frame.
                     */
                }
            );


            /*
             * Camera is now actually running.
             */

            checkoutScannerRunning =
                true;


            setCheckoutScannerStatus(
                "Camera is ready. Please present the visitor's QR code."
            );


        } catch (error) {

            console.error(
                "Unable to start checkout QR scanner:",
                error
            );


            checkoutScannerRunning =
                false;

            activeScannerMode =
                null;


            try {

                if (
                    checkoutQrScanner
                ) {

                    await checkoutQrScanner.clear();
                }

            } catch (clearError) {

                console.warn(
                    "Checkout scanner cleanup error:",
                    clearError
                );
            }


            checkoutQrScanner =
                null;


            checkoutSectionElement?.classList.add(
                "hidden"
            );


            checkoutScannerStartButton?.classList.remove(
                "hidden"
            );


            checkoutScannerStopButton?.classList.add(
                "hidden"
            );


            showCheckoutError(
                "Unable to access the camera. Please make sure camera permission is allowed and that no other scanner is currently using the camera."
            );


            setCheckoutScannerStatus(
                "Camera could not be started."
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK-IN — ID MATCHES
    |--------------------------------------------------------------------------
    */

    idMatchesBtn?.addEventListener(
        "click",
        () => {

            hideError();


            if (!scannedToken) {

                showError(
                    "Please scan and verify a visitor first."
                );

                return;
            }


            idMatchStatus =
                "matched";

            window.frontDeskCheckinIdMatchStatus =
                "matched";

            newIdSaved =
                false;

            window.frontDeskCheckinNewIdSaved =
                false;


            idMatchesBtn.classList.add(
                "ring-2",
                "ring-offset-2"
            );


            idDoesNotMatchBtn?.classList.remove(
                "ring-2",
                "ring-offset-2"
            );


            clearNewIdPhoto();


            // A matching ID is already registered and verified.
            // Do NOT ask the officer to select the ID type again.
            matchedIdSection?.classList.add(
                "hidden"
            );

            newIdSection?.classList.add(
                "hidden"
            );


            if (newIdType) {
                newIdType.value =
                    "";
            }

            if (newIdNumber) {
                newIdNumber.value =
                    "";
            }

            if (newIdReason) {
                newIdReason.value =
                    "";
            }

            if (newIdOtherReason) {
                newIdOtherReason.value =
                    "";
            }

            if (newIdVerified) {
                newIdVerified.checked =
                    false;
            }


            otherReasonContainer?.classList.add(
                "hidden"
            );


            if (saveNewIdBtn) {

                saveNewIdBtn.disabled =
                    false;

                saveNewIdBtn.textContent =
                    "Verify & Add New ID";
            }


            updateProgress(3);


            setScannerStatus(
                "ID matched. Confirm that the physical ID has been surrendered below."
            );


            window.dispatchEvent(
                new CustomEvent(
                    "custodicore:checkin-progress",
                    {
                        detail: {
                            step: 3
                        }
                    }
                )
            );
        }
    );


    /*
    |--------------------------------------------------------------------------
    | CHECK-IN — ID DOES NOT MATCH
    |--------------------------------------------------------------------------
    */

    idDoesNotMatchBtn?.addEventListener(
        "click",
        () => {

            hideError();


            if (!scannedToken) {

                showError(
                    "Please scan and verify a visitor first."
                );

                return;
            }


            idMatchStatus =
                "replaced";

            window.frontDeskCheckinIdMatchStatus =
                "replaced";

            newIdSaved =
                false;

            window.frontDeskCheckinNewIdSaved =
                false;


            idDoesNotMatchBtn.classList.add(
                "ring-2",
                "ring-offset-2"
            );


            idMatchesBtn?.classList.remove(
                "ring-2",
                "ring-offset-2"
            );


            newIdSection?.classList.remove(
                "hidden"
            );


            matchedIdSection?.classList.add(
                "hidden"
            );


            updateProgress(3);


            setScannerStatus(
                "Replacement ID selected. Enter and physically verify the new ID."
            );


            window.dispatchEvent(
                new CustomEvent(
                    "custodicore:checkin-progress",
                    {
                        detail: {
                            step: 3
                        }
                    }
                )
            );
        }
    );


    /*
    |--------------------------------------------------------------------------
    | CHECK-IN — ID SURRENDER TYPE
    |--------------------------------------------------------------------------
    */

    idSurrenderedType?.addEventListener(
        "change",
        () => {

            if (
                idSurrenderedType.value
            ) {

                updateProgress(4);


                setScannerStatus(
                    "ID surrender recorded. Review the information and confirm check-in."
                );


                window.dispatchEvent(
                    new CustomEvent(
                        "custodicore:checkin-progress",
                        {
                            detail: {
                                step: 4
                            }
                        }
                    )
                );

            } else {

                updateProgress(3);
            }
        }
    );


    /*
    |--------------------------------------------------------------------------
    | CHECK-IN — NEW ID REASON
    |--------------------------------------------------------------------------
    */

    newIdReason?.addEventListener(
        "change",
        function () {

            if (
                this.value ===
                "other"
            ) {

                otherReasonContainer?.classList.remove(
                    "hidden"
                );

            } else {

                otherReasonContainer?.classList.add(
                    "hidden"
                );


                if (newIdOtherReason) {

                    newIdOtherReason.value =
                        "";
                }
            }
        }
    );


    /*
    |--------------------------------------------------------------------------
    | CHECK-IN — VERIFY / ADD NEW ID
    |--------------------------------------------------------------------------
    */

    /** Checks the "Different ID Presented" form; marks every problem. */
    function validateNewIdFields() {

        if (newIdNumber) {
            newIdNumber.value = cleanIdNumber(newIdNumber.value);
        }

        const otherReason =
            newIdOtherReason?.value.trim() ?? "";

        return checkFields([
            [newIdType, newIdType?.value ? null : "Select the type of the new ID."],
            [newIdNumber, idNumberProblem(newIdNumber?.value)],
            [newIdReason, newIdReason?.value ? null : "Select the reason for the new ID."],
            [
                newIdOtherReason,
                newIdReason?.value === "other" && otherReason.length < 3
                    ? "Describe the reason in a few words."
                    : null,
            ],
            [
                newIdVerified,
                newIdVerified?.checked ? null : "Confirm that you physically checked the new ID.",
            ],
        ]);
    }


    saveNewIdBtn?.addEventListener(
        "click",
        () => {

            hideError();


            if (
                idMatchStatus !==
                "replaced"
            ) {

                showError(
                    "Please select 'ID Does Not Match' first."
                );

                return;
            }


            if (!validateNewIdFields()) {
                return;
            }


            newIdSaved =
                true;

            window.frontDeskCheckinNewIdSaved =
                true;

            saveNewIdBtn.disabled =
                true;

            saveNewIdBtn.textContent =
                "New ID Verified";


            updateProgress(4);


            setScannerStatus(
                "New ID verified. Review the information and confirm check-in."
            );
        }
    );


    /*
    |--------------------------------------------------------------------------
    | LIVE FIELD CLEAN-UP
    |--------------------------------------------------------------------------
    */

    [newIdNumber, manualIdNumber].forEach((input) => {

        input?.addEventListener("blur", () => {
            input.value = cleanIdNumber(input.value);
        });
    });

    [
        newIdType,
        newIdNumber,
        newIdReason,
        newIdOtherReason,
        newIdVerified,
        manualIdType,
        manualIdNumber,
        overrideReason,
        document.getElementById("manualIdVerified"),
    ].forEach((input) => {

        input?.addEventListener("input", () => clearFieldError(input));
        input?.addEventListener("change", () => clearFieldError(input));
    });

    const overrideReasonCount =
        document.getElementById("overrideReasonCount");

    overrideReason?.addEventListener("input", () => {

        if (overrideReasonCount) {
            overrideReasonCount.textContent = String(overrideReason.value.length);
        }
    });


    /*
    |--------------------------------------------------------------------------
    | NEW ID PHOTO (optional)
    |--------------------------------------------------------------------------
    | Webcam capture or file upload of the replacement ID. Sent with the
    | check-in as a JPEG data URL so the Records Officer can review it.
    */

    const NEW_ID_PHOTO_MAX_WIDTH =
        1280;

    const photoEl = (id) =>
        document.getElementById(id);


    function stopNewIdCamera() {

        newIdPhotoStream?.getTracks().forEach((track) => track.stop());

        newIdPhotoStream =
            null;

        const video = photoEl("newIdPhotoVideo");

        if (video) {
            video.srcObject = null;
        }

        photoEl("newIdPhotoCamera")?.classList.add("hidden");
        photoEl("newIdPhotoCaptureBtn")?.classList.add("hidden");
        photoEl("newIdPhotoCancelBtn")?.classList.add("hidden");
        photoEl("newIdPhotoStartBtn")?.classList.remove("hidden");
        photoEl("newIdPhotoUploadLabel")?.classList.remove("hidden");
    }


    function showNewIdPhoto(dataUrl) {

        newIdPhoto =
            dataUrl;

        const image = photoEl("newIdPhotoImage");

        if (image) {
            image.src = dataUrl;
        }

        photoEl("newIdPhotoPreview")?.classList.remove("hidden");
        photoEl("newIdPhotoRemoveBtn")?.classList.remove("hidden");

        const startBtn = photoEl("newIdPhotoStartBtn");

        if (startBtn) {
            startBtn.textContent = "Retake Photo";
        }
    }


    function clearNewIdPhoto() {

        stopNewIdCamera();

        newIdPhoto =
            null;

        const image = photoEl("newIdPhotoImage");

        if (image) {
            image.removeAttribute("src");
        }

        const fileInput = photoEl("newIdPhotoFile");

        if (fileInput) {
            fileInput.value = "";
        }

        photoEl("newIdPhotoPreview")?.classList.add("hidden");
        photoEl("newIdPhotoRemoveBtn")?.classList.add("hidden");

        const startBtn = photoEl("newIdPhotoStartBtn");

        if (startBtn) {
            startBtn.textContent = "Take Photo";
        }
    }


    /** Draws a video frame or image onto a canvas, scaled down to a JPEG. */
    function toJpegDataUrl(source, width, height) {

        const scale =
            Math.min(1, NEW_ID_PHOTO_MAX_WIDTH / width);

        const canvas =
            document.createElement("canvas");

        canvas.width = Math.round(width * scale);
        canvas.height = Math.round(height * scale);

        canvas
            .getContext("2d")
            .drawImage(source, 0, 0, canvas.width, canvas.height);

        return canvas.toDataURL("image/jpeg", 0.85);
    }


    photoEl("newIdPhotoStartBtn")?.addEventListener(
        "click",
        async () => {

            hideError();

            if (!navigator.mediaDevices?.getUserMedia) {

                showError(
                    "This browser cannot open the camera here. Use Upload Photo instead."
                );

                return;
            }

            try {

                // The QR scanner holds the camera; release it first.
                await stopAnyActiveScanner();

                newIdPhotoStream =
                    await navigator.mediaDevices.getUserMedia({
                        video: {
                            facingMode: "environment",
                            width: { ideal: 1920 },
                            height: { ideal: 1080 },
                        },
                        audio: false,
                    });

                const video = photoEl("newIdPhotoVideo");

                video.srcObject = newIdPhotoStream;

                photoEl("newIdPhotoCamera")?.classList.remove("hidden");
                photoEl("newIdPhotoCaptureBtn")?.classList.remove("hidden");
                photoEl("newIdPhotoCancelBtn")?.classList.remove("hidden");
                photoEl("newIdPhotoStartBtn")?.classList.add("hidden");
                photoEl("newIdPhotoUploadLabel")?.classList.add("hidden");

            } catch (error) {

                console.error(
                    "ID photo camera failed:",
                    error
                );

                stopNewIdCamera();

                showError(
                    "Could not open the camera. Allow camera access in the browser, or use Upload Photo instead. You can also continue without a photo."
                );
            }
        }
    );


    photoEl("newIdPhotoCaptureBtn")?.addEventListener(
        "click",
        () => {

            const video = photoEl("newIdPhotoVideo");

            if (!video?.videoWidth) {

                showError(
                    "The camera is still starting. Try Capture again in a moment."
                );

                return;
            }

            const dataUrl =
                toJpegDataUrl(video, video.videoWidth, video.videoHeight);

            stopNewIdCamera();

            showNewIdPhoto(dataUrl);
        }
    );


    photoEl("newIdPhotoCancelBtn")?.addEventListener(
        "click",
        stopNewIdCamera
    );


    photoEl("newIdPhotoRemoveBtn")?.addEventListener(
        "click",
        clearNewIdPhoto
    );


    photoEl("newIdPhotoFile")?.addEventListener(
        "change",
        (event) => {

            hideError();

            const file =
                event.target.files?.[0];

            if (!file) {
                return;
            }

            if (!["image/jpeg", "image/png"].includes(file.type)) {

                showError(
                    "Please choose a JPG or PNG image."
                );

                event.target.value = "";

                return;
            }

            const url =
                URL.createObjectURL(file);

            const image =
                new Image();

            image.onload = () => {

                showNewIdPhoto(
                    toJpegDataUrl(image, image.naturalWidth, image.naturalHeight)
                );

                URL.revokeObjectURL(url);
            };

            image.onerror = () => {

                showError(
                    "That image could not be opened. Try another photo."
                );

                URL.revokeObjectURL(url);
            };

            image.src = url;
        }
    );


    /*
    |--------------------------------------------------------------------------
    | CHECK-IN — CONFIRM
    |--------------------------------------------------------------------------
    */

    confirmCheckInBtn?.addEventListener(
        "click",
        async () => {

            hideError();


            if (!scannedToken) {

                showError(
                    "No verified QR code is available."
                );

                return;
            }


            if (!idMatchStatus) {

                showError(
                    "Please select whether the visitor's ID matches the registered ID."
                );

                idMatchesBtn?.focus();

                return;
            }


            if (
                idMatchStatus ===
                "replaced"
            ) {

                if (!validateNewIdFields()) {
                    return;
                }


                if (!newIdSaved) {

                    showError(
                        "Please click 'Verify & Add New ID' before confirming check-in."
                    );

                    saveNewIdBtn?.focus();

                    return;
                }
            }


            let finalNewIdReason =
                newIdReason?.value ||
                null;


            if (
                newIdReason?.value ===
                "other"
            ) {

                finalNewIdReason =
                    newIdOtherReason?.value.trim() ||
                    null;
            }


            const surrenderedIdType =
                idMatchStatus ===
                "replaced"
                    ? newIdType.value
                    : (
                        registeredIds?.[0]?.type ||
                        registeredIds?.[0]?.type_label ||
                        registeredIdType?.textContent?.trim() ||
                        null
                    );


            confirmCheckInBtn.disabled =
                true;


            confirmCheckInBtn.textContent =
                "Processing...";


            updateProgress(4);


            setScannerStatus(
                "Completing visitor check-in..."
            );


            try {

                const response =
                    await fetch(
                        "/front-desk/checkin-checkout/confirm",
                        {
                            method: "POST",

                            headers: {

                                "Content-Type":
                                    "application/json",

                                "Accept":
                                    "application/json",

                                "X-CSRF-TOKEN":
                                    getCsrfToken(),
                            },

                            body:
                                JSON.stringify({

                                    qr_token:
                                        scannedToken,

                                    visit_request_id:
                                        scannedVisitRequestId,

                                    id_surrendered_type:
                                        surrenderedIdType,

                                    id_match_status:
                                        idMatchStatus,

                                    new_id_type:
                                        idMatchStatus ===
                                        "replaced"
                                            ? newIdType.value
                                            : null,

                                    new_id_number:
                                        idMatchStatus ===
                                        "replaced"
                                            ? newIdNumber.value.trim()
                                            : null,

                                    new_id_reason:
                                        idMatchStatus ===
                                        "replaced"
                                            ? finalNewIdReason
                                            : null,

                                    new_id_photo:
                                        idMatchStatus ===
                                        "replaced"
                                            ? newIdPhoto
                                            : null,
                                }),
                        }
                    );


                const data =
                    await response.json();


                if (
                    !response.ok ||
                    !data.success
                ) {

                    // Server validation: mark the fields it rejected.
                    const fieldFor = {
                        new_id_type: newIdType,
                        new_id_number: newIdNumber,
                        new_id_reason: newIdReason,
                    };

                    Object.entries(data.errors ?? {}).forEach(([key, messages]) => {
                        setFieldError(fieldFor[key], [].concat(messages)[0]);
                    });

                    throw new Error(
                        data.message ||
                        "Could not complete check-in."
                    );
                }


                confirmCheckInBtn.textContent =
                    "Check-In Complete";


                if (
                    data.new_id_pending
                ) {

                    setScannerStatus(
                        "Visitor checked in successfully. The new ID was recorded and is pending Records Officer verification."
                    );

                } else {

                    setScannerStatus(
                        "Visitor checked in successfully."
                    );
                }


                setTimeout(
                    () => {

                        window.location.reload();

                    },
                    1200
                );


            } catch (error) {

                console.error(
                    "QR check-in failed:",
                    error
                );


                showError(
                    error.message ||
                    "Unable to complete the check-in."
                );


                confirmCheckInBtn.disabled =
                    false;

                confirmCheckInBtn.textContent =
                    "Confirm Check-In";


                setScannerStatus(
                    "Check-in failed. Please correct the information and try again."
                );
            }
        }
    );


    /*
    |--------------------------------------------------------------------------
    | START CHECK-IN
    |--------------------------------------------------------------------------
    */

    startScannerBtn?.addEventListener(
        "click",
        async (event) => {

            event.preventDefault();

            await startScanner();
        }
    );


    /*
    |--------------------------------------------------------------------------
    | STOP CHECK-IN
    |--------------------------------------------------------------------------
    */

    stopScannerBtn?.addEventListener(
        "click",
        async (event) => {

            event.preventDefault();

            await stopScanner();
        }
    );


    /*
    |--------------------------------------------------------------------------
    | CANCEL CHECK-IN
    |--------------------------------------------------------------------------
    */

    cancelScanBtn?.addEventListener(
        "click",
        async (event) => {

            event.preventDefault();


            await stopScanner();


            resetScanResult();


            hideError();


            setScannerStatus(
                "Camera is ready. Please present the visitor's QR code."
            );
        }
    );


    /*
    |--------------------------------------------------------------------------
    | START CHECK-OUT
    |--------------------------------------------------------------------------
    */

    checkoutScannerStartButton?.addEventListener(
        "click",
        async (event) => {

            event.preventDefault();


            await startCheckoutScanner();
        }
    );


    /*
    |--------------------------------------------------------------------------
    | STOP CHECK-OUT
    |--------------------------------------------------------------------------
    */

    checkoutScannerStopButton?.addEventListener(
        "click",
        async (event) => {

            event.preventDefault();


            await stopCheckoutScanner();
        }
    );


    /*
    |--------------------------------------------------------------------------
    | CHECK-OUT ID RETURN CHECKBOX
    |--------------------------------------------------------------------------
    */

    function updateCheckoutConfirmButton() {

        const returned =
            qrCheckoutIdReturned?.checked ||
            checkoutIdReturned?.checked ||
            false;


        if (qrConfirmCheckoutBtn) {

            qrConfirmCheckoutBtn.disabled =
                !returned ||
                !checkoutCheckinId;
        }


        if (confirmCheckoutBtn) {

            confirmCheckoutBtn.disabled =
                !returned ||
                !checkoutCheckinId;
        }
    }


    qrCheckoutIdReturned?.addEventListener(
        "change",
        updateCheckoutConfirmButton
    );


    checkoutIdReturned?.addEventListener(
        "change",
        updateCheckoutConfirmButton
    );


    /*
    |--------------------------------------------------------------------------
    | CHECK-OUT CONFIRM
    |--------------------------------------------------------------------------
    */

    async function confirmCheckout() {

        hideCheckoutError();


        const returned =
            qrCheckoutIdReturned?.checked ||
            checkoutIdReturned?.checked ||
            false;


        if (!checkoutCheckinId) {

            showCheckoutError(
                "No active check-in record was found for this visitor."
            );

            return;
        }


        if (!returned) {

            showCheckoutError(
                "Please confirm that the visitor's ID has been returned before checking out."
            );

            return;
        }


        /*
         * Disable both possible confirm buttons.
         */

        if (qrConfirmCheckoutBtn) {

            qrConfirmCheckoutBtn.disabled =
                true;

            qrConfirmCheckoutBtn.textContent =
                "Processing...";
        }


        if (confirmCheckoutBtn) {

            confirmCheckoutBtn.disabled =
                true;

            confirmCheckoutBtn.textContent =
                "Processing...";
        }


        setCheckoutScannerStatus(
            "Completing visitor check-out..."
        );


        try {

            /*
             * Existing backend route confirmed by the Blade:
             *
             * /front-desk/checkin-checkout/{checkin}/check-out
             */

            const action =
                `/front-desk/checkin-checkout/${encodeURIComponent(checkoutCheckinId)}/check-out`;


            const form =
                document.createElement(
                    "form"
                );


            form.method =
                "POST";


            form.action =
                action;


            form.style.display =
                "none";


            /*
             * CSRF
             */

            const csrfInput =
                document.createElement(
                    "input"
                );


            csrfInput.type =
                "hidden";


            csrfInput.name =
                "_token";


            csrfInput.value =
                getCsrfToken();


            form.appendChild(
                csrfInput
            );


            /*
             * Verification method.
             */

            const methodInput =
                document.createElement(
                    "input"
                );


            methodInput.type =
                "hidden";


            methodInput.name =
                "verification_method";


            methodInput.value =
                "qr";


            form.appendChild(
                methodInput
            );


            /*
             * Append and submit.
             */

            document.body.appendChild(
                form
            );


            form.submit();


        } catch (error) {

            console.error(
                "Checkout failed:",
                error
            );


            showCheckoutError(
                error.message ||
                "Unable to complete check-out."
            );


            if (qrConfirmCheckoutBtn) {

                qrConfirmCheckoutBtn.disabled =
                    false;

                qrConfirmCheckoutBtn.textContent =
                    "Confirm Check-Out";
            }


            if (confirmCheckoutBtn) {

                confirmCheckoutBtn.disabled =
                    false;

                confirmCheckoutBtn.textContent =
                    "Confirm Check-Out";
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK-OUT CONFIRM BUTTONS
    |--------------------------------------------------------------------------
    */

    qrConfirmCheckoutBtn?.addEventListener(
        "click",
        async (event) => {

            event.preventDefault();

            await confirmCheckout();
        }
    );


    confirmCheckoutBtn?.addEventListener(
        "click",
        async (event) => {

            event.preventDefault();

            await confirmCheckout();
        }
    );


    /*
    |--------------------------------------------------------------------------
    | CHECK-OUT CANCEL
    |--------------------------------------------------------------------------
    */

    cancelCheckoutBtn?.addEventListener(
        "click",
        async (event) => {

            event.preventDefault();


            await stopCheckoutScanner();


            checkoutScanResult?.classList.add(
                "hidden"
            );


            hideCheckoutError();


            checkoutScannedToken =
                null;

            checkoutCheckinId =
                null;

            checkoutScanData =
                null;


            if (qrCheckoutIdReturned) {

                qrCheckoutIdReturned.checked =
                    false;
            }


            if (checkoutIdReturned) {

                checkoutIdReturned.checked =
                    false;
            }


            updateCheckoutConfirmButton();


            setCheckoutScannerStatus(
                "Camera is ready. Please present the visitor's QR code."
            );
        }
    );


    /*
    |--------------------------------------------------------------------------
    | MANUAL VISITOR SEARCH
    |--------------------------------------------------------------------------
    */

    function clearManualSearchResults() {

        if (!manualSearchResults) {
            return;
        }


        manualSearchResults.innerHTML =
            "";


        manualSearchResults.classList.add(
            "hidden"
        );
    }


    function resetManualVisitorSelection() {

        selectedManualVisitRequestId =
            null;


        selectedManualVisitor?.classList.add(
            "hidden"
        );


        manualVerificationSection?.classList.add(
            "hidden"
        );


        clearManualSearchResults();


        if (manualVisitorSearch) {

            manualVisitorSearch.value =
                "";
        }


        if (manualIdType) {

            manualIdType.value =
                "";
        }


        if (manualIdNumber) {

            manualIdNumber.value =
                "";
        }


        if (overrideReason) {

            overrideReason.value =
                "";
        }


        const manualIdVerified =
            document.getElementById("manualIdVerified");

        if (manualIdVerified) {
            manualIdVerified.checked = false;
        }

        [
            "manualRegisteredIdType",
            "manualRegisteredIdNumber",
            "manualRegisteredIdStatus",
        ].forEach((id) => setText(document.getElementById(id), "—"));

        [manualIdType, manualIdNumber, overrideReason, manualIdVerified].forEach(clearFieldError);

        const reasonCount =
            document.getElementById("overrideReasonCount");

        if (reasonCount) {
            reasonCount.textContent = "0";
        }

        hideError();


        if (manualCheckInBtn) {

            manualCheckInBtn.disabled =
                false;

            manualCheckInBtn.textContent =
                "Verify & Check In Manually";
        }
    }


    function selectManualVisitor(
        visitor
    ) {

        selectedManualVisitRequestId =
            visitor.visitRequestId;


        setText(
            selectedVisitorName,
            visitor.visitorName
        );


        setText(
            document.getElementById("selectedVisitorId"),
            visitor.visitorId
                ? `Visitor ID: ${visitor.visitorId}`
                : "Visitor ID: Not available"
        );


        setText(
            selectedVisitorDetails,
            `Visiting ${visitor.pdlName} (${visitor.pdlNumber})`
        );


        setText(document.getElementById("manualRegisteredIdType"), visitor.registeredIdType);
        setText(document.getElementById("manualRegisteredIdNumber"), visitor.registeredIdNumber);
        setText(document.getElementById("manualRegisteredIdStatus"), visitor.registeredIdStatus);


        if (selectedVisitorSchedule) {

            if (
                visitor.scheduleDate &&
                visitor.scheduleStart &&
                visitor.scheduleEnd
            ) {

                selectedVisitorSchedule.textContent =
                    `${visitor.scheduleDate} · ${visitor.scheduleStart} - ${visitor.scheduleEnd}`;

            } else {

                selectedVisitorSchedule.textContent =
                    "Schedule information unavailable.";
            }
        }


        selectedManualVisitor?.classList.remove(
            "hidden"
        );


        manualVerificationSection?.classList.remove(
            "hidden"
        );


        clearManualSearchResults();


        if (manualVisitorSearch) {

            manualVisitorSearch.value =
                visitor.visitorName;
        }


        manualIdType?.focus();
    }


    function renderManualSearchResults(
        query
    ) {

        if (!manualSearchResults) {
            return;
        }


        const normalizedQuery =
            query
                .trim()
                .toLowerCase();


        if (!normalizedQuery) {

            clearManualSearchResults();

            return;
        }


        const matches =
            manualVisitors.filter(
                visitor => {

                    const name =
                        String(
                            visitor.visitorName ||
                            ""
                        )
                            .toLowerCase();


                    const visitorId =
                        String(
                            visitor.visitorId ||
                            ""
                        )
                            .toLowerCase();


                    return (
                        name.includes(
                            normalizedQuery
                        ) ||
                        visitorId.includes(
                            normalizedQuery
                        )
                    );
                }
            );


        manualSearchResults.innerHTML =
            "";


        if (
            matches.length === 0
        ) {

            manualSearchResults.innerHTML = `
                <div class="p-md text-metadata text-text-secondary">
                    No matching visitor found.
                </div>
            `;


            manualSearchResults.classList.remove(
                "hidden"
            );


            return;
        }


        matches.forEach(
            visitor => {

                const result =
                    document.createElement(
                        "button"
                    );


                result.type =
                    "button";


                result.className =
                    "block w-full border-b border-border px-md py-md text-left transition hover:bg-background";


                result.innerHTML = `

                    <div class="flex items-center justify-between gap-md">

                        <div class="min-w-0">

                            <p class="text-body font-semibold text-text-primary">
                                ${escapeHtml(visitor.visitorName)}
                            </p>

                            <p class="mt-xs text-metadata text-text-secondary">
                                ${
                                    visitor.visitorId
                                        ? `Visitor ID: ${escapeHtml(visitor.visitorId)}`
                                        : "Visitor ID: Not available"
                                }
                            </p>

                            <p class="mt-xs text-metadata text-text-secondary">
                                Visiting ${escapeHtml(visitor.pdlName)}
                                (${escapeHtml(visitor.pdlNumber)})
                            </p>

                            ${
                                visitor.scheduleDate
                                    ? `
                                        <p class="mt-xs text-metadata text-text-secondary">
                                            ${escapeHtml(visitor.scheduleDate)}
                                            ·
                                            ${escapeHtml(visitor.scheduleStart)}
                                            -
                                            ${escapeHtml(visitor.scheduleEnd)}
                                        </p>
                                    `
                                    : ""
                            }

                        </div>

                        <span class="shrink-0 text-metadata font-semibold text-text-secondary">
                            Select
                        </span>

                    </div>
                `;


                result.addEventListener(
                    "click",
                    () => {

                        selectManualVisitor(
                            visitor
                        );
                    }
                );


                manualSearchResults.appendChild(
                    result
                );
            }
        );


        manualSearchResults.classList.remove(
            "hidden"
        );
    }


    function escapeHtml(
        value
    ) {

        return String(
            value ??
            ""
        )
            .replaceAll(
                "&",
                "&amp;"
            )
            .replaceAll(
                "<",
                "&lt;"
            )
            .replaceAll(
                ">",
                "&gt;"
            )
            .replaceAll(
                '"',
                "&quot;"
            )
            .replaceAll(
                "'",
                "&#039;"
            );
    }


    manualVisitorSearch?.addEventListener(
        "input",
        function () {

            if (
                selectedManualVisitRequestId
            ) {

                selectedManualVisitRequestId =
                    null;


                selectedManualVisitor?.classList.add(
                    "hidden"
                );


                manualVerificationSection?.classList.add(
                    "hidden"
                );
            }


            renderManualSearchResults(
                this.value
            );
        }
    );


    /*
    |--------------------------------------------------------------------------
    | CHANGE MANUAL VISITOR
    |--------------------------------------------------------------------------
    */

    changeManualVisitorBtn?.addEventListener(
        "click",
        () => {

            resetManualVisitorSelection();

            manualVisitorSearch?.focus();
        }
    );


    /*
    |--------------------------------------------------------------------------
    | MANUAL CHECK-IN
    |--------------------------------------------------------------------------
    */

    manualCheckInBtn?.addEventListener(
        "click",
        async () => {

            hideError();


            if (
                !selectedManualVisitRequestId
            ) {

                showError(
                    "Please search for and select a visitor first."
                );

                manualVisitorSearch?.focus();

                return;
            }


            if (manualIdNumber) {
                manualIdNumber.value = cleanIdNumber(manualIdNumber.value);
            }

            const idType =
                manualIdType?.value;

            const idNumber =
                manualIdNumber?.value ?? "";

            const reason =
                (overrideReason?.value ?? "").replace(/\s+/g, " ").trim();

            const manualIdVerified =
                document.getElementById("manualIdVerified");

            const fieldsOk =
                checkFields([
                    [manualIdType, idType ? null : "Select the type of ID the visitor surrendered."],
                    [manualIdNumber, idNumberProblem(idNumber)],
                    [
                        overrideReason,
                        reason.length < 10
                            ? "Describe the reason for the manual check-in in at least 10 characters."
                            : null,
                    ],
                    [
                        manualIdVerified,
                        manualIdVerified && !manualIdVerified.checked
                            ? "Confirm that you physically verified the visitor's ID."
                            : null,
                    ],
                ]);

            if (!fieldsOk) {
                return;
            }


            manualCheckInBtn.disabled =
                true;


            manualCheckInBtn.textContent =
                "Processing...";


            try {

                const form =
                    document.createElement(
                        "form"
                    );


                form.method =
                    "POST";


                form.action =
                    `/front-desk/checkin-checkout/${selectedManualVisitRequestId}/check-in`;


                form.style.display =
                    "none";


                const csrfInput =
                    document.createElement(
                        "input"
                    );


                csrfInput.type =
                    "hidden";


                csrfInput.name =
                    "_token";


                csrfInput.value =
                    getCsrfToken();


                form.appendChild(
                    csrfInput
                );


                const verificationInput =
                    document.createElement(
                        "input"
                    );


                verificationInput.type =
                    "hidden";


                verificationInput.name =
                    "verification_method";


                verificationInput.value =
                    "manual_override";


                form.appendChild(
                    verificationInput
                );


                const idInput =
                    document.createElement(
                        "input"
                    );


                idInput.type =
                    "hidden";


                idInput.name =
                    "id_surrendered_type";


                idInput.value =
                    idType;


                form.appendChild(
                    idInput
                );


                const idNumberInput =
                    document.createElement(
                        "input"
                    );


                idNumberInput.type =
                    "hidden";


                idNumberInput.name =
                    "id_number";


                idNumberInput.value =
                    idNumber;


                form.appendChild(
                    idNumberInput
                );


                const reasonInput =
                    document.createElement(
                        "input"
                    );


                reasonInput.type =
                    "hidden";


                reasonInput.name =
                    "override_reason";


                reasonInput.value =
                    reason;


                form.appendChild(
                    reasonInput
                );


                document.body.appendChild(
                    form
                );


                form.submit();


            } catch (error) {

                console.error(
                    "Manual check-in failed:",
                    error
                );


                showError(
                    "Unable to process the manual check-in."
                );


                manualCheckInBtn.disabled =
                    false;


                manualCheckInBtn.textContent =
                    "Verify & Check In Manually";
            }
        }
    );


    /*
    |--------------------------------------------------------------------------
    | CHECKOUT TAB / WORKSPACE
    |--------------------------------------------------------------------------
    |
    | This is intentionally included here so the scanner works even if
    | the inline Blade workflow script only changes the visible section.
    |--------------------------------------------------------------------------
    */

    const checkInModeBtn =
        document.getElementById(
            "checkInModeBtn"
        );

    const checkOutModeBtn =
        document.getElementById(
            "checkOutModeBtn"
        );

    const checkInWorkspace =
        document.getElementById(
            "checkInWorkspace"
        );

    const checkOutWorkspace =
        document.getElementById(
            "checkOutWorkspace"
        );


    function activateCheckIn() {

        checkInWorkspace?.classList.remove(
            "hidden"
        );

        checkOutWorkspace?.classList.add(
            "hidden"
        );


        checkInModeBtn?.classList.remove(
            "cc-btn-secondary"
        );

        checkInModeBtn?.classList.add(
            "cc-btn-primary"
        );


        checkOutModeBtn?.classList.remove(
            "cc-btn-primary"
        );

        checkOutModeBtn?.classList.add(
            "cc-btn-secondary"
        );
    }


    async function activateCheckOut() {

        /*
         * Stop check-in camera if it is running.
         */

        if (
            activeScannerMode ===
            "checkin"
        ) {

            await stopScanner();
        }


        checkInWorkspace?.classList.add(
            "hidden"
        );

        checkOutWorkspace?.classList.remove(
            "hidden"
        );


        checkOutModeBtn?.classList.remove(
            "cc-btn-secondary"
        );

        checkOutModeBtn?.classList.add(
            "cc-btn-primary"
        );


        checkInModeBtn?.classList.remove(
            "cc-btn-primary"
        );

        checkInModeBtn?.classList.add(
            "cc-btn-secondary"
        );
    }


    checkInModeBtn?.addEventListener(
        "click",
        async (event) => {

            event.preventDefault();

            await stopCheckoutScanner();

            activateCheckIn();
        }
    );


    checkOutModeBtn?.addEventListener(
        "click",
        async (event) => {

            event.preventDefault();

            await activateCheckOut();
        }
    );


    /*
    |--------------------------------------------------------------------------
    | CLEAN UP WHEN LEAVING PAGE
    |--------------------------------------------------------------------------
    */

    window.addEventListener(
        "beforeunload",
        () => {

            if (
                qrScanner &&
                scannerRunning
            ) {

                try {

                    qrScanner.stop();

                } catch (error) {

                    console.warn(
                        "Check-in scanner cleanup failed:",
                        error
                    );
                }
            }


            if (
                checkoutQrScanner &&
                checkoutScannerRunning
            ) {

                try {

                    checkoutQrScanner.stop();

                } catch (error) {

                    console.warn(
                        "Checkout scanner cleanup failed:",
                        error
                    );
                }
            }
        }
    );


    /*
    |--------------------------------------------------------------------------
    | INITIAL STATE
    |--------------------------------------------------------------------------
    */

    updateProgress(1);


    /*
     * Make sure checkout confirm is disabled initially.
     */

    updateCheckoutConfirmButton();
}


/*
|--------------------------------------------------------------------------
| DOM READY
|--------------------------------------------------------------------------
*/

if (
    document.readyState ===
    "loading"
) {

    document.addEventListener(
        "DOMContentLoaded",
        initializeFrontDeskScanner
    );

} else {

    initializeFrontDeskScanner();
}