import { useEffect, useId, useMemo, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ChevronDown, Copy, FileDown, Plus, Save, Trash2 } from 'lucide-react'
import { useAuth } from '../auth/AuthContext'
import { useMeta, useQuotation } from '../hooks/queries'
import { useSaveQuotation } from '../hooks/mutations'
import { downloadFile, errorMessage, fieldErrors, type ValidationErrors } from '../lib/api'
import { AIRPORTS, airportTimezone } from '../lib/airports'
import type { Quotation, QuotationOption, QuotationSegment } from '../lib/types'
import { Button, Card, ErrorBox, Field, Input, Loading, PageHeader, Select, Textarea } from '../components/ui'
import CustomerPicker from '../components/CustomerPicker'
import AirportInput from '../components/AirportInput'
import AirlinePicker from '../components/AirlinePicker'

/*
 * One simple page: trip → flights (one line per flight) → price, baggage, refund → Create PDF.
 * Everything optional (customer, conditions, notes, timezones…) is folded away under "More".
 * Form state uses strings ('' = not entered); the payload turns '' into null.
 */

type Seg = Record<keyof Omit<QuotationSegment, 'id' | 'segment_order' | 'duration' | 'layover' | 'airline_id' | 'operating_airline_id' | 'direction'>, string> & {
  key: string; direction: 'outbound' | 'return'; airline_id: number | null; operating_airline_id: number | null; more: boolean; durText: string
}
type Extra = { title: string; amount: string; details: string }
type Opt = Record<Exclude<keyof QuotationOption, 'id' | 'segments' | 'extras' | 'pricing' | 'legs'>, string> & { key: string; segments: Seg[]; extras: Extra[]; more: boolean }
type Head = Record<string, string>
type Picked = { id: number; full_name: string; phone: string | null; email?: string | null }

let seq = 0
const key = () => `k${++seq}`
const s = (v: unknown) => (v === null || v === undefined ? '' : String(v))
const nil = (v: string | undefined) => (!v || v.trim() === '' ? null : v.trim())

const SEG_FIELDS = ['airline_name', 'operating_airline_name', 'flight_number', 'from_code', 'from_name', 'to_code', 'to_name', 'departure_at', 'arrival_at',
  'departure_tz', 'arrival_tz', 'duration_minutes', 'departure_terminal', 'arrival_terminal', 'aircraft', 'cabin_class', 'baggage_note', 'notes'] as const
const OPT_FIELDS = ['label', 'fare_family', 'cabin_class', 'currency', 'adult_fare', 'adult_tax', 'child_fare', 'child_tax', 'infant_fare', 'infant_tax', 'other_charges',
  'supplier_cost', 'checked_bag_status', 'checked_bag_allowance', 'cabin_bag_status', 'cabin_bag_allowance', 'personal_item_status', 'personal_item_allowance',
  'baggage_notes', 'refundability', 'change_permitted', 'change_fee', 'fare_difference', 'cancellation_conditions', 'no_show_conditions', 'other_conditions',
  'price_valid_until', 'notes', 'internal_notes'] as const
const HEAD_FIELDS = ['trip_type', 'origin_country', 'origin_city', 'origin_code', 'destination_country', 'destination_city', 'destination_code', 'outbound_date',
  'return_date', 'adults', 'children', 'infants', 'cabin_class', 'currency', 'valid_until', 'requested_airline', 'preferred_departure_time', 'max_stops',
  'requested_baggage', 'customer_requirements', 'message_to_customer', 'internal_notes'] as const

const TIMEZONES: string[] = (() => {
  try { return (Intl as unknown as { supportedValuesOf: (k: string) => string[] }).supportedValuesOf('timeZone') } catch { return [] }
})()

/** "1:30", "1h30", "1h 30m", "90" → minutes. */
function parseDuration(text: string): number | null {
  const t = text.trim().toLowerCase()
  if (!t) return null
  const hm = t.match(/^(\d{1,2})\s*(?:h|:|\.)\s*(\d{1,2})?\s*m?$/)
  if (hm) return Number(hm[1]) * 60 + Number(hm[2] ?? 0)
  return /^\d+$/.test(t) ? Number(t) : null
}
const durationText = (minutes: string) => (minutes ? `${Math.floor(Number(minutes) / 60)}:${String(Number(minutes) % 60).padStart(2, '0')}` : '')

