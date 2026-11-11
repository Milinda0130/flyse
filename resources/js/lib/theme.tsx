import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { Monitor, Moon, Sun } from 'lucide-react'

export type ThemeMode = 'light' | 'dark' | 'system'

const KEY = 'ftms_theme'

function readMode(): ThemeMode {
  try {
    const v = localStorage.getItem(KEY)
    return v === 'light' || v === 'dark' ? v : 'system'
  } catch {
    return 'system'
  }
}

const systemDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches

type ThemeState = { mode: ThemeMode; isDark: boolean; setMode: (m: ThemeMode) => void }
const ThemeContext = createContext<ThemeState | null>(null)

/** Applies the `dark` class on <html>; follows the device setting when mode is "system". */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const [mode, setModeState] = useState<ThemeMode>(readMode)
  const [isDark, setIsDark] = useState(() => (mode === 'system' ? systemDark() : mode === 'dark'))

  useEffect(() => {
    const media = window.matchMedia('(prefers-color-scheme: dark)')
    const apply = () => {
      const dark = mode === 'system' ? media.matches : mode === 'dark'
      setIsDark(dark)
      document.documentElement.classList.toggle('dark', dark)
      document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#0c0b0a' : '#f6f4ef')
    }
    apply()
    media.addEventListener('change', apply)
    return () => media.removeEventListener('change', apply)
  }, [mode])

  const value = useMemo<ThemeState>(() => ({
    mode,
    isDark,
    setMode: (m) => {
      setModeState(m)
      try {
        if (m === 'system') localStorage.removeItem(KEY)
        else localStorage.setItem(KEY, m)
      } catch {
        // Storage unavailable (private mode): the choice lasts for this visit only.
      }
    },
  }), [mode, isDark])

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useTheme() {
  const ctx = useContext(ThemeContext)
  if (!ctx) throw new Error('useTheme must be used inside ThemeProvider')
  return ctx
}

const OPTIONS: { mode: ThemeMode; label: string; Icon: typeof Sun }[] = [
  { mode: 'light', label: 'Light', Icon: Sun },
  { mode: 'dark', label: 'Dark', Icon: Moon },
  { mode: 'system', label: 'System', Icon: Monitor },
]

/** Segmented Light / Dark / System switch. */
export function ThemeToggle({ className = '' }: { className?: string }) {
  const { mode, setMode } = useTheme()
  return (
    <div role="radiogroup" aria-label="Colour theme" className={`inline-flex rounded-md border border-slate-300 bg-surface p-0.5 ${className}`}>
      {OPTIONS.map(({ mode: m, label, Icon }) => (
        <button
          key={m}
          type="button"
          role="radio"
          aria-checked={mode === m}
          title={label}
          onClick={() => setMode(m)}
          className={`rounded p-1.5 transition ${mode === m ? 'bg-gold text-ink' : 'text-slate-500 hover:text-slate-800'}`}
        >
          <Icon className="h-4 w-4" aria-hidden />
          <span className="sr-only">{label}</span>
        </button>
      ))}
    </div>
  )
}
