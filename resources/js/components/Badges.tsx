import { CircleAlert, CircleCheck, CircleX } from 'lucide-react'

const bookingTones: Record<string, string> = {
  inquiry: 'bg-slate-100 text-slate-700 ring-slate-300',
  pending_payment: 'bg-amber-50 text-amber-800 ring-amber-300',
  confirmed: 'bg-sky-50 text-sky-800 ring-sky-300',
  ticketed: 'bg-emerald-50 text-emerald-800 ring-emerald-300',
  changed: 'bg-violet-50 text-violet-800 ring-violet-300',
  on_hold: 'bg-orange-50 text-orange-800 ring-orange-300',
  cancelled: 'bg-red-50 text-red-700 ring-red-300',
  completed: 'bg-slate-100 text-slate-600 ring-slate-300',
}

const issueTones: Record<string, string> = {
  open: 'bg-red-50 text-red-700 ring-red-300',
  investigating: 'bg-amber-50 text-amber-800 ring-amber-300',
  waiting_airline: 'bg-sky-50 text-sky-800 ring-sky-300',
  waiting_customer: 'bg-sky-50 text-sky-800 ring-sky-300',
  action_required: 'bg-orange-50 text-orange-800 ring-orange-300',
  resolved: 'bg-emerald-50 text-emerald-800 ring-emerald-300',
  closed: 'bg-slate-100 text-slate-600 ring-slate-300',
}

const severityTones: Record<string, string> = {
  low: 'bg-slate-100 text-slate-600 ring-slate-300',
  medium: 'bg-sky-50 text-sky-800 ring-sky-300',
  high: 'bg-orange-50 text-orange-800 ring-orange-300',
  critical: 'bg-danger text-white ring-danger',
}

const paymentTones: Record<string, string> = {
  paid: 'bg-emerald-50 text-emerald-800 ring-emerald-300',
  partial: 'bg-amber-50 text-amber-800 ring-amber-300',
  unpaid: 'bg-red-50 text-red-700 ring-red-300',
  overpaid: 'bg-violet-50 text-violet-800 ring-violet-300',
  not_due: 'bg-slate-100 text-slate-600 ring-slate-300',
  not_priced: 'bg-slate-100 text-slate-600 ring-slate-300',
  completed: 'bg-emerald-50 text-emerald-800 ring-emerald-300',
  pending: 'bg-amber-50 text-amber-800 ring-amber-300',
  voided: 'bg-slate-100 text-slate-500 ring-slate-300 line-through',
}

const paymentLabels: Record<string, string> = {
  paid: 'Paid', partial: 'Part-paid', unpaid: 'Payment pending', overpaid: 'Refund pending', not_due: 'Nothing due', not_priced: 'Price not set',
}

function Pill({ tone, children }: { tone: string; children: string }) {
  return <span className={`inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${tone}`}>{children}</span>
}

const human = (s: string) => s.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase())

export function BookingStatusBadge({ status, label }: { status: string; label?: string }) {
  return <Pill tone={bookingTones[status] ?? bookingTones.inquiry}>{label ?? human(status)}</Pill>
}

export function IssueStatusBadge({ status, label }: { status: string; label?: string }) {
  return <Pill tone={issueTones[status] ?? issueTones.closed}>{label ?? human(status)}</Pill>
}

export function SeverityBadge({ severity }: { severity: string }) {
  return <Pill tone={severityTones[severity] ?? severityTones.low}>{human(severity)}</Pill>
}

export function PaymentStatusBadge({ status }: { status: string }) {
  return <Pill tone={paymentTones[status] ?? paymentTones.not_due}>{paymentLabels[status] ?? human(status)}</Pill>
}

/** Review state of an alternative ticket PDF (icon + text, never colour alone). */
export function VerificationBadge({ status }: { status: 'pending' | 'verified' | 'rejected' | null | undefined }) {
  if (!status) return null
  const map = ({
    pending: ['bg-amber-50 text-amber-800 ring-amber-300', CircleAlert, 'Pending verification'],
    verified: ['bg-emerald-50 text-emerald-800 ring-emerald-300', CircleCheck, 'Verified'],
    rejected: ['bg-red-50 text-red-700 ring-red-300', CircleX, 'Rejected'],
  } as const)[status]
  const Icon = map[1]
  return (
    <span className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${map[0]}`}>
      <Icon className="h-3.5 w-3.5" aria-hidden />{map[2]}
    </span>
  )
}

/** Official agency e-ticket vs alternative document: always shown side by side so they are never confused. */
export function DocumentKindBadge({ alternative }: { alternative: boolean }) {
  return alternative
    ? <span className="inline-flex items-center whitespace-nowrap rounded-full bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-800 ring-1 ring-inset ring-violet-300">Alternative document</span>
    : <span className="inline-flex items-center whitespace-nowrap rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-800 ring-1 ring-inset ring-sky-300">Official ticket</span>
}

const quotationTones: Record<string, string> = {
  draft: 'bg-slate-100 text-slate-700 ring-slate-300',
  ready: 'bg-sky-50 text-sky-800 ring-sky-300',
  sent: 'bg-violet-50 text-violet-800 ring-violet-300',
  accepted: 'bg-emerald-50 text-emerald-800 ring-emerald-300',
  rejected: 'bg-red-50 text-red-700 ring-red-300',
  expired: 'bg-amber-50 text-amber-800 ring-amber-300',
  converted: 'bg-emerald-100 text-emerald-900 ring-emerald-400',
}

export function QuotationStatusBadge({ status, label }: { status: string; label?: string }) {
  return <Pill tone={quotationTones[status] ?? quotationTones.draft}>{label ?? human(status)}</Pill>
}