function dateTimeInTimezone(value: string, timeZone: string): number | null {
  const match = value.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/)
  if (!match) return null

  const [year, month, day, hour, minute] = match.slice(1).map(Number)
  const localTime = Date.UTC(year, month - 1, day, hour, minute)
  const date = new Date(localTime)
  if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day || date.getUTCHours() !== hour || date.getUTCMinutes() !== minute) return null

  try {
    const formatter = new Intl.DateTimeFormat('en-GB', {
      timeZone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
    })
    let timestamp = localTime
    for (let attempt = 0; attempt < 4; attempt++) {
      const parts = Object.fromEntries(formatter.formatToParts(new Date(timestamp)).map((part) => [part.type, part.value]))
      const representedTime = Date.UTC(Number(parts.year), Number(parts.month) - 1, Number(parts.day), Number(parts.hour), Number(parts.minute), Number(parts.second))
      const adjusted = timestamp + localTime - representedTime
      if (adjusted === timestamp) break
      timestamp = adjusted
    }

    const resolved = Object.fromEntries(formatter.formatToParts(new Date(timestamp)).map((part) => [part.type, part.value]))
    if (Number(resolved.year) !== year || Number(resolved.month) !== month || Number(resolved.day) !== day || Number(resolved.hour) !== hour || Number(resolved.minute) !== minute) return null

    return timestamp
  } catch {
    return null
  }
}

function calculatedDuration(seg: Seg): string | null {
  if (!seg.departure_at || !seg.arrival_at || !seg.departure_tz || !seg.arrival_tz) return null
  const departure = dateTimeInTimezone(seg.departure_at, seg.departure_tz)
  const arrival = dateTimeInTimezone(seg.arrival_at, seg.arrival_tz)
  if (departure === null || arrival === null || arrival <= departure) return null

  const minutes = Math.round((arrival - departure) / 60000)
  return `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`
}

function newSegment(direction: 'outbound' | 'return', prefill: Partial<Seg> = {}): Seg {
  return {
    key: key(), direction, airline_id: null, operating_airline_id: null, more: false, durText: '',
    ...Object.fromEntries(SEG_FIELDS.map((f) => [f, ''])) as Record<(typeof SEG_FIELDS)[number], string>,
    departure_tz: airportTimezone(prefill.from_code) ?? '', arrival_tz: airportTimezone(prefill.to_code) ?? '',
    ...prefill,
  }
}

function newOption(label: string, currency: string, head?: Head): Opt {
  const opt: Opt = {
    key: key(), more: false, segments: [], extras: [],
    ...Object.fromEntries(OPT_FIELDS.map((f) => [f, ''])) as Record<(typeof OPT_FIELDS)[number], string>,
    label, currency, checked_bag_status: 'unknown', cabin_bag_status: 'unknown', personal_item_status: 'unknown',
    refundability: 'unknown', change_permitted: 'unknown', fare_difference: 'unknown',
  }
  // Start with one outbound (and one return) flight, filled from the trip.
  if (head) {
    opt.segments.push(newSegment('outbound', { from_code: head.origin_code ?? '', to_code: head.destination_code ?? '', departure_at: head.outbound_date ? `${head.outbound_date}T` : '' }))
    if (head.trip_type === 'round_trip') {
      opt.segments.push(newSegment('return', { from_code: head.destination_code ?? '', to_code: head.origin_code ?? '', departure_at: head.return_date ? `${head.return_date}T` : '' }))
    }
  }
  return opt
}

function fromQuotation(q: Quotation) {
  const head: Head = Object.fromEntries(HEAD_FIELDS.map((f) => [f, s(q[f as keyof Quotation])]))
  const options: Opt[] = q.options.map((o) => ({
    ...newOption(o.label, o.currency),
    ...Object.fromEntries(OPT_FIELDS.map((f) => [f, s(o[f as keyof QuotationOption])])),
    extras: (Array.isArray(o.extras) ? o.extras : []).map((e) => ({ title: s(e.title), amount: s(e.amount), details: s(e.details) })),
    segments: o.segments.map((sg) => ({
      ...newSegment(sg.direction), ...Object.fromEntries(SEG_FIELDS.map((f) => [f, s(sg[f as keyof QuotationSegment])])),
      departure_tz: s(sg.departure_tz) || airportTimezone(sg.from_code) || '',
      arrival_tz: s(sg.arrival_tz) || airportTimezone(sg.to_code) || '',
      airline_id: sg.airline_id, operating_airline_id: sg.operating_airline_id, durText: durationText(s(sg.duration_minutes)),
    })),
  }))
  return { head, options }
}

function toPayload(head: Head, customerId: number | undefined, options: Opt[]) {
  const num = (v: string | undefined) => (!v || v.trim() === '' ? null : Number(v))
  return {
    ...Object.fromEntries(HEAD_FIELDS.map((f) => [f, nil(head[f])])),
    customer_id: customerId ?? null,
    adults: num(head.adults) ?? 1, children: num(head.children) ?? 0, infants: num(head.infants) ?? 0, max_stops: num(head.max_stops),
    return_date: head.trip_type === 'round_trip' ? nil(head.return_date) : null,
    options: options.map((o) => ({
      ...Object.fromEntries(OPT_FIELDS.map((f) => [f, nil(o[f])])),
      extras: o.extras.filter((e) => e.title.trim()).map((e) => ({ title: e.title.trim(), amount: nil(e.amount), details: nil(e.details) })),
      segments: o.segments.filter((sg) => head.trip_type === 'round_trip' || sg.direction === 'outbound').map((sg) => ({
        direction: sg.direction, airline_id: sg.airline_id, operating_airline_id: sg.operating_airline_id,
        ...Object.fromEntries(SEG_FIELDS.map((f) => [f, nil(sg[f])])),
        duration_minutes: parseDuration(sg.durText),
      })),
    })),
  }
}

