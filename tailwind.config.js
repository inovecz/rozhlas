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
            fontSize: {
                xxs: ['0.625rem', {lineHeight: '0.75rem'}],
            }
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

