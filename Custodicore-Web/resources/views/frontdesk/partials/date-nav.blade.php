{{--
    Front Desk calendar filter: ‹ previous day | 📅 pick a day | next day ›,
    the same on every list. Put it inside the page's GET form; picking a day
    submits it. $prevUrl / $nextUrl: links for the arrows (null greys it out).
    Usage: @include('frontdesk.partials.date-nav', ['value' => '2026-10-22', 'prevUrl' => ..., 'nextUrl' => ..., 'max' => today()])
--}}
<div class="flex h-[38px] items-stretch overflow-hidden rounded-button border border-border bg-white">
    @foreach ([['prevUrl', 'chevron-left', 'Previous day'], ['input'], ['nextUrl', 'chevron-right', 'Next day']] as $part)
        @if ($part[0] === 'input')
            <label class="flex cursor-pointer items-center gap-xs border-x border-border px-sm text-primary-teal">
                @include('admin.partials.icon', ['name' => 'calendar', 'class' => 'h-4 w-4'])
                <span class="sr-only">Date</span>
                <input type="date" name="{{ $name ?? 'date' }}" value="{{ $value }}" onchange="this.form.submit()"
                       @if (! empty($max)) max="{{ \Illuminate\Support\Carbon::parse($max)->format('Y-m-d') }}" @endif
                       class="w-[140px] border-0 bg-transparent p-0 text-body font-semibold text-text-primary focus:outline-none focus:ring-0" />
            </label>
        @elseif (! empty(${$part[0]}))
            <a href="{{ ${$part[0]} }}" class="flex w-9 items-center justify-center text-text-secondary transition hover:bg-background hover:text-primary-teal" title="{{ $part[2] }}" aria-label="{{ $part[2] }}">
                @include('admin.partials.icon', ['name' => $part[1], 'class' => 'h-4 w-4'])
            </a>
        @else
            <span class="flex w-9 items-center justify-center text-text-secondary/40" aria-hidden="true">
                @include('admin.partials.icon', ['name' => $part[1], 'class' => 'h-4 w-4'])
            </span>
        @endif
    @endforeach
</div>