/** Indicative total in the browser; the server recalculates and is authoritative. */
function estimate(o: Opt, head: Head) {
  const n = (v: string | undefined) => (!v || v.trim() === '' ? null : Number(v))
  const other = n(o.other_charges) ?? 0
  const rows = (['adult', 'child', 'infant'] as const).map((t) => {
    const count = Number(head[t === 'adult' ? 'adults' : t === 'child' ? 'children' : 'infants'] || 0)
    const fare = n(o[`${t}_fare`])
    return { t, count, per: fare === null ? null : fare + (n(o[`${t}_tax`]) ?? 0) + other }
  }).filter((r) => r.count > 0)
  const complete = rows.every((r) => r.per !== null)
  return { rows, total: complete ? rows.reduce((sum, r) => sum + (r.per ?? 0) * r.count, 0) : null }
}

const fmt = (n: number | null, cur: string) => (n === null ? '—' : `${n.toLocaleString(undefined, { maximumFractionDigits: 2 })} ${cur}`)

/** Collapsible section that keeps optional fields out of the way. */
function More({ title, open, onToggle, children }: { title: string; open: boolean; onToggle: () => void; children: React.ReactNode }) {
  return (
    <div className="rounded-md border border-slate-200">
      <button type="button" onClick={onToggle} className="flex w-full items-center justify-between px-3 py-2 text-left text-sm font-medium text-slate-600 hover:text-slate-900" aria-expanded={open}>
        {title}<ChevronDown className={`h-4 w-4 transition ${open ? 'rotate-180' : ''}`} aria-hidden />
      </button>
      {open && <div className="border-t border-slate-200 p-3">{children}</div>}
    </div>
  )
}

