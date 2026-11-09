{{--
  Phase 2 — date and session picker: the sessions Records opened for a PDL
  (GET frontdesk.walk-ins.slots), with seats left and why a session cannot
  be booked. Sends requested_date and requested_start_times[].

  $id          unique id for this picker
  $pdlId       fixed PDL (show page), or null when $pdlSource is given
  $pdlSource   CSS selector of an input holding the PDL id (registration form)
  $typeSource  optional CSS selector of the relationship type select
  $visitorId   optional: a registered walk-in, so their booking rules apply
  $oldDate / $oldTimes  the previous choice, to restore
--}}
@php
    $oldTimes = array_values((array) ($oldTimes ?? []));
@endphp
<div data-slot-picker id="{{ $id }}"
     data-url="{{ route('frontdesk.walk-ins.slots') }}"
     data-pdl="{{ $pdlId ?? '' }}"
     data-pdl-source="{{ $pdlSource ?? '' }}"
     data-type-source="{{ $typeSource ?? '' }}"
     data-visitor="{{ $visitorId ?? '' }}"
     data-old-date="{{ $oldDate ?? '' }}"
     data-old-times="{{ implode(',', $oldTimes) }}">
    <input type="hidden" name="requested_date" value="{{ $oldDate ?? '' }}" data-slot-date />
    <div data-slot-times>
        @foreach ($oldTimes as $time)
            <input type="hidden" name="requested_start_times[]" value="{{ $time }}" />
        @endforeach
    </div>

    <p data-slot-message class="text-metadata text-text-secondary" aria-live="polite">
        {{ ($pdlId ?? null) ? 'Loading the sessions Records opened…' : 'Select the PDL first to see the dates and sessions Records opened.' }}
    </p>
    <div data-slot-days class="mt-sm grid grid-cols-1 gap-sm sm:grid-cols-2 lg:grid-cols-3"></div>
    <p data-slot-chosen class="mt-sm hidden text-body font-semibold text-success"></p>
    @error('requested_start_times') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
    @error('requested_date') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
</div>

@once
    @push('scripts')
        <script>
            (() => {
                document.querySelectorAll('[data-slot-picker]').forEach(root => {
                    const dateInput = root.querySelector('[data-slot-date]');
                    const timesBox = root.querySelector('[data-slot-times]');
                    const message = root.querySelector('[data-slot-message]');
                    const daysBox = root.querySelector('[data-slot-days]');
                    const chosen = root.querySelector('[data-slot-chosen]');
                    const pdlSource = root.dataset.pdlSource ? document.querySelector(root.dataset.pdlSource) : null;
                    const typeSource = root.dataset.typeSource ? document.querySelector(root.dataset.typeSource) : null;
                    let selected = { date: root.dataset.oldDate || '', times: (root.dataset.oldTimes || '').split(',').filter(Boolean) };
                    let run = 0;

                    const pdlId = () => root.dataset.pdl || (pdlSource ? pdlSource.value : '');

                    const setChoice = (date, times, label) => {
                        selected = { date, times };
                        dateInput.value = date;
                        timesBox.innerHTML = '';
                        times.forEach(time => {
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = 'requested_start_times[]';
                            input.value = time;
                            timesBox.appendChild(input);
                        });
                        chosen.textContent = label ? `Chosen: ${label}` : '';
                        chosen.classList.toggle('hidden', !label);
                        daysBox.querySelectorAll('button[data-key]').forEach(button => {
                            const on = button.dataset.key === `${date}|${times.join('+')}`;
                            button.classList.toggle('ring-2', on);
                            button.classList.toggle('ring-primary-teal', on);
                            button.setAttribute('aria-pressed', on ? 'true' : 'false');
                        });
                    };

                    const optionButton = (day, times, text, available, reason, remaining) => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.dataset.key = `${day.date}|${times.join('+')}`;
                        button.className = 'w-full rounded-lg border border-border px-sm py-xs text-left text-body '
                            + (available ? 'hover:border-primary-teal' : 'cursor-not-allowed opacity-60');
                        button.disabled = !available;
                        const detail = available
                            ? (remaining === null ? 'Available' : `${remaining} seat${remaining === 1 ? '' : 's'} left`)
                            : reason;
                        button.innerHTML = '';
                        const main = document.createElement('span');
                        main.className = 'font-semibold';
                        main.textContent = text;
                        const sub = document.createElement('span');
                        sub.className = 'block text-metadata ' + (available ? 'text-text-secondary' : 'text-danger');
                        sub.textContent = detail;
                        button.append(main, sub);
                        button.addEventListener('click', () => setChoice(day.date, times, `${day.label} · ${text}`));
                        return button;
                    };

                    const render = data => {
                        daysBox.innerHTML = '';
                        if (!data.pdlAvailable) {
                            message.textContent = data.message || 'Visits with this PDL are not available at this time.';
                        } else if (!data.days.length) {
                            message.textContent = 'Records has not opened any session for this PDL in the next two weeks. Ask Records to open one in Manage Visitor Slots.';
                        } else {
                            message.textContent = 'Choose one of the sessions Records opened:';
                        }

                        let restored = '';
                        data.days.forEach(day => {
                            const card = document.createElement('div');
                            card.className = 'rounded-lg bg-background p-sm';
                            const title = document.createElement('p');
                            title.className = 'text-body font-semibold';
                            title.textContent = day.label + (day.today ? ' (today)' : '');
                            const list = document.createElement('div');
                            list.className = 'mt-xs space-y-xs';
                            day.slots.forEach(slot => {
                                const text = `${slot.startTime}–${slot.endTime}`;
                                list.appendChild(optionButton(day, [slot.startTime], text, slot.available, slot.reason, slot.remaining));
                                if (day.date === selected.date && selected.times.join('+') === slot.startTime && slot.available) restored = `${day.label} · ${text}`;
                            });
                            if (day.wholeDay) {
                                const text = `Whole day ${day.wholeDay.startTime}–${day.wholeDay.endTime}`;
                                list.appendChild(optionButton(day, day.wholeDay.startTimes, text, day.wholeDay.available, day.wholeDay.reason, null));
                                if (day.date === selected.date && selected.times.join('+') === day.wholeDay.startTimes.join('+') && day.wholeDay.available) restored = `${day.label} · ${text}`;
                            }
                            card.append(title, list);
                            daysBox.appendChild(card);
                        });

                        // Keep the earlier choice only while it is still bookable.
                        restored ? setChoice(selected.date, selected.times, restored) : setChoice('', [], '');
                    };

                    const load = () => {
                        const pdl = pdlId();
                        daysBox.innerHTML = '';
                        if (!pdl) {
                            setChoice('', [], '');
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

                    // The PDL search fires `change` on its hidden input when a PDL is picked.
                    pdlSource?.addEventListener('change', load);
                    // Another relationship type can change what the PDL's sessions allow.
                    typeSource?.addEventListener('change', () => { if (pdlId()) load(); });
                    load();
                });
            })();
        </script>
    @endpush
@endonce
