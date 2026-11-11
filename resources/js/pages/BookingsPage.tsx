import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { useBookings, useMeta, useSuppliers } from '../hooks/queries'
import { date, labelFor, localTime, money } from '../lib/format'
import { Button, Card, Empty, ErrorBox, Input, Loading, PageHeader, Pagination, Select, Warn } from '../components/ui'
import { BookingStatusBadge, PaymentStatusBadge } from '../components/Badges'

const FILTER_KEYS = ['q', 'status', 'supplier_id', 'airline', 'origin', 'destination', 'departure_from', 'departure_to', 'booking_from', 'booking_to', 'has_open_issues', 'sort', 'direction', 'page'] as const

export default function BookingsPage() {
  const { can } = useAuth()
  const { data: meta } = useMeta()
  const [params, setParams] = useSearchParams()
  const [showMore, setShowMore] = useState(false)
  const filters = Object.fromEntries(FILTER_KEYS.map((k) => [k, params.get(k) ?? ''])) as Record<(typeof FILTER_KEYS)[number], string>

  const set = (key: string, value: string) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setParams(next)
  }

  const { data, isLoading, error, isFetching } = useBookings(filters)
  const { data: suppliers } = useSuppliers()

  const statuses = filters.status ? filters.status.split(',') : []
  const toggleStatus = (s: string) => set('status', (statuses.includes(s) ? statuses.filter((x) => x !== s) : [...statuses, s]).join(','))

  return (
    <div>
      <PageHeader
        title="Bookings"
        subtitle={data ? `${data.meta.total} booking(s)${isFetching ? ' · updating…' : ''}` : undefined}
        actions={can('edit-records') && <Link to="/bookings/new" className="btn-primary">New booking</Link>}
      />

      <Card className="mb-4">
        <div className="flex flex-wrap items-end gap-3">
          <Input
            className="min-w-[16rem] flex-1"
            placeholder="Booking code, PNR, ticket no., passenger, phone, flight no."
            defaultValue={filters.q}
            key={filters.q}
            onKeyDown={(e) => e.key === 'Enter' && set('q', (e.target as HTMLInputElement).value.trim())}
            onBlur={(e) => e.target.value.trim() !== filters.q && set('q', e.target.value.trim())}
            aria-label="Search bookings"
          />
          <div className="grid w-full grid-cols-2 gap-3 sm:w-auto">
            <label className="text-xs text-slate-500">Departure from
              <Input type="date" className="mt-0.5 sm:w-40" value={filters.departure_from} onChange={(e) => set('departure_from', e.target.value)} />
            </label>
            <label className="text-xs text-slate-500">Departure to
              <Input type="date" className="mt-0.5 sm:w-40" value={filters.departure_to} onChange={(e) => set('departure_to', e.target.value)} />
            </label>
          </div>
          <Button variant="secondary" onClick={() => setShowMore((v) => !v)}>{showMore ? 'Fewer filters' : 'More filters'}</Button>
          {params.toString() && <Button variant="ghost" onClick={() => setParams(new URLSearchParams())}>Clear</Button>}
        </div>
        <div className="mt-3 flex flex-wrap gap-1.5" aria-label="Status filter">
          {meta?.booking_statuses.map((s) => (
            <button
              key={s.value}
              type="button"
              aria-pressed={statuses.includes(s.value)}
              onClick={() => toggleStatus(s.value)}
              className={`rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset ${statuses.includes(s.value) ? 'bg-gold text-ink ring-gold' : 'bg-surface text-slate-600 ring-slate-300 hover:bg-slate-50'}`}
            >
              {s.label}
            </button>
          ))}
        </div>
        {showMore && (
          <div className="mt-3 grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-6">
            <Select value={filters.supplier_id} onChange={(e) => set('supplier_id', e.target.value)} placeholder="Any supplier" options={(suppliers ?? []).map((s) => ({ value: String(s.id), label: s.name }))} aria-label="Supplier" />
            <Input placeholder="Airline" defaultValue={filters.airline} onBlur={(e) => set('airline', e.target.value.trim())} aria-label="Airline" />
            <Input placeholder="Origin (CMB)" maxLength={3} defaultValue={filters.origin} onBlur={(e) => set('origin', e.target.value.trim().toUpperCase())} aria-label="Origin airport" />
            <Input placeholder="Destination (ARN)" maxLength={3} defaultValue={filters.destination} onBlur={(e) => set('destination', e.target.value.trim().toUpperCase())} aria-label="Destination airport" />
            <Input type="date" value={filters.booking_from} onChange={(e) => set('booking_from', e.target.value)} aria-label="Booked from" title="Booked from" />
            <Input type="date" value={filters.booking_to} onChange={(e) => set('booking_to', e.target.value)} aria-label="Booked to" title="Booked to" />
            <Select value={filters.has_open_issues} onChange={(e) => set('has_open_issues', e.target.value)} placeholder="Issues: any" options={[{ value: '1', label: 'Has open issues' }, { value: '0', label: 'No open issues' }]} aria-label="Open issues" />
            <Select value={filters.sort} onChange={(e) => set('sort', e.target.value)} placeholder="Sort: booking date" options={[
              { value: 'first_departure_at', label: 'Sort: departure' }, { value: 'booking_code', label: 'Sort: booking code' }, { value: 'created_at', label: 'Sort: created' },
            ]} aria-label="Sort" />
            <Select value={filters.direction} onChange={(e) => set('direction', e.target.value)} placeholder="Newest first" options={[{ value: 'asc', label: 'Oldest first' }]} aria-label="Sort direction" />
          </div>
        )}
      </Card>

      <Card bodyClass="overflow-x-auto">
        {isLoading ? <Loading /> : error ? <div className="p-4"><ErrorBox message="Could not load bookings." /></div> : !data?.data.length ? (
          <Empty>No bookings match your search.</Empty>
        ) : (
          <table className="table-base">
            <thead>
              <tr><th>Booking</th><th>Customer</th><th>Route / first departure</th><th>Pax</th><th>Status</th><th className="text-right">Customer total</th><th>Payment</th></tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data.data.map((b) => (
                <tr key={b.id} className="hover:bg-slate-50">
                  <td className="whitespace-nowrap">
                    <Link to={`/bookings/${b.id}`} className="link">{b.booking_code}</Link>
                    <div className="font-mono text-xs text-slate-500">{b.pnr ?? 'no PNR'} · {date(b.booking_date)}</div>
                  </td>
                  <td>{b.customer?.full_name}<div className="text-xs text-slate-500">{b.customer?.phone}</div></td>
                  <td>
                    {b.route
                      ? <span className="font-mono">{b.route}</span>
                      : <span>{b.requested_route ?? '—'} <span className="text-xs text-slate-500">(requested)</span></span>}
                    <div className="text-xs text-slate-500">
                      {b.first_departure_at ? localTime(b.first_departure_at) : b.departure_date ? date(b.departure_date) : 'Date not set'}
                      {b.airlines?.length ? ` · ${b.airlines.join(', ')}` : ''} · {b.passenger_count} pax
                    </div>
                  </td>
                  <td>{b.passengers_count}</td>
                  <td>
                    <BookingStatusBadge status={b.status} label={labelFor(meta?.booking_statuses, b.status)} />
                    {!!b.open_issues_count && <div className="mt-1 text-xs font-medium text-red-700"><Warn>{b.open_issues_count} open issue(s)</Warn></div>}
                  </td>
                  <td className="whitespace-nowrap text-right">{money(b.finance?.customer_due, b.currency)}
                    {b.finance && Number(b.finance.customer_balance) !== 0 && <div className="text-xs text-slate-500">balance {money(b.finance.customer_balance)}</div>}
                  </td>
                  <td>{b.finance && <PaymentStatusBadge status={b.finance.payment_status} />}</td>
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
