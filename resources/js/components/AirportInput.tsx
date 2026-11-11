import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react'
import { MapPin } from 'lucide-react'
import { airportLabel, searchAirports, type Airport } from '../lib/airports'

/**
 * City / airport field with suggestions (name or IATA code). Choosing a suggestion stores
 * the IATA code; anything else typed is kept as free text.
 */
export default function AirportInput({ value, onChange, placeholder, invalid, id }: {
  value: string
  onChange: (v: string) => void
  placeholder?: string
  invalid?: boolean
  id?: string
}) {
  const [text, setText] = useState(() => (value ? airportLabel(value) : ''))
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)
  const box = useRef<HTMLDivElement>(null)
  const lastValue = useRef(value)
  const listId = useId()
  const results = searchAirports(text.replace(/\s*\([A-Z]{3}\)$/, ''), 8)

  // Only replace the visible text for changes made outside this input.
  useEffect(() => {
    if (value === lastValue.current) return
    lastValue.current = value
    setText(value ? airportLabel(value) : '')
  }, [value])

  const emit = (next: string) => {
    lastValue.current = next
    onChange(next)
  }

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => { if (!box.current?.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', onDown)
    return () => document.removeEventListener('mousedown', onDown)
  }, [open])

  const choose = (a: Airport) => {
    setText(`${a.city} (${a.code})`)
    emit(a.code)
    setOpen(false)
  }

  const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setOpen(true); setActive((i) => Math.min(i + 1, results.length - 1)) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((i) => Math.max(i - 1, 0)) }
    else if (e.key === 'Enter' && open && results[active]) { e.preventDefault(); choose(results[active]) }
    else if (e.key === 'Escape') setOpen(false)
  }

  return (
    <div ref={box} className="relative">
      <MapPin className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden />
      <input
        id={id}
        role="combobox"
        aria-expanded={open}
        aria-controls={listId}
        aria-autocomplete="list"
        aria-invalid={invalid}
        autoComplete="off"
        className={`input pl-9 ${invalid ? 'border-red-400' : ''}`}
        placeholder={placeholder}
        value={text}
        onChange={(e) => {
          const t = e.target.value
          setText(t)
          setOpen(true)
          setActive(0)
          // A typed 3-letter code is stored as a code; any other text is stored as entered.
          emit(/^[A-Za-z]{3}$/.test(t.trim()) ? t.trim().toUpperCase() : t)
        }}
        onFocus={() => setOpen(true)}
        onKeyDown={onKeyDown}
      />
      {open && results.length > 0 && (
        <ul id={listId} role="listbox" className="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-md border border-slate-200 bg-surface py-1 shadow-xl">
          {results.map((a, i) => (
            <li
              key={a.code}
              role="option"
              aria-selected={i === active}
              onMouseDown={(e) => e.preventDefault()}
              onMouseEnter={() => setActive(i)}
              onClick={() => choose(a)}
              className={`flex cursor-pointer items-center gap-3 px-3 py-2 text-sm ${i === active ? 'bg-brand-50' : ''}`}
            >
              <span className="w-10 shrink-0 font-mono font-semibold text-slate-900">{a.code}</span>
              <span className="min-w-0 flex-1 truncate"><span className="text-slate-900">{a.city}</span> <span className="text-slate-500">· {a.name}, {a.country}</span></span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
