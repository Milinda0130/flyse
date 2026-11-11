import { Link, useSearchParams } from 'react-router-dom'
import { useIssues, useMeta } from '../hooks/queries'
import { date, labelFor } from '../lib/format'
import { Card, Empty, Input, Loading, PageHeader, Pagination, RouteCodes, Select, Warn } from '../components/ui'
import { IssueStatusBadge, SeverityBadge } from '../components/Badges'

const KEYS = ['q', 'status', 'issue_type', 'severity', 'assigned_to', 'open_only', 'overdue', 'sort', 'page'] as const

export default function IssuesPage() {
  const { data: meta } = useMeta()
  const [params, setParams] = useSearchParams(params0())
  const f = Object.fromEntries(KEYS.map((k) => [k, params.get(k) ?? ''])) as Record<(typeof KEYS)[number], string>
  const set = (k: string, v: string) => {
    const next = new URLSearchParams(params)
    if (v) next.set(k, v)
    else next.delete(k)
    if (k !== 'page') next.delete('page')
    setParams(next)
  }

  const { data, isLoading } = useIssues(f)

  return (
    <div>
      <PageHeader title="Issues" subtitle="Cancellations, delays, schedule changes and refunds across all bookings. Create new issues from the booking page." />
      <Card className="mb-4">
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-7">
          <Input className="col-span-2" placeholder="Summary, booking code or PNR" defaultValue={f.q} key={f.q} onKeyDown={(e) => e.key === 'Enter' && set('q', (e.target as HTMLInputElement).value.trim())} aria-label="Search issues" />
          <Select value={f.open_only} onChange={(e) => set('open_only', e.target.value)} options={[{ value: '1', label: 'Open issues' }]} placeholder="All issues" aria-label="Open only" />
          <Select value={f.status} onChange={(e) => set('status', e.target.value)} options={meta?.issue_statuses ?? []} placeholder="Any status" aria-label="Status" />
          <Select value={f.issue_type} onChange={(e) => set('issue_type', e.target.value)} options={meta?.issue_types ?? []} placeholder="Any type" aria-label="Type" />
          <Select value={f.assigned_to} onChange={(e) => set('assigned_to', e.target.value)} options={(meta?.users ?? []).map((u) => ({ value: String(u.id), label: u.name }))} placeholder="Anyone" aria-label="Assigned to" />
          <Select value={f.sort} onChange={(e) => set('sort', e.target.value)} options={[{ value: 'severity', label: 'Sort: severity' }, { value: 'reported_at', label: 'Sort: newest' }]} placeholder="Sort: due date" aria-label="Sort" />
        </div>
        <label className="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" checked={f.overdue === '1'} onChange={(e) => set('overdue', e.target.checked ? '1' : '')} /> Overdue only</label>
      </Card>
      <Card bodyClass="overflow-x-auto">
        {isLoading ? <Loading /> : !data?.data.length ? <Empty>No issues match.</Empty> : (
          <table className="table-base">
            <thead><tr><th>Issue</th><th>Booking</th><th>Segment</th><th>Severity</th><th>Status</th><th>Next action</th><th>Due</th><th>Assigned</th></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {data.data.map((i) => (
                <tr key={i.id} className="hover:bg-slate-50">
                  <td><Link to={`/issues/${i.id}`} className="link">{i.summary}</Link><div className="text-xs text-slate-500">{labelFor(meta?.issue_types, i.issue_type)} · {labelFor(meta?.issue_sources, i.source)} · {date(i.reported_at)}</div></td>
                  <td><Link to={`/bookings/${i.booking_id}`} className="link">{i.booking?.booking_code}</Link><div className="text-xs text-slate-500">{i.booking?.customer} {i.booking?.pnr && `· ${i.booking.pnr}`}</div></td>
                  <td className="whitespace-nowrap text-xs">{i.flight ? <>{i.flight.flight_number} <RouteCodes from={i.flight.origin_code} to={i.flight.destination_code} /></> : '—'}</td>
                  <td><SeverityBadge severity={i.severity} /></td>
                  <td><IssueStatusBadge status={i.status} label={labelFor(meta?.issue_statuses, i.status)} /></td>
                  <td className="max-w-xs">{i.next_action ?? '—'}</td>
                  <td className={`whitespace-nowrap ${i.is_overdue ? 'font-semibold text-red-700' : ''}`}>{i.is_overdue ? <Warn>{date(i.due_at)}</Warn> : date(i.due_at)}</td>
                  <td>{i.assignee?.name ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <Pagination meta={data?.meta} onPage={(p) => set('page', String(p))} />
      </Card>
    </div>
  )
}

function params0() {
  return { open_only: '1' }
}
