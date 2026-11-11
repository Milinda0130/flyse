import { useState } from 'react'
import { Link } from 'react-router-dom'
import { FileText, Plus, TriangleAlert } from 'lucide-react'
import { useAuth } from '../../auth/AuthContext'
import { useMeta } from '../../hooks/queries'
import { date, labelFor } from '../../lib/format'
import type { Booking } from '../../lib/types'
import { Button, Card, Empty, Modal, Warn } from '../../components/ui'
import { IssueStatusBadge, SeverityBadge } from '../../components/Badges'
import DocumentList from '../../components/DocumentList'
import IssueForm from '../../components/IssueForm'
import EmailHistory from '../../components/EmailHistory'

export type DocSub = 'documents' | 'issues'

/** Documents and Issues & Changes together: where an airline email is handled. */
export default function DocumentsIssuesTab({ booking, initialSub = 'documents', onEmailTickets }: { booking: Booking; initialSub?: DocSub; onEmailTickets?: () => void }) {
  const { can } = useAuth()
  const { data: meta } = useMeta()
  const [sub, setSub] = useState<DocSub>(initialSub)
  const [reporting, setReporting] = useState(false)
  const issues = booking.issues ?? []
  const open = issues.filter((i) => !['resolved', 'closed'].includes(i.status))

  return (
    <div className="space-y-4">
      <div className="inline-flex rounded-md border border-slate-300 bg-surface p-0.5" role="tablist" aria-label="Documents and issues">
        {([['documents', <><FileText className="h-4 w-4" aria-hidden /> Documents ({booking.documents?.length ?? 0})</>], ['issues', <><TriangleAlert className="h-4 w-4" aria-hidden /> Issues & changes ({issues.length})</>]] as const).map(([key, label]) => (
          <button key={key} type="button" role="tab" aria-selected={sub === key} onClick={() => setSub(key)}
            className={`inline-flex items-center gap-1.5 rounded px-3 py-1.5 text-sm font-medium transition ${sub === key ? 'bg-gold text-ink' : 'text-slate-600 hover:text-slate-900'}`}>
            {label}
            {key === 'issues' && open.length > 0 && <span className="rounded-full bg-danger px-1.5 text-xs text-white">{open.length} open</span>}
          </button>
        ))}
      </div>

      {sub === 'documents' ? (
        <Card title="Documents" bodyClass="p-4">
          <DocumentList bookingId={booking.id} documents={booking.documents ?? []} passengers={booking.passengers} flights={booking.flights} issues={issues}
            defaultType={booking.status === 'inquiry' ? 'confirmation' : 'e_ticket'} />
          <p className="mt-3 text-xs text-slate-500">Issued e-tickets are best recorded with <strong>Issue tickets</strong> (Passengers & Flights) so each ticket links to its file.</p>
        </Card>
      ) : null}
      {sub === 'documents' ? (
        <EmailHistory bookingId={booking.id} onEmail={booking.status !== 'cancelled' ? onEmailTickets : undefined} />
      ) : (
        <Card title="Issues & changes" actions={can('edit-records') && <Button size="sm" onClick={() => setReporting(true)}><Plus className="h-4 w-4" aria-hidden /> Report issue</Button>} bodyClass="overflow-x-auto">
          {issues.length === 0 ? <Empty>No issues recorded. When an airline email arrives, report it here and attach the email.</Empty> : (
            <table className="table-base">
              <thead><tr><th>Issue</th><th>Segment</th><th>Priority</th><th>Status</th><th>Next action</th><th>Due</th></tr></thead>
              <tbody className="divide-y divide-slate-100">
                {issues.map((i) => {
                  const seg = booking.flights?.find((f) => f.id === i.booking_flight_id)
                  return (
                    <tr key={i.id}>
                      <td><Link to={`/issues/${i.id}`} className="link">{i.summary}</Link><div className="text-xs text-slate-500">{labelFor(meta?.issue_types, i.issue_type)} · {labelFor(meta?.issue_sources, i.source)} · {date(i.reported_at)}</div></td>
                      <td className="whitespace-nowrap font-mono text-xs">{seg ? `${seg.flight_number} ${seg.origin_code}-${seg.destination_code}` : '—'}</td>
                      <td><SeverityBadge severity={i.severity} /></td>
                      <td><IssueStatusBadge status={i.status} label={labelFor(meta?.issue_statuses, i.status)} /></td>
                      <td className="max-w-xs">{i.next_action ?? '—'}</td>
                      <td className="whitespace-nowrap">{i.is_overdue ? <Warn className="font-semibold text-red-700">{date(i.due_at)}</Warn> : date(i.due_at)}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          )}
          <p className="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">Recording an issue never changes a ticket by itself. Verify with the airline, then update the flights and status.</p>
        </Card>
      )}

      <Modal open={reporting} title={`Report issue · ${booking.booking_code}`} onClose={() => setReporting(false)} wide>
        {reporting && <IssueForm booking={booking} onDone={() => setReporting(false)} />}
      </Modal>
    </div>
  )
}
