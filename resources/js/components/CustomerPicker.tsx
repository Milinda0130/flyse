import { useEffect, useId, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { ChevronDown, Search, UserPlus, X } from 'lucide-react'
import { useCustomerSearch } from '../hooks/queries'
import { Button, Modal, Spinner } from './ui'
import CustomerForm from './CustomerForm'

type Picked = { id: number; full_name: string; phone: string | null; email?: string | null }

/** Waits until typing pauses so every keystroke doesn't hit the server. */
function useDebounced<T>(value: T, ms: number) {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const t = window.setTimeout(() => setDebounced(value), ms)
    return () => window.clearTimeout(t)
  }, [value, ms])
  return debounced
}

/** Bold the part of the text that matches what was typed. */
function highlight(text: string, term: string): ReactNode {
  const q = term.trim()
  if (!q) return text
  const i = text.toLowerCase().indexOf(q.toLowerCase())
  if (i < 0) return text
  return <>{text.slice(0, i)}<mark className="rounded-sm bg-gold/30 px-0 font-semibold text-inherit">{text.slice(i, i + q.length)}</mark>{text.slice(i + q.length)}</>
}

/**
 * Searchable customer dropdown (combobox).
 * Click to see customers A-Z; type one letter or more to get suggestions (names starting
 * with the text first). Arrow keys + Enter to choose, Esc to close. The last option
 * creates a new customer pre-filled with what was typed.
 */
