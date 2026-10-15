// Run with: npm test  (node --test, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

// Each time zone runs in its own process so TZ applies from startup.
const moduleUrl = new URL('./formatDate.js', import.meta.url).href;
const probe = `
  const { parseIsoDateString, toIsoDateString } = await import(${JSON.stringify(moduleUrl)});
  console.log(JSON.stringify({
    offset: new Date(2000, 0, 26).getTimezoneOffset(),
    roundTrip: toIsoDateString(parseIsoDateString('2000-01-26')),
    lateEvening: toIsoDateString(new Date(2000, 0, 26, 23, 59, 59)),
    earlyMorning: toIsoDateString(new Date(2000, 0, 26, 0, 0, 1)),
    leap: toIsoDateString(parseIsoDateString('2004-02-29')),
    invalid: parseIsoDateString('2001-02-29'),
    loose: parseIsoDateString('2000-1-26'),
  }));`;

// getTimezoneOffset() on 2000-01-26 (minutes, UTC minus local).
const ZONES = {
  'Asia/Manila': -480,
  UTC: 0,
  'America/Los_Angeles': 480,
  'Pacific/Kiritimati': -840,
};

for (const [tz, offset] of Object.entries(ZONES)) {
  test(`birthdate ISO helpers keep the calendar date in ${tz}`, () => {
    const r = spawnSync(process.execPath, ['--input-type=module', '-e', probe], {
      env: { ...process.env, TZ: tz },
      encoding: 'utf8',
      cwd: fileURLToPath(new URL('../..', import.meta.url)),
    });
    assert.equal(r.status, 0, r.stderr);
    const out = JSON.parse(r.stdout.trim().split('\n').pop());
    assert.equal(out.offset, offset, `TZ=${tz} did not take effect`);
    assert.equal(out.roundTrip, '2000-01-26');
    assert.equal(out.lateEvening, '2000-01-26');
    assert.equal(out.earlyMorning, '2000-01-26');
    assert.equal(out.leap, '2004-02-29');
    assert.equal(out.invalid, null);
    assert.equal(out.loose, null);
  });
}
