// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  AUTO_CAPTURE_CONFIG,
  AUTO_CAPTURE_MESSAGES,
  createAutoCaptureLoop,
  finalPhotoDecision,
  initialAutoCaptureState,
  isAutoCaptureEnabled,
  nextAutoCaptureState,
} from './autoCapture.js';

/** A passing quality result with the face at (offsetX, offsetY) and width ratio `size`. */
function pass(offsetX = 0, offsetY = 0, size = 0.45) {
  return {
    status: 'pass',
    reasons: [],
    message: null,
    checks: { position: { status: 'pass', value: { offsetX, offsetY } }, size: { status: 'pass', value: size } },
  };
}
const FAIL_DARK = { status: 'fail', reasons: ['too_dark'], message: 'Improve the lighting.', checks: {} };
const UNKNOWN = { status: 'unknown', reasons: [], message: null, checks: {} };

/** Feeds results at `stepMs` intervals; returns every step's output. */
function run(results, stepMs = 500, config = AUTO_CAPTURE_CONFIG) {
  let state = initialAutoCaptureState();
  return results.map((result, i) => {
    const out = nextAutoCaptureState(state, result, 1000 + i * stepMs, config);
    state = out.state;
    return out;
  });
}

test('several consecutive passing checks over the stability period trigger capture', () => {
  const steps = run([pass(), pass(), pass(), pass()], 500);
  assert.deepEqual(steps.map((s) => s.capture), [false, false, false, true]);
  assert.equal(steps[0].message, 'Hold still.');
  assert.equal(steps[3].message, 'Capturing…');
});

test('passes in quick succession still wait for the stability period', () => {
  // Three passes 100 ms apart: enough passes, not enough time.
  const quick = run([pass(), pass(), pass()], 100);
  assert.equal(quick.some((s) => s.capture), false);
  // The face only briefly entering the frame never captures.
  assert.equal(run([pass(), FAIL_DARK, pass()], 1000).some((s) => s.capture), false);
});

test('one failed check resets the sequence and shows its message', () => {
  const steps = run([pass(), pass(), FAIL_DARK, pass(), pass(), pass(), pass()], 500);
  assert.equal(steps[2].state.streak, 0);
  assert.equal(steps[2].message, 'Improve the lighting.');
  assert.equal(steps[3].state.streak, 1);
  assert.deepEqual(steps.map((s) => s.capture), [false, false, false, false, false, false, true]);
});

test('a face that moves restarts the run', () => {
  const steps = run([pass(0, 0), pass(0, 0), pass(0.15, 0), pass(0.15, 0)], 600);
  assert.equal(steps[2].state.streak, 1);
  assert.equal(steps.some((s) => s.capture), false);
  // Moving closer (size jump) counts as moving too.
  assert.equal(run([pass(0, 0, 0.3), pass(0, 0, 0.5)], 600)[1].state.streak, 1);
});

test('missing or unknown quality results never trigger capture', () => {
  const steps = run([UNKNOWN, null, undefined, UNKNOWN, UNKNOWN], 2000);
  assert.equal(steps.some((s) => s.capture), false);
  // Not blamed on the visitor; after a few, the restart hint replaces it.
  assert.deepEqual(
    steps.map((s) => s.message),
    [
      AUTO_CAPTURE_MESSAGES.start,
      AUTO_CAPTURE_MESSAGES.start,
      AUTO_CAPTURE_MESSAGES.unavailable,
      AUTO_CAPTURE_MESSAGES.unavailable,
      AUTO_CAPTURE_MESSAGES.unavailable,
    ],
  );
  // …and an unknown in the middle of a run resets it.
  assert.equal(run([pass(), pass(), UNKNOWN, pass()], 600)[3].state.streak, 1);
});

test('repeated detector errors suggest restarting the camera, never capture', () => {
  const steps = run([UNKNOWN, null, UNKNOWN, UNKNOWN], 500);
  assert.deepEqual(steps.map((s) => s.suggestRestart), [false, false, true, true]);
  assert.equal(steps[2].message, AUTO_CAPTURE_MESSAGES.unavailable);
  assert.equal(steps.some((s) => s.capture), false);
  // A real result in between resets the count.
  assert.equal(run([UNKNOWN, UNKNOWN, FAIL_DARK, UNKNOWN], 500)[3].suggestRestart, false);
});

test('final photo: only a pass reaches review', () => {
  assert.deepEqual(finalPhotoDecision(pass()), { action: 'review', message: null });
});

