{{--
    Progress bar for a manual (no QR) check-in or check-out, the same four
    steps as the QR flow. The page script marks the current step.
    Usage: @include('frontdesk.partials.manual-steps', ['wizard' => 'mci', 'steps' => ['Find Visitor', ...]])
--}}
<ol class="mb-lg flex items-start rounded-card border border-border bg-background px-md py-md" data-wizard-progress="{{ $wizard }}" aria-label="Steps">
    @foreach ($steps as $i => $label)
        <li class="relative flex flex-1 flex-col items-center text-center" data-wizard-step="{{ $i + 1 }}">
            @unless ($loop->last)
                <span class="absolute left-1/2 top-[17px] h-[3px] w-full rounded-full bg-border" data-wizard-line aria-hidden="true"></span>
            @endunless
            <span class="relative z-10 flex h-9 w-9 items-center justify-center rounded-full border-2 border-border bg-white text-metadata font-bold text-text-secondary" data-wizard-circle>{{ $i + 1 }}</span>
            <span class="mt-xs block px-xs text-metadata font-semibold text-text-secondary" data-wizard-label>{{ $label }}</span>
        </li>
    @endforeach
</ol>
