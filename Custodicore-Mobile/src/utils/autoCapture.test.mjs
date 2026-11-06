// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  AUTO_CAPTURE_CONFIG,
  AUTO_CAPTURE_MESSAGES,
  createAutoCaptureLoop,
  finalPhotoDecision,
  initialAutoCaptureState,
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
  assert.equal(steps.every((s) => s.message === AUTO_CAPTURE_MESSAGES.start), true);
  // …and an unknown in the middle of a run resets it.
  assert.equal(run([pass(), pass(), UNKNOWN, pass()], 600)[3].state.streak, 1);
});

test('repeated detector errors suggest manual capture early', () => {
  const steps = run([UNKNOWN, null, UNKNOWN], 500);
  assert.deepEqual(steps.map((s) => s.suggestManual), [false, false, true]);
  // A real result in between resets the count.
  assert.equal(run([UNKNOWN, UNKNOWN, FAIL_DARK, UNKNOWN], 500)[3].suggestManual, false);
});

test('final photo: pass or unknown goes to review; a failed automatic capture retries', () => {
  assert.deepEqual(finalPhotoDecision(pass(), { manual: false, manualFailures: 0 }), {
    action: 'review',
    message: null,
    manualFailures: 0,
  });
  // A detector error never blocks the visitor.
  assert.equal(finalPhotoDecision(UNKNOWN, { manual: false, manualFailures: 0 }).action, 'review');
  assert.equal(finalPhotoDecision(null, { manual: true, manualFailures: 0 }).action, 'review');
  assert.deepEqual(finalPhotoDecision(FAIL_DARK, { manual: false, manualFailures: 0 }), {
    action: 'retry',
    message: 'Improve the lighting.',
    manualFailures: 0,
  });
});

test('final photo: a failed manual capture retries once, then goes to review with the hint', () => {
  const first = finalPhotoDecision(FAIL_DARK, { manual: true, manualFailures: 0 });
  assert.deepEqual(first, { action: 'retry', message: 'Improve the lighting.', manualFailures: 1 });
  const second = finalPhotoDecision(FAIL_DARK, { manual: true, manualFailures: first.manualFailures });
  assert.deepEqual(second, { action: 'review', message: 'Improve the lighting.', manualFailures: 2 });
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
  const log = { snapshots: 0, discarded: [], updates: [], captures: 0, manual: 0, active: 0, maxActive: 0 };
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
    onUpdate: (u) => log.updates.push(u.message),
    onCapture: () => {
      log.captures += 1;
    },
    onManualAvailable: () => {
      log.manual += 1;
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
  assert.equal(log.manual, 0, 'no fallback timer after stop');
  assert.equal(clock.pending(), 0);
});

test('loop: manual fallback appears after the timeout without a capture', async () => {
  const { loop, clock, log } = harness({ results: [FAIL_DARK] });
  loop.start();
  await clock.advance(AUTO_CAPTURE_CONFIG.manualFallbackMs - 100);
  assert.equal(log.manual, 0);
  await clock.advance(200);
  assert.equal(log.manual, 1);
  await clock.advance(30000);
  assert.equal(log.manual, 1, 'offered once');
  assert.equal(log.captures, 0);
  loop.stop();
});

test('loop: restarting after a rejected capture keeps the fallback deadline', async () => {
  const { loop, clock, log } = harness({ results: [FAIL_DARK] });
  loop.start();
  await clock.advance(6000);
  await loop.stop();
  loop.start(AUTO_CAPTURE_CONFIG.retryDelayMs);
  await clock.advance(4100);
  assert.equal(log.manual, 1);
  loop.stop();
});

test('loop: detector and camera errors never crash it, and offer manual capture early', async () => {
  const { loop, clock, log } = harness({
    assessImpl: async () => {
      throw new Error('ML Kit failed');
    },
  });
  loop.start();
  await clock.advance(3000);
  assert.ok(log.snapshots >= 3);
  assert.equal(log.manual, 1, 'manual offered before the 10 s timeout');
  assert.equal(log.captures, 0);
  assert.equal(log.discarded.length, log.snapshots, 'snapshots still deleted');

  const broken = harness({
    snapshotImpl: async () => {
      throw new Error('camera busy');
    },
  });
  broken.loop.start();
  await broken.clock.advance(3000);
  assert.equal(broken.log.captures, 0);
  assert.equal(broken.log.manual, 1);
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
