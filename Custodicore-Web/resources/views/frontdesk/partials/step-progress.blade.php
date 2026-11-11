{{--
    The one step progress bar of the Check-In / Check-Out page, shared by the
    QR flows and the manual (no QR) flows so they always look and behave the
    same: connected steps, green ✓ when done, amber for the current step,
    grey for steps still to come.

    Usage: @include('frontdesk.partials.step-progress', ['wizard' => 'mci', 'steps' => ['Find Visitor', ...]])
    Paint it from a script with: window.ccPaintSteps('mci', 2)
--}}
<ol class="{{ $class ?? 'mb-lg' }} flex items-start rounded-card border border-border bg-background px-md py-md" data-wizard-progress="{{ $wizard }}" aria-label="Steps">
    @foreach ($steps as $i => $label)
        <li class="relative flex min-w-0 flex-1 flex-col items-center text-center" data-wizard-step="{{ $i + 1 }}" @if ($i === 0) aria-current="step" @endif>
            @unless ($loop->last)
                <span class="absolute left-1/2 top-[17px] h-[3px] w-full rounded-full bg-border" data-wizard-line aria-hidden="true"></span>
            @endunless
            <span class="relative z-10 flex h-9 w-9 items-center justify-center rounded-full border-2 text-metadata font-bold {{ $i === 0 ? 'border-warning bg-warning/10 text-warning' : 'border-border bg-white text-text-secondary' }}" data-wizard-circle>{{ $i + 1 }}</span>
            <span class="mt-xs block px-xs text-metadata font-semibold {{ $i === 0 ? 'text-warning' : 'text-text-secondary' }}" data-wizard-label>{{ $label }}</span>
        </li>
    @endforeach
</ol>

@once
    <script>
        /* Marks steps before `step` done, `step` current, the rest to do. */
        window.ccPaintSteps = function (name, step) {
            const tone = {
                done: ['border-success', 'bg-success', 'text-white'],
                current: ['border-warning', 'bg-warning/10', 'text-warning'],
                todo: ['border-border', 'bg-white', 'text-text-secondary'],
            };
            const text = { done: 'text-success', current: 'text-warning', todo: 'text-text-secondary' };

            document.querySelectorAll(`[data-wizard-progress="${name}"] [data-wizard-step]`).forEach(li => {
                const i = Number(li.dataset.wizardStep);
                const state = i < step ? 'done' : (i === step ? 'current' : 'todo');
                const circle = li.querySelector('[data-wizard-circle]');
                const label = li.querySelector('[data-wizard-label]');
                const line = li.querySelector('[data-wizard-line]');

                circle.classList.remove(...tone.done, ...tone.current, ...tone.todo);
                circle.classList.add(...tone[state]);
                circle.textContent = state === 'done' ? '✓' : i;
                label.classList.remove(...Object.values(text));
                label.classList.add(text[state]);
                line?.classList.toggle('bg-success', i < step);
                line?.classList.toggle('bg-border', i >= step);

                if (state === 'current') li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
            });
        };
    </script>
@endonce