export default function QuotationFormPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { can } = useAuth()
  const { data: meta } = useMeta()
  const { data: existing, isLoading, error: loadError } = useQuotation(id)
  const save = useSaveQuotation()
  const [customer, setCustomer] = useState<Picked | null>(null)
  const [head, setHead] = useState<Head>({ trip_type: 'round_trip', adults: '1', children: '0', infants: '0', cabin_class: 'economy', currency: '' })
  const [options, setOptions] = useState<Opt[]>([])
  const [saved, setSaved] = useState('')
  const [moreTrip, setMoreTrip] = useState(false)
  const [problems, setProblems] = useState<string[]>([])
  const [creating, setCreating] = useState(false)
  const loaded = useRef(false)
  const errors: ValidationErrors = fieldErrors(save.error)
  const err = (path: string) => errors[path]

  useEffect(() => {
    if (existing && !loaded.current) {
      loaded.current = true
      const f = fromQuotation(existing)
      setHead(f.head)
      setOptions(f.options)
      if (existing.customer) setCustomer(existing.customer)
      setSaved(JSON.stringify(toPayload(f.head, existing.customer_id ?? undefined, f.options)))
    }
  }, [existing])
  useEffect(() => { if (!id && meta && !head.currency) setHead((h) => ({ ...h, currency: meta.default_currency })) }, [meta, id, head.currency])
  // New quotation: the flights section appears by itself once the trip is filled in.
  const tripReady = Boolean(head.origin_city && head.destination_city && head.outbound_date && (head.trip_type !== 'round_trip' || head.return_date))
  useEffect(() => {
    if (!id && tripReady && options.length === 0 && head.currency) setOptions([newOption('Option A', head.currency, head)])
  }, [id, tripReady, options.length, head])

  const payload = useMemo(() => toPayload(head, customer?.id, options), [head, customer, options])
  const dirty = JSON.stringify(payload) !== saved && (Boolean(id) || Boolean(customer) || options.length > 0 || Boolean(head.origin_city))

  useEffect(() => {
    const warn = (e: BeforeUnloadEvent) => { if (dirty) { e.preventDefault(); e.returnValue = '' } }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty])

  if (id && isLoading) return <Loading />
  if (loadError) return <ErrorBox message={errorMessage(loadError)} />
  if (existing && !existing.meta.editable) {
    return <Card bodyClass="p-6 text-sm">This quotation is {existing.status_label.toLowerCase()} and can no longer be edited. <Link className="link" to={`/quotations/${existing.id}`}>Back to the quotation</Link> (use Duplicate to make a new one).</Card>
  }

  const setH = (k: string, v: string) => {
    setHead((h) => ({ ...h, [k]: v }))
    // Prices are entered in the trip currency, so every option follows it.
    if (k === 'currency') setOptions((all) => all.map((o) => ({ ...o, currency: v })))
  }
  const updateOption = (i: number, patch: Partial<Opt>) => setOptions((all) => all.map((o, j) => (j === i ? { ...o, ...patch } : o)))
  const isReturn = head.trip_type === 'round_trip'
  const addOption = () => setOptions((all) => [...all, newOption(`Option ${String.fromCharCode(65 + all.length)}`, head.currency || meta?.default_currency || '', head)])

  const pickPlace = (side: 'origin' | 'destination', code: string) => {
    const a = AIRPORTS.find((x) => x.code === code)
    setHead((h) => ({ ...h, [`${side}_code`]: a ? a.code : '', [`${side}_city`]: a ? a.city : code, [`${side}_country`]: a ? a.country : h[`${side}_country`] ?? '' }))
  }

  const doSave = (after?: (q: Quotation) => void) => save.mutate({ id: existing?.id ?? (id ? Number(id) : undefined), payload }, {
    onSuccess: (q) => {
      setSaved(JSON.stringify(payload))
      if (!id) { loaded.current = true; navigate(`/quotations/${q.id}/edit`, { replace: true }) }
      after?.(q)
    },
  })

  /** Save, then download the PDF and open the quotation page. */
  const createPdf = () => {
    setProblems([])
    doSave(async (q) => {
      if (q.meta.problems.length) { setProblems(q.meta.problems); return }
      setCreating(true)
      try {
        await downloadFile(`/flight-quotations/${q.id}/pdf`, `Flight-Quotation-${q.reference}.pdf`)
        navigate(`/quotations/${q.id}`)
      } catch (e) {
        setProblems([errorMessage(e)])
      } finally {
        setCreating(false)
      }
    })
  }

  return (
    <div className="pb-24">
      <PageHeader
        title={existing ? `Edit ${existing.reference}` : 'New flight quotation'}
        subtitle="Enter the trip, the flights and the price, then Create PDF."
        actions={<Link to={existing ? `/quotations/${existing.id}` : '/quotations'} className="btn-secondary" onClick={(e) => { if (dirty && !confirm('Discard unsaved changes?')) e.preventDefault() }}>Cancel</Link>}
      />

      {save.isError && <div className="mb-4"><ErrorBox message={Object.keys(errors).length ? 'Please correct the fields marked in red.' : errorMessage(save.error)} /></div>}
      {problems.length > 0 && (
        <div className="mb-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" role="alert">
          <p className="font-semibold">Saved. To create the PDF, please complete:</p>
          <ul className="mt-1 list-disc pl-5">{problems.map((p) => <li key={p}>{p}</li>)}</ul>
        </div>
      )}

      <div className="space-y-4">
        {/* 1. Trip */}
        <Card title="1. Trip">
          <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(11rem,1fr))]">
            <Field label="From *" error={err('origin_city')}><AirportInput value={head.origin_code || head.origin_city || ''} onChange={(v) => pickPlace('origin', v)} placeholder="City or code" /></Field>
            <Field label="To *" error={err('destination_city')}><AirportInput value={head.destination_code || head.destination_city || ''} onChange={(v) => pickPlace('destination', v)} placeholder="City or code" /></Field>
            <Field label="Trip"><Select value={head.trip_type} onChange={(e) => setH('trip_type', e.target.value)} options={[{ value: 'round_trip', label: 'Return' }, { value: 'one_way', label: 'One-way' }]} /></Field>
            <Field label="Departure date *" error={err('outbound_date')}><Input type="date" value={head.outbound_date ?? ''} onChange={(e) => setH('outbound_date', e.target.value)} /></Field>
            {isReturn && <Field label="Return date *" error={err('return_date')}><Input type="date" min={head.outbound_date} value={head.return_date ?? ''} onChange={(e) => setH('return_date', e.target.value)} /></Field>}
          </div>
          <div className="mt-3 grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(8rem,1fr))]">
            <Field label="Adults" error={err('adults')}><Input type="number" min={1} max={9} value={head.adults} onChange={(e) => setH('adults', e.target.value)} /></Field>
            <Field label="Children" error={err('children')}><Input type="number" min={0} max={9} value={head.children} onChange={(e) => setH('children', e.target.value)} /></Field>
            <Field label="Infants" error={err('infants')}><Input type="number" min={0} max={9} value={head.infants} onChange={(e) => setH('infants', e.target.value)} /></Field>
            <Field label="Cabin"><Select value={head.cabin_class} onChange={(e) => setH('cabin_class', e.target.value)} options={meta?.cabin_classes ?? []} /></Field>
            <Field label="Currency" error={err('currency')}><Select value={head.currency} onChange={(e) => setH('currency', e.target.value)} options={meta?.currencies ?? []} /></Field>
          </div>
          <div className="mt-3">
            <More title="More options (customer, message, validity, notes)" open={moreTrip} onToggle={() => setMoreTrip((v) => !v)}>
              <div className="grid gap-3 md:grid-cols-2">
                <Field label="Customer (optional)" hint="Only to fill in their email when sending"><CustomerPicker value={customer} onChange={setCustomer} /></Field>
                <Field label="Valid until" error={err('valid_until')}><Input type="datetime-local" value={head.valid_until ?? ''} onChange={(e) => setH('valid_until', e.target.value)} /></Field>
                <Field label="Message to the customer" hint="Printed on the PDF"><Textarea rows={2} value={head.message_to_customer ?? ''} onChange={(e) => setH('message_to_customer', e.target.value)} /></Field>
                {can('view-costs') && <Field label="Internal notes" hint="Never on the PDF"><Textarea rows={2} value={head.internal_notes ?? ''} onChange={(e) => setH('internal_notes', e.target.value)} /></Field>}
                <div className="grid grid-cols-2 gap-3 md:col-span-2">
                  <Field label="From: city / country"><div className="flex gap-2"><Input value={head.origin_city ?? ''} onChange={(e) => setH('origin_city', e.target.value)} aria-label="Origin city" /><Input value={head.origin_country ?? ''} onChange={(e) => setH('origin_country', e.target.value)} aria-label="Origin country" /></div></Field>
                  <Field label="To: city / country"><div className="flex gap-2"><Input value={head.destination_city ?? ''} onChange={(e) => setH('destination_city', e.target.value)} aria-label="Destination city" /><Input value={head.destination_country ?? ''} onChange={(e) => setH('destination_country', e.target.value)} aria-label="Destination country" /></div></Field>
                </div>
              </div>
            </More>
          </div>
        </Card>

        {/* 2. Flight options */}
        {options.map((o, i) => (
          <OptionEditor key={o.key} index={i} option={o} head={head} isReturn={isReturn} errors={errors} single={options.length === 1}
            onChange={(patch) => updateOption(i, patch)}
            onRemove={() => confirm(`Remove ${o.label}?`) && setOptions((all) => all.filter((_, j) => j !== i))}
            onDuplicate={() => setOptions((all) => [...all.slice(0, i + 1), { ...o, key: key(), label: `Option ${String.fromCharCode(65 + all.length)}`, segments: o.segments.map((sg) => ({ ...sg, key: key() })), extras: o.extras.map((e) => ({ ...e })) }, ...all.slice(i + 1)])} />
        ))}
        {options.length === 0 ? (
          <p className="rounded-md border border-dashed border-slate-300 p-4 text-sm text-slate-500">Fill in From, To and the date(s); the flights section then appears here.</p>
        ) : (
          <Button variant="secondary" onClick={addOption} disabled={options.length >= 10}><Plus className="h-4 w-4" aria-hidden /> Add another option</Button>
        )}
      </div>

      {/* Action bar */}
      <div className="no-print fixed inset-x-0 bottom-0 z-20 border-t border-slate-200 bg-surface/95 px-4 py-3 backdrop-blur lg:left-60 3xl:left-72">
        <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-end gap-2">
          {dirty ? <span className="mr-auto text-xs text-amber-700">Unsaved changes</span> : saved && <span className="mr-auto text-xs text-emerald-700">Saved</span>}
          <Button variant="secondary" loading={save.isPending && !creating} onClick={() => doSave()}><Save className="h-4 w-4" aria-hidden /> Save</Button>
          <Button onClick={createPdf} loading={creating || save.isPending} disabled={options.length === 0}><FileDown className="h-4 w-4" aria-hidden /> Create PDF</Button>
        </div>
      </div>
    </div>
  )
}

