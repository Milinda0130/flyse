import { useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { ChevronRight, CircleCheck, ListChecks, Mail, Phone } from 'lucide-react'
import { useAuth } from '../../auth/AuthContext'
import { useMeta } from '../../hooks/queries'
import { airportLabel } from '../../lib/airports'
import { date, dateTime, labelFor, localTime, money } from '../../lib/format'
import type { Booking, ChecklistItem } from '../../lib/types'
import { PaymentStatusBadge } from '../../components/Badges'
import ActivityTab from './ActivityTab'

export type Section = 'overview' | 'passengers' | 'documents' | 'payments'

function Group({ title, children, action }: { title: string; children: ReactNode; action?: ReactNode }) {
  return (
    <section className="card p-4">
      <header className="mb-3 flex items-center justify-between gap-2">
        <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
        {action}
      </header>
      {children}
    </section>
  )
}

function Item({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex justify-between gap-4 py-1 text-sm">
      <dt className="text-slate-500">{label}</dt>
      <dd className="text-right font-medium text-slate-900">{children}</dd>
    </div>
  )
}

/** Shown in place of a missing value: clear, not alarming. */
const NotYet = ({ children = 'Not entered yet' }: { children?: ReactNode }) => <span className="font-normal italic text-slate-400">{children}</span>

/** The first section of a booking: what it is, who it is for, and what to do next. */
export default function OverviewTab({ booking, onGoTo }: { booking: Booking; onGoTo: (section: Section) => void }) {
  const { can } = useAuth()
  const { data: meta } = useMeta()
  const [showHistory, setShowHistory] = useState(false)
  const f = booking.finance
  const c = booking.currency
  const flights = booking.flights ?? []
  const hasMoney = f && (f.customer_due !== null || Number(f.customer_received) > 0 || Number(f.refund_pending) > 0)
  const checklist: ChecklistItem[] = booking.checklist ?? []

  return (
    <div className="space-y-4">
      {booking.status === 'cancelled' && (
        <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
          <strong>Cancelled</strong>{booking.cancelled_at && <> on {dateTime(booking.cancelled_at)}</>}. {booking.cancellation_reason}
          <span className="mt-1 block text-red-700">The booking, its payments and documents are kept for the record.</span>
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-3">
        {/* Outstanding tasks first: the reason someone opened the booking. */}
        <section className="card p-4 lg:col-span-2">
          <h2 className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-900"><ListChecks className="h-4 w-4 text-brand-600" aria-hidden /> To do</h2>
          {checklist.length === 0 ? (
            <p className="flex items-center gap-2 text-sm text-emerald-700"><CircleCheck className="h-4 w-4" aria-hidden /> Nothing outstanding.</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {checklist.map((i) => (
                <li key={i.key}>
                  <button type="button" onClick={() => onGoTo(i.section === 'overview' ? 'overview' : i.section)}
                    className="flex w-full items-center justify-between gap-3 py-2 text-left text-sm text-slate-700 hover:text-slate-900">
                    <span className="flex items-center gap-2"><span className="h-1.5 w-1.5 shrink-0 rounded-full bg-gold" aria-hidden />{i.label}</span>
                    {i.section !== 'overview' && <ChevronRight className="h-4 w-4 shrink-0 text-slate-400" aria-hidden />}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </section>

        <Group title="Customer" action={<Link className="link text-xs" to={`/customers/${booking.customer_id}`}>Profile</Link>}>
          <p className="font-medium text-slate-900">{booking.customer?.full_name}</p>
          {booking.customer?.phone
            ? <a className="mt-1 flex items-center gap-2 text-sm text-slate-700 hover:underline" href={`tel:${booking.customer.phone.replace(/\s+/g, '')}`}><Phone className="h-3.5 w-3.5" aria-hidden />{booking.customer.phone}</a>
            : <p className="mt-1 text-sm"><NotYet>No phone number</NotYet></p>}
          {booking.customer?.email && <a className="mt-1 flex items-center gap-2 text-sm text-slate-700 hover:underline" href={`mailto:${booking.customer.email}`}><Mail className="h-3.5 w-3.5" aria-hidden />{booking.customer.email}</a>}
        </Group>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Group title="Travel" action={<button type="button" className="link text-xs" onClick={() => onGoTo('passengers')}>Passengers & flights</button>}>
          <dl>
            <Item label="Route">{booking.origin || booking.destination ? `${airportLabel(booking.origin)} → ${airportLabel(booking.destination)}` : <NotYet />}</Item>
            <Item label="Trip">{labelFor(meta?.trip_types, booking.trip_type)}</Item>
            <Item label="Departure">{booking.departure_date ? date(booking.departure_date) : <NotYet>Date not set</NotYet>}</Item>
            {booking.trip_type === 'round_trip' && <Item label="Return">{booking.return_date ? date(booking.return_date) : <NotYet>Date not set</NotYet>}</Item>}
            <Item label="Class">{booking.cabin_class ? labelFor(meta?.cabin_classes, booking.cabin_class) : <NotYet>Not decided</NotYet>}</Item>
            <Item label="Passengers">{(booking.passengers ?? []).length} of {booking.passenger_count} named</Item>
          </dl>
          <div className="mt-3 border-t border-slate-100 pt-3 text-sm">
            {flights.length > 0 ? (
              <p><span className="text-slate-500">Confirmed itinerary:</span> <span className="font-mono font-medium">{booking.route}</span><br />
                <span className="text-xs text-slate-500">First flight {localTime(flights[0].departure_at)} · {Array.from(new Set(flights.map((x) => x.airline))).join(', ')}</span></p>
            ) : <p className="text-slate-500">Flight details have not been added yet.</p>}
          </div>
        </Group>

        <Group title="Booking">
          <dl>
            <Item label="Reference">{booking.booking_code}</Item>
            <Item label="Status">{labelFor(meta?.booking_statuses, booking.status)}</Item>
            <Item label="Airline PNR">{booking.pnr ? <span className="font-mono">{booking.pnr}</span> : <NotYet />}</Item>
            <Item label="Supplier">{booking.supplier?.name ?? <NotYet />}</Item>
            <Item label="Booked on">{date(booking.booking_date)}</Item>
            {booking.ticketing_deadline && <Item label="Ticketing deadline">{localTime(booking.ticketing_deadline)}</Item>}
            {booking.payment_due_date && <Item label="Payment due">{date(booking.payment_due_date)}</Item>}
          </dl>
          {booking.notes && <p className="mt-3 whitespace-pre-line border-t border-slate-100 pt-3 text-sm text-slate-600">{booking.notes}</p>}
        </Group>

        <Group title="Payment" action={<button type="button" className="link text-xs" onClick={() => onGoTo('payments')}>Payments</button>}>
          {hasMoney && f ? (
            <>
              <dl>
                <Item label="Customer pays">{f.customer_due !== null ? money(f.customer_due, c) : <NotYet>Price not entered</NotYet>}</Item>
                <Item label="Received">{money(f.customer_paid, c)}</Item>
                <Item label="Balance due">{f.customer_balance !== null ? money(f.customer_balance, c) : <NotYet>Unknown</NotYet>}</Item>
                {Number(f.refund_pending) > 0 && <Item label="Refund pending">{money(f.refund_pending, c)}</Item>}
                {can('view-costs') && f.gross_margin !== undefined && <Item label="Est. margin">{f.gross_margin !== null ? money(f.gross_margin, c) : <NotYet>Needs price and cost</NotYet>}</Item>}
              </dl>
              <div className="mt-2"><PaymentStatusBadge status={f.payment_status} /></div>
            </>
          ) : (
            <p className="text-sm text-slate-500">No price or payment yet.{can('edit-records') && <> <Link className="link" to={`/bookings/${booking.id}/edit`}>Add the price</Link> when it is known.</>}</p>
          )}
        </Group>
      </div>

      {can('view-audit') && (
        <section className="card">
          <button type="button" aria-expanded={showHistory} onClick={() => setShowHistory((v) => !v)} className="flex w-full items-center justify-between px-4 py-3 text-left text-sm font-semibold text-slate-900">
            History of changes
            <ChevronRight className={`h-4 w-4 text-slate-400 transition-transform ${showHistory ? 'rotate-90' : ''}`} aria-hidden />
          </button>
          {showHistory && <div className="border-t border-slate-200"><ActivityTab bookingId={booking.id} embedded /></div>}
        </section>
      )}
    </div>
  )
}
