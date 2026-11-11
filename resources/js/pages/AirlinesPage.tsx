import { useRef, useState, type FormEvent } from 'react'
import { ExternalLink, ImageUp, Plane } from 'lucide-react'
import { useAuth } from '../auth/AuthContext'
import { useAirlines } from '../hooks/queries'
import { useAirlineLogoUpload, useDeleteAirline, useSaveAirline } from '../hooks/mutations'
import { errorMessage, fieldErrors } from '../lib/api'
import type { Airline } from '../lib/types'
import { Button, Card, Empty, ErrorBox, Field, Input, Loading, Modal, PageHeader } from '../components/ui'
import AirlineLogo from '../components/AirlineLogo'

/** Airlines & logos used on quotation PDFs. Logos are uploaded files (never hot-linked). */
export default function AirlinesPage() {
  const { can } = useAuth()
  const [q, setQ] = useState('')
  const [editing, setEditing] = useState<Airline | 'new' | null>(null)
  const { data, isLoading } = useAirlines(q.trim() ? { q: q.trim() } : {})
  const remove = useDeleteAirline()

  return (
    <div>
      <PageHeader title="Airlines & Logos" subtitle="Logos are printed on flight quotations. Upload each airline's logo once."
        actions={can('manage-airlines') && <Button onClick={() => setEditing('new')}><Plane className="h-4 w-4" aria-hidden /> Add airline</Button>} />

      <Card className="mb-4" bodyClass="p-4">
        <Input placeholder="Search by name or code (e.g. EK, UAE)" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search airlines" />
        <p className="mt-3 text-xs text-slate-500">
          Where to get logos: the airline's official media / brand page (preferred), or Wikimedia Commons
          (<a className="link inline-flex items-center gap-0.5" href="https://commons.wikimedia.org/wiki/Category:Logos_of_airlines" target="_blank" rel="noreferrer">logos of airlines <ExternalLink className="h-3 w-3" aria-hidden /></a>).
          Check the licence before use. Upload a PNG (transparent background is best), JPG or WebP; SVG files must be exported as PNG first.
        </p>
      </Card>

      <Card bodyClass="overflow-x-auto">
        {isLoading ? <Loading /> : !data?.length ? <Empty>No airlines yet. Add the airlines you quote most often.</Empty> : (
          <table className="table-base">
            <thead><tr><th>Logo</th><th>Airline</th><th>IATA</th><th>ICAO</th><th>Used in</th><th>Status</th>{can('manage-airlines') && <th />}</tr></thead>
            <tbody className="divide-y divide-slate-100">
              {data.map((a) => (
                <tr key={a.id} className={a.is_active ? '' : 'text-slate-400'}>
                  <td><AirlineLogo airline={a} className="h-8 w-24" /></td>
                  <td className="font-medium">{a.name}</td>
                  <td className="font-mono">{a.iata_code ?? '—'}</td>
                  <td className="font-mono">{a.icao_code ?? '—'}</td>
                  <td>{a.segments_count ?? 0} flight(s)</td>
                  <td>{a.is_active ? 'Active' : 'Inactive'}</td>
                  {can('manage-airlines') && (
                    <td className="whitespace-nowrap">
                      <Button size="sm" variant="ghost" onClick={() => setEditing(a)}>Edit</Button>
                      {can('delete-airlines') && !a.segments_count && (
                        <Button size="sm" variant="ghost" className="text-red-600" loading={remove.isPending && remove.variables === a.id}
                          onClick={() => confirm(`Delete ${a.name}?`) && remove.mutate(a.id, { onError: (e) => alert(errorMessage(e)) })}>Delete</Button>
                      )}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>

      <Modal open={editing !== null} title={editing === 'new' ? 'Add airline' : `Edit ${editing?.name ?? ''}`} onClose={() => setEditing(null)}>
        {editing && <AirlineForm airline={editing === 'new' ? null : editing} onDone={() => setEditing(null)} />}
      </Modal>
    </div>
  )
}

function AirlineForm({ airline, onDone }: { airline: Airline | null; onDone: () => void }) {
  const [current, setCurrent] = useState<Airline | null>(airline)
  const [form, setForm] = useState({ name: airline?.name ?? '', iata_code: airline?.iata_code ?? '', icao_code: airline?.icao_code ?? '', is_active: airline?.is_active ?? true })
  const save = useSaveAirline()
  const logo = useAirlineLogoUpload()
  const file = useRef<HTMLInputElement>(null)
  const errors = fieldErrors(save.error)
  const logoErrors = fieldErrors(logo.error)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate({ id: current?.id, payload: { ...form, iata_code: form.iata_code || null, icao_code: form.icao_code || null } }, {
      onSuccess: (a) => (current ? onDone() : setCurrent(a)),
    })
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      {save.isError && !Object.keys(errors).length && <ErrorBox message={errorMessage(save.error)} />}
      <Field label="Airline name *" error={errors.name}><Input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="e.g. Emirates" /></Field>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="IATA code" error={errors.iata_code} hint="2 characters, e.g. EK"><Input className="font-mono uppercase" maxLength={2} value={form.iata_code} onChange={(e) => setForm({ ...form, iata_code: e.target.value.toUpperCase() })} /></Field>
        <Field label="ICAO code" error={errors.icao_code} hint="3 letters, e.g. UAE"><Input className="font-mono uppercase" maxLength={3} value={form.icao_code} onChange={(e) => setForm({ ...form, icao_code: e.target.value.toUpperCase() })} /></Field>
      </div>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} /> Active (offered when building quotations)</label>

      {current ? (
        <div className="rounded-md border border-dashed border-slate-300 bg-slate-50 p-3">
          <p className="label">Logo</p>
          <div className="flex flex-wrap items-center gap-3">
            <div className="flex h-16 w-40 items-center justify-center rounded border border-slate-200 bg-white p-1"><AirlineLogo airline={current} className="h-14 w-36" /></div>
            <input ref={file} type="file" accept="image/png,image/jpeg,image/webp" className="hidden"
              onChange={(e) => { const f = e.target.files?.[0]; if (f) logo.mutate({ id: current.id, file: f }, { onSuccess: setCurrent, onSettled: () => { if (file.current) file.current.value = '' } }) }} />
            <Button type="button" variant="secondary" loading={logo.isPending} onClick={() => file.current?.click()}><ImageUp className="h-4 w-4" aria-hidden /> {current.has_logo ? 'Replace logo' : 'Upload logo'}</Button>
            {current.has_logo && <Button type="button" variant="ghost" className="text-red-600" onClick={() => logo.mutate({ id: current.id, file: null }, { onSuccess: setCurrent })}>Remove</Button>}
          </div>
          <p className="mt-2 text-xs text-slate-500">PNG, JPG or WebP up to 2 MB. It is resized (max 600 × 300 px) and stored as PNG; the shape is never stretched.</p>
          {(logoErrors.logo ?? (logo.isError ? [errorMessage(logo.error)] : [])).map((m) => <p key={m} className="mt-1 text-xs text-red-600">{m}</p>)}
        </div>
      ) : <p className="text-xs text-slate-500">Save the airline first, then upload its logo.</p>}

      <div className="flex justify-end gap-2">
        {current && !airline && <Button type="button" variant="ghost" onClick={onDone}>Done</Button>}
        <Button type="submit" loading={save.isPending}>{current ? 'Save' : 'Save and add logo'}</Button>
      </div>
    </form>
  )
}
