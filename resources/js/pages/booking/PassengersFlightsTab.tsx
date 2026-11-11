import { useState } from 'react'
import { FileText, Mail, Plane, Plus, Ticket as TicketIcon, Users } from 'lucide-react'
import { errorMessage } from '../../lib/api'
import { useAuth } from '../../auth/AuthContext'
import { useMeta } from '../../hooks/queries'
import { useDeleteFlight, useDeletePassenger, useDeleteTicket, useDownload } from '../../hooks/mutations'
import { airportLabel } from '../../lib/airports'
import { date, labelFor, localTime } from '../../lib/format'
import type { Booking, Flight, Passenger, Ticket } from '../../lib/types'
import { Button, Card, Modal, RouteCodes } from '../../components/ui'
import { FlightEditor, PassengerEditor, TicketEditor } from './editors'

const flightTone: Record<string, string> = {
  scheduled: 'text-slate-600', delayed: 'text-amber-700', changed: 'text-violet-700', cancelled: 'text-red-700', flown: 'text-slate-400',
}
const ACTIVE = ['issued', 'changed', 'used']

/** Requested trip, confirmed itinerary, and passengers with their tickets, in one place. */
export default function PassengersFlightsTab({ booking, onIssueTickets, onEmailTickets }: { booking: Booking; onIssueTickets: () => void; onEmailTickets: () => void }) {
  const { can } = useAuth()
  const { data: meta } = useMeta()
  const editable = can('edit-records') && booking.status !== 'cancelled'
  const deleteFlight = useDeleteFlight()
  const deletePassenger = useDeletePassenger()
  const deleteTicket = useDeleteTicket()
  const download = useDownload()
  const [flightEdit, setFlightEdit] = useState<Flight | 'new' | null>(null)
  const [passengerEdit, setPassengerEdit] = useState<Passenger | 'new' | null>(null)
  const [ticketEdit, setTicketEdit] = useState<Ticket | null>(null)
  const flights = booking.flights ?? []
  const passengers = booking.passengers ?? []
  const tickets = booking.tickets ?? []
  const fail = (err: unknown) => alert(errorMessage(err))

  const removeFlight = (f: Flight) => {
    if (!confirm(`Remove segment ${f.flight_number} ${f.origin_code}-${f.destination_code}? The old values stay in the history. If the airline cancelled it, edit it and set the status to Cancelled instead.`)) return
    deleteFlight.mutate({ id: f.id }, { onError: fail })
  }
  const removePassenger = (p: Passenger) => {
    if (!confirm(`Remove passenger ${p.full_name_as_document}?`)) return
    deletePassenger.mutate({ id: p.id }, { onError: fail })
  }
  const removeTicket = (t: Ticket) => {
    if (!confirm(`Delete ticket record ${t.ticket_number}? Prefer changing its status (voided/refunded) to keep the history visible.`)) return
    deleteTicket.mutate({ id: t.id }, { onError: fail })
  }

  return (
    <div className="space-y-4">
      {/* What the customer asked for: kept separate from what was actually booked. */}
      <section className="rounded-lg border border-dashed border-slate-300 bg-surface/60 p-3 text-sm">
        <span className="font-medium text-slate-700">Requested trip:</span>{' '}
        {airportLabel(booking.origin)} → {airportLabel(booking.destination)}
        {' · '}{labelFor(meta?.trip_types, booking.trip_type)}
        {booking.departure_date && <> · {date(booking.departure_date)}</>}
        {booking.trip_type === 'round_trip' && booking.return_date && <> – {date(booking.return_date)}</>}
        {booking.cabin_class && <> · {labelFor(meta?.cabin_classes, booking.cabin_class)}</>}
        {' · '}{booking.passenger_count} passenger{booking.passenger_count === 1 ? '' : 's'}
      </section>

      <Card
        title={<span className="flex items-center gap-2"><Plane className="h-4 w-4 text-brand-600" aria-hidden /> Confirmed itinerary</span>}
        actions={editable && <Button size="sm" variant="secondary" onClick={() => setFlightEdit('new')}><Plus className="h-4 w-4" aria-hidden /> Add flight</Button>}
        bodyClass="overflow-x-auto"
      >
        {flights.length === 0 ? (
          <div className="px-4 py-6 text-center text-sm text-slate-500">
            Flight details have not been added yet.
            {editable && <div className="mt-2"><Button size="sm" onClick={() => setFlightEdit('new')}>Add flight details</Button></div>}
          </div>
        ) : (
          <table className="table-base">
            <thead><tr><th>Flight</th><th>Route</th><th>Departs (local)</th><th>Arrives (local)</th><th>Cabin / baggage</th><th>Status</th>{editable && <th />}</tr></thead>
            <tbody className="divide-y divide-slate-100">
              {flights.map((f) => (
                <tr key={f.id} className={f.status === 'cancelled' ? 'bg-red-50/50' : ''}>
                  <td className="whitespace-nowrap font-medium">{f.flight_number}<div className="text-xs font-normal text-slate-500">{f.airline}</div></td>
                  <td><RouteCodes from={f.origin_code} to={f.destination_code} /></td>
                  <td className="whitespace-nowrap">{localTime(f.departure_at)}<div className="text-xs text-slate-500">{f.origin_timezone}</div></td>
                  <td className="whitespace-nowrap">{localTime(f.arrival_at)}<div className="text-xs text-slate-500">{f.destination_timezone}</div></td>
                  <td>{labelFor(meta?.cabin_classes, f.cabin_class)}<div className="text-xs text-slate-500">{f.baggage_allowance ?? 'Baggage not recorded'}</div></td>
                  <td className={`font-medium ${flightTone[f.status] ?? ''}`}>{labelFor(meta?.flight_statuses, f.status)}{f.notes && <div className="text-xs font-normal text-slate-500">{f.notes}</div>}</td>
                  {editable && (
                    <td className="no-print whitespace-nowrap text-right">
                      <Button size="sm" variant="ghost" onClick={() => setFlightEdit(f)}>Edit</Button>
                      <Button size="sm" variant="ghost" className="text-red-600" onClick={() => removeFlight(f)}>Remove</Button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>

      <Card
        title={<span className="flex items-center gap-2"><Users className="h-4 w-4 text-brand-600" aria-hidden /> Passengers & tickets <span className="font-normal text-slate-500">({passengers.length} of {booking.passenger_count} named)</span></span>}
        actions={editable && <>
          <Button size="sm" variant="secondary" onClick={() => setPassengerEdit('new')}><Plus className="h-4 w-4" aria-hidden /> Add passenger</Button>
          {booking.status !== 'inquiry' && <Button size="sm" onClick={onIssueTickets}><TicketIcon className="h-4 w-4" aria-hidden /> Issue tickets</Button>}
          {can('email-tickets') && tickets.some((t) => t.e_ticket) && <Button size="sm" variant="secondary" onClick={onEmailTickets}><Mail className="h-4 w-4" aria-hidden /> Email tickets</Button>}
        </>}
      >
        {passengers.length === 0 ? (
          <p className="py-3 text-center text-sm text-slate-500">No passenger names yet. Passport details are optional and only needed when the airline asks.</p>
        ) : (
          <div className="divide-y divide-slate-100">
            {passengers.map((p) => {
              const own = tickets.filter((t) => t.passenger_id === p.id)
              return (
                <div key={p.id} className="py-3 first:pt-0 last:pb-0">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                      <div className="font-medium text-slate-900">{p.full_name_as_document} <span className="text-xs font-normal text-slate-500">{labelFor(meta?.passenger_types, p.passenger_type)}</span></div>
                      <div className="mt-0.5 text-xs text-slate-500">
                        {[p.nationality, p.date_of_birth && `DOB ${date(p.date_of_birth)}`, p.passport_number && `Passport ${p.passport_number}`, p.passport_expiry && `exp. ${date(p.passport_expiry)}`, p.phone].filter(Boolean).join(' · ') || 'No identity details stored'}
                      </div>
                    </div>
                    {editable && (
                      <div className="no-print flex gap-1">
                        <Button size="sm" variant="ghost" onClick={() => setPassengerEdit(p)}>Edit</Button>
                        <Button size="sm" variant="ghost" className="text-red-600" onClick={() => removePassenger(p)}>Remove</Button>
                      </div>
                    )}
                  </div>
                  {own.length > 0 ? (
                    <table className="table-base mt-2">
                      <thead><tr><th>Ticket number</th><th>PNR</th><th>Status</th><th>Issued</th><th>E-ticket</th>{editable && <th />}</tr></thead>
                      <tbody className="divide-y divide-slate-100">
                        {own.map((t) => (
                          <tr key={t.id} className={ACTIVE.includes(t.status) ? '' : 'text-slate-400'}>
                            <td className="font-mono">{t.ticket_number}</td>
                            <td className="font-mono">{t.airline_pnr ?? booking.pnr ?? '—'}</td>
                            <td>{labelFor(meta?.ticket_statuses, t.status)}</td>
                            <td>{date(t.issued_at)}</td>
                            <td>
                              {t.e_ticket
                                ? <button type="button" className="link inline-flex items-center gap-1" onClick={() => download.mutate({ url: `/documents/${t.e_ticket!.id}/download`, filename: t.e_ticket!.original_name, inline: true }, { onError: fail })}><FileText className="h-3.5 w-3.5" aria-hidden />{t.e_ticket.original_name}</button>
                                : <span className="text-amber-700">Missing</span>}
                            </td>
                            {editable && <td className="no-print whitespace-nowrap text-right">
                              <Button size="sm" variant="ghost" onClick={() => setTicketEdit(t)}>Edit</Button>
                              <Button size="sm" variant="ghost" className="text-red-600" onClick={() => removeTicket(t)}>Delete</Button>
                            </td>}
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  ) : <div className="mt-1 text-xs text-slate-500">No ticket issued yet.</div>}
                </div>
              )
            })}
          </div>
        )}
      </Card>

      <Modal open={flightEdit !== null} title={flightEdit === 'new' ? 'Add flight segment' : 'Edit flight segment'} onClose={() => setFlightEdit(null)} wide>
        {flightEdit && <FlightEditor booking={booking} flight={flightEdit === 'new' ? null : flightEdit} onDone={() => setFlightEdit(null)} />}
      </Modal>
      <Modal open={passengerEdit !== null} title={passengerEdit === 'new' ? 'Add passenger' : 'Edit passenger'} onClose={() => setPassengerEdit(null)} wide>
        {passengerEdit && <PassengerEditor booking={booking} passenger={passengerEdit === 'new' ? null : passengerEdit} onDone={() => setPassengerEdit(null)} />}
      </Modal>
      <Modal open={ticketEdit !== null} title="Edit ticket" onClose={() => setTicketEdit(null)}>
        {ticketEdit && <TicketEditor booking={booking} ticket={ticketEdit} onDone={() => setTicketEdit(null)} />}
      </Modal>
    </div>
  )
}
