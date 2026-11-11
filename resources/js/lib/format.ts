import type { Option } from './types'

export function money(amount: string | number | null | undefined, currency?: string) {
  if (amount === null || amount === undefined || amount === '') return '—'
  const n = typeof amount === 'number' ? amount : Number(amount)
  const formatted = n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  return currency ? `${currency} ${formatted}` : formatted
}

export function date(value: string | null | undefined) {
  if (!value) return '—'
  const d = new Date(value.length === 10 ? `${value}T00:00:00` : value)
  return d.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' })
}

export function dateTime(value: string | null | undefined) {
  if (!value) return '—'
  return new Date(value).toLocaleString(undefined, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

/** Flight times are local airport times without a timezone; show them as entered. */
export function localTime(value: string | null | undefined) {
  if (!value) return '—'
  const [d, t] = value.split('T')
  return `${date(d)} ${t ?? ''}`.trim()
}

export function labelFor(options: Option[] | undefined, value: string | null | undefined) {
  if (!value) return '—'
  return options?.find((o) => o.value === value)?.label ?? value.replace(/_/g, ' ')
}

export function fileSize(bytes: number) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

export function today() {
  const d = new Date()
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
}

export function nowLocalInput() {
  const d = new Date()
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
}

/** Converts an ISO timestamp to a value for <input type="datetime-local">. */
export function toLocalInput(iso: string | null | undefined) {
  if (!iso) return ''
  const d = new Date(iso)
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
}
