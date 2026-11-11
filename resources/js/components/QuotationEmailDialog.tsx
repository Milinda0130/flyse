import { useState, type ReactNode } from 'react'
import { CircleAlert, CircleCheck, Clock, FileText, Mail, Send } from 'lucide-react'
import { useQuotationEmail } from '../hooks/queries'
import { usePreviewQuotationEmail, useRetryQuotationEmail, useSendQuotationEmail } from '../hooks/mutations'
import { errorMessage, fieldErrors } from '../lib/api'
import { dateTime, fileSize } from '../lib/format'
import type { Quotation, QuotationEmail } from '../lib/types'
import { Button, ErrorBox, Field, Input, Loading } from './ui'

const newKey = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`)

export function QuotationEmailStatus({ email }: { email: QuotationEmail }) {
  const map = {
    queued: ['bg-sky-50 text-sky-800 ring-sky-300', <Clock key="i" className="h-3.5 w-3.5" aria-hidden />, 'Queued'],
    sending: ['bg-sky-50 text-sky-800 ring-sky-300', <Clock key="i" className="h-3.5 w-3.5" aria-hidden />, 'Sending'],
    sent: ['bg-emerald-50 text-emerald-800 ring-emerald-300', <CircleCheck key="i" className="h-3.5 w-3.5" aria-hidden />, email.delivered_by_provider ? 'Sent' : 'Sent (logged only)'],
    failed: ['bg-red-50 text-red-700 ring-red-300', <CircleAlert key="i" className="h-3.5 w-3.5" aria-hidden />, 'Failed'],
  }[email.status] as [string, ReactNode, string]
  return (
    <span className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${map[0]}`}
      title={email.status === 'sent' ? 'Accepted by the mail server. This does not prove the customer received or opened it.' : undefined}>{map[1]}{map[2]}</span>
  )
}

/**
 * Email the quotation PDF: check the recipient, see the email and the attached version, confirm.
 * The current saved content becomes a PDF version (or the unchanged latest one is reused).
 */
