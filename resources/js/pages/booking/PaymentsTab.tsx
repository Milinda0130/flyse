import { useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Plus } from 'lucide-react'
import { useAuth } from '../../auth/AuthContext'
import { useMeta } from '../../hooks/queries'
import { date, labelFor, money } from '../../lib/format'
import type { Booking, Payment } from '../../lib/types'
import { Button, Card, Empty, Modal } from '../../components/ui'
import { PaymentStatusBadge } from '../../components/Badges'
import { PaymentForm } from './editors'

type Sub = 'customer' | 'supplier' | 'refunds'

const SUBS: Record<Sub, { label: string; directions: string[]; record: { direction: string; label: string; ability: 'record-receipt' | 'record-supplier-payment' }[] }> = {
  customer: { label: 'Customer payments', directions: ['customer_receipt'], record: [{ direction: 'customer_receipt', label: 'Record customer payment', ability: 'record-receipt' }] },
  supplier: { label: 'Supplier payments', directions: ['supplier_payment'], record: [{ direction: 'supplier_payment', label: 'Record supplier payment', ability: 'record-supplier-payment' }] },
  refunds: {
    label: 'Refunds', directions: ['customer_refund', 'supplier_refund'], record: [
      { direction: 'customer_refund', label: 'Refund to customer', ability: 'record-supplier-payment' },
      { direction: 'supplier_refund', label: 'Refund from supplier', ability: 'record-supplier-payment' },
    ],
  },
}

/** Amount, or a clear "Not entered" when the value is unknown (never shown as 0). */
function Amount({ value, currency, empty = 'Not entered' }: { value: string | null | undefined; currency: string; empty?: string }) {
  return value === null || value === undefined ? <span className="text-sm font-normal italic text-slate-400">{empty}</span> : <>{money(value, currency)}</>
}

function Figure({ label, children, hint }: { label: string; children: ReactNode; hint?: ReactNode }) {
  return (
    <div className="rounded-md border border-slate-200 bg-surface p-3">
      <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
      <div className="mt-1 text-lg font-semibold tabular-nums text-slate-900">{children}</div>
      {hint && <div className="mt-0.5 text-xs text-slate-500">{hint}</div>}
    </div>
  )
}

