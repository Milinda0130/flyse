import { useRef, useState, type FormEvent } from 'react'
import { FileUp } from 'lucide-react'
import { errorMessage, fieldErrors } from '../../lib/api'
import { useIssueTickets } from '../../hooks/mutations'
import { today } from '../../lib/format'
import type { Booking } from '../../lib/types'
import { Button, ErrorBox, Field, Input, Select } from '../../components/ui'

const MAX_FILE = 10 * 1024 * 1024
const ALLOWED = ['application/pdf', 'image/jpeg', 'image/png']
const ACTIVE = ['issued', 'changed', 'used']

/**
 * Record issued tickets with their e-ticket. One file may cover several passengers.
 * The server stores the file and tickets together, or nothing at all.
 */
export default function IssueTicketsDialog({ booking, onDone, onAddPassenger }: { booking: Booking; onDone: () => void; onAddPassenger: () => void }) {
  const passengers = booking.passengers ?? []
  const tickets = booking.tickets ?? []
  const hasTicket = (pid: number) => tickets.some((t) => t.passenger_id === pid && ACTIVE.includes(t.status))
  const eTickets = (booking.documents ?? []).filter((d) => d.document_type === 'e_ticket')

  const [rows, setRows] = useState(() => passengers.map((p) => ({ passenger_id: p.id, name: p.full_name_as_document, include: !hasTicket(p.id), ticket_number: '', airline_pnr: '' })))
  const [issuedAt, setIssuedAt] = useState(today())
  const [source, setSource] = useState<'upload' | 'existing'>('upload')
  const [file, setFile] = useState<File | null>(null)
  const [documentId, setDocumentId] = useState('')
  const [fileError, setFileError] = useState('')
  const [progress, setProgress] = useState<number | null>(null)
  const input = useRef<HTMLInputElement>(null)

  const selected = rows.filter((r) => r.include)
  const willCoverAll = passengers.length >= booking.passenger_count && passengers.every((p) => hasTicket(p.id) || selected.some((r) => r.passenger_id === p.id))
  const canMarkTicketed = (booking.allowed_transitions ?? []).includes('ticketed')
  const [markTicketed, setMarkTicketed] = useState(true)

  const mutation = useIssueTickets()
  const errors = fieldErrors(mutation.error)

  if (!passengers.length) {
    return (
      <div className="space-y-3 text-sm">
        <p>Add the passengers first. Each ticket belongs to a passenger.</p>
        <div className="flex justify-end"><Button onClick={onAddPassenger}>Add passenger</Button></div>
      </div>
    )
  }

  const pick = (f: File | null) => {
    setFileError('')
    if (f && !ALLOWED.includes(f.type)) return setFileError('Only PDF, JPG or PNG files are allowed.')
    if (f && f.size > MAX_FILE) return setFileError('The file is larger than 10 MB.')
    setFile(f)
  }

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (source === 'upload' && !file) return setFileError('Choose the issued e-ticket file.')
    const form = new FormData()
    selected.forEach((r, i) => {
      form.append(`tickets[${i}][passenger_id]`, String(r.passenger_id))
      form.append(`tickets[${i}][ticket_number]`, r.ticket_number)
      if (r.airline_pnr) form.append(`tickets[${i}][airline_pnr]`, r.airline_pnr)
      form.append(`tickets[${i}][issued_at]`, issuedAt)
    })
    if (source === 'upload' && file) form.append('e_ticket', file)
    if (source === 'existing') form.append('document_id', documentId)
    form.append('mark_ticketed', canMarkTicketed && willCoverAll && markTicketed ? '1' : '0')
    setProgress(0)
    mutation.mutate({ bookingId: booking.id, form, onProgress: setProgress }, { onSuccess: onDone, onSettled: () => setProgress(null) })
  }

  const statusProblems = errors.status ?? []

  return (
    <form onSubmit={submit} className="space-y-4">
      {mutation.isError && (
        <ErrorBox message={statusProblems.length ? `Nothing was saved. ${statusProblems.join(' ')}` : `Nothing was saved. ${errorMessage(mutation.error)}`} />
      )}

      <fieldset className="space-y-2">
        <legend className="label">Passengers and ticket numbers</legend>
        {rows.map((r, i) => {
          const already = hasTicket(r.passenger_id)
          return (
            <div key={r.passenger_id} className={`grid gap-2 rounded-md border p-2.5 sm:grid-cols-[minmax(0,1fr)_12rem_8rem] sm:items-center ${r.include ? 'border-slate-300' : 'border-slate-200 opacity-70'}`}>
              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={r.include} onChange={(e) => setRows((all) => all.map((x, j) => (j === i ? { ...x, include: e.target.checked } : x)))} />
                <span className="font-medium text-slate-900">{r.name}</span>
                {already && <span className="text-xs text-slate-500">(already ticketed)</span>}
              </label>
              <Input aria-label={`Ticket number for ${r.name}`} placeholder="Ticket number" className="font-mono" required={r.include} disabled={!r.include}
                value={r.ticket_number} onChange={(e) => setRows((all) => all.map((x, j) => (j === i ? { ...x, ticket_number: e.target.value } : x)))} />
              <Input aria-label={`Airline PNR for ${r.name}`} placeholder={booking.pnr ?? 'PNR'} className="font-mono" disabled={!r.include}
                value={r.airline_pnr} onChange={(e) => setRows((all) => all.map((x, j) => (j === i ? { ...x, airline_pnr: e.target.value.toUpperCase() } : x)))} />
              {errors[`tickets.${selected.indexOf(r)}.ticket_number`] && <span className="text-xs text-red-600 sm:col-span-3">{errors[`tickets.${selected.indexOf(r)}.ticket_number`][0]}</span>}
            </div>
          )
        })}
        <p className="text-xs text-slate-500">Leave PNR empty to use the booking PNR{booking.pnr ? ` (${booking.pnr})` : ''}. Passengers added later can be ticketed separately.</p>
      </fieldset>

      <Field label="Issued on" className="max-w-xs"><Input type="date" value={issuedAt} onChange={(e) => setIssuedAt(e.target.value)} /></Field>

      <fieldset className="space-y-2">
        <legend className="label">Issued e-ticket <span className="text-red-600">*</span></legend>
        <div className="flex flex-wrap gap-4 text-sm">
          <label className="flex items-center gap-2"><input type="radio" checked={source === 'upload'} onChange={() => setSource('upload')} /> Upload the e-ticket file</label>
          {eTickets.length > 0 && <label className="flex items-center gap-2"><input type="radio" checked={source === 'existing'} onChange={() => setSource('existing')} /> Use an e-ticket already attached</label>}
        </div>
        {source === 'upload' ? (
          <div className="flex flex-wrap items-center gap-3 rounded-md border border-dashed border-slate-300 bg-slate-50 p-3">
            <input ref={input} type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" className="hidden" onChange={(e) => pick(e.target.files?.[0] ?? null)} />
            <Button variant="secondary" onClick={() => input.current?.click()}><FileUp className="h-4 w-4" aria-hidden /> Choose file</Button>
            <span className="min-w-0 truncate text-sm text-slate-700">{file ? file.name : 'PDF, JPG or PNG, max 10 MB. One file can cover all passengers.'}</span>
          </div>
        ) : (
          <Select value={documentId} required onChange={(e) => setDocumentId(e.target.value)} placeholder="Choose a document" options={eTickets.map((d) => ({ value: String(d.id), label: d.original_name }))} />
        )}
        {(fileError || errors.e_ticket?.[0]) && <p className="text-xs text-red-600">{fileError || errors.e_ticket?.[0]}</p>}
      </fieldset>

      {canMarkTicketed && (
        willCoverAll ? (
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={markTicketed} onChange={(e) => setMarkTicketed(e.target.checked)} /> Also mark the booking as <strong>Ticketed</strong></label>
        ) : (
          <p className="text-xs text-slate-500">The booking can be marked Ticketed once every passenger ({booking.passenger_count}) has a ticket.</p>
        )
      )}

      <div className="flex justify-end gap-2">
        <Button variant="secondary" onClick={onDone}>Cancel</Button>
        <Button type="submit" loading={mutation.isPending} disabled={!selected.length}>
          {mutation.isPending && progress !== null ? `Uploading ${progress}%` : `Save ${selected.length} ticket${selected.length === 1 ? '' : 's'}`}
        </Button>
      </div>
    </form>
  )
}
