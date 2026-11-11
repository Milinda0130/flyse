import { useState, type FormEvent } from 'react'
import { CircleCheck, TriangleAlert } from 'lucide-react'
import { errorMessage, fieldErrors, type ValidationErrors } from '../../lib/api'
import { useAuth } from '../../auth/AuthContext'
import { useMeta, useSuppliers } from '../../hooks/queries'
import { useChangeBookingStatus } from '../../hooks/mutations'
import { labelFor } from '../../lib/format'
import type { Booking } from '../../lib/types'
import { Button, ErrorBox, Field, Input, Select, Textarea } from '../../components/ui'

const DESCRIPTIONS: Record<string, string> = {
  inquiry: 'Back to a customer request without a reservation.',
  confirmed: 'A reservation exists with the airline or supplier; tickets are not issued yet.',
  pending_payment: 'Waiting for the customer to pay before ticketing.',
  on_hold: 'Waiting for a decision from the customer or supplier.',
  ticketed: 'Tickets are issued and every passenger has a ticket number and e-ticket.',
  changed: 'The reservation or ticket was changed (new date, flight or route).',
  completed: 'Travel is finished and the booking is closed.',
  cancelled: 'The booking is cancelled. It stays on record with its payments and documents.',
}

/**
 * Change the booking status, asking only for what that step needs. The server re-checks
 * every rule (for example the ticket and e-ticket requirements for Ticketed).
 */