test('final photo: a FAIL is retaken with its message', () => {
  assert.deepEqual(finalPhotoDecision(FAIL_DARK), { action: 'retry', message: 'Improve the lighting.' });
  // Repeated failures never fall through to review.
  for (let i = 0; i < 5; i += 1) assert.equal(finalPhotoDecision(FAIL_DARK).action, 'retry');
});

test('final photo: UNKNOWN or no result is retaken, never reviewed unverified', () => {
  for (const result of [UNKNOWN, null, undefined, {}]) {
    assert.deepEqual(finalPhotoDecision(result), {
      action: 'retry',
      message: AUTO_CAPTURE_MESSAGES.notVerified,
    });
  }
});

test('web: manual capture with no quality check goes to review', () => {
  // Web has no ML Kit: auto capture is off, so the "Take Photo" button shows.
  assert.equal(isAutoCaptureEnabled({ platform: 'web', qualityCheckSupported: false }), false);
  assert.equal(isAutoCaptureEnabled({ platform: 'web', qualityCheckSupported: true }), false);
  // The web stub's 'unknown' result does not block the manual photo.
  assert.deepEqual(finalPhotoDecision(UNKNOWN, { qualityCheckAvailable: false }), {
    action: 'review',
    message: null,
  });
  assert.equal(finalPhotoDecision(null, { qualityCheckAvailable: false }).action, 'review');
});

test('phones: automatic capture only (no manual button)', () => {
  assert.equal(isAutoCaptureEnabled({ platform: 'android', qualityCheckSupported: true }), true);
  assert.equal(isAutoCaptureEnabled({ platform: 'ios', qualityCheckSupported: true }), true);
});

test('config: 3 passes over at least 1.2 s, and no manual fallback settings', () => {
  assert.equal(AUTO_CAPTURE_CONFIG.requiredPasses, 3);
  assert.equal(AUTO_CAPTURE_CONFIG.minStableMs, 1200);
  for (const key of ['manualFallbackMs', 'unknownBeforeManual', 'manualFailuresBeforeReview']) {
    assert.equal(key in AUTO_CAPTURE_CONFIG, false, key);
  }
});

// ---------------------------------------------------------------------------
// The loop, with a fake clock and timers.
// ---------------------------------------------------------------------------

