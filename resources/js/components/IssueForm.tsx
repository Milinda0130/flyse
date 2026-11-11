import { useState, type FormEvent } from 'react'
import { errorMessage, fieldErrors, type ValidationErrors } from '../lib/api'
import { useSaveIssue } from '../hooks/mutations'
import { useMeta } from '../hooks/queries'
import { nowLocalInput, toLocalInput } from '../lib/format'
import type { Booking, Issue } from '../lib/types'
import { Button, ErrorBox, Field, Input, Select, Textarea } from './ui'

/** Create an issue for a booking, or edit an existing issue's fields. */
export default function IssueForm({ booking, issue, onDone }: { booking?: Booking; issue?: Issue; onDone: () => void }) {
  const { data: meta } = useMeta()
  const [form, setForm] = useState({
    issue_type: issue?.issue_type ?? 'schedule_change',
    source: issue?.source ?? 'airline',
    severity: issue?.severity ?? 'medium',
    status: issue?.status ?? 'open',
    summary: issue?.summary ?? '',
    description: issue?.description ?? '',
    next_action: issue?.next_action ?? '',
    reported_at: issue ? toLocalInput(issue.reported_at) : nowLocalInput(),
    due_at: issue ? toLocalInput(issue.due_at) : '',
    booking_flight_id: issue?.booking_flight_id ? String(issue.booking_flight_id) : '',
    assigned_to: issue?.assigned_to ? String(issue.assigned_to) : '',
    customer_notified: issue?.customer_notified ?? false,
    resolution_notes: issue?.resolution_notes ?? '',
    refund_reference: issue?.refund_reference ?? '',
    update_note: '',
  })
  const mutation = useSaveIssue()
  const errors: ValidationErrors = fieldErrors(mutation.error)
  const set = (k: keyof typeof form, v: string | boolean) => setForm((f) => ({ ...f, [k]: v }))
  const flights = booking?.flights ?? (issue?.flight ? [issue.flight] : [])

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const payload = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]))
    // Datetime-local values are browser-local; send them with the offset so the server stores the right instant.
    for (const k of ['reported_at', 'due_at'] as const) {
      if (form[k]) payload[k] = new Date(form[k]).toISOString()
    }
    mutation.mutate({ bookingId: booking?.id, id: issue?.id, payload }, { onSuccess: onDone })
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      {mutation.isError && <ErrorBox message={errorMessage(mutation.error)} />}
      <div className="grid gap-3 sm:grid-cols-3">
        <Field label="Issue type *" error={errors.issue_type}><Select value={form.issue_type} onChange={(e) => set('issue_type', e.target.value)} options={meta?.issue_types ?? []} /></Field>
        <Field label="Source *" error={errors.source}><Select value={form.source} onChange={(e) => set('source', e.target.value)} options={meta?.issue_sources ?? []} /></Field>
        <Field label="Severity" error={errors.severity}><Select value={form.severity} onChange={(e) => set('severity', e.target.value)} options={meta?.issue_severities ?? []} /></Field>
      </div>
      <Field label="Summary *" error={errors.summary}><Input required value={form.summary} onChange={(e) => set('summary', e.target.value)} placeholder="e.g. QR655 retimed by 45 minutes" autoFocus /></Field>
      <Field label="Description / pasted email summary" error={errors.description}><Textarea rows={4} value={form.description} onChange={(e) => set('description', e.target.value)} /></Field>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Affected segment" error={errors.booking_flight_id}>
          <Select value={form.booking_flight_id} onChange={(e) => set('booking_flight_id', e.target.value)} placeholder="Whole booking"
            options={flights.map((f) => ({ value: String(f.id), label: `${f.flight_number} ${f.origin_code}→${f.destination_code}` }))} />
        </Field>
        <Field label="Assigned to" error={errors.assigned_to}>
          <Select value={form.assigned_to} onChange={(e) => set('assigned_to', e.target.value)} placeholder="Unassigned" options={(meta?.users ?? []).map((u) => ({ value: String(u.id), label: u.name }))} />
        </Field>
        <Field label="Next action" error={errors.next_action}><Input value={form.next_action} onChange={(e) => set('next_action', e.target.value)} /></Field>
        <Field label="Due" error={errors.due_at}><Input type="datetime-local" value={form.due_at} onChange={(e) => set('due_at', e.target.value)} /></Field>
        <Field label="Reported at" error={errors.reported_at}><Input type="datetime-local" value={form.reported_at} onChange={(e) => set('reported_at', e.target.value)} /></Field>
        <Field label="Status" error={errors.status}><Select value={form.status} onChange={(e) => set('status', e.target.value)} options={meta?.issue_statuses ?? []} /></Field>
      </div>
      {issue && (
        <>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Refund reference" error={errors.refund_reference}><Input value={form.refund_reference} onChange={(e) => set('refund_reference', e.target.value)} /></Field>
            <label className="mt-6 flex items-center gap-2 text-sm"><input type="checkbox" checked={form.customer_notified} onChange={(e) => set('customer_notified', e.target.checked)} /> Customer has been notified</label>
          </div>
          <Field label="Resolution notes" error={errors.resolution_notes}><Textarea rows={2} value={form.resolution_notes} onChange={(e) => set('resolution_notes', e.target.value)} /></Field>
        </>
      )}
      <Field label={issue ? 'Note for the timeline (optional)' : 'First timeline note (optional)'}><Input value={form.update_note} onChange={(e) => set('update_note', e.target.value)} /></Field>
      <p className="text-xs text-slate-500">Recording an issue never changes the booking or ticket automatically — update the booking yourself once confirmed.</p>
      <div className="flex justify-end"><Button type="submit" loading={mutation.isPending}>{issue ? 'Save issue' : 'Create issue'}</Button></div>
    </form>
  )
}
