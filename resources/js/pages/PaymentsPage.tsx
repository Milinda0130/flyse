import { Link, useSearchParams } from 'react-router-dom'
import { usePayments, useMeta } from '../hooks/queries'
import { date, labelFor, money } from '../lib/format'
import { Card, Empty, Input, Loading, PageHeader, Pagination, Select } from '../components/ui'
import { PaymentStatusBadge } from '../components/Badges'

const KEYS = ['q', 'direction', 'status', 'currency', 'method', 'from', 'to', 'page'] as const

export default function PaymentsPage() {
  const { data: meta } = useMeta()
  const [params, setParams] = useSearchParams()
  const f = Object.fromEntries(KEYS.map((k) => [k, params.get(k) ?? ''])) as Record<(typeof KEYS)[number], string>
  const set = (k: string, v: string) => {
    const next = new URLSearchParams(params)
    if (v) next.set(k, v)
    else next.delete(k)
    if (k !== 'page') next.delete('page')
    setParams(next)
  }
  const { data, isLoading } = usePayments(f)

  return (
    <div>
      <PageHeader title="Payments" subtitle="All customer receipts, supplier payments and refunds. Record new transactions on the booking’s Finance tab." />
      <Card className="mb-4">
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-7">
          <Input className="col-span-2" placeholder="Booking code or reference" defaultValue={f.q} key={f.q} onKeyDown={(e) => e.key === 'Enter' && set('q', (e.target as HTMLInputElement).value.trim())} aria-label="Search payments" />
          <Select value={f.direction} onChange={(e) => set('direction', e.target.value)} options={meta?.payment_directions ?? []} placeholder="Any type" aria-label="Type" />
          <Select value={f.status} onChange={(e) => set('status', e.target.value)} options={meta?.payment_statuses ?? []} placeholder="Any status" aria-label="Status" />
          <Select value={f.method} onChange={(e) => set('method', e.target.value)} options={meta?.payment_methods ?? []} placeholder="Any method" aria-label="Method" />
          <Input type="date" value={f.from} onChange={(e) => set('from', e.target.value)} aria-label="From date" title="From" />
          <Input type="date" value={f.to} onChange={(e) => set('to', e.target.value)} aria-label="To date" title="To" />
        </div>
      </Card>
      <Card bodyClass="overflow-x-auto">
        {isLoading ? <Loading /> : !data?.data.length ? <Empty>No transactions found.</Empty> : (
          <table className="table-base">
            <thead><tr><th>Date</th><th>Booking</th><th>Type</th><th className="text-right">Amount</th><th>Method</th><th>Reference</th><th>Status</th><th>By</th></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {data.data.map((p) => (
                <tr key={p.id} className={p.status === 'voided' ? 'text-slate-400' : ''}>
                  <td className="whitespace-nowrap">{date(p.transaction_date)}</td>
                  <td><Link to={`/bookings/${p.booking_id}?tab=finance`} className="link">{p.booking?.booking_code}</Link></td>
                  <td>{labelFor(meta?.payment_directions, p.direction)}</td>
                  <td className="whitespace-nowrap text-right font-medium">{p.direction.endsWith('refund') ? '−' : ''}{money(p.amount, p.currency)}</td>
                  <td>{p.method}</td>
                  <td className="font-mono text-xs">{p.transaction_reference ?? '—'}</td>
                  <td><PaymentStatusBadge status={p.status} /></td>
                  <td className="text-xs">{p.creator?.name}</td>
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
