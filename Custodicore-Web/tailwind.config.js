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
            // Follows the shared "Custodicore UI Guidelines" so Web and Mobile
            // use one design language. 1:1 with src/designSystem/tokens/colors.js.
            //
            // Meaning of each color (don't reuse a color for a different meaning):
            //   primary-navy -> branding, navigation, headers
            //   primary-teal -> primary actions / buttons
            //   success      -> green: success / confirmed
            //   warning      -> amber: pending / warning
            //   danger       -> red:   errors / danger / cancelled
            //   info         -> blue:  information
            //
            // The 4 semantic keys (success/warning/danger/info) are referenced
            // by name all over the Blade views and controllers (`cc-chip-success`,
            // `$stat['accent'] => 'danger'`, ...), so only the hex values live here.
            colors: {
                'primary-navy': '#0F3D7A',
                'primary-teal': '#0DA58A',
                // Buttons (everything except the sidebar) use this green — same as the
                // "Attendance Confirmed" green in the mobile app.
                'primary-green': '#16A34A',
                success: '#16A34A',
                warning: '#F59E0B',
                danger: '#EF4444',
                info: '#2563EB',
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
            // One font family for every web page (Admin, Record Officer, Front Desk).
            // Same stack as public/css/styles.css, so the Tailwind pages and the
            // Record Officer pages render in exactly the same typeface.
            fontFamily: {
                sans: ['-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', 'Helvetica', 'Arial', 'sans-serif'],
            },
            // One compact size scale for every web page. These sizes match the
            // Record Officer dashboard (public/css/styles.css): 20px page title,
            // 14px card title, 13px body/table text, 12px secondary text, 11px labels.
            // The default Tailwind names (text-xs / text-sm / text-lg) are pinned to the
            // same scale so no page can drift to a different size.
            fontSize: {
                'page-title': ['20px', { lineHeight: '28px', fontWeight: '700' }],
                'stat-value': ['24px', { lineHeight: '32px', fontWeight: '700' }],
                'section-label': ['10.5px', { lineHeight: '16px', fontWeight: '700', letterSpacing: '0.04em' }],
                'card-title': ['14px', { lineHeight: '20px', fontWeight: '700' }],
                'screen-header': ['20px', { lineHeight: '28px', fontWeight: '700' }],
                eyebrow: ['11px', { lineHeight: '16px', fontWeight: '700', letterSpacing: '0.04em' }],
                body: ['13px', { lineHeight: '20px', fontWeight: '400' }],
                metadata: ['12px', { lineHeight: '16px', fontWeight: '400' }],
                'status-label': ['11px', { lineHeight: '16px', fontWeight: '600', letterSpacing: '0.2px' }],
                xs: ['11px', { lineHeight: '16px' }],
                sm: ['12px', { lineHeight: '16px' }],
                lg: ['14px', { lineHeight: '20px' }],
            },
            // src/designSystem/tokens/shadows.js `card` — subtle, 1px border + soft lift
            boxShadow: {
                card: '0 1px 6px 0 rgb(17 24 39 / 0.06)',
            },
        },
    },
    plugins: [],
};
