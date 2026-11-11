import colors from 'tailwindcss/colors'
import plugin from 'tailwindcss/plugin'

/**
 * Theme: black + gold, taken from the airplane background image.
 *
 * Light/dark mode: every colour family below is a CSS variable. In dark mode the
 * scale is mirrored (50<->950, 100<->900 ... 400<->600), so an existing class such
 * as `bg-slate-50 text-red-700` automatically becomes a dark background with light
 * text. Colours that must never flip (gold buttons, the black sidebar) use the
 * static `gold`, `ink` and `danger` tokens instead.
 */
const STEPS = ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950']

const gold = {
  50: '#fbf8ef', 100: '#f5edd2', 200: '#ebd9a2', 300: '#dfc06a', 400: '#d4a93f', 500: '#c3912a',
  600: '#a87322', 700: '#86561f', 800: '#6f4620', 900: '#5e3b1f', 950: '#36200e',
}

// `slate` is mapped to warm stone greys so the neutrals match the image.
const families = {
  slate: colors.stone, red: colors.red, amber: colors.amber, emerald: colors.emerald,
  sky: colors.sky, violet: colors.violet, orange: colors.orange, brand: gold,
}

const rgb = (hex) => {
  const n = parseInt(hex.slice(1), 16)
  return `${(n >> 16) & 255} ${(n >> 8) & 255} ${n & 255}`
}

const themeVariables = plugin(({ addBase }) => {
  const light = { '--surface': rgb('#ffffff'), '--canvas': rgb('#f6f4ef') }
  const dark = { '--surface': rgb('#191816'), '--canvas': rgb('#0c0b0a') }
  for (const [name, palette] of Object.entries(families)) {
    STEPS.forEach((step, i) => {
      light[`--${name}-${step}`] = rgb(palette[step])
      dark[`--${name}-${step}`] = rgb(palette[STEPS[STEPS.length - 1 - i]])
    })
  }
  addBase({ ':root': light, '.dark': dark })
})

const variableColors = Object.fromEntries(
  Object.keys(families).map((name) => [
    name,
    Object.fromEntries(STEPS.map((s) => [s, `rgb(var(--${name}-${s}) / <alpha-value>)`])),
  ]),
)

/** @type {import('tailwindcss').Config} */
export default {
  darkMode: 'class',
  content: ['./resources/views/**/*.blade.php', './resources/js/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        ...variableColors,
        surface: 'rgb(var(--surface) / <alpha-value>)',
        canvas: 'rgb(var(--canvas) / <alpha-value>)',
        gold: { DEFAULT: gold[400], light: gold[300], dark: gold[600] },
        ink: { DEFAULT: '#0c0b0a', soft: '#1a1916' },
        danger: '#dc2626',
      },
      fontFamily: { sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'] },
      screens: { '3xl': '1920px' },
    },
  },
  plugins: [themeVariables],
}
