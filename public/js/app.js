// Alpine.js is loaded from /js/alpine.min.js (bundled build of alpinejs 3.15).
// Shared Alpine helpers live here.
document.addEventListener('alpine:init', () => {
  window.Alpine.data('copyText', (text) => ({
    copied: false,
    copy() {
      navigator.clipboard.writeText(text).then(() => { this.copied = true; setTimeout(() => (this.copied = false), 2000); });
    },
  }));
});
