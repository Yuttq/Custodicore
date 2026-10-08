// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { buildCompactVisitStepsFromTimeline } from './visitProgressSnapshot.js';

/** Mirrors GET /api/schedules/{id}/timeline: steps up to `lastDone` completed, the next current. */
function timeline(lastDone, extra = []) {
  const ids = [
    'visitor_eligible',
    'schedule_assigned',
    'attendance_confirmed',
    'qr_generated',
    'checked_in',
    'visit_completed',
  ];
  const doneIndex = ids.indexOf(lastDone);
  return [
    ...ids.map((id, i) => ({
      id,
      title: id,
      description: `${id} description`,
      occurredAt: i <= doneIndex ? '2026-10-08T09:00:00+08:00' : null,
      officerNote: null,
      stepState: i <= doneIndex ? 'completed' : i === doneIndex + 1 ? 'current' : 'pending',
    })),
    ...extra,
  ];
}

const summary = (steps) => steps.map((s) => `${s.id}:${s.stepState}`);

test('assigned request shows Awaiting Staff Review as the current step', () => {
  const steps = buildCompactVisitStepsFromTimeline(timeline('schedule_assigned'), 'assigned');
  assert.deepEqual(summary(steps), [
    'visitor_eligible:completed',
    'awaiting_review:current',
    'attendance_confirmed:pending',
    'qr_generated:pending',
    'checked_in:pending',
    'visit_completed:pending',
  ]);
  const review = steps[1];
  assert.equal(review.label, 'Awaiting Staff Review');
  assert.ok(!steps.some((s) => s.label === 'Schedule Assigned'));
});

test('pending_confirmation keeps Schedule Assigned done and Attendance Confirmed current', () => {
  const steps = buildCompactVisitStepsFromTimeline(
    timeline('schedule_assigned'),
    'pending_confirmation',
  );
  assert.deepEqual(summary(steps).slice(0, 3), [
    'visitor_eligible:completed',
    'schedule_assigned:completed',
    'attendance_confirmed:current',
  ]);
  assert.equal(steps[1].label, 'Schedule Assigned');
});

test('confirmed and completed visits are unchanged', () => {
  const confirmed = buildCompactVisitStepsFromTimeline(timeline('attendance_confirmed'), 'confirmed');
  assert.deepEqual(summary(confirmed).slice(1, 4), [
    'schedule_assigned:completed',
    'attendance_confirmed:completed',
    'qr_generated:current',
  ]);
  const completed = buildCompactVisitStepsFromTimeline(timeline('visit_completed'), 'completed');
  assert.ok(completed.every((s) => s.stepState === 'completed'));
  assert.equal(completed.length, 6);
});

test('terminal statuses still end with the backend closing event', () => {
  const closing = {
    id: 'visit_cancelled',
    title: 'Visit Cancelled',
    description: 'This visit was cancelled.',
    occurredAt: '2026-10-08T10:00:00+08:00',
    officerNote: null,
    stepState: 'completed',
  };
  const steps = buildCompactVisitStepsFromTimeline(
    timeline('schedule_assigned', [closing]),
    'cancelled',
  );
  assert.deepEqual(summary(steps), [
    'visitor_eligible:completed',
    'schedule_assigned:completed',
    'visit_cancelled:completed',
  ]);
});

test('without a status the backend steps are used as-is', () => {
  const steps = buildCompactVisitStepsFromTimeline(timeline('schedule_assigned'));
  assert.equal(steps[1].id, 'schedule_assigned');
  assert.equal(steps[2].stepState, 'current');
});