export default function StatusDialog({ booking, initialStatus, onDone, onIssueTickets }: {
  booking: Booking
  initialStatus?: string
  onDone: () => void
  onIssueTickets: () => void
}) {
  const { can } = useAuth()
  const { data: meta } = useMeta()
  const { data: suppliers } = useSuppliers()
  const allowed = booking.allowed_transitions ?? []
  const [status, setStatus] = useState(initialStatus && allowed.includes(initialStatus) ? initialStatus : allowed[0] ?? '')
  const [form, setForm] = useState({
    note: '', reason: '', next_action: '',
    supplier_id: booking.supplier_id ? String(booking.supplier_id) : '', pnr: booking.pnr ?? '',
    ticketing_deadline: booking.ticketing_deadline ?? '', payment_due_date: booking.payment_due_date ?? '',
    selling_total: booking.selling_total ?? '', service_fee: booking.service_fee ?? '', supplier_total: booking.supplier_total ?? '',
    customer_refund_amount: '', refund_method: '',
  })
  const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }))
  const mutation = useChangeBookingStatus()
  const errors: ValidationErrors = fieldErrors(mutation.error)
  const ticketingProblems = booking.ticketing_problems ?? []
  const blocked = status === 'ticketed' && ticketingProblems.length > 0

  if (!allowed.length) {
    return <p className="text-sm text-slate-600">The status of a {labelFor(meta?.booking_statuses, booking.status)} booking cannot be changed.</p>
  }

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const nul = (v: string) => (v === '' ? null : v)
    const payload: Record<string, unknown> = { status, note: nul(form.note), next_action: nul(form.next_action) }
    if (status === 'confirmed') {
      Object.assign(payload, {
        supplier_id: nul(form.supplier_id), pnr: nul(form.pnr), ticketing_deadline: nul(form.ticketing_deadline),
        payment_due_date: nul(form.payment_due_date), selling_total: nul(form.selling_total), service_fee: nul(form.service_fee),
        ...(can('view-costs') ? { supplier_total: nul(form.supplier_total) } : {}),
      })
    }
    if (status === 'cancelled') {
      Object.assign(payload, {
        reason: form.reason, selling_total: nul(form.selling_total), service_fee: nul(form.service_fee),
        ...(can('view-costs') ? { supplier_total: nul(form.supplier_total) } : {}),
        ...(form.customer_refund_amount ? { customer_refund_amount: form.customer_refund_amount, refund_method: nul(form.refund_method) } : {}),
      })
    }
    mutation.mutate({ id: booking.id, payload }, { onSuccess: onDone })
  }

  const serverProblems = errors.status && errors.status.length > 1 ? errors.status : null

  return (
    <form onSubmit={submit} className="space-y-4">
      {mutation.isError && !serverProblems && <ErrorBox message={errorMessage(mutation.error)} />}

      <fieldset>
        <legend className="label">Change from {labelFor(meta?.booking_statuses, booking.status)} to</legend>
        <div className="grid gap-2 sm:grid-cols-2">
          {allowed.map((s) => (
            <label key={s} className={`flex cursor-pointer gap-2 rounded-md border p-2.5 text-sm transition ${status === s ? 'border-gold bg-gold/10 ring-1 ring-gold' : 'border-slate-200 hover:bg-slate-50'}`}>
              <input type="radio" name="status" value={s} checked={status === s} onChange={() => setStatus(s)} className="mt-0.5 accent-[#d4a93f]" />
              <span><span className="block font-medium text-slate-900">{labelFor(meta?.booking_statuses, s)}</span><span className="block text-xs text-slate-500">{DESCRIPTIONS[s]}</span></span>
            </label>
          ))}
        </div>
      </fieldset>

      {status === 'confirmed' && (
        <div className="grid gap-3 rounded-md border border-slate-200 p-3 sm:grid-cols-2">
          <p className="text-sm font-medium text-slate-700 sm:col-span-2">Reservation details <span className="font-normal text-slate-500">(fill in what you have)</span></p>
          <Field label="Supplier" error={errors.supplier_id}>
            <Select value={form.supplier_id} onChange={(e) => set('supplier_id', e.target.value)} placeholder="Not chosen" options={(suppliers ?? []).filter((x) => x.is_active).map((x) => ({ value: String(x.id), label: x.name }))} />
          </Field>
          <Field label="Airline PNR" error={errors.pnr}><Input className="font-mono" value={form.pnr} onChange={(e) => set('pnr', e.target.value.toUpperCase())} /></Field>
          <Field label="Ticketing deadline" error={errors.ticketing_deadline}><Input type="datetime-local" value={form.ticketing_deadline} onChange={(e) => set('ticketing_deadline', e.target.value)} /></Field>
          <Field label="Payment due date" error={errors.payment_due_date}><Input type="date" value={form.payment_due_date} onChange={(e) => set('payment_due_date', e.target.value)} /></Field>
          <Field label={`Selling amount (${booking.currency})`} error={errors.selling_total}><Input type="number" inputMode="decimal" min="0" step="0.01" value={form.selling_total} onChange={(e) => set('selling_total', e.target.value)} /></Field>
          <Field label="Service fee" error={errors.service_fee}><Input type="number" inputMode="decimal" min="0" step="0.01" value={form.service_fee} onChange={(e) => set('service_fee', e.target.value)} /></Field>
          {can('view-costs') && <Field label="Supplier cost" error={errors.supplier_total}><Input type="number" inputMode="decimal" min="0" step="0.01" value={form.supplier_total} onChange={(e) => set('supplier_total', e.target.value)} /></Field>}
        </div>
      )}

      {status === 'ticketed' && (
        blocked || serverProblems ? (
          <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" role="alert">
            <p className="flex items-center gap-1.5 font-medium"><TriangleAlert className="h-4 w-4" aria-hidden /> Before this booking can be marked Ticketed:</p>
            <ul className="mt-1.5 list-disc space-y-0.5 pl-6">{(serverProblems ?? ticketingProblems).map((p) => <li key={p}>{p}</li>)}</ul>
            <Button size="sm" className="mt-3" onClick={onIssueTickets}>Issue tickets</Button>
          </div>
        ) : (
          <p className="flex items-center gap-1.5 rounded-md border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-800"><CircleCheck className="h-4 w-4" aria-hidden /> All passengers have ticket numbers and a stored e-ticket.</p>
        )
      )}

      {status === 'changed' && (
        <Field label="What changed? *" error={errors.note} hint="Update the flight segments under Passengers & Flights; previous times stay in the history.">
          <Textarea required rows={2} value={form.note} onChange={(e) => set('note', e.target.value)} placeholder="e.g. QR167 moved from 08:10 to 10:10 on 17 Nov" />
        </Field>
      )}

      {status === 'cancelled' && (
        <div className="space-y-3 rounded-md border border-slate-200 p-3">
          <Field label="Reason for cancellation *" error={errors.reason}>
            <Textarea required rows={2} value={form.reason} onChange={(e) => set('reason', e.target.value)} placeholder="e.g. Customer cancelled; airline keeps a cancellation fee" />
          </Field>
          <p className="text-xs text-slate-500">Optional: reduce the amounts to what is kept after cancellation. Payments already recorded are never deleted.</p>
          <div className="grid gap-3 sm:grid-cols-3">
            <Field label="Selling amount kept" error={errors.selling_total}><Input type="number" inputMode="decimal" min="0" step="0.01" value={form.selling_total} onChange={(e) => set('selling_total', e.target.value)} /></Field>
            <Field label="Service fee kept" error={errors.service_fee}><Input type="number" inputMode="decimal" min="0" step="0.01" value={form.service_fee} onChange={(e) => set('service_fee', e.target.value)} /></Field>
            {can('view-costs') && <Field label="Supplier cost kept" error={errors.supplier_total}><Input type="number" inputMode="decimal" min="0" step="0.01" value={form.supplier_total} onChange={(e) => set('supplier_total', e.target.value)} /></Field>}
          </div>
          {can('manage-payments') && (
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={`Refund owed to customer (${booking.currency})`} error={errors.customer_refund_amount} hint="Recorded as a pending refund">
                <Input type="number" inputMode="decimal" min="0.01" step="0.01" value={form.customer_refund_amount} onChange={(e) => set('customer_refund_amount', e.target.value)} />
              </Field>
              {form.customer_refund_amount && <Field label="Refund method"><Select value={form.refund_method} onChange={(e) => set('refund_method', e.target.value)} options={meta?.payment_methods ?? []} placeholder="To be confirmed" /></Field>}
            </div>
          )}
        </div>
      )}

      {!['changed', 'cancelled'].includes(status) && (
        <Field label="Note (optional)" error={errors.note}><Input value={form.note} onChange={(e) => set('note', e.target.value)} /></Field>
      )}
      <Field label="Next action (optional)" error={errors.next_action}><Input value={form.next_action} onChange={(e) => set('next_action', e.target.value)} /></Field>

      <div className="flex justify-end gap-2">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button type="submit" loading={mutation.isPending} disabled={blocked} variant={status === 'cancelled' ? 'danger' : 'primary'}>
          {status === 'cancelled' ? 'Cancel booking' : `Change to ${labelFor(meta?.booking_statuses, status)}`}
        </Button>
      </div>
    </form>
  )
}
