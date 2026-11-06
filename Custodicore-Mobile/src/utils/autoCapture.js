/**
 * Automatic capture for the registration photo (Phase 2 Stage 3): decides
 * when a run of passing quality checks is steady enough to take the photo,
 * and runs the preview-snapshot loop one check at a time.
 *
 * Pure JavaScript (no React / React Native imports): the camera, the quality
 * check and the timers are passed in, so the logic runs under `node --test`.
 * RegistrationPhotoCamera wires it to expo-camera and services/photoQuality.
 *
 * This only judges photo quality. It does not recognise the person, check
 * liveness or approve anyone: staff still review every photo.
 */

export const AUTO_CAPTURE_CONFIG = Object.freeze({
  /** Wait after the camera is ready before the first snapshot. */
  firstSnapshotDelayMs: 600,
  /** Pause between the end of one check and the next snapshot (never overlapping). */
  snapshotIntervalMs: 350,
  /** Passing checks in a row needed before capturing. */
  requiredPasses: 3,
  /** And the first of them must be at least this old (the face has stayed put). */
  minStableMs: 1200,
  /** A pass whose face moved more than this (share of the photo) restarts the run. */
  maxMovement: 0.06,
  /** Manual capture appears after this long without an automatic capture… */
  manualFallbackMs: 10000,
  /** …or sooner, after this many checks in a row that could not run. */
  unknownBeforeManual: 3,
  /** After a rejected automatic capture, wait this long before trying again. */
  retryDelayMs: 1500,
  /** A manual capture that fails the check goes to review anyway after this many tries. */
  manualFailuresBeforeReview: 2,
});

export const AUTO_CAPTURE_MESSAGES = Object.freeze({
  start: 'Position your face in the frame.',
  holdStill: 'Hold still.',
  capturing: 'Capturing…',
});

/**
 * @typedef {object} AutoCaptureState
 * @property {number} streak — passing checks in a row
 * @property {number | null} firstPassAt — when the current run began (ms)
 * @property {{ x: number; y: number; size: number } | null} lastFace — the last pass's face placement
 * @property {number} unknownCount — checks in a row that could not run
 */

/** @returns {AutoCaptureState} */
export function initialAutoCaptureState() {
  return { streak: 0, firstPassAt: null, lastFace: null, unknownCount: 0 };
}

/**
 * Where the face was in a passing result (offsets from the guide centre and
 * width ratio, from the quality engine's checks), or null when not reported.
 * @param {any} result
 */
function facePlacement(result) {
  const position = result?.checks?.position?.value;
  const size = result?.checks?.size?.value;
  const x = position?.offsetX;
  const y = position?.offsetY;
  if (![x, y, size].every((v) => typeof v === 'number' && Number.isFinite(v))) return null;
  return { x, y, size };
}

/**
 * The next state after one snapshot's quality result.
 * - 'pass' extends the run (or restarts it when the face moved);
 * - 'fail' resets it and shows the engine's message;
 * - 'unknown' / no result (detector or camera error) resets it without
 *   blaming the visitor, and repeated unknowns suggest manual capture.
 * Capture is due only after `requiredPasses` passes spanning `minStableMs`.
 * @param {AutoCaptureState} state
 * @param {any} result — a PhotoQualityResult, or null when the check failed to run
 * @param {number} now — ms
 * @param {typeof AUTO_CAPTURE_CONFIG} [config]
 * @returns {{ state: AutoCaptureState; capture: boolean; message: string; suggestManual: boolean; passing: boolean }}
 */
export function nextAutoCaptureState(state, result, now, config = AUTO_CAPTURE_CONFIG) {
  const status = result?.status;

  if (status === 'fail') {
    return {
      state: { streak: 0, firstPassAt: null, lastFace: null, unknownCount: 0 },
      capture: false,
      message: result.message || AUTO_CAPTURE_MESSAGES.start,
      suggestManual: false,
      passing: false,
    };
  }

  if (status !== 'pass') {
    const unknownCount = state.unknownCount + 1;
    return {
      state: { streak: 0, firstPassAt: null, lastFace: null, unknownCount },
      capture: false,
      message: AUTO_CAPTURE_MESSAGES.start,
      suggestManual: unknownCount >= config.unknownBeforeManual,
      passing: false,
    };
  }

  const face = facePlacement(result);
  const moved =
    face != null &&
    state.lastFace != null &&
    (Math.abs(face.x - state.lastFace.x) > config.maxMovement ||
      Math.abs(face.y - state.lastFace.y) > config.maxMovement ||
      Math.abs(face.size - state.lastFace.size) > config.maxMovement);

  const restart = state.streak === 0 || moved;
  const next = {
    streak: restart ? 1 : state.streak + 1,
    firstPassAt: restart ? now : /** @type {number} */ (state.firstPassAt),
    lastFace: face,
    unknownCount: 0,
  };
  const capture =
    next.streak >= config.requiredPasses && now - next.firstPassAt >= config.minStableMs;
  return {
    state: next,
    capture,
    message: capture ? AUTO_CAPTURE_MESSAGES.capturing : AUTO_CAPTURE_MESSAGES.holdStill,
    suggestManual: false,
    passing: true,
  };
}

