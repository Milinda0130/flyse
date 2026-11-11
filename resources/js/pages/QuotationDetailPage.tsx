import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Ban, CircleCheck, Copy, Download, FilePlus2, Link2, Mail, Pencil, RotateCcw, Send, Trash2, TriangleAlert } from 'lucide-react'
import { useAuth } from '../auth/AuthContext'
import { useBookings, useMeta, useQuotation, useQuotationEmail } from '../hooks/queries'
import { useDeleteQuotation, useDownload, useDuplicateQuotation, useGenerateQuotationPdf, useQuotationStatus, useRetryQuotationEmail } from '../hooks/mutations'
import { api, errorMessage, fieldErrors } from '../lib/api'
import { date, dateTime, fileSize, labelFor } from '../lib/format'
import type { Quotation } from '../lib/types'
import { Button, Card, Empty, ErrorBox, Loading, Modal, PageHeader, Select } from '../components/ui'
import { QuotationStatusBadge } from '../components/Badges'
import ActionMenu, { type MenuAction } from '../components/ActionMenu'
import QuotationEmailDialog, { QuotationEmailStatus } from '../components/QuotationEmailDialog'

export default function QuotationDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { can } = useAuth()
  const { data: meta } = useMeta()
  const { data: q, isLoading, error } = useQuotation(id)
  const status = useQuotationStatus()
  const duplicate = useDuplicateQuotation()
  const remove = useDeleteQuotation()
  const generate = useGenerateQuotationPdf()
  const download = useDownload()
  const [dialog, setDialog] = useState<'email' | 'accept' | 'convert' | null>(null)
  const [actionError, setActionError] = useState('')

  if (isLoading) return <Loading />
  if (error || !q) return <ErrorBox message={errorMessage(error)} />

  const fail = (e: unknown) => setActionError([errorMessage(e), ...Object.values(fieldErrors(e)).flat()].filter((v, i, a) => a.indexOf(v) === i).join(' '))
  const go = (to: string, extra: Record<string, number> = {}) => { setActionError(''); status.mutate({ id: q.id, status: to, ...extra }, { onError: fail, onSuccess: () => setDialog(null) }) }
  const allowed = (s: string) => q.meta.transitions.includes(s as never)
  const pdfUrl = `/flight-quotations/${q.id}/pdf`
  const filename = q.meta.pdfs[0]?.filename ?? `Flight-Quotation-${q.reference}.pdf`

  const actions: MenuAction[] = [
    can('edit-records') && { key: 'dup', label: 'Duplicate', icon: <Copy className="h-4 w-4" aria-hidden />, onSelect: () => duplicate.mutate(q.id, { onSuccess: (c) => navigate(`/quotations/${c.id}/edit`), onError: fail }) },
    can('edit-records') && allowed('ready') && { key: 'ready', label: 'Mark ready to send', icon: <CircleCheck className="h-4 w-4" aria-hidden />, onSelect: () => go('ready') },
    can('edit-records') && allowed('accepted') && { key: 'acc', label: 'Customer accepted an option', icon: <CircleCheck className="h-4 w-4" aria-hidden />, onSelect: () => setDialog('accept') },
    can('edit-records') && allowed('converted') && { key: 'conv', label: 'Link to a booking', icon: <Link2 className="h-4 w-4" aria-hidden />, onSelect: () => setDialog('convert') },
    can('edit-records') && allowed('rejected') && { key: 'rej', label: 'Customer declined', icon: <Ban className="h-4 w-4" aria-hidden />, onSelect: () => confirm('Mark this quotation as rejected?') && go('rejected') },
    can('edit-records') && allowed('expired') && { key: 'exp', label: 'Mark expired', icon: <Ban className="h-4 w-4" aria-hidden />, onSelect: () => go('expired') },
    can('edit-records') && allowed('draft') && { key: 'draft', label: 'Back to draft', icon: <RotateCcw className="h-4 w-4" aria-hidden />, onSelect: () => go('draft') },
    can('edit-records') && q.status === 'draft' && { key: 'del', label: 'Delete draft', danger: true, icon: <Trash2 className="h-4 w-4" aria-hidden />, onSelect: () => confirm(`Delete ${q.reference}?`) && remove.mutate(q.id, { onSuccess: () => navigate('/quotations'), onError: fail }) },
  ].filter(Boolean) as MenuAction[]

  return (
    <div>
      <PageHeader
        title={<span className="flex flex-wrap items-center gap-2"><span className="font-mono">{q.reference}</span><QuotationStatusBadge status={q.status} label={q.status_label} /></span>}
        subtitle={<>{q.route} · {q.customer ? <Link className="link" to={`/customers/${q.customer.id}`}>{q.customer.full_name}</Link> : 'no customer linked'} · created {dateTime(q.created_at)}{q.creator ? ` by ${q.creator}` : ''}</>}
        actions={
          <div className="flex flex-wrap gap-2">
            {can('edit-records') && q.meta.editable && <Link to={`/quotations/${q.id}/edit`} className="btn-secondary"><Pencil className="h-4 w-4" aria-hidden /> Edit</Link>}
            <Button disabled={q.meta.problems.length > 0} loading={download.isPending && download.variables?.url === pdfUrl}
              onClick={() => download.mutate({ url: pdfUrl, filename }, { onError: fail })}><Download className="h-4 w-4" aria-hidden /> Download PDF</Button>
            {can('email-tickets') && <Button variant="secondary" disabled={Boolean(q.meta.email_problem) || q.meta.problems.length > 0} title={q.meta.email_problem ?? undefined} onClick={() => setDialog('email')}><Send className="h-4 w-4" aria-hidden /> Email to customer</Button>}
            {actions.length > 0 && <ActionMenu actions={actions} />}
          </div>
        }
      />

      {actionError && <div className="mb-4"><ErrorBox message={actionError} /></div>}
      {q.booking && <p className="mb-4 rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">Linked to booking <Link className="link font-mono" to={`/bookings/${q.booking.id}`}>{q.booking.booking_code}</Link>.</p>}
      {q.meta.problems.length > 0 && (
        <div className="mb-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
          <p className="flex items-center gap-2 font-semibold"><TriangleAlert className="h-4 w-4" aria-hidden /> To create the PDF, please complete (Edit):</p>
          <ul className="mt-1 list-disc pl-6">{q.meta.problems.map((p) => <li key={p}>{p}</li>)}</ul>
        </div>
      )}
      {q.meta.warnings.length > 0 && (
        <div className="mb-4 rounded-md border border-sky-300 bg-sky-50 p-3 text-sm text-sky-900">
          <p className="font-semibold">Please check</p>
          <ul className="mt-1 list-disc pl-6">{q.meta.warnings.map((w) => <li key={w}>{w}</li>)}</ul>
        </div>
      )}

      <div className="grid gap-4 xl:grid-cols-3">
        <div className="space-y-4 xl:col-span-2">
          <PdfPreview q={q} />
        </div>
        <div className="space-y-4">
          <Card title="Requirements">
            <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
              <dt className="text-slate-500">Trip</dt><dd>{q.trip_type === 'round_trip' ? 'Return' : 'One-way'}</dd>
              <dt className="text-slate-500">Departure</dt><dd>{date(q.outbound_date)}</dd>
              {q.return_date && <><dt className="text-slate-500">Return</dt><dd>{date(q.return_date)}</dd></>}
              <dt className="text-slate-500">Passengers</dt><dd>{[q.adults && `${q.adults} adult(s)`, q.children && `${q.children} child(ren)`, q.infants && `${q.infants} infant(s)`].filter(Boolean).join(', ')}</dd>
              <dt className="text-slate-500">Cabin</dt><dd>{labelFor(meta?.cabin_classes, q.cabin_class)}</dd>
              <dt className="text-slate-500">Currency</dt><dd>{q.currency}</dd>
              {q.valid_until && <><dt className="text-slate-500">Valid until</dt><dd>{dateTime(q.valid_until)}</dd></>}
              {q.requested_airline && <><dt className="text-slate-500">Airline wish</dt><dd>{q.requested_airline}</dd></>}
              {q.max_stops !== null && <><dt className="text-slate-500">Max stops</dt><dd>{q.max_stops === 0 ? 'Direct only' : q.max_stops}</dd></>}
              {q.requested_baggage && <><dt className="text-slate-500">Baggage</dt><dd>{q.requested_baggage}</dd></>}
            </dl>
            {q.customer_requirements && <p className="mt-3 whitespace-pre-line text-sm text-slate-700">{q.customer_requirements}</p>}
            {q.internal_notes && <p className="mt-3 whitespace-pre-line rounded bg-slate-50 p-2 text-xs text-slate-600"><strong>Internal:</strong> {q.internal_notes}</p>}
          </Card>

          <Card title="PDF versions" actions={can('edit-records') && q.meta.problems.length === 0 && !q.meta.pdf_is_current && (
            <Button size="sm" loading={generate.isPending} onClick={() => generate.mutate(q.id, { onError: fail })}><FilePlus2 className="h-4 w-4" aria-hidden /> Generate PDF</Button>
          )}>
            {q.meta.pdfs.length === 0 ? <Empty>No PDF yet. It is created when you download or email the quotation.</Empty> : (
              <ul className="space-y-1.5 text-sm">
                {q.meta.pdfs.map((p, i) => (
                  <li key={p.id} className="flex flex-wrap items-center justify-between gap-2">
                    <span>Version {p.version}{i === 0 && q.meta.pdf_is_current && <span className="ml-1 text-xs text-emerald-700">(current)</span>}<span className="block text-xs text-slate-500">{dateTime(p.created_at)} · {fileSize(p.size_bytes)}{p.generated_by ? ` · ${p.generated_by}` : ''}</span></span>
                    <Button size="sm" variant="ghost" onClick={() => download.mutate({ url: `/flight-quotations/${q.id}/pdfs/${p.id}`, filename: p.filename }, { onError: fail })}><Download className="h-4 w-4" aria-hidden /></Button>
                  </li>
                ))}
              </ul>
            )}
            {q.meta.pdfs.length > 0 && !q.meta.pdf_is_current && <p className="mt-2 text-xs text-amber-700">The quotation changed after the last PDF. Download or email creates a new version; older versions stay available.</p>}
          </Card>

          {can('email-tickets') && <EmailHistory q={q} />}
        </div>
      </div>

      <Modal open={dialog === 'email'} title={`Email quotation · ${q.reference}`} onClose={() => setDialog(null)} wide>
        {dialog === 'email' && <QuotationEmailDialog quotation={q} onClose={() => setDialog(null)} />}
      </Modal>
      <Modal open={dialog === 'accept'} title="Which option did the customer accept?" onClose={() => setDialog(null)}>
        {dialog === 'accept' && <AcceptForm q={q} pending={status.isPending} onSubmit={(optionId) => go('accepted', { accepted_option_id: optionId })} />}
      </Modal>
      <Modal open={dialog === 'convert'} title="Link to a booking" onClose={() => setDialog(null)}>
        {dialog === 'convert' && <ConvertForm q={q} pending={status.isPending} onSubmit={(bookingId) => go('converted', { booking_id: bookingId })} />}
      </Modal>
    </div>
  )
}