// ---------------------------------------------------------------- one option: flights, price, baggage

function OptionEditor({ option: o, index, head, isReturn, errors, single, onChange, onRemove, onDuplicate }: {
  option: Opt; index: number; head: Head; isReturn: boolean; errors: ValidationErrors; single: boolean
  onChange: (patch: Partial<Opt>) => void; onRemove: () => void; onDuplicate: () => void
}) {
  const { can } = useAuth()
  const { data: meta } = useMeta()
  const p = `options.${index}`
  const err = (f: string) => errors[`${p}.${f}`]
  const set = (f: keyof Opt, v: string) => onChange({ [f]: v } as Partial<Opt>)
  const est = estimate(o, head)
  const cur = o.currency || head.currency

  const setSegments = (segments: Seg[]) => onChange({ segments })
  const addSegment = (direction: 'outbound' | 'return') => {
    const same = o.segments.filter((sg) => sg.direction === direction)
    const last = same[same.length - 1]
    const day = last ? (last.arrival_at || last.departure_at).slice(0, 10) : direction === 'outbound' ? head.outbound_date : head.return_date
    setSegments([...o.segments, newSegment(direction, {
      from_code: last?.to_code ?? (direction === 'outbound' ? head.origin_code : head.destination_code) ?? '',
      to_code: last ? '' : (direction === 'outbound' ? head.destination_code : head.origin_code) ?? '',
      departure_at: day ? `${day}T` : '',
    })])
  }

  return (
    <Card
      title={single ? '2. Flights & price' : <Input className="h-8 w-48 font-semibold" value={o.label} onChange={(e) => set('label', e.target.value)} aria-label="Option name" />}
      actions={
        <span className="flex gap-1">
          <Button size="sm" variant="ghost" onClick={onDuplicate} title="Add a similar option"><Copy className="h-4 w-4" aria-hidden /> Copy</Button>
          {!single && <Button size="sm" variant="ghost" className="text-red-600" onClick={onRemove}><Trash2 className="h-4 w-4" aria-hidden /></Button>}
        </span>
      }
    >
      {/* Flights: one line each */}
      {(isReturn ? (['outbound', 'return'] as const) : (['outbound'] as const)).map((direction) => (
        <div key={direction} className="mb-3">
          <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{direction === 'outbound' ? 'Outbound' : 'Return'}</p>
          <div className="space-y-2">
            {o.segments.map((sg, si) => sg.direction !== direction ? null : (
              <SegmentRow key={sg.key} seg={sg} number={o.segments.slice(0, si + 1).filter((x) => x.direction === direction).length} path={`${p}.segments.${si}`} errors={errors}
                onChange={(patch) => setSegments(o.segments.map((x, j) => (j === si ? { ...x, ...patch } : x)))}
                onRemove={() => setSegments(o.segments.filter((_, j) => j !== si))} />
            ))}
          </div>
          <button type="button" className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline" onClick={() => addSegment(direction)}>
            <Plus className="h-3.5 w-3.5" aria-hidden /> Add connecting flight
          </button>
        </div>
      ))}

      {/* Price, baggage, refund */}
      <div className="grid grid-cols-2 gap-3 rounded-md bg-slate-50 p-3 sm:grid-cols-4">
        {est.rows.map((r) => (
          <div key={r.t} className="col-span-2 grid grid-cols-2 gap-3">
            <Field label={`${r.t === 'adult' ? 'Fare' : r.t === 'child' ? 'Child fare' : 'Infant fare'} per person *`} error={err(`${r.t}_fare`)}>
              <Input type="number" min={0} step="0.01" value={o[`${r.t}_fare`]} onChange={(e) => set(`${r.t}_fare`, e.target.value)} placeholder={cur} />
            </Field>
            <Field label={`${r.t === 'adult' ? 'Taxes' : r.t === 'child' ? 'Child taxes' : 'Infant taxes'} per person`} error={err(`${r.t}_tax`)}>
              <Input type="number" min={0} step="0.01" value={o[`${r.t}_tax`]} onChange={(e) => set(`${r.t}_tax`, e.target.value)} placeholder={cur} />
            </Field>
          </div>
        ))}
        <div className="col-span-2 flex flex-wrap items-baseline justify-between gap-2 border-t border-slate-200 pt-2 text-sm sm:col-span-4">
          <span className="text-slate-500">Total{est.rows.length > 1 || est.rows[0]?.count > 1 ? ' for all passengers' : ' per person'}</span>
          <strong className="text-lg">{fmt(est.total, cur)}</strong>
        </div>
      </div>

      <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {([['checked_bag', 'Checked bag', '23 kg'], ['cabin_bag', 'Cabin bag', '8 kg'], ['personal_item', 'Personal item', '1 bag']] as const).map(([f, label, ph]) => (
          <Field key={f} label={label}>
            <div className="flex min-w-0 gap-1">
              <Select className="min-w-0 flex-1" value={o[`${f}_status`]} onChange={(e) => set(`${f}_status`, e.target.value)} options={meta?.baggage_statuses ?? []} aria-label={label} />
              {o[`${f}_status`] === 'included' && <Input className="w-24 shrink-0" value={o[`${f}_allowance`]} onChange={(e) => set(`${f}_allowance`, e.target.value)} placeholder={ph} aria-label={`${label} allowance`} />}
            </div>
          </Field>
        ))}
        <Field label="Refund"><Select value={o.refundability} onChange={(e) => set('refundability', e.target.value)} options={meta?.refundabilities ?? []} /></Field>
      </div>

      <div className="mt-3">
        <More title="More (date changes, extras, other charges, notes)" open={o.more} onToggle={() => onChange({ more: !o.more })}>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <Field label="Date changes"><Select value={o.change_permitted} onChange={(e) => set('change_permitted', e.target.value)} options={(meta?.yes_no_unknown ?? []).map((x) => ({ ...x, label: x.value === 'yes' ? 'Permitted' : x.value === 'no' ? 'Not permitted' : x.label }))} /></Field>
            {o.change_permitted === 'yes' && <Field label="Change fee per person"><Input type="number" min={0} step="0.01" value={o.change_fee} onChange={(e) => set('change_fee', e.target.value)} /></Field>}
            {o.change_permitted === 'yes' && <Field label="Fare difference applies"><Select value={o.fare_difference} onChange={(e) => set('fare_difference', e.target.value)} options={meta?.yes_no_unknown ?? []} /></Field>}
            <Field label="Other charges per person" error={err('other_charges')}><Input type="number" min={0} step="0.01" value={o.other_charges} onChange={(e) => set('other_charges', e.target.value)} /></Field>
            <Field label="Fare type"><Input value={o.fare_family} onChange={(e) => set('fare_family', e.target.value)} placeholder="e.g. Economy Light" /></Field>
            {can('view-costs') && <Field label="Our cost, whole party (internal)"><Input type="number" min={0} step="0.01" value={o.supplier_cost} onChange={(e) => set('supplier_cost', e.target.value)} /></Field>}
          </div>
          <div className="mt-3">
            <div className="mb-1 flex items-center justify-between">
              <span className="label">Optional extras (e.g. Rebookable +300)</span>
              <Button size="sm" variant="ghost" disabled={o.extras.length >= 5} onClick={() => onChange({ extras: [...o.extras, { title: '', amount: '', details: '' }] })}><Plus className="h-4 w-4" aria-hidden /> Add</Button>
            </div>
            {o.extras.map((e, ei) => (
              <div key={ei} className="mb-2 grid grid-cols-[1fr_auto] gap-2 rounded-md border border-slate-200 p-2 md:grid-cols-[1fr_8rem_2fr_auto] md:border-0 md:p-0">
                <Input value={e.title} placeholder="Title" onChange={(ev) => onChange({ extras: o.extras.map((x, j) => (j === ei ? { ...x, title: ev.target.value } : x)) })} aria-label="Extra title" />
                <Input type="number" min={0} step="0.01" value={e.amount} placeholder={`Price ${cur}`} onChange={(ev) => onChange({ extras: o.extras.map((x, j) => (j === ei ? { ...x, amount: ev.target.value } : x)) })} aria-label="Extra price" />
                <Textarea rows={1} value={e.details} placeholder="Details (one per line)" onChange={(ev) => onChange({ extras: o.extras.map((x, j) => (j === ei ? { ...x, details: ev.target.value } : x)) })} aria-label="Extra details" />
                <Button size="sm" variant="ghost" className="text-red-600" onClick={() => onChange({ extras: o.extras.filter((_, j) => j !== ei) })} aria-label="Remove extra"><Trash2 className="h-4 w-4" aria-hidden /></Button>
              </div>
            ))}
          </div>
          <div className="mt-2 grid gap-3 md:grid-cols-2">
            <Field label="Notes on the PDF"><Textarea rows={2} value={o.notes} onChange={(e) => set('notes', e.target.value)} /></Field>
            <Field label="Other conditions on the PDF"><Textarea rows={2} value={o.other_conditions} onChange={(e) => set('other_conditions', e.target.value)} /></Field>
          </div>
        </More>
      </div>
    </Card>
  )
}

