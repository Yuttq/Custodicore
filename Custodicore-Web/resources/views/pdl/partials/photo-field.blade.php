{{--
  PDL photo upload with live preview. The form using it needs
  enctype="multipart/form-data".
    $pdl      — existing Pdl on the edit page (or null when registering)
    $required — true on Register PDL (a photo is mandatory there)
  Server rules: PdlController::PHOTO_RULES.
--}}
@php
  $pdl = $pdl ?? null;
  $required = $required ?? false;
  $currentUrl = $pdl?->photoUrl();
@endphp
<div class="field-m" data-photo-field>
  <label for="photo">{{ $pdl ? 'Photo' : 'PDL Photo' }}</label>
  <div class="photo-field">
    <div class="photo-preview {{ $currentUrl ? 'has-image' : '' }}" data-photo-preview>
      @if ($currentUrl)
        <img src="{{ $currentUrl }}" alt="Current photo of {{ $pdl->full_name }}">
      @else
        <span>No photo yet</span>
      @endif
    </div>
    <div>
      <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp" data-label="{{ $pdl ? 'New photo' : 'Photo' }}"
             {{ $required ? 'required' : '' }}>
      <p class="field-hint">
        {{ $pdl ? 'Choose a new picture only if you want to replace the current one.' : 'A clear, front-facing picture of the PDL.' }}
        JPG, PNG or WEBP, up to 5 MB.
      </p>
    </div>
  </div>
</div>

@once
<script>
  document.querySelectorAll('[data-photo-field]').forEach(function (field) {
    var input = field.querySelector('input[type="file"]');
    var preview = field.querySelector('[data-photo-preview]');
    var original = preview.innerHTML;
    var originalHasImage = preview.classList.contains('has-image');
    var MAX = 5 * 1024 * 1024;

    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      input.setCustomValidity('');
      if (!file) {
        preview.innerHTML = original;
        preview.classList.toggle('has-image', originalHasImage);
        return;
      }
      if (['image/jpeg', 'image/png', 'image/webp'].indexOf(file.type) === -1) {
        input.setCustomValidity('Please choose a JPG, PNG or WEBP picture.');
      } else if (file.size > MAX) {
        input.setCustomValidity('The picture is too large. Maximum size is 5 MB.');
      }
      if (input.validationMessage) {
        input.reportValidity();
        return;
      }
      var img = document.createElement('img');
      img.alt = 'Selected photo';
      img.src = URL.createObjectURL(file);
      preview.innerHTML = '';
      preview.appendChild(img);
      preview.classList.add('has-image');
    });
  });
</script>
@endonce
