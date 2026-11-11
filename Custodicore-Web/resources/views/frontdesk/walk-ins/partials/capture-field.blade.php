{{--
    A file field that can also be filled from the desk camera (walk-ins/create).
    $name: input name; $id: unique key; $pdf: also accept PDF files.
    Photos: JPG or PNG. Documents: JPG, PNG or PDF. 5 MB at most.
--}}
@php $errorKey = str_replace(['[', ']'], ['.', ''], $name); @endphp
<div @class(['flex flex-wrap items-center gap-sm rounded-lg border-2 p-xs', 'border-danger' => $errors->has($errorKey), 'border-transparent' => ! $errors->has($errorKey)]) data-field-box="{{ $errorKey }}">
    <button type="button" class="cc-btn-primary" data-camera-for="{{ $id }}">Take photo</button>
    <label class="cc-btn-secondary cursor-pointer">
        Upload
        <input type="file" name="{{ $name }}" data-file-for="{{ $id }}" data-error-key="{{ $errorKey }}" class="sr-only"
               accept="{{ $pdf ? 'image/jpeg,image/png,application/pdf' : 'image/jpeg,image/png' }}" />
    </label>
</div>
@include('frontdesk.walk-ins.partials.field-error', ['name' => $errorKey])
<div data-preview-for="{{ $id }}" class="mt-sm hidden text-metadata text-text-secondary"></div>
