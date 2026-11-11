import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useCustomers } from '../hooks/queries'
import { cleanParams } from '../lib/api'
import { useAuth } from '../auth/AuthContext'
import { Button, Card, Empty, Input, Loading, Modal, PageHeader, Pagination } from '../components/ui'
import CustomerForm from '../components/CustomerForm'

export default function CustomersPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [creating, setCreating] = useState(false)
  const q = params.get('q') ?? ''
  const page = params.get('page') ?? '1'

  const { data, isLoading } = useCustomers({ q, page })

  return (
    <div>
      <PageHeader title="Customers" subtitle={data && `${data.meta.total} customer(s)`} actions={can('edit-records') && <Button onClick={() => setCreating(true)}>New customer</Button>} />
      <Card bodyClass="overflow-x-auto">
        <div className="border-b border-slate-200 p-3">
          <Input placeholder="Search name, phone or email" defaultValue={q} key={q} className="max-w-md" aria-label="Search customers"
            onKeyDown={(e) => e.key === 'Enter' && setParams(cleanParams({ q: (e.target as HTMLInputElement).value.trim() }) as Record<string, string>)} />
        </div>
        {isLoading ? <Loading /> : !data?.data.length ? <Empty>No customers found.</Empty> : (
          <table className="table-base">
            <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Bookings</th></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {data.data.map((c) => (
                <tr key={c.id} className="hover:bg-slate-50">
                  <td><Link to={`/customers/${c.id}`} className="link">{c.full_name}</Link></td>
                  <td>{c.phone ?? '—'}</td>
                  <td>{c.email ?? '—'}</td>
                  <td>{c.bookings_count}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <Pagination meta={data?.meta} onPage={(p) => setParams(cleanParams({ q, page: String(p) }) as Record<string, string>)} />
      </Card>
      <Modal open={creating} title="New customer" onClose={() => setCreating(false)}>
        <CustomerForm onSaved={(c) => navigate(`/customers/${c.id}`)} onCancel={() => setCreating(false)} />
      </Modal>
    </div>
  )
}
