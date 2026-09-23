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
            // 1:1 with src/designSystem/tokens/colors.js
            colors: {
                'primary-navy': '#0F3D7A',
                'primary-teal': '#0DA58A',
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
