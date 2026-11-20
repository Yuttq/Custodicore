{{--
  Name suffix (Jr., Sr., II …). Tells apart a father and son with the same
  name, which the duplicate-person check (name + date of birth) relies on.
    $selected — current value (or null)
--}}
<div class="field-m">
  <label>Suffix (optional)</label>
  <select name="suffix">
    <option value="">None</option>
    @foreach (\App\Support\Sanitizer::SUFFIXES as $suffix)
      <option value="{{ $suffix }}" {{ ($selected ?? null) === $suffix ? 'selected' : '' }}>{{ $suffix }}</option>
    @endforeach
  </select>
  @error('suffix')<p class="field-hint" style="color:var(--red-text);">{{ $message }}</p>@enderror
</div>