/**
 * What to do with the full-quality photo once it is checked.
 * - pass, or 'unknown' (the check could not run): show it for review — an
 *   error never blocks the visitor;
 * - fail on an automatic capture: try again with the message;
 * - fail on a manual capture: try again, but after
 *   `manualFailuresBeforeReview` failed tries show it for review anyway (with
 *   the message), so the check can never trap the visitor. Staff still
 *   review the photo either way.
 * @param {any} result — PhotoQualityResult or null
 * @param {{ manual: boolean; manualFailures: number }} attempt — failures so far this session
 * @param {typeof AUTO_CAPTURE_CONFIG} [config]
 * @returns {{ action: 'review' | 'retry'; message: string | null; manualFailures: number }}
 */
export function finalPhotoDecision(result, { manual, manualFailures }, config = AUTO_CAPTURE_CONFIG) {
  if (result?.status !== 'fail') {
    return { action: 'review', message: null, manualFailures: 0 };
  }
  const message = result.message || AUTO_CAPTURE_MESSAGES.start;
  if (!manual) return { action: 'retry', message, manualFailures };
  const failures = manualFailures + 1;
  if (failures >= config.manualFailuresBeforeReview) {
    return { action: 'review', message, manualFailures: failures };
  }
  return { action: 'retry', message, manualFailures: failures };
}

/**
 * The preview-snapshot loop. One snapshot and one quality check at a time:
 * the next snapshot is scheduled only after the previous check finished.
 * Snapshots are always discarded. When a capture is due the loop stops itself
 * and calls `onCapture`; the caller starts a new loop to try again.
 *
 * @param {object} deps
 * @param {() => Promise<any>} deps.snapshot — takes a low-quality preview photo
 * @param {(shot: any) => Promise<any>} deps.assess — its PhotoQualityResult
 * @param {(shot: any) => void | Promise<void>} deps.discard — deletes the snapshot
 * @param {(update: { message: string; passing: boolean }) => void} deps.onUpdate
 * @param {() => void} deps.onCapture — capture is due (the loop has stopped)
 * @param {() => void} deps.onManualAvailable — called once: timeout or repeated errors
 * @param {typeof AUTO_CAPTURE_CONFIG} [deps.config]
 * @param {() => number} [deps.now]
 * @param {(fn: () => void, ms: number) => any} [deps.setTimer]
 * @param {(handle: any) => void} [deps.clearTimer]
 */
export function createAutoCaptureLoop({
  snapshot,
  assess,
  discard,
  onUpdate,
  onCapture,
  onManualAvailable,
  config = AUTO_CAPTURE_CONFIG,
  now = Date.now,
  setTimer = setTimeout,
  clearTimer = clearTimeout,
}) {
  let running = false;
  let state = initialAutoCaptureState();
  let timer = null;
  let manualTimer = null;
  let manualSent = false;
  /** Counted from the first start, so restarting after a retry keeps the deadline. */
  let manualDeadline = null;
  /** @type {Promise<void> | null} */
  let inFlight = null;

  const offerManual = () => {
    if (manualSent) return;
    manualSent = true;
    onManualAvailable();
  };

  async function runCheck() {
    let shot = null;
    let result = null;
    try {
      shot = await snapshot();
      if (running && shot) result = await assess(shot);
    } catch {
      result = null; // camera / detector / image error: an 'unknown', never a crash
    } finally {
      if (shot) {
        try {
          await discard(shot);
        } catch {
          // best effort
        }
      }
    }
    return result;
  }

  async function tick() {
    timer = null;
    if (!running || inFlight) return;
    let result = null;
    inFlight = runCheck().then((r) => {
      result = r;
    });
    await inFlight;
    inFlight = null;
    if (!running) return;

    const next = nextAutoCaptureState(state, result, now(), config);
    state = next.state;
    if (next.suggestManual) offerManual();
    onUpdate({ message: next.message, passing: next.passing });
    if (next.capture) {
      stopTimers();
      running = false;
      onCapture();
      return;
    }
    timer = setTimer(tick, config.snapshotIntervalMs);
  }

  function stopTimers() {
    if (timer != null) clearTimer(timer);
    if (manualTimer != null) clearTimer(manualTimer);
    timer = null;
    manualTimer = null;
  }

  return {
    /** Starts checking; `delayMs` overrides the first snapshot's delay. */
    start(delayMs = config.firstSnapshotDelayMs) {
      if (running) return;
      running = true;
      state = initialAutoCaptureState();
      timer = setTimer(tick, delayMs);
      if (!manualSent) {
        if (manualDeadline == null) manualDeadline = now() + config.manualFallbackMs;
        manualTimer = setTimer(offerManual, Math.max(0, manualDeadline - now()));
      }
    },
    /**
     * Stops the loop. Resolves once any snapshot already in progress has
     * finished (and been discarded), so the camera is free to take a photo.
     */
    stop() {
      running = false;
      stopTimers();
      return inFlight ? inFlight.then(() => {}) : Promise.resolve();
    },
    isRunning() {
      return running;
    },
    /** True while a snapshot or its check is in progress. */
    isBusy() {
      return inFlight != null;
    },
  };
}
