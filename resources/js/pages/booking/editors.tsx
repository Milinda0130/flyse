import { useState, type FormEvent } from 'react'
import { errorMessage, fieldErrors, type ValidationErrors } from '../../lib/api'
import { useAuth } from '../../auth/AuthContext'
import { useMeta } from '../../hooks/queries'
import { useSaveFlight, useSavePassenger, useSavePayment, useUpdateTicket } from '../../hooks/mutations'
import { today } from '../../lib/format'
import type { Booking, Flight, Passenger, Payment, Ticket } from '../../lib/types'
import { Button, ErrorBox, Field, Input, Select, Textarea } from '../../components/ui'
import { emptyFlight, emptyPassenger, FlightFields, nullify, PassengerFields, type FlightDraft, type PassengerDraft } from '../../components/BookingFields'

/** Shared edit forms used inside dialogs on the booking page. */

/** Pre-fills the amount a payment of this type is most likely for. */
function suggestedAmount(booking: Booking, direction: string): string {
  const f = booking.finance
  if (!f) return ''
  const positive = (v: string | null | undefined) => (v && Number(v) > 0 ? v : '')
  if (direction === 'customer_receipt') return positive(f.customer_balance)
  if (direction === 'supplier_payment') return positive(f.supplier_balance)
  if (direction === 'customer_refund') return positive(f.refund_pending)
  return ''
}

export function FlightEditor({ booking, flight, onDone }: { booking: Booking; flight: Flight | null; onDone: () => void }) {
  const { data: meta } = useMeta()
  const last = booking.flights?.[booking.flights.length - 1]
  const [draft, setDraft] = useState<FlightDraft>(() => flight ? {
    airline: flight.airline, flight_number: flight.flight_number, origin_code: flight.origin_code, destination_code: flight.destination_code,
    departure_at: flight.departure_at, arrival_at: flight.arrival_at ?? '', origin_timezone: flight.origin_timezone ?? '',
    destination_timezone: flight.destination_timezone ?? '', cabin_class: flight.cabin_class, baggage_allowance: flight.baggage_allowance ?? '',
    status: flight.status, notes: flight.notes ?? '',
  } : emptyFlight(last ? {
    ...emptyFlight(), airline: last.airline, destination_code: last.destination_code, destination_timezone: last.destination_timezone ?? '',
    cabin_class: last.cabin_class, baggage_allowance: last.baggage_allowance ?? '',
  } : undefined))
  const mutation = useSaveFlight()
  const errors: ValidationErrors = fieldErrors(mutation.error)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    mutation.mutate({ bookingId: booking.id, id: flight?.id, payload: nullify(draft) }, { onSuccess: onDone })
  }

  return (
    <form onSubmit={submit} className="space-y-4">
      {mutation.isError && <ErrorBox message={errorMessage(mutation.error)} />}
      <FlightFields value={draft} onChange={setDraft} errors={errors} meta={meta} showStatus />
      {flight && <p className="text-xs text-slate-500">Saving keeps the previous values in the booking’s Activity history. If the itinerary changed, also set the booking status to “Changed”.</p>}
      <div className="flex justify-end gap-2"><Button type="submit" loading={mutation.isPending}>Save segment</Button></div>
    </form>
  )
}

export function PassengerEditor({ booking, passenger, onDone }: { booking: Booking; passenger: Passenger | null; onDone: () => void }) {
  const { can } = useAuth()
  const { data: meta } = useMeta()
  const [draft, setDraft] = useState<PassengerDraft>(() => passenger ? {
    full_name_as_document: passenger.full_name_as_document, passenger_type: passenger.passenger_type, date_of_birth: passenger.date_of_birth ?? '',
    nationality: passenger.nationality ?? '', passport_number: passenger.passport_number ?? '', passport_expiry: passenger.passport_expiry ?? '',
    phone: passenger.phone ?? '', notes: passenger.notes ?? '',
  } : emptyPassenger())
  const mutation = useSavePassenger()
  const errors: ValidationErrors = fieldErrors(mutation.error)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const payload = nullify(draft)
    if (!can('view-passport')) delete payload.passport_number
    mutation.mutate({ bookingId: booking.id, id: passenger?.id, payload }, { onSuccess: onDone })
  }

  return (
    <form onSubmit={submit} className="space-y-4">
      {mutation.isError && <ErrorBox message={errorMessage(mutation.error)} />}
      <PassengerFields value={draft} onChange={setDraft} errors={errors} meta={meta} />
      <div className="flex justify-end"><Button type="submit" loading={mutation.isPending}>Save passenger</Button></div>
    </form>
  )
}

