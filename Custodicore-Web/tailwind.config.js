/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
    ],
    // Dashboard controllers pick an accent name ('success' | 'warning' | 'danger' | 'info')
    // and the Blade views build the class dynamically (`text-{{ $stat['accent'] }}`),
    // which Tailwind's content scanner can't see as a literal string — safelisted here.
    safelist: [
        'text-success', 'text-warning', 'text-danger', 'text-info',
        'bg-success', 'bg-warning', 'bg-danger', 'bg-info',
        // stat-card.blade.php builds `bg-{accent}/10` dynamically
        'bg-success/10', 'bg-warning/10', 'bg-danger/10', 'bg-info/10',
    ],
    theme: {
        extend: {
            // Trimmed to 2 brand hues (navy + teal) plus neutral gray/white
            // so every page in the web app reads as one consistent product,
            // instead of the 6-hue palette this used to be (navy, teal,
            // green, amber, red, blue).
            //
            // The 4 semantic keys below (success/warning/danger/info) are
            // KEPT — Blade views and controllers all over the app already
            // reference them by name (`cc-chip-success`, `$stat['accent']
            // => 'danger'`, etc.) and none of that had to change. Only the
            // hex values changed, so status is now told apart by shade +
            // the text label next to it, not by a separate hue:
            //   success -> primary-teal exactly   (positive: active, verified, eligible, completed)
            //   warning -> a lighter blue, same navy family (caution: pending, awaiting review)
            //   danger  -> primary-navy exactly    (needs attention: inactive, rejected, restricted)
            //   info    -> text-secondary exactly  (neutral/informational, no action needed)
            //
            // NOTE: this file was "1:1 with src/designSystem/tokens/colors.js"
            // (the mobile app's own tokens) before this pass — that's no
            // longer true after this trim. This pass covers the web app
            // only; the mobile app's colors.js hasn't been touched, so the
            // two are now out of sync until someone does the same pass there.
            colors: {
                'primary-navy': '#0F3D7A',
                'primary-teal': '#0DA58A',
                success: '#0DA58A',
                warning: '#2563EB',
                danger: '#0F3D7A',
                info: '#6B7280',
                background: '#F8FAFC',
                card: '#FFFFFF',
                border: '#E5E7EB',
                'text-primary': '#111827',
                'text-secondary': '#6B7280',
            },
            // 1:1 with src/designSystem/tokens/spacing.js (spacing + layout.*Radius)
            spacing: {
                xs: '4px',
                sm: '8px',
                md: '16px',
                lg: '24px',
                xl: '32px',
            },
            borderRadius: {
                card: '16px',      // layout.cardRadius
                button: '12px',    // layout.buttonRadius (sm + xs)
                sm: '8px',         // layout.borderRadiusSm
                chip: '9999px',    // layout.chipRadius
            },
            // 1:1 with src/designSystem/tokens/typography.js
            fontSize: {
                'page-title': ['30px', { lineHeight: '36px', fontWeight: '700' }],
                'section-label': ['14px', { lineHeight: '20px', fontWeight: '600', letterSpacing: '0.6px' }],
                'card-title': ['18px', { lineHeight: '24px', fontWeight: '600' }],
                'screen-header': ['20px', { lineHeight: '24px', fontWeight: '700' }],
                eyebrow: ['14px', { lineHeight: '20px', fontWeight: '600', letterSpacing: '0.6px' }],
                body: ['16px', { lineHeight: '24px', fontWeight: '400' }],
                metadata: ['14px', { lineHeight: '20px', fontWeight: '400' }],
                'status-label': ['12px', { lineHeight: '16px', fontWeight: '600', letterSpacing: '0.2px' }],
            },
            // src/designSystem/tokens/shadows.js `card` — subtle, 1px border + soft lift
            boxShadow: {
                card: '0 1px 6px 0 rgb(17 24 39 / 0.06)',
            },
        },
    },
    plugins: [],
};
