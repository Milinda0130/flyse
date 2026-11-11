import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { errorMessage } from '../lib/api'
import { useDeleteCustomer } from '../hooks/mutations'
import { useAuth } from '../auth/AuthContext'
import { useCustomer, useMeta } from '../hooks/queries'
import { date, labelFor, localTime, money } from '../lib/format'
import { Button, Card, DefinitionList, Empty, ErrorBox, Loading, Modal, PageHeader } from '../components/ui'
import { BookingStatusBadge, PaymentStatusBadge } from '../components/Badges'
import CustomerForm from '../components/CustomerForm'

export default function CustomerDetailPage() {
  const { id } = useParams()
  const { can } = useAuth()
  const { data: meta } = useMeta()
  const navigate = useNavigate()
  const deleteCustomer = useDeleteCustomer()
  const [editing, setEditing] = useState(false)
  const { data, isLoading, error } = useCustomer(id)

  if (isLoading) return <Loading />
  if (error || !data) return <ErrorBox message="Customer not found." />
  const c = data.customer

  const remove = () => {
    if (!confirm(`Delete ${c.full_name}? Only customers without bookings can be deleted.`)) return
    deleteCustomer.mutate({ id: c.id }, { onSuccess: () => navigate('/customers'), onError: (err) => alert(errorMessage(err)) })
  }

  return (
    <div className="space-y-4">
      <PageHeader title={c.full_name} subtitle={`${data.bookings.length} booking(s)`} actions={<>
        {can('manage-settings') && data.bookings.length === 0 && <Button variant="secondary" className="text-red-600" onClick={remove}>Delete</Button>}
        {can('edit-records') && <Button variant="secondary" onClick={() => setEditing(true)}>Edit</Button>}
        {can('edit-records') && <Link to="/bookings/new" className="btn-primary">New booking</Link>}
      </>} />
      <Card title="Contact">
        <DefinitionList items={[
          ['Phone', c.phone], ['Email', c.email], ['Address', c.address], ['Nationality', c.nationality],
          ['Date of birth', date(c.date_of_birth)], ['Passport', c.passport_number ?? '—'], ['Passport expiry', date(c.passport_expiry)],
          ['Notes', c.notes ? <span className="whitespace-pre-line">{c.notes}</span> : '—'],
        ]} />
      </Card>
      <Card title="Booking history" bodyClass="overflow-x-auto">
        {data.bookings.length === 0 ? <Empty>No bookings yet.</Empty> : (
          <table className="table-base">
            <thead><tr><th>Booking</th><th>Route</th><th>First departure</th><th>Status</th><th className="text-right">Total</th><th>Payment</th></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {data.bookings.map((b) => (
                <tr key={b.id}>
                  <td><Link to={`/bookings/${b.id}`} className="link">{b.booking_code}</Link><div className="font-mono text-xs text-slate-500">{b.pnr} · {date(b.booking_date)}</div></td>
                  <td className="font-mono">{b.route || b.requested_route || '—'}</td>
                  <td>{b.first_departure_at ? localTime(b.first_departure_at) : date(b.departure_date)}</td>
                  <td><BookingStatusBadge status={b.status} label={labelFor(meta?.booking_statuses, b.status)} /></td>
                  <td className="text-right">{money(b.finance?.customer_due, b.currency)}</td>
                  <td>{b.finance && <PaymentStatusBadge status={b.finance.payment_status} />}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
      <Modal open={editing} title="Edit customer" onClose={() => setEditing(false)}>
        <CustomerForm customer={c} onSaved={() => setEditing(false)} onCancel={() => setEditing(false)} />
      </Modal>
    </div>
  )
}