/** The PDF exactly as the customer gets it, shown on the page (refreshes after every save). */
function PdfPreview({ q }: { q: Quotation }) {
  const [url, setUrl] = useState<string | null>(null)
  const [failed, setFailed] = useState('')
  useEffect(() => {
    let current: string | null = null
    let cancelled = false
    api.get<Blob>(`/flight-quotations/${q.id}/preview`, { responseType: 'blob' })
      .then((res) => { if (!cancelled) { current = URL.createObjectURL(res.data); setUrl(current) } })
      .catch((e) => !cancelled && setFailed(errorMessage(e)))
    return () => { cancelled = true; if (current) URL.revokeObjectURL(current) }
  }, [q.id, q.updated_at])
  return (
    <Card title="PDF" bodyClass="p-0">
      {failed ? <div className="p-4"><ErrorBox message={failed} /></div> : url
        ? <iframe title="Quotation PDF" src={url} className="h-[75vh] w-full rounded-b-lg bg-white" />
        : <Loading />}
    </Card>
  )
}

function EmailHistory({ q }: { q: Quotation }) {
  const { data } = useQuotationEmail(q.id)
  const retry = useRetryQuotationEmail()
  const history = data?.history ?? []
  return (
    <Card title={<span className="flex items-center gap-2"><Mail className="h-4 w-4 text-brand-600" aria-hidden /> Emails</span>} bodyClass="">
      {history.length === 0 ? <Empty>Not emailed yet.</Empty> : (
        <ul className="divide-y divide-slate-100">
          {history.map((e) => (
            <li key={e.id} className="px-4 py-3 text-sm">
              <div className="flex flex-wrap items-center justify-between gap-2"><span className="break-all font-medium">{e.recipient}</span><QuotationEmailStatus email={e} /></div>
              <div className="mt-0.5 text-xs text-slate-500">{dateTime(e.requested_at)} by {e.requested_by ?? 'unknown'} · PDF version {e.pdf_version}{e.attempts > 1 && ` · ${e.attempts} attempts`}</div>
              {e.status === 'failed' && (
                <div className="mt-1 flex flex-wrap items-center gap-2"><span className="text-xs text-red-700">{e.failure_reason}</span>
                  <Button size="sm" variant="secondary" loading={retry.isPending && retry.variables?.emailId === e.id} onClick={() => retry.mutate({ emailId: e.id, quotationId: q.id }, { onError: (err) => alert(errorMessage(err)) })}>Retry</Button></div>
              )}
            </li>
          ))}
        </ul>
      )}
      {history.length > 0 && <p className="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">“Sent” means the mail server accepted the email; it does not prove it was read.</p>}
    </Card>
  )
}

