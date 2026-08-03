/**
 * Tailwind build config.
 *
 * The app used to pull Tailwind from cdn.tailwindcss.com and configure it in
 * an inline <script>. Both are blocked by any reasonable Content-Security-
 * Policy, and a blocked CDN means an unstyled page — so the CSS is now built
 * ahead of time into public/assets/css/app.css and committed.
 *
 * Rebuild after changing markup or this file:
 *
 *     npx tailwindcss@3 -c tailwind.config.js \
 *         -i resources/app.css -o public/assets/css/app.css --minify
 *
 * `content` must list every file that contains Tailwind class names, or the
 * classes they use get tree-shaken out of the build and the page renders
 * unstyled in places.
 */
module.exports = {
    darkMode: 'class',
    content: [
        './public/*.php',
        './public/**/*.php',
        './app/Views/**/*.php',
    ],
    theme: {
        extend: {
            colors: {
                ink:    { 900: '#020617', 800: '#0b1220', 700: '#111a2e' },
                accent: { DEFAULT: '#22c55e', soft: '#4ade80' },
                gold:   '#fbbf24',
            },
            fontFamily: {
                // Inter and JetBrains Mono are used when the visitor happens
                // to have them; otherwise the system stack. Self-hosting the
                // web fonts would mean shipping ~200KB of files for a small
                // typographic gain, and Google Fonts is another origin the
                // CSP would have to allow.
                sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
                mono: ['"JetBrains Mono"', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'monospace'],
            },
            keyframes: {
                pulseRing: {
                    '0%':   { boxShadow: '0 0 0 0 rgba(34,197,94,.45)' },
                    '70%':  { boxShadow: '0 0 0 18px rgba(34,197,94,0)' },
                    '100%': { boxShadow: '0 0 0 0 rgba(34,197,94,0)' },
                },
                slideUp: {
                    '0%':   { opacity: 0, transform: 'translateY(8px)' },
                    '100%': { opacity: 1, transform: 'translateY(0)' },
                },
                ticker: {
                    '0%':   { opacity: 0, transform: 'translateX(-10px)' },
                    '100%': { opacity: 1, transform: 'translateX(0)' },
                },
                popIn: {
                    '0%':   { transform: 'scale(.85)', opacity: 0 },
                    '100%': { transform: 'scale(1)', opacity: 1 },
                },
            },
            animation: {
                'pulse-ring': 'pulseRing 2s infinite',
                'slide-up':   'slideUp .35s ease-out both',
                'ticker-in':  'ticker .3s ease-out both',
                'pop-in':     'popIn .18s ease-out both',
            },
        },
    },
};
