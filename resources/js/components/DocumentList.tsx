import { useRef, useState } from 'react'
import { errorMessage } from '../lib/api'
import { useArchiveDocument, useDownload, useUploadDocuments, useVerifyDocument } from '../hooks/mutations'
import { useAuth } from '../auth/AuthContext'
import { useMeta } from '../hooks/queries'
import { dateTime, fileSize, labelFor } from '../lib/format'
import type { DocumentFile, Flight, Issue, Passenger } from '../lib/types'
import { Button, Empty, ErrorBox, Field, Input, Select } from './ui'
import { DocumentKindBadge, VerificationBadge } from './Badges'

const MAX_FILE = 10 * 1024 * 1024
const MAX_TOTAL = 30 * 1024 * 1024
const ALLOWED = ['application/pdf', 'image/jpeg', 'image/png']

/** Upload panel + list of private documents. Files are fetched through the authorised API only. */
export default function DocumentList({ bookingId, documents, passengers = [], flights = [], issues = [], issueId, defaultType = 'e_ticket' }: {
  bookingId: number; documents: DocumentFile[]; passengers?: Passenger[]; flights?: Flight[]; issues?: Issue[]; issueId?: number; defaultType?: string
}) {
  const { can } = useAuth()
  const { data: meta } = useMeta()
  const input = useRef<HTMLInputElement>(null)
  const [type, setType] = useState(defaultType)
  const [passengerId, setPassengerId] = useState('')
  const [flightId, setFlightId] = useState('')
  const [notes, setNotes] = useState('')
  const [source, setSource] = useState('')
  const [sourceDescription, setSourceDescription] = useState('')
  const alternative = type === 'alternative_ticket'
  const [progress, setProgress] = useState<number | null>(null)
  const [error, setError] = useState('')
  const uploadDocuments = useUploadDocuments()
  const archiveDocument = useArchiveDocument()
  const verify = useVerifyDocument()
  const download = useDownload()
  const showError = (err: unknown) => setError(errorMessage(err))

  const upload = (files: FileList | null) => {
    if (!files?.length) return
    setError('')
    const list = Array.from(files)
    const bad = list.find((f) => !ALLOWED.includes(f.type))
    if (bad) return setError(`${bad.name}: only PDF, JPG and PNG files are allowed.`)
    if (alternative && list.some((f) => f.type !== 'application/pdf')) return setError('An alternative ticket must be a PDF file.')
    if (alternative && !source) return setError('Choose where the alternative ticket PDF came from.')
    const big = list.find((f) => f.size > MAX_FILE)
    if (big) return setError(`${big.name} is larger than 10 MB.`)
    if (list.reduce((s, f) => s + f.size, 0) > MAX_TOTAL) return setError('Total upload must be 30 MB or less.')

    const form = new FormData()
    list.forEach((f) => form.append('files[]', f))
    form.append('document_type', type)
    if (passengerId) form.append('passenger_id', passengerId)
    if (flightId) form.append('booking_flight_id', flightId)
    if (notes.trim()) form.append('notes', notes.trim())
    if (issueId) form.append('issue_id', String(issueId))
    if (alternative) {
      // Uploaded here it starts "Pending verification"; verify it below or in Email tickets.
      form.append('source_type', source)
      if (sourceDescription.trim()) form.append('source_description', sourceDescription.trim())
    }
    setProgress(0)
    uploadDocuments.mutate({ bookingId, form, onProgress: setProgress }, {
      onError: showError,
      onSettled: () => {
        setProgress(null)
        if (input.current) input.current.value = ''
      },
    })
  }

  const archive = (d: DocumentFile) => {
    if (!confirm(`Archive ${d.original_name}? It will be hidden but kept for recovery, and the action is logged.`)) return
    archiveDocument.mutate({ id: d.id }, { onError: showError })
  }

  const open = (d: DocumentFile, inline: boolean) =>
    download.mutate({ url: `/documents/${d.id}/download`, filename: d.original_name, inline }, { onError: showError })

  return (
    <div className="space-y-3">
      {error && <ErrorBox message={error} />}
      {can('edit-records') && (
        <div className="no-print grid items-end gap-3 rounded-md border border-dashed border-slate-300 bg-slate-50 p-3 [grid-template-columns:repeat(auto-fill,minmax(11rem,1fr))]">
          <Field label="Document type" className="min-w-0"><Select value={type} onChange={(e) => setType(e.target.value)} options={(meta?.document_types ?? []).filter((t) => t.value !== 'expense_receipt')} /></Field>
          {passengers.length > 0 && (
            <Field label="Passenger" className="min-w-0">
              <Select value={passengerId} onChange={(e) => setPassengerId(e.target.value)} placeholder="Whole booking" options={passengers.map((p) => ({ value: String(p.id), label: p.full_name_as_document }))} />
            </Field>
          )}
          {flights.length > 0 && (
            <Field label="Flight segment" className="min-w-0">
              <Select value={flightId} onChange={(e) => setFlightId(e.target.value)} placeholder="Not specific" options={flights.map((f) => ({ value: String(f.id), label: `${f.flight_number} ${f.origin_code}-${f.destination_code}` }))} />
            </Field>
          )}
          {alternative && (
            <>
              <Field label="Document source *" className="min-w-0"><Select value={source} onChange={(e) => setSource(e.target.value)} placeholder="Select…" options={meta?.document_sources ?? []} /></Field>
              <Field label={`Source description${source === 'other_authorized' ? ' *' : ''}`} className="min-w-0"><Input value={sourceDescription} maxLength={255} onChange={(e) => setSourceDescription(e.target.value)} placeholder="Internal only" /></Field>
            </>
          )}
          <Field label="Note (optional)" className="min-w-0"><Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="e.g. Reissued after date change" /></Field>
          <input ref={input} type="file" multiple accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" className="hidden" onChange={(e) => upload(e.target.files)} />
          <Button className="min-w-0" onClick={() => input.current?.click()} loading={uploadDocuments.isPending}>{uploadDocuments.isPending ? `Uploading${progress !== null ? ` ${progress}%` : '…'}` : 'Upload files'}</Button>
          <span className="text-xs text-slate-500 [grid-column:1/-1]">PDF, JPG or PNG · max 10 MB each, 30 MB per upload. Files are private: only signed-in users with access can open them.</span>
        </div>
      )}
      {documents.length === 0 ? <Empty>No documents attached.</Empty> : (
        <ul className="divide-y divide-slate-100 rounded-md border border-slate-200">
          {documents.map((d) => (
            <li key={d.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
              <div className="min-w-0">
                <button type="button" className="link truncate" onClick={() => open(d, true)} title="Open in new tab">{d.original_name}</button>
                {(d.document_type === 'e_ticket' || d.document_type === 'alternative_ticket') && (
                  <div className="my-0.5 flex flex-wrap items-center gap-1.5">
                    <DocumentKindBadge alternative={d.document_type === 'alternative_ticket'} />
                    <VerificationBadge status={d.verification_status} />
                  </div>
                )}
                <div className="text-xs text-slate-500">
                  <span className="font-medium text-slate-700">{labelFor(meta?.document_types, d.document_type)}</span>
                  {d.source_label && ` · ${d.source_label}`}{d.source_description && ` (${d.source_description})`}
                  {d.passenger_id && passengers.length > 0 && ` · ${passengers.find((p) => p.id === d.passenger_id)?.full_name_as_document ?? ''}`}
                  {d.booking_flight_id && flights.length > 0 && ` · ${flights.find((f) => f.id === d.booking_flight_id)?.flight_number ?? ''}`}
                  {d.ticket_numbers && d.ticket_numbers.length > 0 && ` · Tickets ${d.ticket_numbers.join(', ')}`}
                  {d.issue_id && issues.length > 0 && ` · Issue: ${issues.find((i) => i.id === d.issue_id)?.summary ?? ''}`}
                  {' · '}{fileSize(d.size_bytes)} · {d.uploaded_by?.name ?? 'unknown'} · {dateTime(d.created_at)}
                </div>
                {d.verified_by && <div className="text-xs text-slate-500">{d.verification_status === 'rejected' ? 'Rejected' : 'Verified'} by {d.verified_by.name} · {dateTime(d.verified_at)}{d.verification_note && `: ${d.verification_note}`}</div>}
                {d.notes && <div className="text-xs italic text-slate-600">{d.notes}</div>}
              </div>
              <div className="no-print flex flex-wrap gap-1">
                {d.document_type === 'alternative_ticket' && can('verify-documents') && d.verification_status !== 'verified' && (
                  <Button size="sm" variant="secondary" loading={verify.isPending && verify.variables?.id === d.id}
                    onClick={() => confirm(`Mark ${d.original_name} as verified? Only do this after checking it belongs to this booking and is appropriate to send to the customer.`) && verify.mutate({ id: d.id, status: 'verified' }, { onError: showError })}>Verify</Button>
                )}
                {d.document_type === 'alternative_ticket' && can('verify-documents') && d.verification_status !== 'rejected' && (
                  <Button size="sm" variant="ghost" className="text-red-600" onClick={() => {
                    const note = prompt(`Why is ${d.original_name} being rejected?`)
                    if (note?.trim()) verify.mutate({ id: d.id, status: 'rejected', note: note.trim() }, { onError: showError })
                  }}>Reject</Button>
                )}
                <Button size="sm" variant="secondary" onClick={() => open(d, false)}>Download</Button>
                {can('delete-documents') && <Button size="sm" variant="ghost" className="text-red-600" onClick={() => archive(d)}>Archive</Button>}
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
