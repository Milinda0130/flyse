import { useEffect, useRef, useState, type FormEvent } from 'react'
import { errorMessage, fieldErrors, type ValidationErrors } from '../lib/api'
import { useSaveUser, useUpdateSettings, useUploadLogo } from '../hooks/mutations'
import { useAuth } from '../auth/AuthContext'
import { useAgencyLogo, useAgencySettings, useAuditLogs, useMeta, useUsers } from '../hooks/queries'
import { dateTime } from '../lib/format'
import type { User } from '../lib/types'
import { Button, Card, Empty, ErrorBox, Field, Input, Loading, Modal, PageHeader, Pagination, Select, SuccessBox, Tabs, Textarea } from '../components/ui'
import AuditTable from '../components/AuditTable'

export default function SettingsPage() {
  const [tab, setTab] = useState('agency')
  return (
    <div>
      <PageHeader title="Settings" />
      <Tabs tabs={[{ key: 'agency', label: 'Agency & lists' }, { key: 'users', label: 'Users' }, { key: 'audit', label: 'Audit log' }]} active={tab} onChange={setTab} />
      {tab === 'agency' && <AgencySettings />}
      {tab === 'users' && <Users />}
      {tab === 'audit' && <AuditLog />}
    </div>
  )
}


function AgencySettings() {
  const { data, isLoading } = useAgencySettings()
  const mutation = useUpdateSettings()
  const [form, setForm] = useState<Record<string, string>>({})
  const errors: ValidationErrors = fieldErrors(mutation.error)

  useEffect(() => {
    if (data) setForm({
      agency_name: data.agency_name, agency_address: data.agency_address ?? '', agency_phone: data.agency_phone ?? '', agency_email: data.agency_email ?? '',
      agency_website: data.agency_website ?? '', agency_logo_url: data.agency_logo_url ?? '',
      default_currency: data.default_currency, currencies: data.currencies.join(', '), payment_methods: data.payment_methods.join('\n'), expense_categories: data.expense_categories.join('\n'),
    })
  }, [data])

  if (isLoading || !data) return <Loading />
  const list = (s: string, sep: RegExp) => s.split(sep).map((x) => x.trim()).filter(Boolean)
  const set = (k: string, v: string) => setForm((f) => ({ ...f, [k]: v }))

  const submit = (e: FormEvent) => {
    e.preventDefault()
    mutation.mutate({
      agency_name: form.agency_name, agency_address: form.agency_address || null, agency_phone: form.agency_phone || null, agency_email: form.agency_email || null,
      agency_website: form.agency_website || null, agency_logo_url: form.agency_logo_url || null,
      default_currency: form.default_currency, currencies: list(form.currencies, /[,\s]+/), payment_methods: list(form.payment_methods, /\n/), expense_categories: list(form.expense_categories, /\n/),
    })
  }

  return (
    <form onSubmit={submit} className="space-y-4">
      {mutation.isSuccess && <SuccessBox message="Settings saved." />}
      {mutation.isError && <ErrorBox message={errorMessage(mutation.error)} />}
      <Card title="Agency profile">
        <div className="grid gap-3 md:grid-cols-2">
          <Field label="Agency name" error={errors.agency_name}><Input value={form.agency_name ?? ''} onChange={(e) => set('agency_name', e.target.value)} /></Field>
          <Field label="Phone" error={errors.agency_phone}><Input value={form.agency_phone ?? ''} onChange={(e) => set('agency_phone', e.target.value)} /></Field>
          <Field label="Email" error={errors.agency_email}><Input type="email" value={form.agency_email ?? ''} onChange={(e) => set('agency_email', e.target.value)} /></Field>
          <Field label="Address" error={errors.agency_address} hint="Use two lines if you like; printed on ticket PDFs"><Textarea rows={2} value={form.agency_address ?? ''} onChange={(e) => set('agency_address', e.target.value)} /></Field>
          <Field label="Website" error={errors.agency_website} hint="Shown in customer emails"><Input type="url" value={form.agency_website ?? ''} onChange={(e) => set('agency_website', e.target.value)} placeholder="https://" /></Field>
          <Field label="Logo URL (optional)" error={errors.agency_logo_url} hint="Public https image for the email header"><Input type="url" value={form.agency_logo_url ?? ''} onChange={(e) => set('agency_logo_url', e.target.value)} placeholder="https://" /></Field>
        </div>
      </Card>
      <LogoCard />
      <Card title="Reference lists">
        <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
          <Field label="Currencies (ISO codes)" error={errors.currencies ?? errors['currencies.0']} hint="Comma separated, e.g. LKR, USD"><Input value={form.currencies ?? ''} onChange={(e) => set('currencies', e.target.value.toUpperCase())} /></Field>
          <Field label="Default currency" error={errors.default_currency}><Select value={form.default_currency ?? ''} onChange={(e) => set('default_currency', e.target.value)} options={list(form.currencies ?? '', /[,\s]+/)} /></Field>
          <Field label="Payment methods" error={errors.payment_methods} hint="One per line"><Textarea rows={6} value={form.payment_methods ?? ''} onChange={(e) => set('payment_methods', e.target.value)} /></Field>
          <Field label="Expense categories" error={errors.expense_categories} hint="One per line"><Textarea rows={6} value={form.expense_categories ?? ''} onChange={(e) => set('expense_categories', e.target.value)} /></Field>
        </div>
        <p className="mt-3 text-xs text-slate-500">Booking statuses, issue types and issue statuses are fixed in the system so reports stay consistent.</p>
      </Card>
      <div className="flex justify-end"><Button type="submit" loading={mutation.isPending}>Save settings</Button></div>
    </form>
  )
}

