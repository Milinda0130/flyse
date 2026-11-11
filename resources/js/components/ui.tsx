import { useEffect, type ButtonHTMLAttributes, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react'
import { createPortal } from 'react-dom'
import { ArrowRight, TriangleAlert, X } from 'lucide-react'
import type { Option } from '../lib/types'

type Variant = 'primary' | 'secondary' | 'danger' | 'ghost'
const variants: Record<Variant, string> = {
  primary: 'bg-gold font-semibold text-ink hover:bg-gold-light disabled:opacity-50',
  secondary: 'border border-slate-300 bg-surface text-slate-700 hover:bg-slate-50 disabled:text-slate-400',
  danger: 'bg-danger text-white hover:brightness-110 disabled:opacity-50',
  ghost: 'text-slate-600 hover:bg-slate-100',
}

export function Button({ variant = 'primary', size = 'md', loading, className = '', children, disabled, ...props }:
  ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; size?: 'sm' | 'md'; loading?: boolean }) {
  const sizes = size === 'sm' ? 'px-2.5 py-1 text-xs' : 'px-4 py-2 text-sm'
  return (
    <button
      type="button"
      {...props}
      disabled={disabled || loading}
      className={`inline-flex items-center justify-center gap-1.5 rounded-md font-medium shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-1 focus-visible:ring-offset-surface disabled:cursor-not-allowed ${sizes} ${variants[variant]} ${className}`}
    >
      {loading && <Spinner small />}
      {children}
    </button>
  )
}

export function Field({ label, error, children, hint, className = '' }: { label: string; error?: string | string[]; children: ReactNode; hint?: string; className?: string }) {
  const message = Array.isArray(error) ? error[0] : error
  return (
    <label className={`block ${className}`}>
      <span className="label">{label}</span>
      {children}
      {hint && !message && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
      {message && <span className="mt-1 block text-xs text-red-600">{message}</span>}
    </label>
  )
}

/** Date limits stop a mistyped 5-digit year (e.g. 12003) that the database would reject. */
const DATE_LIMITS: Record<string, { min: string; max: string }> = {
  date: { min: '1900-01-01', max: '2100-12-31' },
  'datetime-local': { min: '1900-01-01T00:00', max: '2100-12-31T23:59' },
}

export function Input(props: InputHTMLAttributes<HTMLInputElement>) {
  return <input {...DATE_LIMITS[props.type ?? '']} {...props} className={`input ${props.className ?? ''}`} />
}

export function Textarea(props: TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return <textarea rows={3} {...props} className={`input ${props.className ?? ''}`} />
}

export function Select({ options, placeholder, ...props }: SelectHTMLAttributes<HTMLSelectElement> & { options: (Option | string)[]; placeholder?: string }) {
  return (
    <select {...props} className={`input ${props.className ?? ''}`}>
      {placeholder !== undefined && <option value="">{placeholder}</option>}
      {options.map((o) => typeof o === 'string'
        ? <option key={o} value={o}>{o}</option>
        : <option key={o.value} value={o.value}>{o.label}</option>)}
    </select>
  )
}

export function Card({ title, actions, children, className = '', bodyClass = 'p-4' }: { title?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string; bodyClass?: string }) {
  return (
    <section className={`card ${className}`}>
      {(title || actions) && (
        <header className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
          <h2 className="text-sm font-semibold text-slate-800">{title}</h2>
          <div className="flex items-center gap-2">{actions}</div>
        </header>
      )}
      <div className={bodyClass}>{children}</div>
    </section>
  )
}

export function Spinner({ small }: { small?: boolean }) {
  return <span className={`inline-block animate-spin rounded-full border-2 border-current border-r-transparent ${small ? 'h-3.5 w-3.5' : 'h-6 w-6 text-brand-500'}`} role="status" aria-label="Loading" />
}

export function Loading() {
  return <div className="flex justify-center p-10"><Spinner /></div>
}

export function ErrorBox({ message }: { message: string }) {
  return <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{message}</div>
}

export function SuccessBox({ message }: { message: string }) {
  return <div className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{message}</div>
}

export function Empty({ children }: { children: ReactNode }) {
  return <div className="px-4 py-8 text-center text-sm text-slate-500">{children}</div>
}

export function PageHeader({ title, subtitle, actions }: { title: ReactNode; subtitle?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 className="break-words text-lg font-semibold text-slate-900 sm:text-xl">{title}</h1>
        {subtitle && <div className="mt-0.5 text-sm text-slate-500">{subtitle}</div>}
      </div>
      {actions && <div className="no-print flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  )
}

/**
 * Dialog rendered in a portal on <body>, so a form inside it is never nested inside a
 * page form (nested forms break submit buttons). Submit events from the dialog are also
 * stopped from bubbling through React to the page behind it.
 */
export function Modal({ open, title, onClose, children, wide }: { open: boolean; title: string; onClose: () => void; children: ReactNode; wide?: boolean }) {
  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    // Stop the page behind the dialog from scrolling (important on phones).
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      window.removeEventListener('keydown', onKey)
      document.body.style.overflow = previousOverflow
    }
  }, [open, onClose])
  if (!open) return null
  return createPortal(
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/60 p-3 backdrop-blur-sm sm:p-10"
      onMouseDown={(e) => { if (e.target === e.currentTarget) onClose() }}
      onSubmit={(e) => e.stopPropagation()}
      onClick={(e) => e.stopPropagation()}
    >
      <div role="dialog" aria-modal="true" aria-label={title} className={`card my-auto w-full ${wide ? 'max-w-3xl' : 'max-w-lg'}`}>
        <header className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
          <h2 className="font-semibold">{title}</h2>
          <button type="button" onClick={onClose} className="rounded p-1 text-slate-500 hover:bg-slate-100" aria-label="Close"><X className="h-4 w-4" aria-hidden /></button>
        </header>
        <div className="p-4">{children}</div>
      </div>
    </div>,
    document.body,
  )
}