// ---------------------------------------------------------------- one flight, one line

function SegmentRow({ seg, number, path, errors, onChange, onRemove }: { seg: Seg; number: number; path: string; errors: ValidationErrors; onChange: (patch: Partial<Seg>) => void; onRemove: () => void }) {
  const err = (f: string) => errors[`${path}.${f}`]?.[0]
  const set = (f: keyof Seg, v: string) => {
    const patch = { [f]: v } as Partial<Seg>
    if (['departure_at', 'arrival_at', 'departure_tz', 'arrival_tz'].includes(f)) {
      patch.durText = calculatedDuration({ ...seg, [f]: v }) ?? ''
    }
    onChange(patch)
  }
  const setAirport = (side: 'from' | 'to', value: string) => {
    const code = /^[A-Za-z]{3}$/.test(value.trim()) ? value.trim().toUpperCase() : value
    const codeField = side === 'from' ? 'from_code' : 'to_code'
    const timezoneField = side === 'from' ? 'departure_tz' : 'arrival_tz'
    const previousTimezone = airportTimezone(seg[codeField])
    const nextTimezone = airportTimezone(code)
    const patch: Partial<Seg> = {
      [codeField]: code,
      [timezoneField]: nextTimezone ?? (previousTimezone === seg[timezoneField] ? '' : seg[timezoneField]),
    }
    patch.durText = calculatedDuration({ ...seg, ...patch }) ?? ''
    onChange(patch)
  }
  const tzList = useId()
  const rowErrors = ['airline_name', 'flight_number', 'from_code', 'to_code', 'departure_at', 'arrival_at', 'duration_minutes'].map(err).filter(Boolean)

  return (
    <div className="rounded-md border border-slate-200 p-3">
      <div className="mb-2 flex items-center justify-between gap-2">
        <span className="text-xs font-semibold text-slate-500">Flight {number}</span>
        <span className="flex items-center gap-1">
          <button type="button" className="inline-flex items-center gap-1 rounded px-1.5 py-1 text-xs font-medium text-brand-700 hover:bg-slate-100" onClick={() => onChange({ more: !seg.more })} aria-expanded={seg.more}>
            More details<ChevronDown className={`h-3.5 w-3.5 transition ${seg.more ? 'rotate-180' : ''}`} aria-hidden />
          </button>
          <Button size="sm" variant="ghost" className="text-red-600" onClick={onRemove} aria-label={`Remove flight ${number}`}><Trash2 className="h-4 w-4" aria-hidden /></Button>
        </span>
      </div>
      {/* Phone: 2 columns. Tablet/laptop: airline+no.+duration, then from/to, then times. Wide: two rows. */}
      <div className="grid grid-cols-2 gap-x-3 gap-y-2 md:grid-cols-12">
        <Field label="Airline" className="col-span-2 min-w-0 md:col-span-6"><AirlinePicker airlineId={seg.airline_id} name={seg.airline_name} invalid={Boolean(err('airline_name'))} onChange={(v) => onChange({ airline_id: v.airline_id, airline_name: v.airline_name ?? '' })} /></Field>
        <Field label="Flight no." className="min-w-0 md:col-span-3"><Input className={`font-mono uppercase ${err('flight_number') ? 'border-red-500' : ''}`} maxLength={8} value={seg.flight_number} onChange={(e) => set('flight_number', e.target.value.toUpperCase())} placeholder="EK651" /></Field>
        <Field label="Duration" hint="Auto-calculated for known airports; editable for corrections." className="min-w-0 md:col-span-3"><Input value={seg.durText} onChange={(e) => onChange({ durText: e.target.value })} placeholder="1:30" title="Flight time, e.g. 1:30" /></Field>
        <Field label="From" className="min-w-0 md:col-span-6 xl:col-span-3"><AirportInput value={seg.from_code} onChange={(v) => setAirport('from', v)} placeholder="CMB" invalid={Boolean(err('from_code'))} /></Field>
        <Field label="To" className="min-w-0 md:col-span-6 xl:col-span-3"><AirportInput value={seg.to_code} onChange={(v) => setAirport('to', v)} placeholder="LHR" invalid={Boolean(err('to_code'))} /></Field>
        <Field label="Departs" className="col-span-2 min-w-0 md:col-span-6 xl:col-span-3"><Input type="datetime-local" className={err('departure_at') ? 'border-red-500' : ''} value={seg.departure_at} onChange={(e) => set('departure_at', e.target.value)} /></Field>
        <Field label="Arrives" className="col-span-2 min-w-0 md:col-span-6 xl:col-span-3"><Input type="datetime-local" className={err('arrival_at') ? 'border-red-500' : ''} value={seg.arrival_at} onChange={(e) => set('arrival_at', e.target.value)} /></Field>
      </div>
      {rowErrors.length > 0 && <p className="mt-1 text-xs text-red-600">{rowErrors.join(' ')}</p>}
      {seg.more && (
        <div className="mt-3 grid grid-cols-1 gap-2 border-t border-slate-100 pt-3 sm:grid-cols-2 lg:grid-cols-4">
          <Field label="Operated by"><Input value={seg.operating_airline_name} onChange={(e) => onChange({ operating_airline_name: e.target.value, operating_airline_id: null })} /></Field>
          <Field label="Aircraft"><Input value={seg.aircraft} onChange={(e) => set('aircraft', e.target.value)} placeholder="A350" /></Field>
          <Field label="Departure terminal"><Input value={seg.departure_terminal} onChange={(e) => set('departure_terminal', e.target.value)} /></Field>
          <Field label="Arrival terminal"><Input value={seg.arrival_terminal} onChange={(e) => set('arrival_terminal', e.target.value)} /></Field>
          <Field label="Departure timezone" hint="Auto-filled for known airports."><Input list={tzList} value={seg.departure_tz} onChange={(e) => set('departure_tz', e.target.value)} placeholder="Asia/Colombo" /></Field>
          <Field label="Arrival timezone" hint="Auto-filled for known airports."><Input list={tzList} value={seg.arrival_tz} onChange={(e) => set('arrival_tz', e.target.value)} placeholder="Europe/London" /></Field>
          <Field label="Baggage (this flight)"><Input value={seg.baggage_note} onChange={(e) => set('baggage_note', e.target.value)} /></Field>
          <Field label="Note"><Input value={seg.notes} onChange={(e) => set('notes', e.target.value)} /></Field>
          <datalist id={tzList}>{TIMEZONES.map((z) => <option key={z} value={z} />)}</datalist>
        </div>
      )}
    </div>
  )
}
