import { useState } from 'react'
import { CircleAlert, CircleCheck, Clock, Download, Eye, Mail, Send } from 'lucide-react'
import { downloadFile, errorMessage, fieldErrors } from '../lib/api'
import { useGenerateTickets, usePreviewGeneratedTicket, useSendTicketEmail } from '../hooks/mutations'
import type { Booking, BookingEmail, DocumentFile } from '../lib/types'
import { Button, ErrorBox, Field, Input } from './ui'

const newKey = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`)
const clean = (v: string) => v.toUpperCase().replace(/\s+/g, '')

/**
 * The simple way to give a customer their ticket: one window with the codes, the passengers and two
 * buttons, Download PDF and Email to customer. The PDF is the agency's e-ticket built from this
 * booking's passengers and flights (nothing is invented; the booking itself is not changed).
 */
export default function TicketPdfDialog({ booking, onClose }: { booking: Booking; onClose: () => void }) {
  const passengers = booking.passengers ?? []
  const [codes, setCodes] = useState({ reservation_code: booking.pnr ?? '', airline_pnr: booking.pnr ?? '' })
  const [ids, setIds] = useState<number[]>(passengers.map((p) => p.id))
  const [to, setTo] = useState(booking.customer?.email ?? '')
  // PDFs already created for exactly these choices (so Download then Email does not create them twice).
  const [made, setMade] = useState<{ sig: string; docs: DocumentFile[] } | null>(null)
  const [busy, setBusy] = useState<'download' | 'email' | null>(null)
  const [error, setError] = useState('')
  const [sent, setSent] = useState<BookingEmail | null>(null)
  const generate = useGenerateTickets()
  const preview = usePreviewGeneratedTicket()
  const send = useSendTicketEmail()

  // What is missing before a ticket PDF can be made (checked again by the server).
  const missing = [
    booking.status === 'inquiry' && 'This booking is still an inquiry. Confirm the reservation first.',
    booking.status === 'cancelled' && 'This booking is cancelled.',
    passengers.length === 0 && 'Add the passengers to the booking.',
    (booking.flights ?? []).length === 0 && 'Add the flights to the booking.',
  ].filter(Boolean) as string[]
  const codeOk = (v: string) => /^[A-Z0-9]{4,10}$/.test(clean(v))
  const ready = missing.length === 0 && ids.length > 0 && codeOk(codes.reservation_code) && codeOk(codes.airline_pnr)
  const sig = JSON.stringify([clean(codes.reservation_code), clean(codes.airline_pnr), [...ids].sort()])
  const payloadCodes = { reservation_code: clean(codes.reservation_code), airline_pnr: clean(codes.airline_pnr) }

  const create = async (): Promise<DocumentFile[]> => {
    if (made?.sig === sig) return made.docs
    const docs = await generate.mutateAsync({ bookingId: booking.id, passengerIds: ids, codes: payloadCodes })
    setMade({ sig, docs })
    return docs
  }
  const fail = (e: unknown) => setError([...Object.values(fieldErrors(e)).flat(), ...(Object.keys(fieldErrors(e)).length ? [] : [errorMessage(e)])].join(' '))

  const doDownload = async () => {
    setError('')
    setBusy('download')
    try {
      for (const d of await create()) await downloadFile(`/documents/${d.id}/download`, d.original_name)
    } catch (e) { fail(e) } finally { setBusy(null) }
  }

  const doEmail = async () => {
    setError('')
    const address = to.trim()
    if (!address) return setError('Enter the email address to send the ticket to.')
    if (!confirm(`Send ${ids.length === 1 ? 'the ticket PDF' : `${ids.length} ticket PDFs`} to ${address}?`)) return
    setBusy('email')
    try {
      const docs = await create()
      const email = await send.mutateAsync({
        bookingId: booking.id, email_type: 'alternative', document_ids: docs.map((d) => d.id),
        recipient: address.toLowerCase() === (booking.customer?.email ?? '').toLowerCase() ? null : address,
        idempotency_key: newKey(), confirm_resend: true, confirm_reviewed: true, official_pending: false,
      })
      setSent(email)
    } catch (e) { fail(e) } finally { setBusy(null) }
  }

  if (sent) {
    return (
      <div className="space-y-4">
        {sent.status === 'failed' ? (
          <div className="rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-800" role="alert">
            <p className="flex items-center gap-2 font-semibold"><CircleAlert className="h-4 w-4" aria-hidden /> The email was not sent.</p>
            <p className="mt-1">{sent.failure_reason}</p>
            <p className="mt-1 text-xs">You can retry from Documents &amp; Issues → Ticket emails.</p>
          </div>
        ) : sent.status === 'sent' ? (
          <div className="rounded-md border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-800" role="status">
            <p className="flex items-center gap-2 font-semibold"><CircleCheck className="h-4 w-4" aria-hidden /> Ticket sent to {sent.recipient}</p>
            <p className="mt-1">{sent.attachment_count} PDF{sent.attachment_count === 1 ? '' : 's'} attached.{!sent.delivered_by_provider && ' (Development mode: logged, not delivered.)'}</p>
          </div>
        ) : (
          <div className="rounded-md border border-sky-300 bg-sky-50 p-4 text-sm text-sky-900" role="status">
            <p className="flex items-center gap-2 font-semibold"><Clock className="h-4 w-4" aria-hidden /> Email queued for {sent.recipient}</p>
            <p className="mt-1">It will be sent in a moment (see Documents &amp; Issues → Ticket emails).</p>
          </div>
        )}
        <div className="flex justify-end"><Button variant="secondary" onClick={onClose}>Close</Button></div>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      {missing.length > 0 && (
        <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" role="alert">
          <p className="font-semibold">Before you can create the ticket PDF:</p>
          <ul className="mt-1 list-disc pl-5">{missing.map((m) => <li key={m}>{m}</li>)}</ul>
        </div>
      )}
      {error && <ErrorBox message={error} />}

      <div className="grid grid-cols-2 gap-3">
        <Field label="Reservation code" error={codes.reservation_code && !codeOk(codes.reservation_code) ? '4–10 letters or digits' : undefined}>
          <Input className="font-mono uppercase" maxLength={12} value={codes.reservation_code} onChange={(e) => { setCodes({ ...codes, reservation_code: e.target.value }); setMade(null) }} placeholder="9VKNT2" />
        </Field>
        <Field label="Airline PNR" error={codes.airline_pnr && !codeOk(codes.airline_pnr) ? '4–10 letters or digits' : undefined}>
          <Input className="font-mono uppercase" maxLength={12} value={codes.airline_pnr} onChange={(e) => { setCodes({ ...codes, airline_pnr: e.target.value }); setMade(null) }} placeholder="9VKNT2" />
        </Field>
      </div>

      {passengers.length > 0 && (
        <fieldset>
          <legend className="label">Passengers (one PDF each)</legend>
          <ul className="space-y-1.5">
            {passengers.map((p) => (
              <li key={p.id} className="flex items-center justify-between gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm">
                <label className="flex min-w-0 items-center gap-2">
                  <input type="checkbox" checked={ids.includes(p.id)} onChange={() => { setIds((cur) => (cur.includes(p.id) ? cur.filter((x) => x !== p.id) : [...cur, p.id])); setMade(null) }} />
                  <span className="truncate font-medium text-slate-900">{p.full_name_as_document}</span>
                </label>
                <Button size="sm" variant="ghost" disabled={!ready} loading={preview.isPending && preview.variables?.passengerId === p.id}
                  onClick={() => preview.mutate({ bookingId: booking.id, passengerId: p.id, codes: payloadCodes }, { onError: fail })}>
                  <Eye className="h-4 w-4" aria-hidden /> Preview
                </Button>
              </li>
            ))}
          </ul>
        </fieldset>
      )}

      <Field label="Customer email" hint={booking.customer?.email ? 'Change it here to send to another address (the customer record is not changed).' : 'The customer has no saved email; type one to send.'}>
        <div className="relative">
          <Mail className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden />
          <Input type="email" className="pl-9" value={to} onChange={(e) => setTo(e.target.value)} placeholder="customer@example.com" />
        </div>
      </Field>

      <div className="flex flex-col-reverse gap-2 border-t border-slate-200 pt-4 sm:flex-row sm:justify-end">
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button variant="secondary" disabled={!ready || busy !== null} loading={busy === 'download'} onClick={doDownload}><Download className="h-4 w-4" aria-hidden /> Download PDF</Button>
        <Button disabled={!ready || busy !== null || !to.trim()} loading={busy === 'email'} onClick={doEmail}><Send className="h-4 w-4" aria-hidden /> Email to customer</Button>
      </div>
      <p className="text-xs text-slate-500">The PDF shows your logo and details, the passenger, both codes and the flights of this booking. It is saved in the booking's documents and every email is listed under Ticket emails.</p>
    </div>
  )
}