/** Logo printed on generated ticket PDFs (stored privately; separate from the email logo URL). */
function LogoCard() {
  const { data: logo } = useAgencyLogo()
  const upload = useUploadLogo()
  const input = useRef<HTMLInputElement>(null)
  const errors = fieldErrors(upload.error)
  const pick = (file: File | undefined) => {
    if (file) upload.mutate(file, { onSettled: () => { if (input.current) input.current.value = '' } })
  }
  return (
    <Card title="Logo for ticket PDFs">
      <div className="flex flex-wrap items-center gap-4">
        <div className="flex h-24 w-44 items-center justify-center rounded-md border border-dashed border-slate-300 bg-white p-2">
          {logo ? <img src={logo} alt="Agency logo" className="max-h-full max-w-full object-contain" /> : <span className="text-xs text-slate-500">No logo uploaded</span>}
        </div>
        <div className="space-y-2">
          <input ref={input} type="file" accept="image/png,image/jpeg" className="hidden" onChange={(e) => pick(e.target.files?.[0])} />
          <div className="flex flex-wrap gap-2">
            <Button type="button" variant="secondary" loading={upload.isPending} onClick={() => input.current?.click()}>{logo ? 'Replace logo' : 'Upload logo'}</Button>
            {logo && <Button type="button" variant="ghost" className="text-red-600" onClick={() => confirm('Remove the logo from ticket PDFs?') && upload.mutate(null)}>Remove</Button>}
          </div>
          <p className="text-xs text-slate-500">PNG or JPG, up to 1 MB. Printed at the top of ticket PDFs created by the system.</p>
          {(errors.logo ?? (upload.isError ? [errorMessage(upload.error)] : [])).map((m) => <p key={m} className="text-xs text-red-600">{m}</p>)}
        </div>
      </div>
    </Card>
  )
}

function Users() {
  const { user: me } = useAuth()
  const [editing, setEditing] = useState<User | 'new' | null>(null)
  const { data, isLoading } = useUsers()
  return (
    <Card title="User accounts" actions={<Button size="sm" onClick={() => setEditing('new')}>Add user</Button>} bodyClass="overflow-x-auto">
      {isLoading ? <Loading /> : (
        <table className="table-base">
          <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last sign-in</th><th /></tr></thead>
          <tbody className="divide-y divide-slate-100">
            {data?.map((u) => (
              <tr key={u.id} className={u.is_active ? '' : 'text-slate-400'}>
                <td className="font-medium">{u.name}{u.id === me?.id && <span className="ml-1 text-xs text-slate-500">(you)</span>}</td>
                <td>{u.email}</td><td>{u.role_label}</td><td>{u.is_active ? 'Active' : 'Deactivated'}</td><td>{dateTime(u.last_login_at)}</td>
                <td><Button size="sm" variant="ghost" onClick={() => setEditing(u)}>Edit</Button></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      <p className="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">Give every person their own account — never share passwords. Deactivating a user signs them out immediately; their history is kept.</p>
      <Modal open={editing !== null} title={editing === 'new' ? 'Add user' : 'Edit user'} onClose={() => setEditing(null)}>
        {editing && <UserForm user={editing === 'new' ? null : editing} onDone={() => setEditing(null)} />}
      </Modal>
    </Card>
  )
}

function UserForm({ user, onDone }: { user: User | null; onDone: () => void }) {
  const { data: meta } = useMeta()
  const [form, setForm] = useState({ name: user?.name ?? '', email: user?.email ?? '', password: '', role: user?.role ?? 'staff', is_active: user?.is_active ?? true })
  const mutation = useSaveUser()
  const errors: ValidationErrors = fieldErrors(mutation.error)
  const set = (k: keyof typeof form, v: string | boolean) => setForm((f) => ({ ...f, [k]: v }))

  const submit = (e: FormEvent) => {
    e.preventDefault()
    mutation.mutate({ id: user?.id, payload: { ...form, password: form.password || null } }, { onSuccess: onDone })
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      {mutation.isError && <ErrorBox message={errorMessage(mutation.error)} />}
      <Field label="Name *" error={errors.name}><Input required value={form.name} onChange={(e) => set('name', e.target.value)} /></Field>
      <Field label="Email *" error={errors.email}><Input type="email" required value={form.email} onChange={(e) => set('email', e.target.value)} /></Field>
      <Field label={user ? 'New password (leave blank to keep)' : 'Password *'} error={errors.password} hint="At least 10 characters with letters and numbers">
        <Input type="password" autoComplete="new-password" required={!user} value={form.password} onChange={(e) => set('password', e.target.value)} />
      </Field>
      <Field label="Role" error={errors.role}><Select value={form.role} onChange={(e) => set('role', e.target.value)} options={meta?.user_roles ?? []} /></Field>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} /> Active</label>
      <div className="flex justify-end"><Button type="submit" loading={mutation.isPending}>Save</Button></div>
    </form>
  )
}

function AuditLog() {
  const [page, setPage] = useState(1)
  const [entity, setEntity] = useState('')
  const { data, isLoading } = useAuditLogs({ page, entity_type: entity || undefined })
  return (
    <Card title="Audit log" actions={
      <Select className="w-44 py-1" value={entity} onChange={(e) => { setEntity(e.target.value); setPage(1) }} placeholder="All records" aria-label="Record type"
        options={['Booking', 'Passenger', 'BookingFlight', 'Ticket', 'Payment', 'Document', 'BookingIssue', 'Customer', 'Supplier', 'Expense', 'User', 'Setting']} />
    } bodyClass="">
      {isLoading ? <Loading /> : !data?.data.length ? <Empty>No entries.</Empty> : <AuditTable entries={data.data} />}
      <Pagination meta={data?.meta} onPage={setPage} />
    </Card>
  )
}
