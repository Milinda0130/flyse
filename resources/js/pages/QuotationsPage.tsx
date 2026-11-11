import { Link, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { useMeta, useQuotations } from '../hooks/queries'
import { date } from '../lib/format'
import { Card, Empty, ErrorBox, Input, Loading, PageHeader, Pagination, Select } from '../components/ui'
import { QuotationStatusBadge } from '../components/Badges'
import { errorMessage } from '../lib/api'

const FILTER_KEYS = ['q', 'status', 'currency', 'departure_from', 'departure_to', 'page'] as const

/** All flight quotations, newest first, with search and filters. */
export default function QuotationsPage() {
  const { can } = useAuth()
  const { data: meta } = useMeta()
  const [params, setParams] = useSearchParams()
  const filters = Object.fromEntries(FILTER_KEYS.map((k) => [k, params.get(k) ?? ''])) as Record<(typeof FILTER_KEYS)[number], string>
  const { data, isLoading, error, isFetching } = useQuotations(filters)

  const set = (key: string, value: string) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setParams(next)
  }

  return (
    <div>
      <PageHeader
        title="Flight Quotations"
        subtitle={data ? `${data.meta.total} quotation(s)${isFetching ? ' · updating…' : ''}` : 'Prices and flights entered by staff; never a reservation.'}
        actions={can('edit-records') && <Link to="/quotations/new" className="btn-primary">New quotation</Link>}
      />

      <Card className="mb-4">
        <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(10rem,1fr))]">
          <Input className="[grid-column:1/-1] sm:[grid-column:span_2]" placeholder="Reference, customer, city or airport code" defaultValue={filters.q} key={filters.q}
            onKeyDown={(e) => e.key === 'Enter' && set('q', (e.target as HTMLInputElement).value.trim())}
            onBlur={(e) => e.target.value.trim() !== filters.q && set('q', e.target.value.trim())} aria-label="Search quotations" />
          <Select value={filters.status} onChange={(e) => set('status', e.target.value)} placeholder="All statuses" options={meta?.quotation_statuses ?? []} aria-label="Status" />
          <Select value={filters.currency} onChange={(e) => set('currency', e.target.value)} placeholder="All currencies" options={meta?.currencies ?? []} aria-label="Currency" />
          <label className="text-xs text-slate-500">Departure from<Input type="date" className="mt-0.5" value={filters.departure_from} onChange={(e) => set('departure_from', e.target.value)} /></label>
          <label className="text-xs text-slate-500">Departure to<Input type="date" className="mt-0.5" value={filters.departure_to} onChange={(e) => set('departure_to', e.target.value)} /></label>
        </div>
      </Card>

      <Card bodyClass="overflow-x-auto">
        {error ? <ErrorBox message={errorMessage(error)} /> : isLoading ? <Loading /> : !data?.data.length ? (
          <Empty>No quotations found. {can('edit-records') && <Link to="/quotations/new" className="link">Create the first one.</Link>}</Empty>
        ) : (
          <table className="table-base">
            <thead>
              <tr><th>Reference</th><th>Customer</th><th>Route</th><th>Departure</th><th>Return</th><th>Pax</th><th>Options</th><th>Price per adult</th><th>Status</th><th>Valid until</th><th>Last email</th></tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data.data.map((q) => (
                <tr key={q.id}>
                  <td className="whitespace-nowrap"><Link to={`/quotations/${q.id}`} className="link font-mono font-medium">{q.reference}</Link><div className="text-xs text-slate-500">{date(q.created_at)}</div></td>
                  <td>{q.customer?.full_name ?? <span className="text-slate-400">No customer</span>}</td>
                  <td>{q.origin} {q.trip_type === 'round_trip' ? '⇄' : '→'} {q.destination}</td>
                  <td className="whitespace-nowrap">{date(q.outbound_date)}</td>
                  <td className="whitespace-nowrap">{q.return_date ? date(q.return_date) : '—'}</td>
                  <td>{q.passengers}</td>
                  <td>{q.options_count}</td>
                  <td className="whitespace-nowrap">{q.price_range ?? (q.price_range_mixed ? 'Several currencies' : '—')}</td>
                  <td><QuotationStatusBadge status={q.status} label={q.status_label} /></td>
                  <td className="whitespace-nowrap">{q.valid_until ? date(q.valid_until) : '—'}</td>
                  <td className="whitespace-nowrap text-xs">{q.last_email ? `${q.last_email.status === 'sent' ? 'Sent' : q.last_email.status === 'failed' ? 'Failed' : 'Queued'} ${date(q.last_email.at)}` : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
      <Pagination meta={data?.meta} onPage={(p) => set('page', String(p))} />
    </div>
  )
}