function fakeTimers() {
  let time = 0;
  let nextId = 1;
  const timers = new Map();
  return {
    now: () => time,
    setTimer: (fn, ms) => {
      const id = nextId++;
      timers.set(id, { fn, at: time + ms });
      return id;
    },
    clearTimer: (id) => timers.delete(id),
    pending: () => timers.size,
    /** Advances time, firing due timers in order and letting their promises settle. */
    async advance(ms) {
      const settle = async () => {
        for (let i = 0; i < 20; i += 1) await Promise.resolve();
      };
      const end = time + ms;
      await settle(); // work resolved since the last advance (e.g. a released check)
      for (;;) {
        const due = [...timers.entries()].filter(([, t]) => t.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
        if (!due) break;
        timers.delete(due[0]);
        time = Math.max(time, due[1].at);
        due[1].fn();
        await settle();
      }
      time = end;
    },
  };
}

/** A loop whose snapshots and checks are controllable; records what happened. */
function harness({ results = [], assessImpl, snapshotImpl, config } = {}) {
  const clock = fakeTimers();
  const log = { snapshots: 0, discarded: [], updates: [], restart: [], captures: 0, active: 0, maxActive: 0 };
  let i = 0;
  const loop = createAutoCaptureLoop({
    snapshot:
      snapshotImpl ??
      (async () => {
        log.snapshots += 1;
        log.active += 1;
        log.maxActive = Math.max(log.maxActive, log.active);
        return { uri: `shot-${log.snapshots}` };
      }),
    assess:
      assessImpl ??
      (async () => {
        const r = results[Math.min(i, results.length - 1)];
        i += 1;
        return r;
      }),
    discard: (shot) => {
      log.discarded.push(shot.uri);
      log.active -= 1;
    },
    onUpdate: (u) => {
      log.updates.push(u.message);
      log.restart.push(u.suggestRestart);
    },
    onCapture: () => {
      log.captures += 1;
    },
    config: { ...AUTO_CAPTURE_CONFIG, ...(config ?? {}) },
    now: clock.now,
    setTimer: clock.setTimer,
    clearTimer: clock.clearTimer,
  });
  return { loop, clock, log };
}

test('loop: passing checks lead to exactly one capture, then the loop stops', async () => {
  const { loop, clock, log } = harness({ results: [pass()] });
  loop.start();
  await clock.advance(5000);
  assert.equal(log.captures, 1);
  assert.equal(loop.isRunning(), false);
  const snapshotsAtCapture = log.snapshots;
  await clock.advance(5000);
  assert.equal(log.snapshots, snapshotsAtCapture, 'no snapshots after capture');
  assert.equal(log.captures, 1, 'no repeated automatic capture');
});

test('loop: snapshots never overlap and every snapshot is discarded', async () => {
  let release;
  const { loop, clock, log } = harness({
    // A slow check: the next snapshot must wait for it.
    assessImpl: () =>
      new Promise((resolve) => {
        release = () => resolve(FAIL_DARK);
      }),
  });
  loop.start();
  await clock.advance(AUTO_CAPTURE_CONFIG.firstSnapshotDelayMs);
  assert.equal(log.snapshots, 1);
  await clock.advance(5000); // nothing else may start while the check runs
  assert.equal(log.snapshots, 1);
  assert.equal(loop.isBusy(), true);
  release();
  await clock.advance(0);
  assert.equal(log.discarded.length, 1);
  await clock.advance(AUTO_CAPTURE_CONFIG.snapshotIntervalMs);
  assert.equal(log.snapshots, 2);
  assert.equal(log.maxActive, 1);
  loop.stop();
});

test('loop: stopping (camera closed) ends polling and waits for the snapshot in progress', async () => {
  let release;
  const { loop, clock, log } = harness({
    assessImpl: () =>
      new Promise((resolve) => {
        release = () => resolve(pass());
      }),
  });
  loop.start();
  await clock.advance(AUTO_CAPTURE_CONFIG.firstSnapshotDelayMs);
  let stopped = false;
  loop.stop().then(() => {
    stopped = true;
  });
  await clock.advance(0);
  assert.equal(stopped, false, 'stop waits for the snapshot in progress');
  release();
  await clock.advance(0);
  assert.equal(stopped, true);
  assert.deepEqual(log.discarded, ['shot-1']);
  await clock.advance(20000);
  assert.equal(log.snapshots, 1, 'no polling after stop');
  assert.equal(log.updates.length, 0, 'no updates after stop');
  assert.equal(clock.pending(), 0, 'no timers left after stop');
});

test('loop: failing checks never capture, however long they go on', async () => {
  const { loop, clock, log } = harness({ results: [FAIL_DARK] });
  loop.start();
  await clock.advance(60000);
  assert.equal(log.captures, 0);
  assert.ok(log.snapshots > 10, 'keeps checking');
  assert.equal(log.restart.some(Boolean), false, 'a real FAIL is not a restart case');
  loop.stop();
});

test('loop: a face that passes then fails never captures', async () => {
  const { loop, clock, log } = harness({ results: [pass(), pass(), FAIL_DARK, pass(), FAIL_DARK] });
  loop.start();
  await clock.advance(5000);
  assert.equal(log.captures, 0);
  loop.stop();
});

test('loop: detector and camera errors never crash it or capture, and suggest a restart', async () => {
  const { loop, clock, log } = harness({
    assessImpl: async () => {
      throw new Error('ML Kit failed');
    },
  });
  loop.start();
  await clock.advance(3000);
  assert.ok(log.snapshots >= 3);
  assert.equal(log.captures, 0);
  assert.equal(log.restart[2], true, 'restart suggested after 3 unchecked snapshots');
  assert.equal(log.updates[2], AUTO_CAPTURE_MESSAGES.unavailable);
  assert.equal(log.discarded.length, log.snapshots, 'snapshots still deleted');

  const broken = harness({
    snapshotImpl: async () => {
      throw new Error('camera busy');
    },
  });
  broken.loop.start();
  await broken.clock.advance(3000);
  assert.equal(broken.log.captures, 0);
  assert.equal(broken.log.restart.at(-1), true);
  broken.loop.stop();
  loop.stop();
});

test('loop: starting twice does not create a second polling chain', async () => {
  const { loop, clock, log } = harness({ results: [FAIL_DARK] });
  loop.start();
  loop.start();
  await clock.advance(AUTO_CAPTURE_CONFIG.firstSnapshotDelayMs);
  assert.equal(log.snapshots, 1);
  loop.stop();
});