/** Edit an issued ticket. New tickets are recorded with "Issue tickets", which requires the e-ticket. */
export function TicketEditor({ booking, ticket, onDone }: { booking: Booking; ticket: Ticket; onDone: () => void }) {
  const { data: meta } = useMeta()
  const eTickets = (booking.documents ?? []).filter((d) => d.document_type === 'e_ticket')
  const [form, setForm] = useState({
    ticket_number: ticket.ticket_number, airline_pnr: ticket.airline_pnr ?? '', booking_flight_id: ticket.booking_flight_id ? String(ticket.booking_flight_id) : '',
    issued_at: ticket.issued_at ?? '', status: ticket.status, fare_conditions: ticket.fare_conditions ?? '',
    e_ticket_document_id: ticket.e_ticket_document_id ? String(ticket.e_ticket_document_id) : '',
  })
  const mutation = useUpdateTicket()
  const errors: ValidationErrors = fieldErrors(mutation.error)
  const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }))

  const submit = (e: FormEvent) => {
    e.preventDefault()
    mutation.mutate({ id: ticket.id, payload: nullify(form) }, { onSuccess: onDone })
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      {mutation.isError && <ErrorBox message={errorMessage(mutation.error)} />}
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Ticket number *" error={errors.ticket_number}><Input required className="font-mono" value={form.ticket_number} onChange={(e) => set('ticket_number', e.target.value)} /></Field>
        <Field label="Airline PNR" error={errors.airline_pnr} hint="If different from the booking PNR"><Input className="font-mono" value={form.airline_pnr} onChange={(e) => set('airline_pnr', e.target.value.toUpperCase())} /></Field>
        <Field label="Segment" error={errors.booking_flight_id}>
          <Select value={form.booking_flight_id} onChange={(e) => set('booking_flight_id', e.target.value)} placeholder="Whole itinerary"
            options={(booking.flights ?? []).map((f) => ({ value: String(f.id), label: `${f.flight_number} ${f.origin_code}-${f.destination_code}` }))} />
        </Field>
        <Field label="Issued on" error={errors.issued_at}><Input type="date" value={form.issued_at} onChange={(e) => set('issued_at', e.target.value)} /></Field>
        <Field label="Ticket status" error={errors.status}><Select value={form.status} onChange={(e) => set('status', e.target.value)} options={meta?.ticket_statuses ?? []} /></Field>
        <Field label="E-ticket document" error={errors.e_ticket_document_id} hint="Upload new e-tickets under Documents & Issues">
          <Select value={form.e_ticket_document_id} onChange={(e) => set('e_ticket_document_id', e.target.value)} placeholder="None"
            options={eTickets.map((d) => ({ value: String(d.id), label: d.original_name }))} />
        </Field>
      </div>
      <Field label="Fare conditions" error={errors.fare_conditions} hint="Copy from the supplier documents"><Textarea value={form.fare_conditions} onChange={(e) => set('fare_conditions', e.target.value)} /></Field>
      <div className="flex justify-end"><Button type="submit" loading={mutation.isPending}>Save ticket</Button></div>
    </form>
  )
}

export function PaymentForm({ booking, payment, onDone, defaultDirection = 'customer_receipt' }: { booking: Booking; payment?: Payment; onDone: () => void; defaultDirection?: string }) {
  const { can } = useAuth()
  const { data: meta } = useMeta()
  const directions = (meta?.payment_directions ?? []).filter((d) => d.value === 'customer_receipt' ? can('record-receipt') : can('record-supplier-payment'))
  const [form, setForm] = useState({
    direction: payment?.direction ?? defaultDirection,
    amount: payment?.amount ?? suggestedAmount(booking, defaultDirection),
    method: payment?.method ?? meta?.payment_methods[0] ?? '',
    transaction_date: payment?.transaction_date ?? today(),
    transaction_reference: payment?.transaction_reference ?? '',
    status: payment?.status ?? 'completed',
    notes: payment?.notes ?? '',
  })
  const mutation = useSavePayment()
  const errors: ValidationErrors = fieldErrors(mutation.error)
  const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }))

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const payload = { ...form, transaction_reference: form.transaction_reference || null, notes: form.notes || null }
    // The direction of an existing transaction cannot change.
    const { direction: _direction, ...editable } = payload
    mutation.mutate({ bookingId: booking.id, id: payment?.id, payload: payment ? editable : payload }, { onSuccess: onDone })
  }

  const statusOptions = (meta?.payment_statuses ?? []).filter((s) => payment || s.value !== 'voided')

  return (
    <form onSubmit={submit} className="space-y-3">
      {mutation.isError && <ErrorBox message={errorMessage(mutation.error)} />}
      <Field label="Type" error={errors.direction}>
        <Select value={form.direction} disabled={!!payment} onChange={(e) => set('direction', e.target.value)} options={payment ? meta?.payment_directions ?? [] : directions} />
      </Field>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={`Amount (${booking.currency}) *`} error={errors.amount}><Input type="number" min="0.01" step="0.01" required value={form.amount} onChange={(e) => set('amount', e.target.value)} /></Field>
        <Field label="Date *" error={errors.transaction_date}><Input type="date" required value={form.transaction_date} onChange={(e) => set('transaction_date', e.target.value)} /></Field>
        <Field label="Method *" error={errors.method}><Select value={form.method} onChange={(e) => set('method', e.target.value)} options={meta?.payment_methods ?? []} /></Field>
        <Field label="Status" error={errors.status} hint="Pending rows do not affect balances"><Select value={form.status} onChange={(e) => set('status', e.target.value)} options={statusOptions} /></Field>
      </div>
      <Field label="Reference" error={errors.transaction_reference}><Input value={form.transaction_reference} onChange={(e) => set('transaction_reference', e.target.value)} placeholder="Bank ref, receipt no., refund ref…" /></Field>
      <Field label="Notes" error={errors.notes}><Textarea rows={2} value={form.notes} onChange={(e) => set('notes', e.target.value)} /></Field>
      {payment && <p className="text-xs text-slate-500">Edits are logged. To reverse a mistaken entry, set its status to Voided.</p>}
      <div className="flex justify-end"><Button type="submit" loading={mutation.isPending}>Save</Button></div>
    </form>
  )
}
