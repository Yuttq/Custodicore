{{--
    A visitor's photo, big enough to match the face at the gate; click it for
    the full-size photo in a popup. Without a photo: their initials.
    Usage: @include('frontdesk.partials.visitor-photo', ['visitor' => $visitor])
--}}
@php $photoUrl = $visitor->photoUrl(); @endphp
@if ($photoUrl)
    <button type="button" data-photo-open="visitorPhotoDialog" title="Click to enlarge the photo"
            class="group relative shrink-0 overflow-hidden rounded-xl border-2 border-border transition hover:border-primary-teal focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-teal focus-visible:ring-offset-2">
        <img src="{{ $photoUrl }}" alt="Photo of {{ $visitor->full_name }}" class="h-28 w-28 object-cover">
        <span class="absolute inset-x-0 bottom-0 bg-black/55 py-0.5 text-center text-[11px] font-semibold text-white opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100">Enlarge</span>
    </button>

    <dialog id="visitorPhotoDialog" class="cc-modal max-w-xl" aria-label="Photo of {{ $visitor->full_name }}">
        <div class="flex items-center justify-between gap-sm">
            <p class="text-card-title">{{ $visitor->listName() }}</p>
            <button type="button" class="cc-modal-close" data-photo-close>Close</button>
        </div>
        <img src="{{ $photoUrl }}" alt="Photo of {{ $visitor->full_name }}" class="mt-md max-h-[70vh] w-full rounded-lg border border-border bg-background object-contain">
        <div class="mt-md flex justify-end gap-sm">
            <a href="{{ $photoUrl }}" target="_blank" rel="noopener" class="cc-btn-secondary">Open in new tab</a>
            <button type="button" class="cc-btn-primary" data-photo-close>Done</button>
        </div>
    </dialog>

    <script>
        (() => {
            const dialog = document.getElementById('visitorPhotoDialog');
            document.querySelector('[data-photo-open="visitorPhotoDialog"]').addEventListener('click', () => dialog.showModal());
            dialog.querySelectorAll('[data-photo-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
            // Clicking the dark area around the photo closes it too.
            dialog.addEventListener('click', e => { if (e.target === dialog) dialog.close(); });
        })();
    </script>
@else
    @include('admin.partials.avatar', ['name' => $visitor->full_name, 'size' => 'h-28 w-28'])
@endif
