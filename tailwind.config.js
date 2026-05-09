import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    // Tailwind preflight is disabled so Tailwind utility classes can layer
    // on top of Bootstrap without resetting Bootstrap's normalization.
    // The classic admin layout is Bootstrap-based; the v2 admin layout uses
    // Tailwind utilities. Both must coexist on the same site.
    corePlugins: {
        preflight: false,
    },

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [
        // 'class' strategy = forms styles only apply to elements with .form-input,
        // .form-select, etc. — does NOT globally restyle every <input>/<button> on
        // the page. Required so Bootstrap form controls and navbar items are not
        // affected when Tailwind is loaded inside the classic admin layout.
        forms({ strategy: 'class' }),
    ],
};
