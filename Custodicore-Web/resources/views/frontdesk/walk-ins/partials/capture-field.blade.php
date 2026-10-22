{{--
    A file field that can also be filled from the desk camera (walk-ins/create).
    $name: input name; $id: unique key; $pdf: also accept PDF files.
--}}
<div class="flex flex-wrap items-center gap-sm">
    <button type="button" class="cc-btn-primary" data-camera-for="{{ $id }}">Take photo</button>
    <label class="cc-btn-secondary cursor-pointer">
        Upload
        <input type="file" name="{{ $name }}" data-file-for="{{ $id }}" class="sr-only"
               accept="{{ $pdf ? 'image/jpeg,image/png,image/webp,application/pdf' : 'image/jpeg,image/png,image/webp' }}" />
    </label>
    @php $errorKey = str_replace(['[', ']'], ['.', ''], $name); @endphp
    @error($errorKey)
        <p class="w-full text-metadata text-danger">{{ $message }}</p>
    @enderror
</div>
<div data-preview-for="{{ $id }}" class="mt-sm hidden text-metadata text-text-secondary"></div>
