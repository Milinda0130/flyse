import { useMemo, useRef, useState } from 'react'
import { ArrowLeft, CircleAlert, CircleCheck, Clock, Eye, FilePlus2, FileText, Mail, Plus, Send, ShieldAlert, Ticket, Upload } from 'lucide-react'
import { useAuth } from '../auth/AuthContext'
import { errorMessage, fieldErrors } from '../lib/api'
import { useMeta, useTicketEmail } from '../hooks/queries'
import { useDownload, useGenerateTickets, usePreviewGeneratedTicket, usePreviewTicketEmail, useRetryTicketEmail, useSendTicketEmail, useUploadDocuments, useVerifyDocument } from '../hooks/mutations'
import { dateTime, fileSize } from '../lib/format'
import type { Booking, BookingEmail, EmailableDocument, EmailType, TicketEmailInfo, TicketEmailPreview } from '../lib/types'
import { Button, ErrorBox, Field, Input, Loading, Select } from './ui'
import { DocumentKindBadge, VerificationBadge } from './Badges'

const newKey = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`)

export const ALTERNATIVE_EXPLANATION = 'The official company ticket document is not currently available. You can upload or select another relevant ticket PDF and send it to the customer after verifying the document.'
const ALTERNATIVE_WARNING = 'This document is not classified as an official company-received ticket. Confirm that you have reviewed the document and verified the recipient before sending.'

/** Status pill with icon + text (never colour alone). "Sent" means accepted by the mail server, not confirmed delivery. */
export function EmailStatusPill({ email }: { email: BookingEmail }) {
  const map = {
    queued: { cls: 'bg-sky-50 text-sky-800 ring-sky-300', icon: <Clock className="h-3.5 w-3.5" aria-hidden />, text: 'Queued' },
    sending: { cls: 'bg-sky-50 text-sky-800 ring-sky-300', icon: <Clock className="h-3.5 w-3.5" aria-hidden />, text: 'Sending' },
    sent: { cls: 'bg-emerald-50 text-emerald-800 ring-emerald-300', icon: <CircleCheck className="h-3.5 w-3.5" aria-hidden />, text: email.delivered_by_provider ? 'Sent' : 'Sent (logged only)' },
    failed: { cls: 'bg-red-50 text-red-700 ring-red-300', icon: <CircleAlert className="h-3.5 w-3.5" aria-hidden />, text: 'Failed' },
  }[email.status]
  return (
    <span className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${map.cls}`}
      title={email.status === 'sent' ? 'Accepted by the mail server. This does not prove the customer received or opened it.' : undefined}>
      {map.icon}{map.text}
    </span>
  )
}

/**
 * Email ticket PDFs to the customer. Two workflows, chosen first:
 *  - Official: e-ticket PDFs the agency received;
 *  - Alternative: an uploaded / customer-provided PDF, sent after a person verified it.
 * Both preview the real email before sending and report Queued / Sent / Failed honestly.
 */
export default function EmailTicketsDialog({ booking, onClose, initialMode }: { booking: Booking; onClose: () => void; initialMode?: EmailType }) {
  const { data: info, isLoading, error } = useTicketEmail(booking.id)
  const [mode, setMode] = useState<EmailType | null>(initialMode ?? null)

  if (isLoading) return <Loading />
  if (error || !info) return <ErrorBox message={errorMessage(error)} />
  if (!mode) return <ModeChooser info={info} onChoose={setMode} onClose={onClose} />

  return <Composer key={mode} mode={mode} booking={booking} info={info} onBack={() => setMode(null)} onClose={onClose} />
}

// ---------------------------------------------------------------- 1. choose the workflow