export default function CustomerPicker({ value, onChange, error }: { value: Picked | null; onChange: (c: Picked | null) => void; error?: string }) {
  const [term, setTerm] = useState('')
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)
  const [creating, setCreating] = useState(false)
  const box = useRef<HTMLDivElement>(null)
  const input = useRef<HTMLInputElement>(null)
  const listId = useId()

  const debounced = useDebounced(term, 200)
  const { data: results = [], isFetching } = useCustomerSearch(debounced, open)
  const optionCount = results.length + 1 // + "New customer"

  useEffect(() => setActive(0), [debounced])

  // Close when clicking outside the picker.
  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => { if (!box.current?.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', onDown)
    return () => document.removeEventListener('mousedown', onDown)
  }, [open])

  // Keep the highlighted option in view while using the arrow keys.
  useEffect(() => {
    if (open) document.getElementById(`${listId}-opt-${active}`)?.scrollIntoView({ block: 'nearest' })
  }, [active, open, listId])

  const choose = (c: Picked) => {
    onChange(c)
    setOpen(false)
    setTerm('')
  }

  const startCreate = () => {
    setOpen(false)
    setCreating(true)
  }

  const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      if (!open) setOpen(true)
      else setActive((a) => (a + 1) % optionCount)
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setActive((a) => (a - 1 + optionCount) % optionCount)
    } else if (e.key === 'Enter' && open) {
      e.preventDefault()
      if (active < results.length) choose(results[active])
      else startCreate()
    } else if (e.key === 'Escape') {
      setOpen(false)
    }
  }

  if (value) {
    return (
      <div className="flex items-center justify-between gap-2 rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-sm">
        <span className="flex min-w-0 items-center gap-2">
          <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gold/20 text-xs font-semibold text-brand-700">
            {value.full_name.split(/\s+/).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('')}
          </span>
          <span className="min-w-0 truncate"><span className="font-medium text-slate-900">{value.full_name}</span> <span className="text-slate-500">{value.phone}</span></span>
        </span>
        <button type="button" className="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium text-brand-700 hover:bg-slate-100"
          onClick={() => { onChange(null); setTimeout(() => { input.current?.focus(); setOpen(true) }, 0) }}>
          <X className="h-3.5 w-3.5" aria-hidden /> Change
        </button>
      </div>
    )
  }

  return (
    <div ref={box} className="relative">
      <div className="flex gap-2">
      <div className="relative min-w-0 flex-1">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden />
        <input
          ref={input}
          role="combobox"
          aria-expanded={open}
          aria-controls={listId}
          aria-autocomplete="list"
          aria-activedescendant={open ? `${listId}-opt-${active}` : undefined}
          aria-invalid={!!error}
          aria-label="Customer"
          autoComplete="off"
          className={`input pl-9 pr-16 ${error ? 'border-red-400' : ''}`}
          placeholder="Select or type a name, phone or email"
          value={term}
          onChange={(e) => { setTerm(e.target.value); setOpen(true) }}
          onFocus={() => setOpen(true)}
          onClick={() => setOpen(true)}
          onKeyDown={onKeyDown}
        />
        <span className="absolute right-2 top-1/2 flex -translate-y-1/2 items-center gap-1">
          {isFetching && open && <Spinner small />}
          <button type="button" tabIndex={-1} aria-label={open ? 'Close list' : 'Show customers'}
            className="rounded p-1 text-slate-500 hover:bg-slate-100"
            onMouseDown={(e) => e.preventDefault()}
            onClick={() => { setOpen((o) => !o); input.current?.focus() }}>
            <ChevronDown className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`} aria-hidden />
          </button>
        </span>
      </div>
      <Button variant="secondary" className="shrink-0" onClick={startCreate} title="Add a new customer">
        <UserPlus className="h-4 w-4" aria-hidden /><span className="hidden sm:inline">New</span><span className="sr-only sm:hidden">New customer</span>
      </Button>
      </div>
      {error && <span className="mt-1 block text-xs text-red-600">{error}</span>}

      {open && (
        <ul id={listId} role="listbox" aria-label="Customers"
          className="absolute z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-md border border-slate-200 bg-surface py-1 shadow-xl">
          {!term.trim() && <li className="px-3 pb-1 pt-1.5 text-[0.7rem] font-semibold uppercase tracking-wide text-slate-500" role="presentation">Customers A–Z · type to search</li>}
          {results.length === 0 && !isFetching && (
            <li className="px-3 py-2 text-sm text-slate-500" role="presentation">No customer matches “{term.trim()}”.</li>
          )}
          {results.map((c, i) => (
            <li
              key={c.id}
              id={`${listId}-opt-${i}`}
              role="option"
              aria-selected={active === i}
              onMouseDown={(e) => e.preventDefault()}
              onMouseEnter={() => setActive(i)}
              onClick={() => choose(c)}
              className={`flex cursor-pointer items-center gap-3 px-3 py-2 text-sm ${active === i ? 'bg-brand-50' : ''}`}
            >
              <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600">
                {c.full_name.split(/\s+/).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('')}
              </span>
              <span className="min-w-0 flex-1">
                <span className="block truncate font-medium text-slate-900">{highlight(c.full_name, term)}</span>
                <span className="block truncate text-xs text-slate-500">
                  {highlight([c.phone, c.email].filter(Boolean).join(' · ') || 'No contact details', term)}
                </span>
              </span>
              {c.bookings_count !== undefined && <span className="shrink-0 text-xs text-slate-400">{c.bookings_count} booking{c.bookings_count === 1 ? '' : 's'}</span>}
            </li>
          ))}
          <li
            id={`${listId}-opt-${results.length}`}
            role="option"
            aria-selected={active === results.length}
            onMouseDown={(e) => e.preventDefault()}
            onMouseEnter={() => setActive(results.length)}
            onClick={startCreate}
            className={`mt-1 flex cursor-pointer items-center gap-2 border-t border-slate-100 px-3 py-2.5 text-sm font-medium text-brand-700 ${active === results.length ? 'bg-brand-50' : ''}`}
          >
            <UserPlus className="h-4 w-4" aria-hidden />
            {term.trim() ? <>New customer “{term.trim()}”</> : 'New customer'}
          </li>
        </ul>
      )}

      <Modal open={creating} title="New customer" onClose={() => setCreating(false)}>
        <CustomerForm initialName={term.trim()} onSaved={(c) => { choose(c); setCreating(false) }} onCancel={() => setCreating(false)} />
      </Modal>
    </div>
  )
}
