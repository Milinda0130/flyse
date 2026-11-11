import { useState, type FormEvent } from 'react'
import { errorMessage, fieldErrors, type ValidationErrors } from '../lib/api'
import { useDeleteExpense, useDownload, useSaveExpense } from '../hooks/mutations'
import { useExpenses, useMeta } from '../hooks/queries'
import { date, money, today } from '../lib/format'
import type { Expense } from '../lib/types'
import { Button, Card, Empty, ErrorBox, Field, Input, Loading, Modal, PageHeader, Pagination, Select } from '../components/ui'

export default function ExpensesPage() {
  const deleteExpense = useDeleteExpense()
  const download = useDownload()
  const { data: meta } = useMeta()
  const [filters, setFilters] = useState({ from: '', to: '', category: '', page: '1' })
  const [editing, setEditing] = useState<Expense | 'new' | null>(null)
  const { data, isLoading } = useExpenses(filters)

  const remove = (e: Expense) => {
    if (!confirm(`Delete expense “${e.description}”? This is logged.`)) return
    deleteExpense.mutate({ id: e.id }, { onError: (err) => alert(errorMessage(err)) })
  }

  return (
    <div>
      <PageHeader title="Operating expenses" subtitle="Rent, salaries, bank charges and overheads — used for the net estimate in reports." actions={<Button onClick={() => setEditing('new')}>Add expense</Button>} />
      <Card className="mb-4">
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
          <Input type="date" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value, page: '1' })} aria-label="From" />
          <Input type="date" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value, page: '1' })} aria-label="To" />
          <Select value={filters.category} onChange={(e) => setFilters({ ...filters, category: e.target.value, page: '1' })} options={meta?.expense_categories ?? []} placeholder="All categories" aria-label="Category" />
        </div>
      </Card>
      <Card bodyClass="overflow-x-auto">
        {isLoading ? <Loading /> : !data?.data.length ? <Empty>No expenses recorded.</Empty> : (
          <table className="table-base">
            <thead><tr><th>Date</th><th>Category</th><th>Description</th><th className="text-right">Amount</th><th>Method</th><th>Receipt</th><th /></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {data.data.map((e) => (
                <tr key={e.id}>
                  <td>{date(e.expense_date)}</td><td>{e.category}</td><td>{e.description}</td>
                  <td className="text-right font-medium">{money(e.amount, e.currency)}</td><td>{e.payment_method ?? '—'}</td>
                  <td>{e.receipt ? <button type="button" className="link" onClick={() => download.mutate({ url: `/documents/${e.receipt!.id}/download`, filename: e.receipt!.original_name, inline: true }, { onError: (err) => alert(errorMessage(err)) })}>View</button> : '—'}</td>
                  <td className="whitespace-nowrap text-right">
                    <Button size="sm" variant="ghost" onClick={() => setEditing(e)}>Edit</Button>
                    <Button size="sm" variant="ghost" className="text-red-600" onClick={() => remove(e)}>Delete</Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <Pagination meta={data?.meta} onPage={(p) => setFilters({ ...filters, page: String(p) })} />
      </Card>
      <Modal open={editing !== null} title={editing === 'new' ? 'Add expense' : 'Edit expense'} onClose={() => setEditing(null)}>
        {editing && <ExpenseForm expense={editing === 'new' ? null : editing} onDone={() => setEditing(null)} />}
      </Modal>
    </div>
  )
}

function ExpenseForm({ expense, onDone }: { expense: Expense | null; onDone: () => void }) {
  const { data: meta } = useMeta()
  const [form, setForm] = useState({
    expense_date: expense?.expense_date ?? today(), category: expense?.category ?? meta?.expense_categories[0] ?? '',
    description: expense?.description ?? '', amount: expense?.amount ?? '', currency: expense?.currency ?? meta?.default_currency ?? '',
    payment_method: expense?.payment_method ?? '',
  })
  const [file, setFile] = useState<File | null>(null)
  const mutation = useSaveExpense()
  const errors: ValidationErrors = fieldErrors(mutation.error)
  const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }))

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const body = new FormData()
    Object.entries(form).forEach(([k, v]) => v !== '' && body.append(k, v))
    if (file) body.append('receipt', file)
    mutation.mutate({ id: expense?.id, body }, { onSuccess: onDone })
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      {mutation.isError && <ErrorBox message={errorMessage(mutation.error)} />}
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Date *" error={errors.expense_date}><Input type="date" required value={form.expense_date} onChange={(e) => set('expense_date', e.target.value)} /></Field>
        <Field label="Category *" error={errors.category}><Select value={form.category} onChange={(e) => set('category', e.target.value)} options={meta?.expense_categories ?? []} /></Field>
      </div>
      <Field label="Description *" error={errors.description}><Input required value={form.description} onChange={(e) => set('description', e.target.value)} /></Field>
      <div className="grid gap-3 sm:grid-cols-3">
        <Field label="Amount *" error={errors.amount}><Input type="number" min="0.01" step="0.01" required value={form.amount} onChange={(e) => set('amount', e.target.value)} /></Field>
        <Field label="Currency *" error={errors.currency}><Select value={form.currency} onChange={(e) => set('currency', e.target.value)} options={meta?.currencies ?? []} /></Field>
        <Field label="Method" error={errors.payment_method}><Select value={form.payment_method} onChange={(e) => set('payment_method', e.target.value)} options={meta?.payment_methods ?? []} placeholder="—" /></Field>
      </div>
      <Field label={expense?.receipt ? 'Replace receipt (PDF/JPG/PNG)' : 'Receipt (PDF/JPG/PNG, optional)'} error={errors.receipt}>
        <input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="block text-sm" />
      </Field>
      <div className="flex justify-end"><Button type="submit" loading={mutation.isPending}>Save</Button></div>
    </form>
  )
}
