{{--
  Phase 2 — date and session picker: the sessions Records opened for a PDL
  (GET frontdesk.walk-ins.slots), as a date dropdown and a session dropdown
  showing seats left and why a session cannot be booked. Once picked, the
  Picked Schedule box shows it. Sends requested_date and requested_start_times[].

  With $allowExtra, "+ Add another schedule" (hidden until clicked) adds more
  days for the same visitor, sent as extra_visits[i][date] and
  extra_visits[i][start_times][] (WalkInRegistrationRequest::MAX_EXTRA_VISITS).

  $id          unique id for this picker
  $pdlId       fixed PDL (show page), or null when $pdlSource is given
  $pdlSource   CSS selector of an input holding the PDL id (registration form)
  $typeSource  optional CSS selector of the relationship type select
  $visitorId   optional: a registered walk-in, so their booking rules apply
  $oldDate / $oldTimes  the previous choice, to restore
  $allowExtra  optional: let the desk add more days
  $oldExtras   optional: the previous extra days, [{date, start_times}]
--}}
@php
    $oldTimes = array_values((array) ($oldTimes ?? []));
    $allowExtra = (bool) ($allowExtra ?? false);
    $oldExtras = $allowExtra ? array_values(array_filter((array) ($oldExtras ?? []), 'is_array')) : [];
    $maxExtra = \App\Http\Requests\FrontDesk\WalkInRegistrationRequest::MAX_EXTRA_VISITS;
@endphp
<div data-slot-picker id="{{ $id }}"
     data-url="{{ route('frontdesk.walk-ins.slots') }}"
     data-pdl="{{ $pdlId ?? '' }}"
     data-pdl-source="{{ $pdlSource ?? '' }}"
     data-type-source="{{ $typeSource ?? '' }}"
     data-visitor="{{ $visitorId ?? '' }}"
     data-old-date="{{ $oldDate ?? '' }}"
     data-old-times="{{ implode(',', $oldTimes) }}"
     data-allow-extra="{{ $allowExtra ? $maxExtra : 0 }}"
     data-old-extras="{{ json_encode($oldExtras) }}">
    <input type="hidden" name="requested_date" value="{{ $oldDate ?? '' }}" data-slot-date />
    <div data-slot-times>
        @foreach ($oldTimes as $time)
            <input type="hidden" name="requested_start_times[]" value="{{ $time }}" />
        @endforeach
    </div>
    {{-- Kept until the sessions load, so a failed load does not drop them. --}}
    <div data-slot-extra-inputs>
        @foreach ($oldExtras as $i => $extra)
            <input type="hidden" name="extra_visits[{{ $i }}][date]" value="{{ $extra['date'] ?? '' }}" />
            @foreach ((array) ($extra['start_times'] ?? []) as $time)
                <input type="hidden" name="extra_visits[{{ $i }}][start_times][]" value="{{ $time }}" />
            @endforeach
        @endforeach
    </div>

    <p data-slot-message class="text-metadata text-text-secondary" aria-live="polite">
        {{ ($pdlId ?? null) ? 'Loading the sessions Records opened…' : 'Select the PDL first to see the dates and sessions Records opened.' }}
    </p>
    <div class="mt-sm grid grid-cols-1 gap-md md:grid-cols-2">
        <div>
            <label for="{{ $id }}_date" class="cc-label">Visit date</label>
            <select id="{{ $id }}_date" data-slot-day-select disabled
                    @class(['cc-input', 'border-danger' => $errors->has('requested_date')])>
                <option value="">Select a date…</option>
            </select>
        </div>
        <div>
            <label for="{{ $id }}_session" class="cc-label">Session</label>
            <select id="{{ $id }}_session" data-slot-session-select disabled
                    @class(['cc-input', 'border-danger' => $errors->has('requested_start_times')])>
                <option value="">Select the date first</option>
            </select>
        </div>
    </div>
    @error('requested_start_times') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
    @error('requested_date') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror

    @if ($allowExtra)
        {{-- More days for the same visitor: nothing shows until the desk asks for it. --}}
        <div data-slot-extra-rows class="space-y-md"></div>
        <button type="button" data-slot-add-extra class="mt-md hidden text-body font-semibold text-primary-teal hover:underline">
            + Add another schedule for this visitor
        </button>
        @error('extra_visits') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
        @if (! $errors->has('extra_visits') && $errors->has('extra_visits.*'))
            <p class="mt-xs text-metadata text-danger">{{ $errors->first('extra_visits.*') }}</p>
        @endif
    @endif

    {{-- What was picked, shown once a date and session are chosen. --}}
    <div data-slot-summary class="mt-md hidden rounded-lg border border-success bg-background p-md" aria-live="polite">
        <p class="text-body font-semibold text-success" data-slot-summary-title>Picked Schedule</p>
        <ol data-slot-summary-list class="mt-sm space-y-sm"></ol>
    </div>
