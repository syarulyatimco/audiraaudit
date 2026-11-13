/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./resources/views/**/*.blade.php', './app/View/**/*.php', './resources/js/**/*.js'],
  // Status badge tones are composed dynamically in components/status.blade.php.
  safelist: ['badge-neutral', 'badge-info', 'badge-teal', 'badge-warn', 'badge-danger', 'badge-success'],
  theme: {
    extend: {
      colors: {
        navy: { 50: '#f1f4f9', 100: '#e1e7f1', 200: '#c3cfe2', 300: '#94a8c8', 400: '#5f7aa6', 500: '#3e5a88', 600: '#2c446d', 700: '#213457', 800: '#172642', 900: '#0f1b31', 950: '#0a1324' },
        teal: { 50: '#ecfbf9', 100: '#cff4ef', 200: '#a1e8df', 300: '#67d5ca', 400: '#33bbb0', 500: '#169f96', 600: '#0d8079', 700: '#0f6662', 800: '#11514f', 900: '#124442' },
        ink: { 400: '#8b95a7', 500: '#667085', 600: '#475467', 700: '#344054', 900: '#101828' },
        canvas: '#f5f7fa',
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'sans-serif'],
        mono: ['JetBrains Mono', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'monospace'],
      },
      fontSize: { '2xs': ['0.6875rem', '1rem'] },
      boxShadow: { card: '0 1px 2px rgba(16,24,40,.04), 0 1px 3px rgba(16,24,40,.06)' },
    },
  },
  plugins: [require('@tailwindcss/forms')],
};