export default function QuotationEmailDialog({ quotation, onClose }: { quotation: Quotation; onClose: () => void }) {
  const { data: info, isLoading, error } = useQuotationEmail(quotation.id)
  const [recipient, setRecipient] = useState<string | null>(null)
  const [step, setStep] = useState<'edit' | 'preview' | 'result'>('edit')
  const [confirmResend, setConfirmResend] = useState(false)
  const [key] = useState(newKey)
  const [sentId, setSentId] = useState<number | null>(null)
  const preview = usePreviewQuotationEmail()
  const send = useSendQuotationEmail()
  const retry = useRetryQuotationEmail()

  if (isLoading) return <Loading />
  if (error || !info) return <ErrorBox message={errorMessage(error)} />

  const to = recipient ?? info.customer.email ?? ''
  const isOverride = to.trim().toLowerCase() !== (info.customer.email ?? '').toLowerCase()
  const blocked = info.problem ?? (info.content_problems.length ? info.content_problems.join(' ') : null)
  const previewErrors = fieldErrors(preview.error)
  const sendErrors = fieldErrors(send.error)
  const result = sentId ? info.history.find((e) => e.id === sentId) ?? send.data : null

  const doSend = () => send.mutate({ id: quotation.id, recipient: isOverride ? to.trim() : null, idempotency_key: key, confirm_resend: confirmResend }, {
    onSuccess: (e) => { setSentId(e.id); setStep('result') },
    onError: (e) => { if (fieldErrors(e).confirm_resend || fieldErrors(e).recipient) setStep('edit') },
  })

  if (step === 'result' && result) {
    return (
      <div className="space-y-4">
        {result.status === 'failed' ? (
          <div className="rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-800" role="alert">
            <p className="flex items-center gap-2 font-semibold"><CircleAlert className="h-4 w-4" aria-hidden /> The email was not sent.</p>
            <p className="mt-1">{result.failure_reason}</p>
            <Button className="mt-3" loading={retry.isPending} onClick={() => retry.mutate({ emailId: result.id, quotationId: quotation.id })}>Retry</Button>
          </div>
        ) : result.status === 'sent' ? (
          <div className="rounded-md border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-800" role="status">
            <p className="flex items-center gap-2 font-semibold"><CircleCheck className="h-4 w-4" aria-hidden /> Sent to {result.recipient}</p>
            <p className="mt-1">{result.attachment_name} (version {result.pdf_version}, {fileSize(result.size_bytes)}) attached.</p>
            {result.delivered_by_provider
              ? <p className="mt-1 text-xs">The mail server accepted the message. This does not confirm the customer has received or opened it.</p>
              : <p className="mt-2 text-amber-800">Development mail driver “{result.mailer}”: written to the application log, <strong>not delivered</strong>.</p>}
          </div>
        ) : (
          <div className="rounded-md border border-sky-300 bg-sky-50 p-4 text-sm text-sky-900" role="status">
            <p className="flex items-center gap-2 font-semibold"><Clock className="h-4 w-4" aria-hidden /> Email queued for {result.recipient}, not sent yet</p>
            <p className="mt-1">The queue worker will send it shortly. This updates automatically. If it stays queued, the queue worker is not running.</p>
          </div>
        )}
        <div className="flex justify-end"><Button variant="secondary" onClick={onClose}>Close</Button></div>
      </div>
    )
  }

  if (step === 'preview' && preview.data) {
    const p = preview.data
    return (
      <div className="space-y-4">
        {send.isError && <ErrorBox message={errorMessage(send.error)} />}
        <dl className="grid gap-x-6 gap-y-2 rounded-md border border-slate-200 p-3 text-sm sm:grid-cols-2">
          <div><dt className="text-xs uppercase tracking-wide text-slate-500">Customer</dt><dd className="font-medium">{info.customer.name ?? 'Not linked'}</dd></div>
          <div><dt className="text-xs uppercase tracking-wide text-slate-500">Send to</dt><dd className="break-all font-medium">{p.recipient}{p.recipient_is_override && <span className="ml-1 text-xs font-normal text-amber-700">(not the saved address)</span>}</dd></div>
          <div className="sm:col-span-2"><dt className="text-xs uppercase tracking-wide text-slate-500">Subject</dt><dd className="font-medium">{p.subject}</dd></div>
          <div className="sm:col-span-2">
            <dt className="text-xs uppercase tracking-wide text-slate-500">Attachment</dt>
            <dd className="flex items-center gap-1.5"><FileText className="h-4 w-4 text-red-600" aria-hidden />
              {info.pdf_is_current && info.latest_pdf ? `${info.latest_pdf.filename} (version ${info.latest_pdf.version}, ${fileSize(info.latest_pdf.size_bytes)})` : 'A new PDF version is created from the saved quotation when you send.'}
            </dd>
          </div>
        </dl>
        <div className="overflow-hidden rounded-md border border-slate-300">
          <p className="border-b border-slate-200 bg-slate-50 px-3 py-1.5 text-xs text-slate-500">Email preview (exactly what the customer receives)</p>
          <iframe title="Email preview" sandbox="" srcDoc={p.html} className="h-[26rem] w-full bg-white" />
        </div>
        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button variant="secondary" onClick={() => setStep('edit')}>Back</Button>
          <Button loading={send.isPending} disabled={send.isPending} onClick={doSend}><Send className="h-4 w-4" aria-hidden /> Confirm and send</Button>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      {blocked && <ErrorBox message={blocked} />}
      {!info.delivers_mail && !blocked && <p className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">Mail driver is “{info.mailer}” (development): emails are logged, not delivered.</p>}
      {preview.isError && !previewErrors.recipient && <ErrorBox message={errorMessage(preview.error)} />}
      <Field label="Send to *" error={previewErrors.recipient ?? sendErrors.recipient}
        hint={!info.customer.name ? 'No customer is linked: type the email address to send to.' : info.customer.email ? (isOverride ? `For this email only. The saved address stays ${info.customer.email}.` : `${info.customer.name}'s registered email`) : `${info.customer.name} has no saved email. Enter one for this email.`}>
        <div className="relative">
          <Mail className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden />
          <Input type="email" className="pl-9" value={to} onChange={(e) => setRecipient(e.target.value)} />
        </div>
      </Field>
      <p className="text-sm text-slate-600"><span className="font-medium text-slate-800">Subject:</span> {info.subject}</p>
      <p className="text-sm text-slate-600"><span className="font-medium text-slate-800">PDF:</span> {info.pdf_is_current && info.latest_pdf ? `${info.latest_pdf.filename} (version ${info.latest_pdf.version})` : 'a new version will be created from the saved quotation'}</p>
      {sendErrors.confirm_resend && (
        <label className="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
          <input type="checkbox" className="mt-0.5" checked={confirmResend} onChange={(e) => setConfirmResend(e.target.checked)} />
          <span>{sendErrors.confirm_resend[0]} Tick to send it again.</span>
        </label>
      )}
      <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button loading={preview.isPending} disabled={Boolean(blocked) || !to.trim() || (Boolean(sendErrors.confirm_resend) && !confirmResend)}
          onClick={() => preview.mutate({ id: quotation.id, recipient: to.trim() || null }, { onSuccess: () => setStep('preview') })}>Preview email</Button>
      </div>
      {info.history.length > 0 && <p className="text-xs text-slate-500">Last email: {info.history[0].recipient}, {dateTime(info.history[0].requested_at)} ({info.history[0].status}).</p>}
    </div>
  )
}
