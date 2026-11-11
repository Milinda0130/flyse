import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Minus, Plus } from 'lucide-react'
import { errorMessage, fieldErrors, type ValidationErrors } from '../lib/api'
import { useSaveBooking } from '../hooks/mutations'
import { useAuth } from '../auth/AuthContext'
import { useBooking, useMeta, useSuppliers } from '../hooks/queries'
import { money } from '../lib/format'
import { Button, ErrorBox, Field, Input, Loading, PageHeader, Select, Textarea } from '../components/ui'
import CustomerPicker from '../components/CustomerPicker'
import AirportInput from '../components/AirportInput'

type Picked = { id: number; full_name: string; phone: string | null; email?: string | null }

type Trip = { trip_type: string; origin: string; destination: string; departure_date: string; return_date: string; cabin_class: string; passenger_count: number; notes: string }
type Reservation = { supplier_id: string; pnr: string; ticketing_deadline: string; payment_due_date: string; next_action: string; currency: string; selling_total: string; service_fee: string; supplier_total: string }

const emptyTrip: Trip = { trip_type: 'one_way', origin: 'CMB', destination: '', departure_date: '', return_date: '', cabin_class: 'economy', passenger_count: 1, notes: '' }

/** Numbered step card used on the New booking page. */
function Step({ n, title, hint, children }: { n?: number; title: string; hint?: string; children: ReactNode }) {
  return (
    <section className="card p-4 sm:p-5">
      <header className="mb-4 flex items-start gap-3">
        {n !== undefined && <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gold text-sm font-semibold text-ink">{n}</span>}
        <div>
          <h2 className="font-semibold text-slate-900">{title}</h2>
          {hint && <p className="text-sm text-slate-500">{hint}</p>}
        </div>
      </header>
      {children}
    </section>
  )
}

/**
 * New booking: only the customer and the requested trip (creates an Inquiry).
 * Edit booking: trip, reservation details and prices. The status changes through "Change status".
 */
export default function BookingFormPage() {
  const { id } = useParams()
  const editing = Boolean(id)
  const navigate = useNavigate()
  const { can } = useAuth()
  const { data: meta } = useMeta()
  const { data: suppliers } = useSuppliers()
  const { data: existing, isLoading } = useBooking(id)
  const mutation = useSaveBooking()
  const errors: ValidationErrors = fieldErrors(mutation.error)

  const [customer, setCustomer] = useState<Picked | null>(null)
  const [trip, setTrip] = useState<Trip>(emptyTrip)
  const [res, setRes] = useState<Reservation>({ supplier_id: '', pnr: '', ticketing_deadline: '', payment_due_date: '', next_action: '', currency: '', selling_total: '', service_fee: '', supplier_total: '' })

  useEffect(() => {
    if (!existing) return
    setCustomer(existing.customer ? { id: existing.customer.id, full_name: existing.customer.full_name, phone: existing.customer.phone } : null)
    setTrip({
      trip_type: existing.trip_type, origin: existing.origin ?? '', destination: existing.destination ?? '',
      departure_date: existing.departure_date ?? '', return_date: existing.return_date ?? '', cabin_class: existing.cabin_class ?? '',
      passenger_count: existing.passenger_count, notes: existing.notes ?? '',
    })
    setRes({
      supplier_id: existing.supplier_id ? String(existing.supplier_id) : '', pnr: existing.pnr ?? '',
      ticketing_deadline: existing.ticketing_deadline ?? '', payment_due_date: existing.payment_due_date ?? '', next_action: existing.next_action ?? '',
      currency: existing.currency, selling_total: existing.selling_total ?? '', service_fee: existing.service_fee ?? '', supplier_total: existing.supplier_total ?? '',
    })
  }, [existing])

  const setT = <K extends keyof Trip>(k: K, v: Trip[K]) => setTrip((t) => ({ ...t, [k]: v }))
  const setR = (k: keyof Reservation, v: string) => setRes((r) => ({ ...r, [k]: v }))
  const nul = (v: string) => (v === '' ? null : v)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const tripPayload = {
      customer_id: customer?.id ?? null,
      trip_type: trip.trip_type,
      origin: trip.origin,
      destination: trip.destination,
      departure_date: nul(trip.departure_date),
      return_date: trip.trip_type === 'round_trip' ? nul(trip.return_date) : null,
      cabin_class: nul(trip.cabin_class),
      passenger_count: trip.passenger_count,
      notes: nul(trip.notes),
    }
    const payload: Record<string, unknown> = editing
      ? {
          ...tripPayload,
          supplier_id: nul(res.supplier_id), pnr: nul(res.pnr), ticketing_deadline: nul(res.ticketing_deadline),
          payment_due_date: nul(res.payment_due_date), next_action: nul(res.next_action), currency: res.currency,
          selling_total: nul(res.selling_total), service_fee: nul(res.service_fee),
          ...(can('view-costs') ? { supplier_total: nul(res.supplier_total) } : {}),
        }
      : tripPayload
    mutation.mutate({ id, payload }, {
      onSuccess: (booking) => navigate(`/bookings/${booking.id}`, { state: editing ? { saved: true } : { created: booking.booking_code } }),
      onError: () => window.scrollTo({ top: 0, behavior: 'smooth' }),
    })
  }

  if (editing && isLoading) return <Loading />

  const priced = res.selling_total !== ''
  const due = Number(res.selling_total || 0) + Number(res.service_fee || 0)

  return (
    <form onSubmit={submit} className="mx-auto max-w-4xl space-y-5" noValidate={false}>
      <PageHeader
        title={editing ? `Edit ${existing?.booking_code ?? 'booking'}` : 'New booking'}
        subtitle={editing
          ? 'Update the trip, reservation details and prices. Passengers, flights, tickets and payments are managed on the booking page.'
          : 'Record what the customer is asking for. Airline, PNR, tickets and prices can be added later on the booking page.'}
      />
      {mutation.isError && <ErrorBox message={errorMessage(mutation.error)} />}

      <Step n={editing ? undefined : 1} title="Customer" hint={editing ? undefined : 'Choose an existing customer or add a new one.'}>
        <span className="label">Customer <span className="text-red-600">*</span></span>
        <CustomerPicker value={customer} onChange={setCustomer} error={errors.customer_id?.[0]} />
      </Step>

      <Step n={editing ? undefined : 2} title="Travel details" hint={editing ? undefined : 'What the customer asked for. This is not the confirmed itinerary.'}>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <span className="label">Trip type</span>
            <div className="inline-flex rounded-md border border-slate-300 bg-surface p-0.5" role="radiogroup" aria-label="Trip type">
              {(meta?.trip_types ?? [{ value: 'one_way', label: 'One-way' }, { value: 'round_trip', label: 'Round-trip' }]).map((o) => (
                <button key={o.value} type="button" role="radio" aria-checked={trip.trip_type === o.value} onClick={() => setT('trip_type', o.value)}
                  className={`rounded px-4 py-1.5 text-sm font-medium transition ${trip.trip_type === o.value ? 'bg-gold text-ink' : 'text-slate-600 hover:text-slate-900'}`}>
                  {o.label}
                </button>
              ))}
            </div>
          </div>
          <Field label="From (city or airport) *" error={errors.origin}>
            <AirportInput value={trip.origin} onChange={(v) => setT('origin', v)} placeholder="e.g. Colombo or CMB" invalid={!!errors.origin} />
          </Field>
          <Field label="To (city or airport) *" error={errors.destination}>
            <AirportInput value={trip.destination} onChange={(v) => setT('destination', v)} placeholder="e.g. Stockholm or ARN" invalid={!!errors.destination} />
          </Field>
          <Field label="Departure date" error={errors.departure_date} hint="Leave empty if not decided yet">
            <Input type="date" value={trip.departure_date} onChange={(e) => setT('departure_date', e.target.value)} />
          </Field>
          {trip.trip_type === 'round_trip' ? (
            <Field label="Return date" error={errors.return_date} hint="Optional">
              <Input type="date" min={trip.departure_date || undefined} value={trip.return_date} onChange={(e) => setT('return_date', e.target.value)} />
            </Field>
          ) : <div className="hidden sm:block" />}
          <Field label="Cabin class" error={errors.cabin_class}>
            <Select value={trip.cabin_class} onChange={(e) => setT('cabin_class', e.target.value)} options={meta?.cabin_classes ?? []} placeholder="Not decided" />
          </Field>
          <Field label="Passengers *" error={errors.passenger_count}>
            <div className="flex items-center gap-2">
              <Button variant="secondary" aria-label="Fewer passengers" onClick={() => setT('passenger_count', Math.max(1, trip.passenger_count - 1))}><Minus className="h-4 w-4" aria-hidden /></Button>
              <Input type="number" inputMode="numeric" min={1} max={50} className="w-20 text-center" aria-label="Number of passengers"
                value={trip.passenger_count} onChange={(e) => setT('passenger_count', Math.max(1, Math.min(50, Number(e.target.value) || 1)))} />
              <Button variant="secondary" aria-label="More passengers" onClick={() => setT('passenger_count', Math.min(50, trip.passenger_count + 1))}><Plus className="h-4 w-4" aria-hidden /></Button>
            </div>
          </Field>
          <Field label="Notes or customer requirements" error={errors.notes} className="sm:col-span-2" hint="Optional, e.g. window seats, flexible dates, budget">
            <Textarea rows={2} value={trip.notes} onChange={(e) => setT('notes', e.target.value)} />
          </Field>
        </div>
      </Step>

      {editing && (
        <>
          <Step title="Reservation details" hint="Fill in when a reservation exists. All optional.">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Supplier" error={errors.supplier_id}>
                <Select value={res.supplier_id} onChange={(e) => setR('supplier_id', e.target.value)} placeholder="Not chosen"
                  options={(suppliers ?? []).filter((s) => s.is_active || String(s.id) === res.supplier_id).map((s) => ({ value: String(s.id), label: s.name }))} />
              </Field>
              <Field label="Airline PNR" error={errors.pnr} hint="From the airline or supplier, never invented">
                <Input value={res.pnr} onChange={(e) => setR('pnr', e.target.value.toUpperCase())} className="font-mono" maxLength={20} />
              </Field>
              <Field label="Ticketing deadline" error={errors.ticketing_deadline}><Input type="datetime-local" value={res.ticketing_deadline} onChange={(e) => setR('ticketing_deadline', e.target.value)} /></Field>
              <Field label="Payment due date" error={errors.payment_due_date}><Input type="date" value={res.payment_due_date} onChange={(e) => setR('payment_due_date', e.target.value)} /></Field>
              <Field label="Next action" error={errors.next_action} className="sm:col-span-2" hint="Optional; the booking page also suggests the next step">
                <Input value={res.next_action} onChange={(e) => setR('next_action', e.target.value)} />
              </Field>
            </div>
          </Step>

          <Step title="Prices" hint="Leave empty until known; empty is shown as “Not entered”, not as zero.">
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <Field label="Currency" error={errors.currency}><Select value={res.currency} onChange={(e) => setR('currency', e.target.value)} options={meta?.currencies ?? []} /></Field>
              <Field label="Ticket selling amount" error={errors.selling_total} hint="Fare + taxes to customer"><Input type="number" inputMode="decimal" min="0" step="0.01" value={res.selling_total} onChange={(e) => setR('selling_total', e.target.value)} /></Field>
              <Field label="Service fee" error={errors.service_fee}><Input type="number" inputMode="decimal" min="0" step="0.01" value={res.service_fee} onChange={(e) => setR('service_fee', e.target.value)} /></Field>
              {can('view-costs') && <Field label="Supplier cost" error={errors.supplier_total}><Input type="number" inputMode="decimal" min="0" step="0.01" value={res.supplier_total} onChange={(e) => setR('supplier_total', e.target.value)} /></Field>}
            </div>
            <p className="mt-3 text-sm text-slate-600">
              Customer pays: <strong>{priced ? money(due, res.currency) : 'Not entered'}</strong>
              {can('view-costs') && priced && res.supplier_total !== '' && <> · Estimated margin: <strong>{money(due - Number(res.supplier_total), res.currency)}</strong></>}
            </p>
          </Step>
        </>
      )}

      <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <Link to={editing ? `/bookings/${id}` : '/bookings'} className="btn-secondary">Cancel</Link>
        <Button type="submit" loading={mutation.isPending}>{editing ? 'Save changes' : 'Create inquiry'}</Button>
      </div>
    </form>
  )
}
