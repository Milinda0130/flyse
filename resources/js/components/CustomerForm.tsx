import { useState, type FormEvent } from 'react'
import { errorMessage, fieldErrors, type ValidationErrors } from '../lib/api'
import { useSaveCustomer } from '../hooks/mutations'
import { useAuth } from '../auth/AuthContext'
import type { Customer } from '../lib/types'
import { Button, ErrorBox, Field, Input, Textarea } from './ui'

export default function CustomerForm({ customer, initialName = '', onSaved, onCancel }: { customer?: Customer; initialName?: string; onSaved: (c: Customer) => void; onCancel: () => void }) {
  const { can } = useAuth()
  const [form, setForm] = useState({
    full_name: customer?.full_name ?? initialName,
    phone: customer?.phone ?? '',
    email: customer?.email ?? '',
    address: customer?.address ?? '',
    nationality: customer?.nationality ?? '',
    date_of_birth: customer?.date_of_birth ?? '',
    passport_number: customer?.passport_number ?? '',
    passport_expiry: customer?.passport_expiry ?? '',
    notes: customer?.notes ?? '',
  })
  const mutation = useSaveCustomer()
  const errors: ValidationErrors = fieldErrors(mutation.error)
  const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }))

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const payload: Record<string, string | null> = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]))
    if (!can('view-passport')) delete payload.passport_number
    mutation.mutate({ id: customer?.id, payload }, { onSuccess: onSaved })
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      {mutation.isError && <ErrorBox message={errorMessage(mutation.error)} />}
      <Field label="Full name *" error={errors.full_name}><Input required value={form.full_name} onChange={(e) => set('full_name', e.target.value)} autoFocus /></Field>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Phone" error={errors.phone}><Input value={form.phone} onChange={(e) => set('phone', e.target.value)} placeholder="+94 77 123 4567" /></Field>
        <Field label="Email" error={errors.email}><Input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} /></Field>
      </div>
      <Field label="Address" error={errors.address}><Input value={form.address} onChange={(e) => set('address', e.target.value)} /></Field>
      <details className="rounded-md border border-slate-200 p-3" open={!!(form.passport_number || form.date_of_birth)}>
        <summary className="cursor-pointer text-sm font-medium text-slate-700">Identity details (optional — only if needed)</summary>
        <div className="mt-3 grid gap-3 sm:grid-cols-2">
          <Field label="Nationality" error={errors.nationality}><Input value={form.nationality} onChange={(e) => set('nationality', e.target.value)} /></Field>
          <Field label="Date of birth" error={errors.date_of_birth}><Input type="date" value={form.date_of_birth} onChange={(e) => set('date_of_birth', e.target.value)} /></Field>
          {can('view-passport') && <Field label="Passport number" error={errors.passport_number} hint="Stored encrypted"><Input value={form.passport_number} onChange={(e) => set('passport_number', e.target.value)} autoComplete="off" /></Field>}
          <Field label="Passport expiry" error={errors.passport_expiry}><Input type="date" value={form.passport_expiry} onChange={(e) => set('passport_expiry', e.target.value)} /></Field>
        </div>
      </details>
      <Field label="Notes" error={errors.notes}><Textarea value={form.notes} onChange={(e) => set('notes', e.target.value)} /></Field>
      <div className="flex justify-end gap-2">
        <Button variant="secondary" onClick={onCancel}>Cancel</Button>
        <Button type="submit" loading={mutation.isPending}>{customer ? 'Save changes' : 'Create customer'}</Button>
      </div>
    </form>
  )
}