function ModeChooser({ info, onChoose, onClose }: { info: TicketEmailInfo; onChoose: (m: EmailType) => void; onClose: () => void }) {
  const officialCount = info.documents.filter((d) => d.sendable).length
  const altCount = info.alternative_documents.length
  const blocked = info.booking_problem ?? info.mail_problem

  return (
    <div className="space-y-4">
      {blocked && <ErrorBox message={blocked} />}
      <div className="grid gap-3 sm:grid-cols-2">
        <button type="button" disabled={officialCount === 0} onClick={() => onChoose('official')}
          className="group flex flex-col items-start gap-2 rounded-lg border border-slate-300 p-4 text-left transition hover:border-gold hover:bg-gold/5 disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:border-slate-300 disabled:hover:bg-transparent">
          <span className="flex items-center gap-2 font-semibold text-slate-900"><Ticket className="h-5 w-5 text-brand-600" aria-hidden /> Send Official Ticket</span>
          <span className="text-sm text-slate-600">E-ticket PDFs the agency received through its normal process.</span>
          <span className={`text-xs font-medium ${officialCount ? 'text-emerald-700' : 'text-slate-500'}`}>
            {officialCount ? `${officialCount} official PDF${officialCount === 1 ? '' : 's'} available` : 'No official e-ticket PDF has been received yet.'}
          </span>
        </button>
        <button type="button" onClick={() => onChoose('alternative')}
          className="group flex flex-col items-start gap-2 rounded-lg border border-slate-300 p-4 text-left transition hover:border-gold hover:bg-gold/5">
          <span className="flex items-center gap-2 font-semibold text-slate-900"><FileText className="h-5 w-5 text-violet-600" aria-hidden /> Send Alternative Ticket PDF</span>
          <span className="text-sm text-slate-600">{ALTERNATIVE_EXPLANATION}</span>
          <span className="text-xs font-medium text-slate-500">{altCount ? `${altCount} alternative PDF${altCount === 1 ? '' : 's'} on this booking` : 'Create one from the booking, or upload a PDF, in the next step'}</span>
        </button>
      </div>
      <div className="flex justify-end"><Button variant="ghost" onClick={onClose}>Cancel</Button></div>
    </div>
  )
}

// ---------------------------------------------------------------- 2-4. select, preview, send

