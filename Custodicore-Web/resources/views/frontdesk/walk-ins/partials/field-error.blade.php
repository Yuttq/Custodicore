{{--
    The red message under a walk-in field. Shows the server's error after a
    failed submit; the page script (walk-ins/create) fills and clears it
    while the officer types. $name: the field's error key (dot notation).
--}}
<p class="mt-xs text-metadata font-semibold text-danger" data-error-for="{{ $name }}" role="alert" @unless ($errors->has($name)) hidden @endunless>{{ $errors->first($name) }}</p>