</div>

@once
    @push('scripts')
        <script>
            (() => {
                const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;
                const option = (value, text, disabled = false) => {
                    const el = document.createElement('option');
                    el.value = value;
                    el.textContent = text;
                    el.disabled = disabled;
                    return el;
                };
                const hidden = (name, value) => {
                    const el = document.createElement('input');
                    el.type = 'hidden';
                    el.name = name;
                    el.value = value;
                    return el;
                };

                // Every bookable choice of a day: its sessions, then the whole day.
                const sessionsOf = day => {
                    const list = day.slots.map(slot => ({
                        times: [slot.startTime],
                        text: `${slot.startTime}–${slot.endTime}`,
                        available: slot.available,
                        detail: slot.available
                            ? (slot.remaining == null ? 'Available' : `${plural(slot.remaining, 'seat')} left`)
                            : (slot.reason || 'Not available'),
                    }));
                    if (day.wholeDay) {
                        list.push({
                            times: day.wholeDay.startTimes,
                            text: `Whole day ${day.wholeDay.startTime}–${day.wholeDay.endTime}`,
                            available: day.wholeDay.available,
                            detail: day.wholeDay.available ? 'Available' : (day.wholeDay.reason || 'Not available'),
                        });
                    }
                    return list;
                };
                const keyOf = times => times.join('+');

                document.querySelectorAll('[data-slot-picker]').forEach(root => {
                    const dateInput = root.querySelector('[data-slot-date]');
                    const timesBox = root.querySelector('[data-slot-times]');
                    const extraInputs = root.querySelector('[data-slot-extra-inputs]');
                    const message = root.querySelector('[data-slot-message]');
                    const extraRows = root.querySelector('[data-slot-extra-rows]');
                    const addButton = root.querySelector('[data-slot-add-extra]');
                    const summary = root.querySelector('[data-slot-summary]');
                    const summaryTitle = root.querySelector('[data-slot-summary-title]');
                    const summaryList = root.querySelector('[data-slot-summary-list]');
                    const pdlSource = root.dataset.pdlSource ? document.querySelector(root.dataset.pdlSource) : null;
                    const typeSource = root.dataset.typeSource ? document.querySelector(root.dataset.typeSource) : null;
                    const maxExtra = Number(root.dataset.allowExtra || 0);
                    let oldExtras = [];
                    try { oldExtras = JSON.parse(root.dataset.oldExtras || '[]'); } catch (e) { oldExtras = []; }

                    let days = [];
                    let run = 0;
                    const pdlId = () => root.dataset.pdl || (pdlSource ? pdlSource.value : '');

                    // One date + session pair. rows[0] is the first schedule (from the markup).
                    const rows = [];
                    const makeRow = (daySelect, sessionSelect, element = null) => {
                        const row = { daySelect, sessionSelect, element, pick: null };
                        daySelect.addEventListener('change', () => { fillSessions(row, []); sync(); });
                        sessionSelect.addEventListener('change', () => { pickFrom(row); sync(); });
                        return row;
                    };

                    const dayOf = date => days.find(d => d.date === date);

                    const pickFrom = row => {
                        const day = dayOf(row.daySelect.value);
                        const session = day && sessionsOf(day).find(s => s.available && keyOf(s.times) === row.sessionSelect.value);
                        row.pick = session ? { day, session } : null;
                    };

                    const fillSessions = (row, keepTimes) => {
                        const day = dayOf(row.daySelect.value);
                        const select = row.sessionSelect;
                        select.innerHTML = '';
                        if (!day) {
                            select.appendChild(option('', 'Select the date first'));
                            select.disabled = true;
                            row.pick = null;
                            return;
                        }
                        select.appendChild(option('', 'Select a session…'));
                        sessionsOf(day).forEach(s => select.appendChild(option(keyOf(s.times), `${s.text} · ${s.detail}`, !s.available)));
                        select.disabled = false;
                        const match = sessionsOf(day).find(s => s.available && keyOf(s.times) === keyOf(keepTimes));
                        select.value = match ? keyOf(match.times) : '';
                        pickFrom(row);
                    };

                    const fillDays = (row, keepDate, keepTimes) => {
                        const select = row.daySelect;
                        select.innerHTML = '';
                        select.appendChild(option('', days.length ? 'Select a date…' : 'No dates open'));
                        days.forEach(day => {
                            const open = sessionsOf(day).filter(s => s.available).length;
                            const el = option(day.date, `${day.label}${day.today ? ' (today)' : ''} · ${open ? `${plural(open, 'session')} open` : 'no sessions left'}`, !open);
                            el.dataset.full = open ? '' : '1';
                            el.dataset.text = el.textContent;
                            select.appendChild(el);
                        });
                        select.disabled = !days.length;
                        const day = dayOf(keepDate);
                        select.value = day && sessionsOf(day).some(s => s.available) ? day.date : '';
                        fillSessions(row, select.value ? keepTimes : []);
                    };

                    // A day already picked in one row cannot be picked in another (one visit a day).
                    const lockTakenDays = () => {
                        rows.forEach(row => {
                            const taken = rows.filter(other => other !== row && other.daySelect.value).map(other => other.daySelect.value);
                            [...row.daySelect.options].forEach(el => {
                                if (!el.value) return;
                                const isTaken = taken.includes(el.value);
                                el.disabled = el.dataset.full === '1' || isTaken;
                                el.textContent = isTaken ? `${el.dataset.text} · already picked` : el.dataset.text;
                            });
                        });
                    };

                    const sync = () => {
                        // The first schedule.
                        const first = rows[0].pick;
                        dateInput.value = first ? first.day.date : '';
                        timesBox.innerHTML = '';
                        (first ? first.session.times : []).forEach(time => timesBox.appendChild(hidden('requested_start_times[]', time)));

                        // The added ones, numbered in order.
                        extraInputs.innerHTML = '';
                        rows.slice(1).forEach((row, i) => {
                            row.element.querySelector('[data-row-title]').textContent = `Schedule ${i + 2}`;
                            if (!row.pick) return;
                            extraInputs.appendChild(hidden(`extra_visits[${i}][date]`, row.pick.day.date));
                            row.pick.session.times.forEach(time => extraInputs.appendChild(hidden(`extra_visits[${i}][start_times][]`, time)));
                        });

                        lockTakenDays();

                        // Picked Schedule box.
                        const picked = rows.map(row => row.pick).filter(Boolean);
                        summaryList.innerHTML = '';
                        picked.forEach((pick, i) => {
                            const item = document.createElement('li');
                            item.className = 'rounded-lg border border-border bg-surface px-sm py-xs';
                            const head = document.createElement('p');
                            head.className = 'text-metadata font-semibold text-text-secondary';
                            head.textContent = picked.length > 1 ? `Schedule ${i + 1}` : 'Schedule';
                            const date = document.createElement('p');
                            date.className = 'text-body font-semibold';
                            date.textContent = `${pick.day.label}${pick.day.today ? ' (today)' : ''}`;
                            const session = document.createElement('p');
                            session.className = 'text-body';
                            session.textContent = `${pick.session.text} · ${pick.session.detail}`;
                            item.append(head, date, session);
                            summaryList.appendChild(item);
                        });
                        summaryTitle.textContent = picked.length > 1 ? `Picked Schedules (${picked.length})` : 'Picked Schedule';
                        summary.classList.toggle('hidden', !picked.length);

                        // "+ Add another" only after the first schedule is picked, while days are left.
                        if (addButton) {
                            const daysLeft = days.filter(d => sessionsOf(d).some(s => s.available)).length;
                            const canAdd = !!first && rows.length - 1 < maxExtra && rows.length < daysLeft;
                            addButton.classList.toggle('hidden', !canAdd);
                        }
                    };

                    const addRow = (keepDate = '', keepTimes = []) => {
                        const element = document.createElement('div');
                        element.className = 'mt-md rounded-lg bg-background p-sm';
                        element.innerHTML = `
                            <div class="flex items-center justify-between gap-sm">
                                <p class="text-body font-semibold" data-row-title></p>
                                <button type="button" class="text-metadata font-semibold text-danger hover:underline" data-row-remove>Remove</button>
                            </div>
                            <div class="mt-xs grid grid-cols-1 gap-md md:grid-cols-2">
                                <div><label class="cc-label">Visit date</label><select class="cc-input" data-row-day></select></div>
                                <div><label class="cc-label">Session</label><select class="cc-input" data-row-session></select></div>
                            </div>`;
                        const uid = `${root.id}_extra_${Date.now()}_${rows.length}`;
                        const [dayLabel, sessionLabel] = element.querySelectorAll('label');
                        const daySelect = element.querySelector('[data-row-day]');
                        const sessionSelect = element.querySelector('[data-row-session]');
                        daySelect.id = `${uid}_date`; dayLabel.htmlFor = daySelect.id;
                        sessionSelect.id = `${uid}_session`; sessionLabel.htmlFor = sessionSelect.id;
                        const row = makeRow(daySelect, sessionSelect, element);
                        element.querySelector('[data-row-remove]').addEventListener('click', () => {
                            rows.splice(rows.indexOf(row), 1);
                            element.remove();
                            sync();
                        });
                        rows.push(row);
                        extraRows.appendChild(element);
                        fillDays(row, keepDate, keepTimes);
                        return row;
                    };

                    const clearExtras = () => {
                        rows.splice(1).forEach(row => row.element.remove());
                    };

                    rows.push(makeRow(root.querySelector('[data-slot-day-select]'), root.querySelector('[data-slot-session-select]')));
                    addButton?.addEventListener('click', () => {
                        const row = addRow();
                        sync();
                        row.daySelect.focus();
                    });

                    const reset = text => {
                        days = [];
                        clearExtras();
                        const first = rows[0];
                        first.daySelect.innerHTML = '';
                        first.daySelect.appendChild(option('', text));
                        first.daySelect.disabled = true;
                        fillSessions(first, []);
                    };

                    const render = data => {
                        days = data.days || [];
                        if (!data.pdlAvailable) {
                            message.textContent = data.message || 'Visits with this PDL are not available at this time.';
                        } else if (!days.length) {
                            message.textContent = 'Records has not opened any session for this PDL in the next two weeks. Ask Records to open one in Manage Visitor Slots.';
                        } else {
                            message.textContent = 'Choose a date, then one of the sessions Records opened for it.';
                        }

                        // Keep the earlier choices only while they are still bookable.
                        const first = rows[0];
                        const keep = {
                            date: first.pick ? first.pick.day.date : (root.dataset.oldDate || ''),
                            times: first.pick ? first.pick.session.times : (root.dataset.oldTimes || '').split(',').filter(Boolean),
                        };
                        const keepExtras = rows.length > 1
                            ? rows.slice(1).filter(row => row.pick).map(row => ({ date: row.pick.day.date, start_times: row.pick.session.times }))
                            : oldExtras;
                        root.dataset.oldDate = '';
                        root.dataset.oldTimes = '';
                        oldExtras = [];

                        clearExtras();
                        fillDays(first, keep.date, keep.times);
                        if (first.pick && maxExtra) {
                            keepExtras.slice(0, maxExtra).forEach(extra => {
                                const row = addRow(extra.date, extra.start_times || []);
                                if (!row.pick || rows.some(other => other !== row && other.daySelect.value === row.daySelect.value)) {
                                    rows.splice(rows.indexOf(row), 1);
                                    row.element.remove();
                                }
                            });
                        }
                        sync();
                    };

                    const load = () => {
                        const pdl = pdlId();
                        if (!pdl) {
                            reset('Select the PDL first');
                            sync();
                            message.textContent = 'Select the PDL first to see the dates and sessions Records opened.';
                            return;
                        }
                        message.textContent = 'Loading the sessions Records opened…';
                        const params = new URLSearchParams({ pdl_id: pdl });
                        if (root.dataset.visitor) params.set('visitor', root.dataset.visitor);
                        if (typeSource && typeSource.value) params.set('relationship_type', typeSource.value);
                        const current = ++run;
                        fetch(`${root.dataset.url}?${params}`, { headers: { Accept: 'application/json' } })
                            .then(r => r.ok ? r.json() : Promise.reject())
                            .then(data => { if (current === run) render(data); })
                            .catch(() => { if (current === run) message.textContent = 'The sessions could not be loaded. Reload the page and try again.'; });
                    };

                    // A different PDL has other sessions: start over.
                    pdlSource?.addEventListener('change', () => { rows[0].pick = null; clearExtras(); load(); });
                    // Another relationship type can change what the PDL's sessions allow.
                    typeSource?.addEventListener('change', () => { if (pdlId()) load(); });
                    load();
                });
            })();
        </script>
    @endpush
@endonce
