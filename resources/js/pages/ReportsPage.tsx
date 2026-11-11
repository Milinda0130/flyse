import { useMemo, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Minus, TrendingDown, TrendingUp } from 'lucide-react'
import { cleanParams, errorMessage } from '../lib/api'
import { useDownload } from '../hooks/mutations'
import { useIssuesReport, useMeta, useOutstandingReport, useReportOverview, useSalesReport, useSuppliers } from '../hooks/queries'
import { date, money } from '../lib/format'
import type { OverviewKpis, ReportOverview } from '../lib/types'
import { Button, Card, Empty, ErrorBox, Field, Input, Loading, PageHeader, Select, Stat, Tabs } from '../components/ui'
import { ChartCard, Donut, GroupedBars, RankedBars, StackedCountBars } from '../components/charts'

const iso = (d: Date) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)

function firstOfMonth() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`
}

/** Quick periods for the dashboard. */
const PRESETS: { key: string; label: string; range: () => [string, string] }[] = [
  { key: 'month', label: 'This month', range: () => [firstOfMonth(), iso(new Date())] },
  { key: '3m', label: '3 months', range: () => { const d = new Date(); return [iso(new Date(d.getFullYear(), d.getMonth() - 2, 1)), iso(d)] } },
  { key: '12m', label: '12 months', range: () => { const d = new Date(); return [iso(new Date(d.getFullYear(), d.getMonth() - 11, 1)), iso(d)] } },
  { key: 'year', label: 'This year', range: () => { const d = new Date(); return [`${d.getFullYear()}-01-01`, iso(d)] } },
]

/** Filter fields wrap to fit any width: each field keeps at least 10rem. */
function FilterBar({ children }: { children: ReactNode }) {
  return (
    <Card className="no-print mb-4">
      <div className="grid items-end gap-3 [grid-template-columns:repeat(auto-fill,minmax(10rem,1fr))]">{children}</div>
    </Card>
  )
}

export default function ReportsPage() {
  const { data: meta } = useMeta()
  const [tab, setTab] = useState('overview')
  const [filters, setFilters] = useState({ from: firstOfMonth(), to: '', date_basis: 'booking_date', group_by: 'month', currency: '', supplier_id: '', airline: '' })
  const set = (k: keyof typeof filters, v: string) => setFilters((f) => ({ ...f, [k]: v }))
  const download = useDownload()
  const { data: suppliers } = useSuppliers()

  const exportCsv = (report: string) =>
    download.mutate({ url: `/reports/export?${new URLSearchParams({ report, ...cleanParams(filters) } as Record<string, string>)}`, filename: `${report}.csv` })

  const supplierOptions = (suppliers ?? []).map((s) => ({ value: String(s.id), label: s.name }))

  return (
    <div>
      <PageHeader title="Reports" subtitle="Amounts are never added across currencies." actions={<Button variant="secondary" onClick={() => window.print()}>Print / save as PDF</Button>} />
      {download.isError && <div className="mb-3"><ErrorBox message={errorMessage(download.error)} /></div>}
      <Tabs
        tabs={[{ key: 'overview', label: 'Overview' }, { key: 'sales', label: 'Sales & margin' }, { key: 'outstanding', label: 'Balances & payables' }, { key: 'issues', label: 'Issues & refunds' }]}
        active={tab}
        onChange={setTab}
      />

      {tab === 'overview' && <OverviewDashboard />}

      {tab !== 'overview' && (
        <FilterBar>
          {tab !== 'outstanding' && <>
            <Field label={tab === 'issues' ? 'Reported from' : filters.date_basis === 'departure_date' ? 'Departure from' : 'Booked from'} className="min-w-0">
              <Input type="date" value={filters.from} onChange={(e) => set('from', e.target.value)} />
            </Field>
            <Field label="To" className="min-w-0"><Input type="date" value={filters.to} onChange={(e) => set('to', e.target.value)} /></Field>
          </>}
          {tab === 'sales' && <>
            <Field label="Date basis" className="min-w-0"><Select value={filters.date_basis} onChange={(e) => set('date_basis', e.target.value)} options={[{ value: 'booking_date', label: 'Booking date' }, { value: 'departure_date', label: 'Departure date' }]} /></Field>
            <Field label="Group by" className="min-w-0"><Select value={filters.group_by} onChange={(e) => set('group_by', e.target.value)} options={[
              { value: 'month', label: 'Month' }, { value: 'supplier', label: 'Supplier' }, { value: 'airline', label: 'Airline' }, { value: 'route', label: 'Route' }, { value: 'status', label: 'Status' },
            ]} /></Field>
            <Field label="Airline" className="min-w-0"><Input value={filters.airline} onChange={(e) => set('airline', e.target.value)} placeholder="Any" /></Field>
          </>}
          {tab !== 'issues' && <>
            <Field label="Currency" className="min-w-0"><Select value={filters.currency} onChange={(e) => set('currency', e.target.value)} options={meta?.currencies ?? []} placeholder="All" /></Field>
            <Field label="Supplier" className="min-w-0"><Select value={filters.supplier_id} onChange={(e) => set('supplier_id', e.target.value)} options={supplierOptions} placeholder="All" /></Field>
          </>}
        </FilterBar>
      )}

      {tab === 'sales' && <SalesReport filters={filters} onExport={() => exportCsv('sales')} />}
      {tab === 'outstanding' && <OutstandingReport filters={filters} onExport={exportCsv} />}
      {tab === 'issues' && <IssuesReport filters={filters} onExport={() => exportCsv('issues')} />}
    </div>
  )
}

// ---------------------------------------------------------------- Overview dashboard

function OverviewDashboard() {
  const { data: meta } = useMeta()
  const [preset, setPreset] = useState('12m')
  const [range, setRange] = useState(() => PRESETS[2].range())
  const [currency, setCurrency] = useState('')
  const [basis, setBasis] = useState('booking_date')
  const activeCurrency = currency || meta?.default_currency || ''
  const { data, isLoading, isFetching, error } = useReportOverview({ from: range[0], to: range[1], currency: activeCurrency, date_basis: basis })

  const choosePreset = (key: string) => {
    setPreset(key)
    setRange(PRESETS.find((p) => p.key === key)!.range())
  }

  return (
    <div className="space-y-4">
      <FilterBar>
        <div className="col-span-full flex flex-wrap gap-1.5" role="group" aria-label="Period">
          {PRESETS.map((p) => (
            <button
              key={p.key}
              type="button"
              aria-pressed={preset === p.key}
              onClick={() => choosePreset(p.key)}
              className={`rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset transition ${preset === p.key ? 'bg-gold text-ink ring-gold' : 'bg-surface text-slate-600 ring-slate-300 hover:bg-slate-50'}`}
            >
              {p.label}
            </button>
          ))}
          {isFetching && <span className="self-center text-xs text-slate-500">Updating…</span>}
        </div>
        <Field label="From" className="min-w-0"><Input type="date" value={range[0]} onChange={(e) => { setPreset(''); setRange([e.target.value, range[1]]) }} /></Field>
        <Field label="To" className="min-w-0"><Input type="date" value={range[1]} onChange={(e) => { setPreset(''); setRange([range[0], e.target.value]) }} /></Field>
        <Field label="Date basis" className="min-w-0"><Select value={basis} onChange={(e) => setBasis(e.target.value)} options={[{ value: 'booking_date', label: 'Booking date' }, { value: 'departure_date', label: 'Departure date' }]} /></Field>
        <Field label="Currency" className="min-w-0"><Select value={activeCurrency} onChange={(e) => setCurrency(e.target.value)} options={meta?.currencies ?? []} /></Field>
      </FilterBar>

      {error ? <ErrorBox message={errorMessage(error)} /> : isLoading || !data ? <Loading /> : <OverviewContent data={data} />}
    </div>
  )
}

/** Percentage change vs the previous period; null when there is nothing to compare. */
function change(now: number, before: number) {
  if (!before) return null
  return Math.round(((now - before) / Math.abs(before)) * 1000) / 10
}

function Trend({ value, invert = false }: { value: number | null; invert?: boolean }) {
  if (value === null) return <span className="text-xs text-slate-500">No earlier data</span>
  const up = value > 0
  const good = value === 0 ? null : invert ? !up : up
  const Icon = value === 0 ? Minus : up ? TrendingUp : TrendingDown
  return (
    <span className={`inline-flex items-center gap-1 text-xs font-medium ${good === null ? 'text-slate-500' : good ? 'text-emerald-700' : 'text-red-700'}`}>
      <Icon className="h-3.5 w-3.5" aria-hidden />
      {up ? '+' : ''}{value}% <span className="font-normal text-slate-500">vs previous</span>
    </span>
  )
}

function Kpi({ label, value, sub, trend }: { label: string; value: ReactNode; sub?: ReactNode; trend?: ReactNode }) {
  return (
    <div className="card relative overflow-hidden p-4">
      <span className="absolute inset-x-0 top-0 h-0.5 bg-gradient-to-r from-gold/70 via-gold/30 to-transparent" aria-hidden />
      <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
      <div className="mt-1 break-words text-xl font-semibold tabular-nums text-slate-900 sm:text-2xl">{value}</div>
      {sub && <div className="mt-0.5 text-xs text-slate-500">{sub}</div>}
      {trend && <div className="mt-1.5">{trend}</div>}
    </div>
  )
}

function OverviewContent({ data }: { data: ReportOverview }) {
  const k = data.kpis
  const p: OverviewKpis = data.previous
  const cur = data.currency
  const m = (v: string | number) => money(v, cur)
  const n = Number

  const monthly = useMemo(() => data.monthly.map((r) => ({ label: r.label, sales: Number(r.sales), margin: Number(r.margin) })), [data.monthly])
  const statusTotal = data.by_status.reduce((s, r) => s + r.count, 0)

  return (
    <div className="space-y-4">
      <p className="text-xs text-slate-500">
        {date(data.period.from)} – {date(data.period.to)} · compared with {date(data.period.previous_from)} – {date(data.period.previous_to)} · {cur} only
      </p>

      <div className="grid grid-cols-1 gap-4 min-[420px]:grid-cols-2 xl:grid-cols-4">
        <Kpi label="Sales" value={m(k.sales)} sub={`${k.bookings} booking(s) · ${k.passengers} passenger(s)`} trend={<Trend value={change(n(k.sales), n(p.sales))} />} />
        <Kpi label="Gross margin" value={m(k.margin)} sub={k.margin_pct === null ? 'No sales' : `${k.margin_pct}% of sales`} trend={<Trend value={change(n(k.margin), n(p.margin))} />} />
        <Kpi label="Net estimate" value={m(k.net)} sub={`After ${m(k.expenses)} expenses`} trend={<Trend value={change(n(k.net), n(p.net))} />} />
        <Kpi label="Average booking" value={m(k.avg_booking_value)} sub="Sales ÷ bookings" trend={<Trend value={change(n(k.avg_booking_value), n(p.avg_booking_value))} />} />
        <Kpi label="Customers owe" value={m(k.customer_outstanding)} sub="Current position, all dates" />
        <Kpi label="Owed to suppliers" value={m(k.supplier_payable)} sub="Current position, all dates" />
        <Kpi label="Cancellations" value={k.cancelled} sub={k.cancellation_rate === null ? '—' : `${k.cancellation_rate}% of confirmed bookings`} trend={<Trend value={change(k.cancelled, p.cancelled)} invert />} />
        <Kpi label="Open inquiries" value={k.inquiries} sub="Not yet confirmed" />
      </div>
      {!!k.incomplete && (
        <p className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
          {k.incomplete} booking(s) in this period have no selling price or supplier cost entered, so sales and margin are incomplete.
        </p>
      )}

      <div className="grid gap-4 xl:grid-cols-3">
        <ChartCard title="Sales and gross margin by month" caption={`${cur} · ${data.monthly.length} month(s)`} className="xl:col-span-2">
          <GroupedBars data={monthly} xKey="label" currency={cur} series={[{ key: 'sales', label: 'Sales' }, { key: 'margin', label: 'Gross margin' }]} height={380} />
        </ChartCard>
        <ChartCard title="Bookings by status" caption="All bookings in the period, by count">
          <Donut data={data.by_status.map((s) => ({ name: s.label, value: s.count, share: s.share }))} centerLabel="Bookings" centerValue={String(statusTotal)} format={(v) => String(v)} />
        </ChartCard>
      </div>

      <div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
        <ChartCard title="Sales by airline" caption="Top 8, by first segment's airline">
          <RankedBars data={data.by_airline} valueKey="sales" label="Sales" currency={cur} />
        </ChartCard>
        <ChartCard title="Sales share by supplier" caption="Top 5 + other">
          <Donut data={data.by_supplier.map((s) => ({ name: s.name, value: n(s.sales), share: s.share }))} centerLabel="Sales" centerValue={compactMoney(n(k.sales))} format={(v) => m(v)} />
        </ChartCard>
        <ChartCard title="Customer payments by method" caption="Receipts in the period">
          <Donut data={data.payment_methods.map((s) => ({ name: s.name, value: n(s.amount), share: s.share }))} centerLabel="Received"
            centerValue={compactMoney(data.payment_methods.reduce((s, r) => s + n(r.amount), 0))} format={(v) => m(v)} />
        </ChartCard>
      </div>

      <div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
        <ChartCard title="Top routes" caption="By sales">
          <RankedBars data={data.by_route} valueKey="sales" label="Sales" currency={cur} />
        </ChartCard>
        <ChartCard title="Issues by type" caption="Reported in the period">
          <StackedCountBars data={data.issues_by_type} keys={[{ key: 'open', label: 'Open' }, { key: 'resolved', label: 'Resolved / closed' }]} />
        </ChartCard>
        <ChartCard title="Top customers" caption="By sales">
          {data.top_customers.length === 0 ? <Empty>No sales in this period.</Empty> : (
            <ol className="divide-y divide-slate-100 text-sm">
              {data.top_customers.map((c, i) => (
                <li key={c.name} className="flex items-center gap-3 py-2">
                  <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gold/15 text-xs font-semibold text-brand-700">{i + 1}</span>
                  <span className="min-w-0 flex-1 truncate text-slate-800">{c.name}<span className="block text-xs text-slate-500">{c.bookings} booking(s)</span></span>
                  <span className="text-right tabular-nums"><span className="block font-medium text-slate-900">{m(c.sales)}</span><span className="text-xs text-slate-500">margin {m(c.margin ?? 0)}</span></span>
                </li>
              ))}
            </ol>
          )}
        </ChartCard>
      </div>

      <Card title="Monthly figures (table)" bodyClass="overflow-x-auto">
        <table className="table-base">
          <thead><tr><th>Month</th><th className="text-right">Bookings</th><th className="text-right">Sales</th><th className="text-right">Supplier cost</th><th className="text-right">Gross margin</th></tr></thead>
          <tbody className="divide-y divide-slate-100">
            {data.monthly.map((r) => (
              <tr key={r.month}><td>{r.label}</td><td className="text-right">{r.bookings}</td><td className="text-right">{m(r.sales)}</td><td className="text-right">{m(r.cost)}</td><td className="text-right font-medium">{m(r.margin)}</td></tr>
            ))}
          </tbody>
        </table>
      </Card>
    </div>
  )
}

function compactMoney(v: number) {
  return v.toLocaleString(undefined, { notation: 'compact', maximumFractionDigits: 1 })
}

// ---------------------------------------------------------------- Detailed reports

function SalesReport({ filters, onExport }: { filters: Record<string, string>; onExport: () => void }) {
  const { data, isLoading } = useSalesReport(filters)
  if (isLoading || !data) return <Loading />
  return (
    <div className="space-y-4">
      {data.totals.filter((t) => t.incomplete > 0).map((t) => (
        <p key={`incomplete-${t.currency}`} className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
          {t.currency}: {t.incomplete} booking(s) have no selling price or supplier cost entered, so these figures are incomplete.
        </p>
      ))}
      {data.totals.map((t) => (
        <div key={t.currency} className="grid grid-cols-1 gap-4 min-[420px]:grid-cols-2 lg:grid-cols-5">
          <Stat label={`Sales (${t.currency})`} value={money(t.sales)} hint={`${t.bookings} booking(s)`} />
          <Stat label="Supplier cost" value={money(t.cost)} />
          <Stat label="Gross margin" value={money(t.gross_margin)} tone={Number(t.gross_margin) < 0 ? 'danger' : 'good'} />
          <Stat label="Expenses (same period)" value={money(t.expenses)} hint="By expense date" />
          <Stat label="Net estimate" value={money(t.net_estimate)} tone={Number(t.net_estimate) < 0 ? 'danger' : 'default'} hint="Margin − expenses; not formal accounts" />
        </div>
      ))}
      {data.totals.map((t) => {
        const rows = data.rows.filter((r) => r.currency === t.currency).map((r) => ({ group: r.group, sales: Number(r.sales), margin: Number(r.margin) }))
        return rows.length > 0 && (
          <ChartCard key={`chart-${t.currency}`} title={`Sales and gross margin by ${filters.group_by}`} caption={t.currency}>
            <GroupedBars data={rows} xKey="group" currency={t.currency} series={[{ key: 'sales', label: 'Sales' }, { key: 'margin', label: 'Gross margin' }]} height={280} />
          </ChartCard>
        )
      })}
      <Card title="Breakdown" actions={<Button size="sm" variant="secondary" onClick={onExport}>Export CSV</Button>} bodyClass="overflow-x-auto">
        {data.rows.length === 0 ? <Empty>No sales in this period. Inquiries and cancelled bookings are excluded.</Empty> : (
          <table className="table-base">
            <thead><tr><th>{filters.group_by}</th><th>Currency</th><th className="text-right">Bookings</th><th className="text-right">Sales</th><th className="text-right">Supplier cost</th><th className="text-right">Gross margin</th></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {data.rows.map((r) => (
                <tr key={r.group + r.currency}>
                  <td className="font-medium">{r.group}</td><td>{r.currency}</td><td className="text-right">{r.bookings}</td>
                  <td className="text-right">{money(r.sales)}</td><td className="text-right">{money(r.cost)}</td>
                  <td className={`text-right font-medium ${Number(r.margin) < 0 ? 'text-red-700' : ''}`}>{money(r.margin)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <p className="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">Sales = ticket selling amount + service fee. Includes Pending Payment, Confirmed, Ticketed, Changed, On Hold and Completed bookings.</p>
      </Card>
    </div>
  )
}


function OutstandingReport({ filters, onExport }: { filters: Record<string, string>; onExport: (r: string) => void }) {
  const { data, isLoading } = useOutstandingReport({ currency: filters.currency, supplier_id: filters.supplier_id })
  if (isLoading || !data) return <Loading />
  return (
    <div className="space-y-4">
      <Card title={<>Customer balances {data.customer_totals.map((t) => <span key={t.currency} className="ml-2 font-normal text-slate-500">{money(t.total, t.currency)}</span>)}</>}
        actions={<Button size="sm" variant="secondary" onClick={() => onExport('customer_balances')}>Export CSV</Button>} bodyClass="overflow-x-auto">
        {data.customer_balances.length === 0 ? <Empty>All customer balances are settled.</Empty> : (
          <table className="table-base">
            <thead><tr><th>Booking</th><th>Customer</th><th>Payment due</th><th className="text-right">Due</th><th className="text-right">Paid</th><th className="text-right">Balance</th></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {data.customer_balances.map((r) => (
                <tr key={r.booking_id}>
                  <td><Link to={`/bookings/${r.booking_id}?tab=finance`} className="link">{r.booking_code}</Link></td>
                  <td>{r.customer}<div className="text-xs text-slate-500">{r.phone}</div></td>
                  <td>{date(r.payment_due_date)}</td>
                  <td className="text-right">{money(r.customer_due, r.currency)}</td>
                  <td className="text-right">{money(r.customer_paid, r.currency)}</td>
                  <td className={`text-right font-semibold ${Number(r.balance) < 0 ? 'text-violet-800' : 'text-amber-800'}`}>{money(r.balance, r.currency)}{Number(r.balance) < 0 && <div className="text-xs font-normal">refund owed</div>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
      <Card title={<>Supplier payables {data.supplier_totals.map((t) => <span key={t.currency} className="ml-2 font-normal text-slate-500">{money(t.total, t.currency)}</span>)}</>}
        actions={<Button size="sm" variant="secondary" onClick={() => onExport('supplier_payables')}>Export CSV</Button>} bodyClass="overflow-x-auto">
        {data.supplier_payables.length === 0 ? <Empty>No supplier balances outstanding.</Empty> : (
          <table className="table-base">
            <thead><tr><th>Booking</th><th>Supplier</th><th className="text-right">Supplier cost</th><th className="text-right">Paid (net)</th><th className="text-right">Balance</th></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {data.supplier_payables.map((r) => (
                <tr key={r.booking_id}>
                  <td><Link to={`/bookings/${r.booking_id}?tab=finance`} className="link">{r.booking_code}</Link></td>
                  <td>{r.supplier}</td>
                  <td className="text-right">{money(r.supplier_total, r.currency)}</td>
                  <td className="text-right">{money(r.supplier_paid, r.currency)}</td>
                  <td className="text-right font-semibold">{money(r.balance, r.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
    </div>
  )
}


function IssuesReport({ filters, onExport }: { filters: Record<string, string>; onExport: () => void }) {
  const { data: meta } = useMeta()
  const { data, isLoading, error } = useIssuesReport({ from: filters.from, to: filters.to })
  if (error) return <ErrorBox message={errorMessage(error)} />
  if (isLoading || !data) return <Loading />
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-3 gap-4">
        <Stat label="Issues reported" value={data.total} />
        <Stat label="Still open" value={data.open} tone={data.open ? 'warn' : 'good'} />
        <Stat label="Open refund issues" value={data.open_refund_issues} />
      </div>
      <Card title="By issue type" actions={<Button size="sm" variant="secondary" onClick={onExport}>Export CSV</Button>} bodyClass="overflow-x-auto">
        <table className="table-base">
          <thead><tr><th>Type</th><th className="text-right">Total</th><th className="text-right">Open</th><th className="text-right">Resolved / closed</th></tr></thead>
          <tbody className="divide-y divide-slate-100">
            {data.by_type.filter((t) => t.total > 0).map((t) => (
              <tr key={t.type}><td>{t.label}</td><td className="text-right">{t.total}</td><td className="text-right">{t.open}</td><td className="text-right">{t.resolved}</td></tr>
            ))}
          </tbody>
        </table>
        {data.by_type.every((t) => t.total === 0) && <Empty>No issues in this period.</Empty>}
      </Card>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card title={`Cancelled bookings (${data.cancellations.length})`} bodyClass="overflow-x-auto">
          {data.cancellations.length === 0 ? <Empty>None.</Empty> : (
            <table className="table-base">
              <thead><tr><th>Booking</th><th>Customer</th><th>PNR</th><th>Last updated</th></tr></thead>
              <tbody className="divide-y divide-slate-100">
                {data.cancellations.map((c) => (
                  <tr key={c.booking_id}><td><Link className="link" to={`/bookings/${c.booking_id}`}>{c.booking_code}</Link></td><td>{c.customer}</td><td className="font-mono">{c.pnr ?? '—'}</td><td>{date(c.cancelled_on)}</td></tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
        <Card title={`Refunds (${data.refunds.length})`} bodyClass="overflow-x-auto">
          {data.refunds.length === 0 ? <Empty>None.</Empty> : (
            <table className="table-base">
              <thead><tr><th>Booking</th><th>Type</th><th className="text-right">Amount</th><th>Status</th><th>Date</th></tr></thead>
              <tbody className="divide-y divide-slate-100">
                {data.refunds.map((r) => (
                  <tr key={r.payment_id}>
                    <td><Link className="link" to={`/bookings/${r.booking_id}?tab=finance`}>{r.booking_code}</Link></td>
                    <td>{meta?.payment_directions.find((d) => d.value === r.direction)?.label}</td>
                    <td className="text-right">{money(r.amount, r.currency)}</td>
                    <td className={r.status === 'pending' ? 'font-medium text-amber-700' : ''}>{r.status}</td>
                    <td>{date(r.transaction_date)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      </div>
    </div>
  )
}