function AcceptForm({ q, pending, onSubmit }: { q: Quotation; pending: boolean; onSubmit: (optionId: number) => void }) {
  const [choice, setChoice] = useState(String(q.options[0]?.id ?? ''))
  return (
    <div className="space-y-3">
      <Select value={choice} onChange={(e) => setChoice(e.target.value)} options={q.options.map((o) => ({ value: String(o.id), label: `${o.label}${o.pricing?.adult_per_person ? ` · ${Number(o.pricing.adult_per_person).toLocaleString()} ${o.currency} per adult` : ''}` }))} />
      <p className="text-xs text-slate-500">This only records the customer's choice. No ticket is bought and no reservation is made; create the booking in Bookings when you have reserved the flights.</p>
      <div className="flex justify-end"><Button loading={pending} disabled={!choice} onClick={() => onSubmit(Number(choice))}>Save</Button></div>
    </div>
  )
}

function ConvertForm({ q, pending, onSubmit }: { q: Quotation; pending: boolean; onSubmit: (bookingId: number) => void }) {
  const { data } = useBookings(q.customer_id ? { customer_id: q.customer_id, per_page: 50 } : { per_page: 50 })
  const [choice, setChoice] = useState('')
  const bookings = data?.data ?? []
  return (
    <div className="space-y-3">
      {bookings.length === 0 ? (
        <p className="text-sm text-slate-600">{q.customer ? `${q.customer.full_name} has no bookings yet.` : 'No bookings yet.'} <Link className="link" to="/bookings/new">Create the booking</Link> first, then link it here.</p>
      ) : (
        <Select value={choice} onChange={(e) => setChoice(e.target.value)} placeholder="Choose a booking" options={bookings.map((b) => ({ value: String(b.id), label: `${b.booking_code} · ${b.status}${b.pnr ? ` · ${b.pnr}` : ''}` }))} />
      )}
      <p className="text-xs text-slate-500">Linking does not change the booking. <Link className="link" to="/bookings/new">New booking</Link></p>
      <div className="flex justify-end"><Button loading={pending} disabled={!choice} onClick={() => onSubmit(Number(choice))}>Link booking</Button></div>
    </div>
  )
}
