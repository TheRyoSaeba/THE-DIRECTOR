/** @type {import('tailwindcss').Config} */
export default {
    darkMode: ["class"],
    content: [
        "./resources/views/**/*.blade.php",
        "./resources/js/**/*.{js,jsx,ts,tsx}",
        "./app/Models/**/*.php",
    ],
    // Safelist dynamically-constructed class names the JIT scanner can't see.
    // These are built via string interpolation in PHP models.

    theme: {
        container: {
            center: true,
            padding: "2rem",
            screens: {
                "2xl": "1400px",
            },
        },
        extend: {
            screens: {
                nav: '1072px',
            },
            // Token colours (values in resources/css/app.css :root). The
            // `/ <alpha-value>` form makes opacity modifiers work: bg-card/60.
            colors: {
                border: "hsl(var(--border) / <alpha-value>)",
                input: "hsl(var(--input) / <alpha-value>)",
                ring: "hsl(var(--ring) / <alpha-value>)",
                background: "hsl(var(--background) / <alpha-value>)",
                foreground: "hsl(var(--foreground) / <alpha-value>)",
                primary: {
                    DEFAULT: "hsl(var(--primary) / <alpha-value>)",
                    foreground: "hsl(var(--primary-foreground) / <alpha-value>)",
                },
                secondary: {
                    DEFAULT: "hsl(var(--secondary) / <alpha-value>)",
                    foreground: "hsl(var(--secondary-foreground) / <alpha-value>)",
                },
                destructive: {
                    DEFAULT: "hsl(var(--destructive) / <alpha-value>)",
                    foreground: "hsl(var(--destructive-foreground) / <alpha-value>)",
                },
                success: {
                    DEFAULT: "hsl(var(--success) / <alpha-value>)",
                    foreground: "hsl(var(--success-foreground) / <alpha-value>)",
                },
                warning: {
                    DEFAULT: "hsl(var(--warning) / <alpha-value>)",
                    foreground: "hsl(var(--warning-foreground) / <alpha-value>)",
                },
                muted: {
                    DEFAULT: "hsl(var(--muted) / <alpha-value>)",
                    foreground: "hsl(var(--muted-foreground) / <alpha-value>)",
                },
                accent: {
                    DEFAULT: "hsl(var(--accent) / <alpha-value>)",
                    foreground: "hsl(var(--accent-foreground) / <alpha-value>)",
                },
                popover: {
                    DEFAULT: "hsl(var(--popover) / <alpha-value>)",
                    foreground: "hsl(var(--popover-foreground) / <alpha-value>)",
                },
                card: {
                    DEFAULT: "hsl(var(--card) / <alpha-value>)",
                    foreground: "hsl(var(--card-foreground) / <alpha-value>)",
                },
                cash: {
                    clean: "hsl(var(--cash-clean) / <alpha-value>)",
                    dirty: "hsl(var(--cash-dirty) / <alpha-value>)",
                },
                gold: "hsl(var(--gold) / <alpha-value>)",
            },
            // Named type scale (additive: xs…9xl and arbitrary sizes still work).
            // `label` is the only new size; the rest alias the defaults by role.
            // Pair `text-label` with `uppercase`.
            fontSize: {
                label: ["10px", { lineHeight: "14px", letterSpacing: "0.16em", fontWeight: "800" }],
                caption: ["12px", { lineHeight: "16px" }],
                body: ["14px", { lineHeight: "20px" }],
                lead: ["16px", { lineHeight: "24px" }],
                title: ["20px", { lineHeight: "28px" }],
                display: ["30px", { lineHeight: "36px" }],
            },
            // One layering scale (additive: z-0…z-50 still work).
            zIndex: {
                header: "40",
                drawer: "50",
                dialog: "60",
                toast: "70",
                tooltip: "80",
            },
            borderRadius: {
                lg: "var(--radius)",
                md: "calc(var(--radius) - 2px)",
                sm: "calc(var(--radius) - 4px)",
            },
        },
    },
    plugins: [
        require("tailwindcss-animate"),
        require("@tailwindcss/forms"),
    ],
};