function Composer({ mode, booking, info, onBack, onClose }: { mode: EmailType; booking: Booking; info: TicketEmailInfo; onBack: () => void; onClose: () => void }) {
  const alternative = mode === 'alternative'
  const { can } = useAuth()
  const [step, setStep] = useState<'edit' | 'preview' | 'result'>('edit')
  const [recipient, setRecipient] = useState<string | null>(null)
  const [selected, setSelected] = useState<number[] | null>(null)
  const [confirmResend, setConfirmResend] = useState(false)
  const [officialPending, setOfficialPending] = useState(false)
  const [reviewed, setReviewed] = useState(false)
  const [adding, setAdding] = useState<'upload' | 'generate' | null>(null)
  const download = useDownload()
  const [key] = useState(newKey)
  const [preview, setPreview] = useState<TicketEmailPreview | null>(null)
  const [sentId, setSentId] = useState<number | null>(null)
  const previewMutation = usePreviewTicketEmail()
  const sendMutation = useSendTicketEmail()
  const retryMutation = useRetryTicketEmail()
  const verify = useVerifyDocument()

  const docs = alternative ? info.alternative_documents : info.documents
  // Official: all readable PDFs preselected. Alternative: nothing is sent unless explicitly chosen.
  const chosen = (selected ?? (alternative ? [] : docs.filter((d) => d.sendable).map((d) => d.id))).filter((id) => docs.some((d) => d.id === id && d.sendable))
  const to = recipient ?? info.customer.email ?? ''
  const chosenDocs = docs.filter((d) => chosen.includes(d.id))
  const totalBytes = chosenDocs.reduce((s, d) => s + d.size_bytes, 0)
  const overLimit = totalBytes > info.limits.max_total_bytes
  const isOverride = to.trim().toLowerCase() !== (info.customer.email ?? '').toLowerCase()
  const sameAsBefore = useMemo(() => {
    const ids = [...chosen].sort().join(',')
    return info.history.find((e) => e.email_type === mode && e.status !== 'failed' && e.recipient.toLowerCase() === to.trim().toLowerCase() && e.attachments.map((a) => a.document_id).sort().join(',') === ids)
  }, [info, chosen, to, mode])

  const previewErrors = fieldErrors(previewMutation.error)
  const sendErrors = fieldErrors(sendMutation.error)
  const result = sentId ? info.history.find((e) => e.id === sentId) ?? sendMutation.data : null
  const blocked = info.booking_problem ?? info.mail_problem
  const toggle = (id: number) => setSelected(chosen.includes(id) ? chosen.filter((x) => x !== id) : [...chosen, id])

  const goPreview = () => previewMutation.mutate(
    { bookingId: booking.id, documentIds: chosen, recipient: to.trim() || null, emailType: mode, officialPending },
    { onSuccess: (p) => { setPreview(p); setReviewed(false); setStep('preview') } },
  )
  const send = () => sendMutation.mutate(
    {
      bookingId: booking.id, email_type: mode, document_ids: chosen, recipient: isOverride ? to.trim() : null, idempotency_key: key,
      confirm_resend: confirmResend || Boolean(sameAsBefore), confirm_reviewed: alternative ? reviewed : undefined, official_pending: alternative ? officialPending : undefined,
    },
    {
      onSuccess: (email) => { setSentId(email.id); setStep('result') },
      onError: (err) => { const f = fieldErrors(err); if (f.confirm_resend || f.document_ids || f.recipient) setStep('edit') },
    },
  )
  const reject = (d: EmailableDocument) => {
    const note = prompt(`Why is ${d.name} being rejected? (kept in the internal history)`)
    if (note?.trim()) verify.mutate({ id: d.id, status: 'rejected', note: note.trim() }, { onError: (e) => alert(errorMessage(e)) })
  }

  // ---------------------------------------------------------------- result
  if (step === 'result' && result) {
    return (
      <div className="space-y-4">
        {result.status === 'failed' ? (
          <div className="rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-800" role="alert">
            <p className="flex items-center gap-2 font-semibold"><CircleAlert className="h-4 w-4" aria-hidden /> The email was not sent.</p>
            <p className="mt-1">{result.failure_reason}</p>
            {retryMutation.isError && <p className="mt-1">{errorMessage(retryMutation.error)}</p>}
            <Button className="mt-3" loading={retryMutation.isPending} onClick={() => retryMutation.mutate({ id: result.id, bookingId: booking.id })}>Retry</Button>
          </div>
        ) : result.status === 'sent' ? (
          <div className="rounded-md border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-800" role="status">
            <p className="flex items-center gap-2 font-semibold"><CircleCheck className="h-4 w-4" aria-hidden /> Sent to {result.recipient}</p>
            <p className="mt-1">{result.attachment_count} PDF{result.attachment_count === 1 ? '' : 's'} attached ({fileSize(result.total_bytes)}) · {result.email_type_label}.</p>
            {result.delivered_by_provider ? (
              <p className="mt-1 text-xs text-emerald-900/80">The mail server accepted the message. This does not confirm the customer has received or opened it.</p>
            ) : (
              <p className="mt-2 text-amber-800">Development mail driver “{result.mailer}”: the message was written to the application log and was <strong>not delivered</strong>. Configure MAIL_* settings to send real email.</p>
            )}
          </div>
        ) : (
          <div className="rounded-md border border-sky-300 bg-sky-50 p-4 text-sm text-sky-900" role="status">
            <p className="flex items-center gap-2 font-semibold"><Clock className="h-4 w-4" aria-hidden /> Email queued for {result.recipient}, not sent yet</p>
            <p className="mt-1">The queue worker will send it in a moment. This updates automatically; you can close this window. If it stays queued, the queue worker is not running.</p>
          </div>
        )}
        <div className="flex justify-end"><Button variant="secondary" onClick={onClose}>Close</Button></div>
      </div>
    )
  }

  // ---------------------------------------------------------------- preview
  if (step === 'preview' && preview) {
    return (
      <div className="space-y-4">
        {sendMutation.isError && <ErrorBox message={errorMessage(sendMutation.error)} />}
        {alternative && <AltWarning />}
        <dl className="grid gap-x-6 gap-y-2 rounded-md border border-slate-200 p-3 text-sm sm:grid-cols-2">
          <div><dt className="text-xs uppercase tracking-wide text-slate-500">Customer</dt><dd className="font-medium text-slate-900">{info.customer.name}</dd></div>
          <div><dt className="text-xs uppercase tracking-wide text-slate-500">Send to</dt><dd className="break-all font-medium text-slate-900">{preview.recipient}{preview.recipient_is_override && <span className="ml-1 text-xs font-normal text-amber-700">(not the saved address)</span>}</dd></div>
          <div><dt className="text-xs uppercase tracking-wide text-slate-500">Booking</dt><dd className="font-medium text-slate-900">{booking.booking_code}</dd></div>
          <div><dt className="text-xs uppercase tracking-wide text-slate-500">Contains</dt><dd><DocumentKindBadge alternative={alternative} /></dd></div>
          <div className="sm:col-span-2"><dt className="text-xs uppercase tracking-wide text-slate-500">Subject</dt><dd className="font-medium text-slate-900">{preview.subject}</dd></div>
          <div className="sm:col-span-2">
            <dt className="text-xs uppercase tracking-wide text-slate-500">{preview.attachments.length} PDF attachment{preview.attachments.length === 1 ? '' : 's'} · {fileSize(preview.total_bytes)}</dt>
            <dd className="mt-1 space-y-1">
              {preview.attachments.map((a) => {
                const d = docs.find((x) => x.id === a.document_id)
                return (
                  <div key={a.document_id} className="flex flex-wrap items-center gap-1.5 break-all text-slate-800">
                    <FileText className="h-3.5 w-3.5 shrink-0 text-red-600" aria-hidden />{a.name}
                    {alternative && d && <><span className="text-xs text-slate-500">· {d.source_label}</span><VerificationBadge status={d.verification_status} /></>}
                  </div>
                )
              })}
            </dd>
          </div>
        </dl>
        <div className="overflow-hidden rounded-md border border-slate-300">
          <p className="border-b border-slate-200 bg-slate-50 px-3 py-1.5 text-xs text-slate-500">Email preview (exactly the template the customer receives)</p>
          {/* sandbox="" : the preview can never run scripts or navigate */}
          <iframe title="Email preview" sandbox="" srcDoc={preview.html} className="h-[28rem] w-full bg-white" />
        </div>
        {alternative && (
          <label className="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
            <input type="checkbox" className="mt-0.5" checked={reviewed} onChange={(e) => setReviewed(e.target.checked)} />
            <span>I have reviewed the attached document, it belongs to this booking, and I have verified the recipient {preview.recipient}.</span>
          </label>
        )}
        {sendErrors.confirm_reviewed && <p className="text-xs text-red-600">{sendErrors.confirm_reviewed[0]}</p>}
        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button variant="secondary" onClick={() => setStep('edit')}>Back to edit</Button>
          <Button loading={sendMutation.isPending} disabled={sendMutation.isPending || (alternative && !reviewed)} onClick={send}>
            <Send className="h-4 w-4" aria-hidden /> {alternative ? 'Confirm and Send' : 'Send Tickets to Customer'}
          </Button>
        </div>
      </div>
    )
  }

  // ---------------------------------------------------------------- edit
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <button type="button" onClick={onBack} className="inline-flex items-center gap-1 text-sm text-slate-600 hover:text-slate-900"><ArrowLeft className="h-4 w-4" aria-hidden /> Choose another option</button>
        <DocumentKindBadge alternative={alternative} />
      </div>
      {blocked && <ErrorBox message={blocked} />}
      {!info.delivers_mail && !blocked && (
        <p className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">
          Mail driver is “{info.mailer}” (development): emails are recorded in the application log, not delivered. Set the MAIL_* settings to send real email.
        </p>
      )}
      {alternative && <AltWarning />}
      {previewMutation.isError && !previewErrors.recipient && !previewErrors.document_ids && <ErrorBox message={errorMessage(previewMutation.error)} />}

      <Field label="Send to *" error={previewErrors.recipient ?? sendErrors.recipient}
        hint={info.customer.email ? (isOverride ? `For this email only. ${info.customer.name}'s saved address stays ${info.customer.email}.` : `${info.customer.name}'s registered email`) : `${info.customer.name} has no saved email. Enter one for this email, or add it to the customer.`}>
        <div className="relative">
          <Mail className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden />
          <Input type="email" inputMode="email" className={`pl-9 ${isOverride && info.customer.email ? 'border-amber-400' : ''}`} value={to} onChange={(e) => setRecipient(e.target.value)} />
        </div>
      </Field>

      <fieldset>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <legend className="label">{alternative ? 'Alternative ticket PDFs to attach' : 'Official ticket PDFs to attach'}</legend>
          {alternative && !adding && (
            <span className="flex flex-wrap gap-2">
              <Button size="sm" variant="secondary" onClick={() => setAdding('generate')}><FilePlus2 className="h-4 w-4" aria-hidden /> Create ticket PDF</Button>
              {can('edit-records') && <Button size="sm" variant="secondary" onClick={() => setAdding('upload')}><Plus className="h-4 w-4" aria-hidden /> Add PDF</Button>}
            </span>
          )}
        </div>
        {alternative && adding === 'upload' && (
          <AltUpload booking={booking} onCancel={() => setAdding(null)}
            onUploaded={(ids, verified) => { setAdding(null); if (verified) setSelected([...chosen, ...ids]) }} />
        )}
        {alternative && adding === 'generate' && <GeneratePanel booking={booking} onDone={() => setAdding(null)} />}
        {docs.length === 0 ? (
          <p className="mt-1 rounded-md border border-dashed border-slate-300 p-3 text-sm text-slate-600">
            {alternative ? 'No alternative ticket PDF on this booking yet. Use Add PDF to upload one.' : 'This booking has no official e-ticket PDFs yet. Use Issue tickets, or upload them under Documents with the type “E-ticket”.'}
          </p>
        ) : (
          <ul className="mt-1 space-y-1.5">
            {docs.map((d) => (
              <li key={d.id} className={`rounded-md border p-2.5 text-sm ${chosen.includes(d.id) ? 'border-gold bg-gold/5' : 'border-slate-200'}`}>
                <label className={`flex items-start gap-3 ${d.sendable ? 'cursor-pointer' : 'cursor-not-allowed'}`}>
                  <input type="checkbox" className="mt-0.5" disabled={!d.sendable} checked={chosen.includes(d.id)} onChange={() => toggle(d.id)} aria-label={`Attach ${d.name}`} />
                  <FileText className={`mt-0.5 h-4 w-4 shrink-0 text-red-600 ${d.sendable ? '' : 'opacity-50'}`} aria-hidden />
                  <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-1.5">
                      <span className={`break-all font-medium ${d.sendable ? 'text-slate-900' : 'text-slate-500'}`}>{d.name}</span>
                      {alternative && <VerificationBadge status={d.verification_status} />}
                    </span>
                    <span className="block text-xs text-slate-500">
                      {[alternative ? d.source_label : '', d.passengers.join(', ') || (alternative ? 'Whole booking' : ''), d.tickets.length ? `Ticket ${d.tickets.join(', ')}` : '', fileSize(d.size_bytes)].filter(Boolean).join(' · ')}
                      {!d.readable && <span className="text-red-700"> · file missing, upload again</span>}
                    </span>
                    {alternative && (
                      <span className="block text-xs text-slate-500">
                        Uploaded by {d.uploaded_by ?? 'unknown'} {dateTime(d.uploaded_at)}
                        {d.verified_by && <> · {d.verification_status === 'rejected' ? 'rejected' : 'verified'} by {d.verified_by} {dateTime(d.verified_at)}</>}
                        {d.source_description && <> · “{d.source_description}”</>}
                      </span>
                    )}
                    {d.verification_status === 'rejected' && d.verification_note && <span className="block text-xs text-red-700">Rejected: {d.verification_note}</span>}
                  </span>
                </label>
                {alternative && d.readable && (
                  <div className="mt-2 flex flex-wrap gap-2 pl-7">
                    <Button size="sm" variant="secondary" loading={download.isPending && download.variables?.url.includes(`/documents/${d.id}/`)}
                      onClick={() => download.mutate({ url: `/documents/${d.id}/download`, filename: d.name, inline: true }, { onError: (e) => alert(errorMessage(e)) })}>
                      <Eye className="h-4 w-4" aria-hidden /> Open
                    </Button>
                    {can('verify-documents') && d.verification_status !== 'verified' && <>
                    <Button size="sm" loading={verify.isPending && verify.variables?.id === d.id && verify.variables.status === 'verified'}
                      onClick={() => verify.mutate({ id: d.id, status: 'verified' }, { onSuccess: () => setSelected([...chosen, d.id]), onError: (e) => alert(errorMessage(e)) })}>
                      <CircleCheck className="h-4 w-4" aria-hidden /> I reviewed it, mark verified
                    </Button>
                    {d.verification_status !== 'rejected' && <Button size="sm" variant="ghost" className="text-red-600" onClick={() => reject(d)}>Reject</Button>}
                    </>}
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}
        {(previewErrors.document_ids ?? sendErrors.document_ids)?.map((m) => <p key={m} className="mt-1 text-xs text-red-600">{m}</p>)}
        <p className={`mt-2 text-xs ${overLimit ? 'font-medium text-red-700' : 'text-slate-500'}`}>
          {chosen.length} selected · {fileSize(totalBytes)} of {fileSize(info.limits.max_total_bytes)} allowed per email
        </p>
      </fieldset>

      {alternative && (
        <label className="flex items-start gap-2 text-sm text-slate-700">
          <input type="checkbox" className="mt-0.5" checked={officialPending} onChange={(e) => setOfficialPending(e.target.checked)} />
          <span>Tell the customer the official ticket document is still pending. <span className="text-slate-500">Tick only if you know this is true.</span></span>
        </label>
      )}

      <p className="text-sm text-slate-600"><span className="font-medium text-slate-800">Subject:</span> {info.subjects[mode]}</p>

      {(sameAsBefore || sendErrors.confirm_resend) && (
        <label className="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
          <input type="checkbox" className="mt-0.5" checked={confirmResend} onChange={(e) => setConfirmResend(e.target.checked)} />
          <span>{sendErrors.confirm_resend?.[0] ?? `These PDFs were already ${sameAsBefore?.status === 'sent' ? 'sent' : 'queued'} to ${sameAsBefore?.recipient} on ${dateTime(sameAsBefore?.requested_at)}.`} Tick to send them again.</span>
        </label>
      )}

      <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button loading={previewMutation.isPending} onClick={goPreview}
          disabled={Boolean(blocked) || chosen.length === 0 || overLimit || !to.trim() || ((Boolean(sameAsBefore) || Boolean(sendErrors.confirm_resend)) && !confirmResend)}>
          Preview email
        </Button>
      </div>
    </div>
  )
}

/**
 * Creates the agency's ticket PDF from the booking records (one per passenger). The new PDFs are
 * "Pending verification": open each one, check it, then mark it verified before sending.
 */
function GeneratePanel({ booking, onDone }: { booking: Booking; onDone: () => void }) {
  const passengers = booking.passengers ?? []
  const [ids, setIds] = useState<number[]>(passengers.map((p) => p.id))
  const [codes, setCodes] = useState({ reservation_code: booking.pnr ?? '', airline_pnr: booking.pnr ?? '' })
  const generate = useGenerateTickets()
  const preview = usePreviewGeneratedTicket()
  const errors = fieldErrors(generate.error)
  const codeError = (field: 'reservation_code' | 'airline_pnr') => {
    const v = codes[field].trim()
    if (!v) return 'Required'
    return /^[A-Za-z0-9]{4,10}$/.test(v) ? errors[field]?.[0] : '4 to 10 letters or digits'
  }
  const codesOk = !codeError('reservation_code') && !codeError('airline_pnr')
  const setCode = (field: 'reservation_code' | 'airline_pnr', value: string) => setCodes((c) => ({ ...c, [field]: value.toUpperCase().replace(/\s+/g, '') }))
  const problems = errors.booking ?? fieldErrors(preview.error).booking
  const toggle = (id: number) => setIds((cur) => (cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id]))

  return (
    <div className="my-2 space-y-3 rounded-md border border-dashed border-slate-300 bg-slate-50 p-3">
      <p className="text-sm text-slate-700">
        Creates your agency ticket PDF from this booking: passenger, reservation code, airline PNR, flights, times and baggage as recorded here.
        Titled <strong>E-TICKET</strong>. The ticket number and our internal booking reference are not printed.
      </p>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Reservation code *" error={codes.reservation_code ? codeError('reservation_code') : errors.reservation_code}>
          <Input className="font-mono uppercase" maxLength={10} value={codes.reservation_code} onChange={(e) => setCode('reservation_code', e.target.value)} placeholder="e.g. 9VKNT2" />
        </Field>
        <Field label="Airline PNR *" error={codes.airline_pnr ? codeError('airline_pnr') : errors.airline_pnr}>
          <Input className="font-mono uppercase" maxLength={10} value={codes.airline_pnr} onChange={(e) => setCode('airline_pnr', e.target.value)} placeholder="e.g. 9VKNT2" />
        </Field>
      </div>
      {problems ? (
        <div className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800" role="alert">
          <p className="font-semibold">The PDF can't be created yet:</p>
          <ul className="mt-1 list-disc pl-5">{problems.map((m) => <li key={m}>{m}</li>)}</ul>
        </div>
      ) : (generate.isError || preview.isError) && <ErrorBox message={errorMessage(generate.error ?? preview.error)} />}
      {passengers.length === 0 ? <p className="text-sm text-slate-600">Add the passengers to the booking first.</p> : (
        <ul className="space-y-1.5">
          {passengers.map((p) => (
            <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-slate-200 bg-surface px-2.5 py-2 text-sm">
              <label className="flex min-w-0 items-center gap-2">
                <input type="checkbox" checked={ids.includes(p.id)} onChange={() => toggle(p.id)} />
                <span className="break-all font-medium text-slate-900">{p.full_name_as_document}</span>
              </label>
              <Button size="sm" variant="ghost" disabled={!codesOk} loading={preview.isPending && preview.variables?.passengerId === p.id}
                onClick={() => preview.mutate({ bookingId: booking.id, passengerId: p.id, codes })}><Eye className="h-4 w-4" aria-hidden /> Preview</Button>
            </li>
          ))}
        </ul>
      )}
      <p className="text-xs text-slate-500">Check the flights, times and names in the preview. Fix anything wrong on the booking first; the PDF only shows what is recorded.</p>
      <div className="flex justify-end gap-2">
        <Button size="sm" variant="ghost" onClick={onDone}>Cancel</Button>
        <Button size="sm" disabled={ids.length === 0 || !codesOk} loading={generate.isPending}
          onClick={() => generate.mutate({ bookingId: booking.id, passengerIds: ids, codes }, { onSuccess: onDone })}>
          <FilePlus2 className="h-4 w-4" aria-hidden /> Create {ids.length > 1 ? `${ids.length} PDFs` : 'PDF'}
        </Button>
      </div>
    </div>
  )
}

function AltWarning() {
  return (
    <p className="flex items-start gap-2 rounded-md border border-violet-300 bg-violet-50 px-3 py-2 text-sm text-violet-900" role="note">
      <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden /> {ALTERNATIVE_WARNING}
    </p>
  )
}

/** Uploads an alternative ticket PDF to this booking with its source; "reviewed" makes it verified immediately. */
function AltUpload({ booking, onUploaded, onCancel }: { booking: Booking; onUploaded: (ids: number[], verified: boolean) => void; onCancel: () => void }) {
  const { data: meta } = useMeta()
  const fileInput = useRef<HTMLInputElement>(null)
  const [file, setFile] = useState<File | null>(null)
  const [passengerId, setPassengerId] = useState('')
  const [source, setSource] = useState('')
  const [description, setDescription] = useState('')
  const [notes, setNotes] = useState('')
  const [reviewed, setReviewed] = useState(false)
  const [error, setError] = useState('')
  const upload = useUploadDocuments()
  const errors = fieldErrors(upload.error)
  const passengers = booking.passengers ?? []

  const submit = () => {
    setError('')
    if (!file) return setError('Choose a PDF file.')
    if (file.type !== 'application/pdf') return setError(`${file.name}: only PDF files can be sent as a ticket.`)
    if (file.size > 10 * 1024 * 1024) return setError(`${file.name} is larger than 10 MB.`)
    const form = new FormData()
    form.append('files[]', file)
    form.append('document_type', 'alternative_ticket')
    form.append('source_type', source)
    if (description.trim()) form.append('source_description', description.trim())
    if (passengerId) form.append('passenger_id', passengerId)
    if (notes.trim()) form.append('notes', notes.trim())
    form.append('reviewed', reviewed ? '1' : '0')
    upload.mutate({ bookingId: booking.id, form }, {
      onSuccess: (docs) => onUploaded(docs.map((d) => d.id), docs.every((d) => d.verification_status === 'verified')),
    })
  }

  return (
    <div className="my-2 space-y-3 rounded-md border border-dashed border-slate-300 bg-slate-50 p-3">
      {(error || (upload.isError && !Object.keys(errors).length)) && <ErrorBox message={error || errorMessage(upload.error)} />}
      <div>
        <input ref={fileInput} type="file" accept=".pdf,application/pdf" className="hidden" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        <Button size="sm" variant="secondary" onClick={() => fileInput.current?.click()}><Upload className="h-4 w-4" aria-hidden /> {file ? 'Choose another PDF' : 'Choose PDF'}</Button>
        <span className="ml-2 break-all text-sm text-slate-700">{file ? `${file.name} · ${fileSize(file.size)}` : 'PDF only, max 10 MB'}</span>
        {(errors.files ?? errors['files.0'])?.map((m) => <p key={m} className="mt-1 text-xs text-red-600">{m}</p>)}
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Document source *" error={errors.source_type}>
          <Select value={source} onChange={(e) => setSource(e.target.value)} placeholder="Select…" options={meta?.document_sources ?? []} />
        </Field>
        {passengers.length > 0 && (
          <Field label="Passenger" hint="Leave as whole booking if the PDF covers everyone">
            <Select value={passengerId} onChange={(e) => setPassengerId(e.target.value)} placeholder="Whole booking" options={passengers.map((p) => ({ value: String(p.id), label: p.full_name_as_document }))} />
          </Field>
        )}
        <Field label={`Source description${source === 'other_authorized' ? ' *' : ''}`} error={errors.source_description} hint="Internal only, never in the email">
          <Input value={description} maxLength={255} onChange={(e) => setDescription(e.target.value)} placeholder="e.g. Forwarded by the customer from the airline app" />
        </Field>
        <Field label="Internal note" hint="Internal only, never in the email">
          <Input value={notes} maxLength={2000} onChange={(e) => setNotes(e.target.value)} />
        </Field>
      </div>
      <label className="flex items-start gap-2 text-sm text-slate-700">
        <input type="checkbox" className="mt-0.5" checked={reviewed} onChange={(e) => setReviewed(e.target.checked)} />
        <span>I have reviewed this PDF and it is appropriate to send for this booking (marks it <strong>Verified</strong>; otherwise it stays <em>Pending verification</em>).</span>
      </label>
      <div className="flex justify-end gap-2">
        <Button size="sm" variant="ghost" onClick={onCancel}>Cancel</Button>
        <Button size="sm" loading={upload.isPending} disabled={!file || !source} onClick={submit}>Upload PDF</Button>
      </div>
    </div>
  )
}
