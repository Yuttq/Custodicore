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

    function showError(message) {

        if (!scannerError) {
            return;
        }

        scannerError.textContent =
            message;

        scannerError.classList.remove(
            "hidden"
        );
    }


    function hideError() {

        if (!scannerError) {
            return;
        }

        scannerError.textContent =
            "";

        scannerError.classList.add(
            "hidden"
        );
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

        registeredIds =
            [];

        newIdSaved =
            false;


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


        setText(
            resultVisitor,
            visitor.name
        );


        setText(
            resultPdl,
            `${visitor.pdl ?? "—"} (${visitor.pdl_number ?? "—"})`
        );


        setText(
            resultSchedule,
            data.schedule?.display ??
            "Today's confirmed visit"
        );


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
             * The Blade page builds the check-out form from the scanned
             * check-in id — tell it which check-in was found.
             */
            if (checkoutCheckinId) {
                window.dispatchEvent(
                    new CustomEvent(
                        "custodicore:checkout-scanned",
                        {
                            detail: {
                                checkinId:
                                    checkoutCheckinId,
                            },
                        }
                    )
                );
            }


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


            newIdSaved =
                false;


            idMatchesBtn.classList.add(
                "ring-2",
                "ring-offset-2"
            );


            idDoesNotMatchBtn?.classList.remove(
                "ring-2",
                "ring-offset-2"
            );


            matchedIdSection?.classList.remove(
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
                "ID matched. Select the physical ID surrendered by the visitor."
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


            newIdSaved =
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


            if (
                !newIdType?.value
            ) {

                showError(
                    "Please select the new ID type."
                );

                newIdType?.focus();

                return;
            }


            if (
                !newIdNumber?.value.trim()
            ) {

                showError(
                    "Please enter the new ID number."
                );

                newIdNumber?.focus();

                return;
            }


            if (
                !newIdReason?.value
            ) {

                showError(
                    "Please select the reason for the new ID."
                );

                newIdReason?.focus();

                return;
            }


            if (
                newIdReason.value ===
                    "other" &&
                !newIdOtherReason?.value.trim()
            ) {

                showError(
                    "Please explain the reason for the new ID."
                );

                newIdOtherReason?.focus();

                return;
            }


            if (
                !newIdVerified?.checked
            ) {

                showError(
                    "Please confirm that you physically verified the new ID."
                );

                newIdVerified?.focus();

                return;
            }


            newIdSaved =
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


            /*
             * The current check-in page has no "ID matches / does not match"
             * buttons — step 4 shows the registered ID and an "ID has been
             * physically surrendered" checkbox. A checked box means the
             * registered ID was presented and surrendered.
             */
            if (
                !idMatchStatus &&
                !idMatchesBtn &&
                !idDoesNotMatchBtn &&
                document.getElementById("idSurrendered")?.checked
            ) {
                idMatchStatus =
                    "matched";
            }


            if (!idMatchStatus) {

                showError(
                    "Please select whether the visitor's ID matches the registered ID."
                );

                idMatchesBtn?.focus();

                return;
            }


            // Without a type field on the page, the surrendered ID is the registered one.
            const matchedIdType =
                idSurrenderedType?.value ||
                registeredIds[0]?.type ||
                "";


            if (
                idMatchStatus ===
                "matched"
            ) {

                if (
                    !matchedIdType
                ) {

                    showError(
                        idSurrenderedType
                            ? "Please select the ID type surrendered by the visitor."
                            : "This visitor has no verified registered ID on file. Use Manual Check-In."
                    );

                    idSurrenderedType?.focus();

                    return;
                }
            }


            if (
                idMatchStatus ===
                "replaced"
            ) {

                if (!newIdType?.value) {

                    showError(
                        "Please select the new ID type."
                    );

                    newIdType?.focus();

                    return;
                }


                if (
                    !newIdNumber?.value.trim()
                ) {

                    showError(
                        "Please enter the new ID number."
                    );

                    newIdNumber?.focus();

                    return;
                }


                if (!newIdReason?.value) {

                    showError(
                        "Please select the reason for the new ID."
                    );

                    newIdReason?.focus();

                    return;
                }


                if (
                    newIdReason.value ===
                        "other" &&
                    !newIdOtherReason?.value.trim()
                ) {

                    showError(
                        "Please explain the reason for the new ID."
                    );

                    newIdOtherReason?.focus();

                    return;
                }


                if (
                    !newIdVerified?.checked
                ) {

                    showError(
                        "Please physically verify the new ID before completing check-in."
                    );

                    newIdVerified?.focus();

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
                    : matchedIdType;


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


        if (selectedVisitorDetails) {

            const visitorIdText =
                visitor.visitorId
                    ? `Visitor ID: ${visitor.visitorId}`
                    : "Visitor ID: Not available";


            selectedVisitorDetails.textContent =
                `${visitorIdText} · Visiting ${visitor.pdlName} (${visitor.pdlNumber})`;
        }


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


            const idType =
                manualIdType?.value;


            if (!idType) {

                showError(
                    "Please select the visitor's ID type."
                );

                manualIdType?.focus();

                return;
            }


            const idNumber =
                manualIdNumber?.value.trim();


            if (!idNumber) {

                showError(
                    "Please enter the visitor's ID number."
                );

                manualIdNumber?.focus();

                return;
            }


            const reason =
                overrideReason?.value.trim();


            if (!reason) {

                showError(
                    "Please provide a reason for the manual override."
                );

                overrideReason?.focus();

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
                    "new_id_number";


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