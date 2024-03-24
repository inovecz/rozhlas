/** @type {import('tailwindcss').Config} */
import defaultTheme from 'tailwindcss/defaultTheme';

export default {
    content: [
        "./resources/**/*.blade.php",
        "./resources/**/*.js",
        "./resources/**/*.vue",
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },
    variants: {
        textOpacity: ['group-disabled'],
    },
    plugins: [
        require("daisyui")
    ],
    daisyui: {
        themes: ["light", "dark", "night"],
    },
}

