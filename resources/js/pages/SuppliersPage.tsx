import { useState, type FormEvent } from 'react'
import { errorMessage, fieldErrors, type ValidationErrors } from '../lib/api'
import { useSaveSupplier } from '../hooks/mutations'
import { useAuth } from '../auth/AuthContext'
import { useMeta, useSuppliers } from '../hooks/queries'
import { labelFor } from '../lib/format'
import type { Supplier } from '../lib/types'
import { Button, Card, Empty, ErrorBox, Field, Input, Loading, Modal, PageHeader, Select, Textarea } from '../components/ui'

export default function SuppliersPage() {
  const { can } = useAuth()
  const { data: meta } = useMeta()
  const [editing, setEditing] = useState<Supplier | 'new' | null>(null)
  const { data, isLoading } = useSuppliers()

  return (
    <div>
      <PageHeader title="Suppliers" subtitle="Airlines, consolidators and agencies you buy tickets from." actions={can('edit-records') && <Button onClick={() => setEditing('new')}>Add supplier</Button>} />
      <Card bodyClass="overflow-x-auto">
        {isLoading ? <Loading /> : !data?.length ? <Empty>No suppliers yet.</Empty> : (
          <table className="table-base">
            <thead><tr><th>Name</th><th>Type</th><th>Contact</th><th>Bookings</th><th>Status</th>{can('edit-records') && <th />}</tr></thead>
            <tbody className="divide-y divide-slate-100">
              {data.map((s) => (
                <tr key={s.id} className={s.is_active ? '' : 'text-slate-400'}>
                  <td className="font-medium">{s.name}{s.notes && <div className="text-xs font-normal text-slate-500">{s.notes}</div>}</td>
                  <td>{labelFor(meta?.supplier_types, s.supplier_type)}</td>
                  <td>{[s.contact_person, s.phone, s.email].filter(Boolean).join(' · ') || '—'}</td>
                  <td>{s.bookings_count}</td>
                  <td>{s.is_active ? 'Active' : 'Inactive'}</td>
                  {can('edit-records') && <td><Button size="sm" variant="ghost" onClick={() => setEditing(s)}>Edit</Button></td>}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
      <Modal open={editing !== null} title={editing === 'new' ? 'Add supplier' : 'Edit supplier'} onClose={() => setEditing(null)}>
        {editing && <SupplierForm supplier={editing === 'new' ? null : editing} onDone={() => setEditing(null)} />}
      </Modal>
    </div>
  )
}

function SupplierForm({ supplier, onDone }: { supplier: Supplier | null; onDone: () => void }) {
  const { data: meta } = useMeta()
  const [form, setForm] = useState({
    name: supplier?.name ?? '', supplier_type: supplier?.supplier_type ?? 'consolidator', contact_person: supplier?.contact_person ?? '',
    phone: supplier?.phone ?? '', email: supplier?.email ?? '', notes: supplier?.notes ?? '', is_active: supplier?.is_active ?? true,
  })
  const mutation = useSaveSupplier()
  const errors: ValidationErrors = fieldErrors(mutation.error)
  const set = (k: keyof typeof form, v: string | boolean) => setForm((f) => ({ ...f, [k]: v }))

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const payload = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]))
    mutation.mutate({ id: supplier?.id, payload }, { onSuccess: onDone })
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      {mutation.isError && <ErrorBox message={errorMessage(mutation.error)} />}
      <Field label="Name *" error={errors.name}><Input required value={form.name} onChange={(e) => set('name', e.target.value)} /></Field>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Type" error={errors.supplier_type}><Select value={form.supplier_type} onChange={(e) => set('supplier_type', e.target.value)} options={meta?.supplier_types ?? []} /></Field>
        <Field label="Contact person" error={errors.contact_person}><Input value={form.contact_person} onChange={(e) => set('contact_person', e.target.value)} /></Field>
        <Field label="Phone" error={errors.phone}><Input value={form.phone} onChange={(e) => set('phone', e.target.value)} /></Field>
        <Field label="Email" error={errors.email}><Input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} /></Field>
      </div>
      <Field label="Notes" error={errors.notes}><Textarea rows={2} value={form.notes} onChange={(e) => set('notes', e.target.value)} /></Field>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} /> Active</label>
      <div className="flex justify-end"><Button type="submit" loading={mutation.isPending}>Save</Button></div>
    </form>
  )
}
