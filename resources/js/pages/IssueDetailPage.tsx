import { useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { errorMessage } from '../lib/api'
import { useAddIssueUpdate } from '../hooks/mutations'
import { useAuth } from '../auth/AuthContext'
import { useIssue, useMeta } from '../hooks/queries'
import { dateTime, labelFor } from '../lib/format'
import { ArrowRight } from 'lucide-react'
import { Button, Card, DefinitionList, ErrorBox, Loading, Modal, PageHeader, RouteCodes, Select, Textarea, Warn } from '../components/ui'
import { IssueStatusBadge, SeverityBadge } from '../components/Badges'
import IssueForm from '../components/IssueForm'
import DocumentList from '../components/DocumentList'
import AuditTable from '../components/AuditTable'

export default function IssueDetailPage() {
  const { id } = useParams()
  const { can } = useAuth()
  const { data: meta } = useMeta()
  const addIssueUpdate = useAddIssueUpdate()
  const [editing, setEditing] = useState(false)
  const [note, setNote] = useState('')
  const [status, setStatus] = useState('')

  const { data: issue, isLoading } = useIssue(id)

  const addUpdate = (e: FormEvent) => {
    e.preventDefault()
    addIssueUpdate.mutate({ issueId: id!, payload: { update_text: note, status: status || null } }, {
      onSuccess: () => {
        setNote('')
        setStatus('')
      },
    })
  }

  if (isLoading || !issue) return <Loading />

  return (
    <div className="space-y-4">
      <PageHeader
        title={<span className="flex flex-wrap items-center gap-3">{issue.summary}<IssueStatusBadge status={issue.status} label={labelFor(meta?.issue_statuses, issue.status)} /><SeverityBadge severity={issue.severity} /></span>}
        subtitle={<>Booking <Link to={`/bookings/${issue.booking_id}`} className="link">{issue.booking?.booking_code}</Link> · {issue.booking?.customer}{issue.booking?.pnr && ` · PNR ${issue.booking.pnr}`}</>}
        actions={can('edit-records') && <Button variant="secondary" onClick={() => setEditing(true)}>Edit issue</Button>}
      />
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <Card title="Details">
            <DefinitionList items={[
              ['Type', labelFor(meta?.issue_types, issue.issue_type)],
              ['Source', labelFor(meta?.issue_sources, issue.source)],
              ['Affected segment', issue.flight ? <>{issue.flight.flight_number} <RouteCodes from={issue.flight.origin_code} to={issue.flight.destination_code} /></> : 'Whole booking'],
              ['Reported', dateTime(issue.reported_at)],
              ['Due', <span className={issue.is_overdue ? 'font-semibold text-red-700' : ''}>{issue.is_overdue ? <Warn>{dateTime(issue.due_at)}</Warn> : dateTime(issue.due_at)}</span>],
              ['Assigned to', issue.assignee?.name ?? 'Unassigned'],
              ['Next action', issue.next_action ?? '—'],
              ['Customer notified', issue.customer_notified ? 'Yes' : 'No'],
              ['Refund reference', issue.refund_reference ?? '—'],
              ['Resolved', dateTime(issue.resolved_at)],
            ]} />
            {issue.description && <div className="mt-4 whitespace-pre-line rounded-md bg-slate-50 p-3 text-sm">{issue.description}</div>}
            {issue.resolution_notes && <div className="mt-3 rounded-md bg-emerald-50 p-3 text-sm"><strong>Resolution:</strong> {issue.resolution_notes}</div>}
          </Card>
          {issue.flight && (issue.flight_history?.length ?? 0) > 0 && (
            <Card title={`Schedule history · ${issue.flight.flight_number} ${issue.flight.origin_code}-${issue.flight.destination_code}`} bodyClass="">
              <p className="px-4 pt-3 text-xs text-slate-500">Previous values are kept whenever the segment is edited.</p>
              <AuditTable entries={issue.flight_history ?? []} />
            </Card>
          )}
          <Card title="Linked documents (airline emails saved as PDF, attachments)">
            <DocumentList bookingId={issue.booking_id} issueId={issue.id} documents={issue.documents ?? []} defaultType="airline_email" />
          </Card>
        </div>
        <Card title="Timeline">
          {can('edit-records') && (
            <form onSubmit={addUpdate} className="mb-4 space-y-2">
              {addIssueUpdate.isError && <ErrorBox message={errorMessage(addIssueUpdate.error)} />}
              <Textarea required rows={3} placeholder="What happened / what was done?" value={note} onChange={(e) => setNote(e.target.value)} aria-label="Update note" />
              <div className="flex gap-2">
                <Select value={status} onChange={(e) => setStatus(e.target.value)} options={meta?.issue_statuses ?? []} placeholder="Keep status" aria-label="New status" />
                <Button type="submit" loading={addIssueUpdate.isPending}>Add</Button>
              </div>
            </form>
          )}
          <ol className="space-y-3 border-l-2 border-slate-200 pl-4">
            {issue.updates?.map((u) => (
              <li key={u.id} className="relative text-sm">
                <span className="absolute -left-[1.4rem] top-1 h-2.5 w-2.5 rounded-full bg-gold ring-2 ring-surface" />
                <div className="text-xs text-slate-500">{dateTime(u.created_at)} · {u.author?.name ?? 'System'}</div>
                {u.status_to && <div className="mt-0.5 flex items-center gap-1 text-xs">{u.status_from && <>{labelFor(meta?.issue_statuses, u.status_from)} <ArrowRight className="h-3 w-3 text-slate-400" aria-label="changed to" /></>}<strong>{labelFor(meta?.issue_statuses, u.status_to)}</strong></div>}
                <div className="whitespace-pre-line">{u.update_text}</div>
              </li>
            ))}
          </ol>
        </Card>
      </div>
      <Modal open={editing} title="Edit issue" onClose={() => setEditing(false)} wide>
        {editing && <IssueForm issue={issue} onDone={() => setEditing(false)} />}
      </Modal>
    </div>
  )
}