/** Customer payments, supplier payments, refunds and the financial summary. */
export default function PaymentsTab({ booking }: { booking: Booking }) {
  const { can } = useAuth()
  const { data: meta } = useMeta()
  const [sub, setSub] = useState<Sub>('customer')
  const [recording, setRecording] = useState<string | null>(null)
  const [editing, setEditing] = useState<Payment | null>(null)
  const f = booking.finance
  const c = booking.currency
  const seesCosts = can('view-costs')
  const subs = (Object.keys(SUBS) as Sub[]).filter((s) => s !== 'supplier' || seesCosts)
  const rows = (booking.payments ?? []).filter((p) => SUBS[sub].directions.includes(p.direction))
  const balance = f?.customer_balance === null || f?.customer_balance === undefined ? null : Number(f.customer_balance)

  return (
    <div className="space-y-4">
      {f && (
        <section className="card p-4">
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-sm font-semibold text-slate-900">Summary <span className="font-normal text-slate-500">· {c}</span></h2>
            <div className="flex items-center gap-2">
              <PaymentStatusBadge status={f.payment_status} />
              {can('edit-records') && <Link to={`/bookings/${booking.id}/edit`} className="link text-xs">Edit prices</Link>}
            </div>
          </div>
          <div className="grid gap-3 min-[480px]:grid-cols-2 lg:grid-cols-4">
            <Figure label="Customer pays" hint={booking.selling_total !== null ? `Fare ${money(booking.selling_total)} + fee ${money(booking.service_fee ?? 0)}` : 'Selling price not entered'}>
              <Amount value={f.customer_due} currency={c} />
            </Figure>
            <Figure label="Received" hint={Number(f.customer_refunded) > 0 ? `After ${money(f.customer_refunded, c)} refunded` : undefined}>{money(f.customer_paid, c)}</Figure>
            <Figure label={balance !== null && balance < 0 ? 'We owe customer' : 'Balance due'}>
              <span className={balance === null ? '' : balance > 0 ? 'text-amber-800' : balance < 0 ? 'text-violet-800' : 'text-emerald-700'}>
                <Amount value={balance === null ? null : String(Math.abs(balance))} currency={c} empty="Unknown until priced" />
              </span>
            </Figure>
            <Figure label="Refund pending">{Number(f.refund_pending) > 0 ? money(f.refund_pending, c) : <span className="text-sm font-normal text-slate-500">None</span>}</Figure>
            {seesCosts && <>
              <Figure label="Supplier cost"><Amount value={f.supplier_total} currency={c} /></Figure>
              <Figure label="Paid to supplier" hint={Number(f.supplier_refunded) > 0 ? `${money(f.supplier_refunded, c)} refunded by supplier` : undefined}>{money(f.supplier_paid, c)}</Figure>
              <Figure label="Supplier balance due"><Amount value={f.supplier_balance} currency={c} empty="Unknown until cost entered" /></Figure>
              <Figure label="Estimated gross margin" hint="Selling + fee − supplier cost. Not net profit.">
                <span className={f.gross_margin !== null && f.gross_margin !== undefined && Number(f.gross_margin) < 0 ? 'text-red-700' : ''}>
                  <Amount value={f.gross_margin} currency={c} empty="Needs price and cost" />
                </span>
              </Figure>
            </>}
          </div>
        </section>
      )}

      <Card
        title={
          <span className="inline-flex rounded-md border border-slate-300 bg-surface p-0.5" role="tablist">
            {subs.map((s) => (
              <button key={s} type="button" role="tab" aria-selected={sub === s} onClick={() => setSub(s)}
                className={`rounded px-3 py-1 text-sm font-medium transition ${sub === s ? 'bg-gold text-ink' : 'text-slate-600 hover:text-slate-900'}`}>
                {SUBS[s].label}
              </button>
            ))}
          </span>
        }
        actions={SUBS[sub].record.filter((r) => can(r.ability)).map((r) => (
          <Button key={r.direction} size="sm" onClick={() => setRecording(r.direction)}><Plus className="h-4 w-4" aria-hidden /> {r.label}</Button>
        ))}
        bodyClass="overflow-x-auto"
      >
        {rows.length === 0 ? <Empty>No {SUBS[sub].label.toLowerCase()} recorded.</Empty> : (
          <table className="table-base">
            <thead><tr><th>Date</th><th>Type</th><th className="text-right">Amount</th><th>Method</th><th>Reference</th><th>Status</th><th>By</th>{can('manage-payments') && <th />}</tr></thead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((p) => (
                <tr key={p.id} className={p.status === 'voided' ? 'text-slate-400' : ''}>
                  <td className="whitespace-nowrap">{date(p.transaction_date)}</td>
                  <td>{labelFor(meta?.payment_directions, p.direction)}{p.notes && <div className="text-xs text-slate-500">{p.notes}</div>}</td>
                  <td className={`whitespace-nowrap text-right font-medium ${p.status === 'voided' ? 'line-through' : ''}`}>{money(p.amount, p.currency)}</td>
                  <td>{p.method}</td>
                  <td className="font-mono text-xs">{p.transaction_reference ?? '—'}</td>
                  <td><PaymentStatusBadge status={p.status} /></td>
                  <td className="text-xs">{p.creator?.name ?? '—'}</td>
                  {can('manage-payments') && <td className="no-print"><Button size="sm" variant="ghost" onClick={() => setEditing(p)}>Edit</Button></td>}
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <p className="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">
          Transactions are never deleted; a mistake is corrected by the owner setting it to Voided. Pending and voided rows do not change balances. Amounts are in {c} only.
        </p>
      </Card>

      <Modal open={recording !== null} title={recording ? (SUBS[sub].record.find((r) => r.direction === recording)?.label ?? 'Record transaction') : ''} onClose={() => setRecording(null)}>
        {recording && <PaymentForm booking={booking} defaultDirection={recording} onDone={() => setRecording(null)} />}
      </Modal>
      <Modal open={editing !== null} title="Edit transaction" onClose={() => setEditing(null)}>
        {editing && <PaymentForm booking={booking} payment={editing} onDone={() => setEditing(null)} />}
      </Modal>
    </div>
  )
}
