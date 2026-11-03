{{--
    ☰ button that opens and closes the sidebar menu on phones (hidden on wider
    screens by CSS). Placed inside the <aside> sidebar, right after the logo.
    $class: 'cc-menu-toggle' (Admin / Front Desk, resources/css/app.css)
            or 'menu-toggle' (Record Officer, public/css/styles.css).
--}}
<button type="button" class="{{ $class }}" data-menu-toggle aria-expanded="false" aria-label="Open menu">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
        <path d="M4 7h16M4 12h16M4 17h16" />
    </svg>
</button>
<script>
    (function (button) {
        button.addEventListener('click', function () {
            var open = button.closest('aside').classList.toggle('is-open');
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            button.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        });
    })(document.currentScript.previousElementSibling);
</script>
