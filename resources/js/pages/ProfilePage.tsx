import { useState, type FormEvent } from 'react'
import { useAuth } from '../auth/AuthContext'
import { errorMessage, fieldErrors, type ValidationErrors } from '../lib/api'
import { useChangePassword } from '../hooks/mutations'
import { Button, Card, DefinitionList, ErrorBox, Field, Input, PageHeader, SuccessBox } from '../components/ui'

export default function ProfilePage() {
  const { user } = useAuth()
  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' })
  const mutation = useChangePassword()
  const errors: ValidationErrors = fieldErrors(mutation.error)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    mutation.mutate(form, { onSuccess: () => setForm({ current_password: '', password: '', password_confirmation: '' }) })
  }

  return (
    <div className="max-w-xl space-y-4">
      <PageHeader title="My account" />
      <Card title="Profile"><DefinitionList items={[['Name', user?.name], ['Email', user?.email], ['Role', user?.role_label]]} /></Card>
      <Card title="Change password">
        <form onSubmit={submit} className="space-y-3">
          {mutation.isSuccess && <SuccessBox message="Password changed. Other sessions have been signed out." />}
          {mutation.isError && <ErrorBox message={errorMessage(mutation.error)} />}
          <Field label="Current password" error={errors.current_password}><Input type="password" required autoComplete="current-password" value={form.current_password} onChange={(e) => setForm({ ...form, current_password: e.target.value })} /></Field>
          <Field label="New password" error={errors.password} hint="At least 10 characters with letters and numbers"><Input type="password" required autoComplete="new-password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} /></Field>
          <Field label="Confirm new password"><Input type="password" required autoComplete="new-password" value={form.password_confirmation} onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} /></Field>
          <div className="flex justify-end"><Button type="submit" loading={mutation.isPending}>Change password</Button></div>
        </form>
      </Card>
    </div>
  )
}
