import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { useAuth } from '../auth/AuthContext'
import { useDashboard, useMeta } from '../hooks/queries'
import { useTheme } from '../lib/theme'
import { date, labelFor, localTime, money } from '../lib/format'
import type { MonthlyRow } from '../lib/types'
import { CircleCheck } from 'lucide-react'
import { Card, Empty, ErrorBox, Loading, PageHeader, RouteCodes, Select, Stat, Warn } from '../components/ui'
import { BookingStatusBadge, IssueStatusBadge, SeverityBadge } from '../components/Badges'


// Categorical slots 1 and 2 of the validated palette, stepped separately for each theme
// (checked against the white and #191816 card surfaces).
const SERIES = {
  light: { sales: '#2a78d6', margin: '#eb6834', grid: '#e7e5e4', axis: '#d6d3d1', tick: '#57534e', cursor: 'rgba(120,113,108,0.12)' },
  dark: { sales: '#3987e5', margin: '#d95926', grid: '#292524', axis: '#44403c', tick: '#a8a29e', cursor: 'rgba(168,162,158,0.12)' },
}

export default function DashboardPage() {
  const { can } = useAuth()
  const { data: meta } = useMeta()
  const { data, isLoading, error } = useDashboard()

  if (isLoading) return <Loading />
  if (error || !data) return <ErrorBox message="Could not load the dashboard." />

  const balances = data.customer_balances.totals.filter((t) => Number(t.total) > 0)

  return (
    <div className="space-y-6">
      <PageHeader title="Dashboard" subtitle={`Today is ${date(new Date().toISOString())}`} actions={
        can('edit-records') && <Link to="/bookings/new" className="btn-primary">New booking</Link>
      } />

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <Stat label="Bookings this month" value={data.bookings.this_month} hint={`${data.bookings.total} in total`} />
        <Stat label="Open issues" value={data.issues.open} tone={data.issues.overdue ? 'danger' : 'default'} hint={data.issues.overdue ? <Warn className="font-medium text-red-700">{data.issues.overdue} overdue</Warn> : 'None overdue'} />
        <Stat
          label="Customer balances due"
          value={balances.length ? <span className="text-lg">{balances.map((b) => money(b.total, b.currency)).join(' · ')}</span> : '0'}
          tone={balances.length ? 'warn' : 'good'}
          hint={`${data.customer_balances.count} booking(s) with a balance`}
        />
        <Stat
          label="Refunds awaiting action"
          value={data.refunds_awaiting.open_refund_issues + data.refunds_awaiting.pending_refund_payments}
          hint={`${data.refunds_awaiting.open_refund_issues} open refund issue(s), ${data.refunds_awaiting.pending_refund_payments} pending refund payment(s)`}
        />
      </div>

      <div className="grid gap-6 xl:grid-cols-3">
        <Card title="Upcoming departures (next 14 days)" className="xl:col-span-2" bodyClass="overflow-x-auto">
          {data.upcoming_departures.length === 0 ? <Empty>No departures in the next 14 days.</Empty> : (
            <table className="table-base">
              <thead><tr><th>Departure (local)</th><th>Flight</th><th>Route</th><th>Booking</th><th>Customer</th><th>Pax</th><th>Status</th></tr></thead>
              <tbody className="divide-y divide-slate-100">
                {data.upcoming_departures.map((f) => (
                  <tr key={f.id} className={f.status === 'cancelled' || f.status === 'delayed' || f.status === 'changed' ? 'bg-amber-50/60' : ''}>
                    <td className="whitespace-nowrap">{localTime(f.departure_at)}</td>
                    <td className="whitespace-nowrap">{f.flight_number}<div className="text-xs text-slate-500">{f.airline}</div></td>
                    <td><RouteCodes from={f.origin_code} to={f.destination_code} /></td>
                    <td><Link className="link" to={`/bookings/${f.booking.id}`}>{f.booking.booking_code}</Link><div className="text-xs text-slate-500">{f.booking.pnr}</div></td>
                    <td>{f.booking.customer}<div className="text-xs text-slate-500">{f.booking.phone}</div></td>
                    <td>{f.booking.passengers_count}</td>
                    <td><BookingStatusBadge status={f.booking.status} label={labelFor(meta?.booking_statuses, f.booking.status)} />
                      {f.status !== 'scheduled' && <div className="mt-1 text-xs font-medium text-amber-700">Flight {labelFor(meta?.flight_statuses, f.status).toLowerCase()}</div>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>

        <div className="space-y-6">
          <Card title="Ticketing deadlines (≤ 3 days)">
            {data.ticketing_deadlines.length === 0 ? <Empty>No deadlines approaching.</Empty> : (
              <ul className="divide-y divide-slate-100 text-sm">
                {data.ticketing_deadlines.map((d) => (
                  <li key={d.id} className="flex items-center justify-between py-2">
                    <span><Link className="link" to={`/bookings/${d.id}`}>{d.booking_code}</Link> <span className="text-slate-500">{d.customer}</span></span>
                    <span className="text-xs font-medium text-red-700">{localTime(d.ticketing_deadline)}</span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
          <Card title="Payments due (≤ 3 days)">
            {data.customer_balances.overdue.length === 0 ? <Empty>No customer payments due.</Empty> : (
              <ul className="divide-y divide-slate-100 text-sm">
                {data.customer_balances.overdue.map((b) => (
                  <li key={b.booking_id} className="flex items-center justify-between py-2">
                    <span><Link className="link" to={`/bookings/${b.booking_id}`}>{b.booking_code}</Link> <span className="text-slate-500">{b.customer}</span></span>
                    <span className="text-right"><span className="font-medium">{money(b.balance, b.currency)}</span><div className="text-xs text-slate-500">due {date(b.payment_due_date)}</div></span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      </div>

      <Card title="Open issues" actions={<Link to="/issues" className="link text-sm">View all</Link>} bodyClass="overflow-x-auto">
        {data.issues.list.length === 0 ? <Empty><span className="inline-flex items-center gap-1.5 text-emerald-700"><CircleCheck className="h-4 w-4" aria-hidden /> No open issues.</span></Empty> : (
          <table className="table-base">
            <thead><tr><th>Issue</th><th>Booking</th><th>Severity</th><th>Status</th><th>Next action</th><th>Due</th><th>Assigned</th></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {data.issues.list.map((i) => (
                <tr key={i.id}>
                  <td><Link className="link" to={`/issues/${i.id}`}>{i.summary}</Link><div className="text-xs text-slate-500">{labelFor(meta?.issue_types, i.issue_type)}</div></td>
                  <td><Link className="link" to={`/bookings/${i.booking_id}`}>{i.booking?.booking_code}</Link><div className="text-xs text-slate-500">{i.booking?.customer}</div></td>
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
      </Card>

      {data.monthly && <MonthlyChart rows={data.monthly} defaultCurrency={meta?.default_currency} payables={data.supplier_payables ?? []} />}
    </div>
  )
}

function MonthlyChart({ rows, defaultCurrency, payables }: { rows: MonthlyRow[]; defaultCurrency?: string; payables: { currency: string; total: string }[] }) {
  const { isDark } = useTheme()
  const c = isDark ? SERIES.dark : SERIES.light
  const currencies = useMemo(() => Array.from(new Set(rows.map((r) => r.currency))).sort(), [rows])
  const [currency, setCurrency] = useState<string>('')
  const active = currency || (defaultCurrency && currencies.includes(defaultCurrency) ? defaultCurrency : currencies[0])
  const chartData = rows.filter((r) => r.currency === active).map((r) => ({
    month: r.group, sales: Number(r.sales), margin: Number(r.margin), bookings: r.bookings,
  }))
  const owing = payables.filter((p) => Number(p.total) !== 0)

  return (
    <Card
      title="Monthly sales and gross margin (last 12 months, by booking date)"
      actions={currencies.length > 1 && <Select aria-label="Currency" value={active} onChange={(e) => setCurrency(e.target.value)} options={currencies} className="w-24 py-1" />}
    >
      {chartData.length === 0 ? <Empty>No sales recorded yet.</Empty> : (
        <div className="h-64 sm:h-72 3xl:h-96">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={chartData} barGap={2} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
              <CartesianGrid vertical={false} stroke={c.grid} />
              <XAxis dataKey="month" tickLine={false} axisLine={{ stroke: c.axis }} tick={{ fontSize: 12, fill: c.tick }} />
              <YAxis tickLine={false} axisLine={false} width={64} tick={{ fontSize: 12, fill: c.tick }} tickFormatter={(v: number) => v.toLocaleString(undefined, { notation: 'compact' })} />
              <Tooltip
                cursor={{ fill: c.cursor }}
                contentStyle={{ background: 'rgb(var(--surface))', border: '1px solid rgb(var(--slate-200))', borderRadius: 8, color: 'rgb(var(--slate-800))' }}
                labelStyle={{ color: 'rgb(var(--slate-600))' }}
                formatter={(value: number, name: string) => [money(value, active), name]}
                labelFormatter={(l: string) => `Month ${l}`}
              />
              <Legend wrapperStyle={{ fontSize: 12, color: c.tick }} />
              <Bar dataKey="sales" name="Sales" fill={c.sales} radius={[4, 4, 0, 0]} maxBarSize={28} />
              <Bar dataKey="margin" name="Gross margin" fill={c.margin} radius={[4, 4, 0, 0]} maxBarSize={28} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      )}
      {owing.length > 0 && (
        <p className="mt-3 text-sm text-slate-600">Supplier payables outstanding: <span className="font-medium">{owing.map((p) => money(p.total, p.currency)).join(' · ')}</span>. See <Link to="/reports" className="link">Reports</Link> for the table view.</p>
      )}
    </Card>
  )
}