export function Pagination({ meta, onPage }: { meta?: { current_page: number; last_page: number; total: number; from: number | null; to: number | null }; onPage: (p: number) => void }) {
  if (!meta || meta.total === 0) return null
  return (
    <div className="no-print flex items-center justify-between border-t border-slate-200 px-4 py-2 text-sm text-slate-600">
      <span>{meta.from}–{meta.to} of {meta.total}</span>
      <div className="flex gap-2">
        <Button size="sm" variant="secondary" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>Previous</Button>
        <Button size="sm" variant="secondary" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>Next</Button>
      </div>
    </div>
  )
}

export function Tabs({ tabs, active, onChange }: { tabs: { key: string; label: ReactNode }[]; active: string; onChange: (k: string) => void }) {
  return (
    <div className="no-print mb-4 flex gap-1 overflow-x-auto border-b border-slate-200" role="tablist">
      {tabs.map((t) => (
        <button
          key={t.key}
          type="button"
          role="tab"
          aria-selected={active === t.key}
          onClick={() => onChange(t.key)}
          className={`whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium ${active === t.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800'}`}
        >
          {t.label}
        </button>
      ))}
    </div>
  )
}

export function DefinitionList({ items }: { items: [string, ReactNode][] }) {
  return (
    <dl className="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
      {items.map(([k, v]) => (
        <div key={k}>
          <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{k}</dt>
          <dd className="mt-0.5 text-sm text-slate-800">{v ?? '—'}</dd>
        </div>
      ))}
    </dl>
  )
}

export function Stat({ label, value, hint, tone = 'default' }: { label: string; value: ReactNode; hint?: ReactNode; tone?: 'default' | 'warn' | 'danger' | 'good' }) {
  const tones = { default: 'text-slate-900', warn: 'text-amber-700', danger: 'text-red-700', good: 'text-emerald-700' }
  return (
    <div className="card p-4">
      <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
      <div className={`mt-1 break-words text-xl font-semibold sm:text-2xl ${tones[tone]}`}>{value}</div>
      {hint && <div className="mt-1 text-xs text-slate-500">{hint}</div>}
    </div>
  )
}

/** Inline warning text with an alert icon (overdue items, open issues). */
export function Warn({ children, className = '' }: { children: ReactNode; className?: string }) {
  return (
    <span className={`inline-flex items-center gap-1 ${className}`}>
      <TriangleAlert className="h-3.5 w-3.5 shrink-0" aria-hidden />
      {children}
    </span>
  )
}

/** Airport pair such as CMB → DOH, with an arrow icon. */
export function RouteCodes({ from, to }: { from: string; to: string }) {
  return (
    <span className="inline-flex items-center gap-1 whitespace-nowrap font-mono">
      {from}
      <ArrowRight className="h-3.5 w-3.5 text-slate-400" aria-hidden />
      <span className="sr-only">to</span>
      {to}
    </span>
  )
}
