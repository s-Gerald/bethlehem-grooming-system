/** @type {import('tailwindcss-v3').Config} */
export default {
  content: ["./*.html", "./pages/**/*.html", "./scripts/**/*.js", "!./scripts/vendor/**"],
  theme: {
    extend: {
      // Opt-in theme values live in css/themes/portal.css. Existing Tailwind
      // colors are untouched; new pages can reuse these semantic utilities.
      colors: {
        portal: Object.fromEntries([
          "canvas", "surface", "sidebar", "surface-soft", "icon-surface", "border", "text",
          "muted", "muted-icon", "primary", "primary-hover", "active", "accent",
          "success", "success-soft", "warning", "warning-soft", "danger",
          "danger-soft", "danger-hover", "nav-hover", "record", "reminder",
          "reminder-border",
        ].map((name) => [name, `var(--portal-${name})`])),
      },
      borderRadius: {
        portal: "18px",
      },
      boxShadow: {
        portal: "var(--portal-shadow)",
        "portal-button": "0 3px 8px rgb(44 62 80 / 10%)",
        "portal-dropdown": "0 12px 36px rgb(44 62 80 / 14%)",
      },
    },
  },
  plugins: [],
};
