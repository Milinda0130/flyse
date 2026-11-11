import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react'
import { Plus } from 'lucide-react'
import { useAuth } from '../auth/AuthContext'
import { useAirlines } from '../hooks/queries'
import { useSaveAirline } from '../hooks/mutations'
import { errorMessage } from '../lib/api'
import type { Airline } from '../lib/types'
import AirlineLogo from './AirlineLogo'

/**
 * Airline combobox with logos. Choosing an airline stores its id (and name); typing a name that is
 * not in the list keeps it as free text. Staff who manage airlines can add the missing one here.
 */
export default function AirlinePicker({ airlineId, name, onChange, invalid, placeholder = 'Search airline or code' }: {
  airlineId: number | null
  name: string | null
  onChange: (value: { airline_id: number | null; airline_name: string | null }) => void
  invalid?: boolean
  placeholder?: string
}) {
  const { can } = useAuth()
  const { data: airlines = [] } = useAirlines({ active_only: 1 })
  const save = useSaveAirline()
  const [text, setText] = useState(name ?? '')
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)
  const box = useRef<HTMLDivElement>(null)
  const listId = useId()
  const selected = airlines.find((a) => a.id === airlineId) ?? null

  useEffect(() => { setText(name ?? '') }, [name])
  useEffect(() => {
    const close = (e: MouseEvent) => { if (!box.current?.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [])

  const term = text.trim().toLowerCase()
  const matches = airlines
    .filter((a) => !term || a.name.toLowerCase().includes(term) || a.iata_code?.toLowerCase() === term || a.icao_code?.toLowerCase() === term)
    .sort((a, b) => Number(b.iata_code?.toLowerCase() === term) - Number(a.iata_code?.toLowerCase() === term) || a.name.localeCompare(b.name))
    .slice(0, 8)
  const exact = airlines.some((a) => a.name.toLowerCase() === term)
  const canAdd = can('manage-airlines') && term.length > 1 && !exact

  const choose = (a: Airline) => {
    onChange({ airline_id: a.id, airline_name: a.name })
    setText(a.name)
    setOpen(false)
  }
  const add = () => save.mutate({ payload: { name: text.trim() } }, { onSuccess: choose, onError: (e) => alert(errorMessage(e)) })

  const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setOpen(true); setActive((i) => Math.min(i + 1, matches.length - 1)) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((i) => Math.max(i - 1, 0)) }
    else if (e.key === 'Enter' && open && matches[active]) { e.preventDefault(); choose(matches[active]) }
    else if (e.key === 'Escape') setOpen(false)
  }

  return (
    <div ref={box} className="relative">
      <div className="relative">
        {selected && <span className="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2"><AirlineLogo airline={selected} className="h-5 w-10" /></span>}
        <input
          role="combobox" aria-expanded={open} aria-controls={listId} aria-autocomplete="list"
          className={`input ${selected ? 'pl-14' : ''} ${invalid ? 'border-red-500' : ''}`}
          value={text} placeholder={placeholder}
          onFocus={() => setOpen(true)}
          onChange={(e) => {
            setText(e.target.value)
            setOpen(true)
            setActive(0)
            // Free text until an airline is chosen.
            onChange({ airline_id: null, airline_name: e.target.value || null })
          }}
          onKeyDown={onKey}
        />
      </div>
      {open && (matches.length > 0 || canAdd) && (
        <ul id={listId} role="listbox" className="absolute z-30 mt-1 max-h-72 w-full overflow-auto rounded-md border border-slate-200 bg-surface py-1 shadow-lg">
          {matches.map((a, i) => (
            <li key={a.id} role="option" aria-selected={i === active}
              onMouseDown={(e) => { e.preventDefault(); choose(a) }} onMouseEnter={() => setActive(i)}
              className={`flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm ${i === active ? 'bg-gold/10' : ''}`}>
              <AirlineLogo airline={a} className="h-5 w-12" />
              <span className="min-w-0 flex-1 truncate text-slate-900">{a.name}</span>
              <span className="font-mono text-xs text-slate-500">{[a.iata_code, a.icao_code].filter(Boolean).join(' / ')}</span>
            </li>
          ))}
          {canAdd && (
            <li role="option" aria-selected={false} onMouseDown={(e) => { e.preventDefault(); add() }}
              className="flex cursor-pointer items-center gap-2 border-t border-slate-100 px-3 py-1.5 text-sm text-brand-700 hover:bg-gold/10">
              <Plus className="h-4 w-4" aria-hidden /> {save.isPending ? 'Adding…' : `Add “${text.trim()}” as a new airline`}
            </li>
          )}
        </ul>
      )}
    </div>
  )
}
