{{-- "No expiry date" turns off the ID expiry date box (any page with ID expiry fields). --}}
@once
<script src="{{ asset('js/id-expiry-scan.js') }}?v={{ @filemtime(public_path('js/id-expiry-scan.js')) ?: '1' }}" defer></script>
<script>
  // "No expiry date" clears and turns off the date box.
  document.addEventListener('change', function (e) {
    if (!e.target.matches('[data-id-no-expiry]')) return;
    var date = e.target.closest('form, [data-id-expiry-fields]').querySelector('[data-id-expiry-date]');
    if (!date) return;
    date.disabled = e.target.checked;
    if (e.target.checked) date.value = '';
  });
</script>
@endonce
