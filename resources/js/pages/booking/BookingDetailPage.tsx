import { useEffect, useState, type ReactNode } from 'react'
import { Link, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import {
  ArrowRightLeft, CircleCheck, CreditCard, FileDown, FilePlus, Mail, Pencil, Plane, Printer, Ticket as TicketIcon, TriangleAlert, UserPlus,
} from 'lucide-react'
import { useAuth } from '../../auth/AuthContext'
import { useBooking, useMeta } from '../../hooks/queries'
import { airportLabel } from '../../lib/airports'
import { date, labelFor } from '../../lib/format'
import type { Abilities, Booking } from '../../lib/types'
import { Button, ErrorBox, Loading, Modal, SuccessBox, Tabs } from '../../components/ui'
import { BookingStatusBadge, PaymentStatusBadge } from '../../components/Badges'
import ActionMenu, { type MenuAction } from '../../components/ActionMenu'
import IssueForm from '../../components/IssueForm'
import EmailTicketsDialog from '../../components/EmailTicketsDialog'
import TicketPdfDialog from '../../components/TicketPdfDialog'
import OverviewTab, { type Section } from './OverviewTab'
import PassengersFlightsTab from './PassengersFlightsTab'
import DocumentsIssuesTab, { type DocSub } from './DocumentsIssuesTab'
import PaymentsTab from './PaymentsTab'
import StatusDialog from './StatusDialog'
import IssueTicketsDialog from './IssueTicketsDialog'
import { FlightEditor, PassengerEditor, PaymentForm } from './editors'

type Dialog =
  | { kind: 'status'; status?: string }
  | { kind: 'issue-tickets' }
  | { kind: 'passenger' }
  | { kind: 'flight' }
  | { kind: 'payment'; direction: string }
  | { kind: 'issue' }
  | { kind: 'email' }
  | { kind: 'ticket-pdf' }
  | null

type PrimaryAction = { label: string; icon: ReactNode; dialog: Dialog } | null

/** The single most useful next action for this booking's stage and the user's permissions. */
function primaryAction(booking: Booking, can: (a: keyof Abilities) => boolean): PrimaryAction {
  const f = booking.finance
  const owes = f?.customer_balance !== null && f?.customer_balance !== undefined && Number(f.customer_balance) > 0
  const supplierDue = f?.supplier_balance !== null && f?.supplier_balance !== undefined && Number(f.supplier_balance) > 0
  switch (booking.status) {
    case 'inquiry':
      return { label: 'Confirm reservation', icon: <CircleCheck className="h-4 w-4" aria-hidden />, dialog: { kind: 'status', status: 'confirmed' } }
    case 'confirmed':
    case 'pending_payment':
    case 'on_hold':
      return (booking.ticketing_problems ?? []).length === 0
        ? { label: 'Mark as Ticketed', icon: <CircleCheck className="h-4 w-4" aria-hidden />, dialog: { kind: 'status', status: 'ticketed' } }
        : { label: 'Issue tickets', icon: <TicketIcon className="h-4 w-4" aria-hidden />, dialog: { kind: 'issue-tickets' } }
    case 'ticketed':
    case 'changed':
      if (owes && can('record-receipt')) return { label: 'Record payment', icon: <CreditCard className="h-4 w-4" aria-hidden />, dialog: { kind: 'payment', direction: 'customer_receipt' } }
      if (supplierDue && can('record-supplier-payment')) return { label: 'Pay supplier', icon: <CreditCard className="h-4 w-4" aria-hidden />, dialog: { kind: 'payment', direction: 'supplier_payment' } }
      return { label: 'Report an issue', icon: <TriangleAlert className="h-4 w-4" aria-hidden />, dialog: { kind: 'issue' } }
    default:
      // Cancelled / completed: the "To do" list points to anything left (e.g. a pending refund).
      return null
  }
}

const TAB_KEYS: Section[] = ['overview', 'passengers', 'documents', 'payments']

/** Booking details in four sections: Overview, Passengers & Flights, Documents & Issues, Payments. */
export default function BookingDetailPage() {
  const { id } = useParams()
  const { can } = useAuth()
  const { data: meta } = useMeta()
  const location = useLocation()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const tabParam = params.get('tab') as Section | null
  const tab: Section = tabParam && TAB_KEYS.includes(tabParam) ? tabParam : 'overview'
  const [docSub, setDocSub] = useState<DocSub>(params.get('sub') === 'issues' ? 'issues' : 'documents')
  const [dialog, setDialog] = useState<Dialog>(null)
  const [flash, setFlash] = useState<string | null>(null)
  const { data: booking, isLoading, error } = useBooking(id)

  // One-time confirmation after creating or saving, then cleared from history state.
  useEffect(() => {
    const state = location.state as { created?: string; saved?: boolean } | null
    if (!state) return
    if (state.created) setFlash(`Inquiry ${state.created} created. Add flights, passengers and prices when you have them.`)
    else if (state.saved) setFlash('Changes saved.')
    navigate(location.pathname + location.search, { replace: true, state: null })
  }, [location.state, location.pathname, location.search, navigate])

  useEffect(() => {
    if (!flash) return
    const t = window.setTimeout(() => setFlash(null), 6000)
    return () => window.clearTimeout(t)
  }, [flash])

  if (isLoading) return <Loading />
  if (error || !booking) return <ErrorBox message="Booking not found or you do not have access." />

  const editable = can('edit-records')
  const goTo = (section: Section, sub?: DocSub) => {
    setFlash(null)
    if (sub) setDocSub(sub)
    setParams(section === 'overview' ? {} : { tab: section }, { replace: true })
  }
  const primary = editable ? primaryAction(booking, can) : null
  const openIssues = (booking.issues ?? []).filter((i) => !['resolved', 'closed'].includes(i.status))
  const active = booking.status !== 'cancelled' && booking.status !== 'completed'

  const candidates: (MenuAction | false)[] = editable ? [
    { key: 'edit', label: 'Edit booking', icon: <Pencil className="h-4 w-4" aria-hidden />, onSelect: () => navigate(`/bookings/${booking.id}/edit`) },
    (booking.allowed_transitions ?? []).length > 0 && { key: 'status', label: 'Change status', icon: <ArrowRightLeft className="h-4 w-4" aria-hidden />, onSelect: () => setDialog({ kind: 'status' }) },
    active && { key: 'flight', label: 'Add flight details', icon: <Plane className="h-4 w-4" aria-hidden />, onSelect: () => setDialog({ kind: 'flight' }) },
    active && { key: 'passenger', label: 'Add passenger', icon: <UserPlus className="h-4 w-4" aria-hidden />, onSelect: () => setDialog({ kind: 'passenger' }) },
    active && booking.status !== 'inquiry' && { key: 'tickets', label: 'Issue tickets', icon: <TicketIcon className="h-4 w-4" aria-hidden />, onSelect: () => setDialog({ kind: 'issue-tickets' }) },
    can('email-tickets') && booking.status !== 'cancelled' && { key: 'email', label: 'Email tickets', icon: <Mail className="h-4 w-4" aria-hidden />, onSelect: () => setDialog({ kind: 'email' }) },
    { key: 'document', label: 'Add document', icon: <FilePlus className="h-4 w-4" aria-hidden />, onSelect: () => goTo('documents', 'documents') },
    can('record-receipt') && { key: 'payment', label: 'Record payment', icon: <CreditCard className="h-4 w-4" aria-hidden />, onSelect: () => setDialog({ kind: 'payment', direction: 'customer_receipt' }) },
    { key: 'issue', label: 'Report an issue', icon: <TriangleAlert className="h-4 w-4" aria-hidden />, onSelect: () => setDialog({ kind: 'issue' }) },
  ] : []
  const more: MenuAction[] = [
    ...candidates.filter((a): a is MenuAction => Boolean(a) && (a as MenuAction).label !== primary?.label),
    { key: 'print', label: 'Print', icon: <Printer className="h-4 w-4" aria-hidden />, onSelect: () => window.print() },
  ]

  const tabs = [
    { key: 'overview', label: 'Overview' },
    { key: 'passengers', label: 'Passengers & Flights' },
    { key: 'documents', label: <>Documents & Issues{openIssues.length > 0 && <span className="ml-1.5 rounded-full bg-danger px-1.5 text-xs text-white" aria-label={`${openIssues.length} open issues`}>{openIssues.length}</span>}</> },
    { key: 'payments', label: 'Payments' },
  ]
  const close = () => setDialog(null)

  return (
    <div>
      {flash && <div className="mb-4"><SuccessBox message={flash} /></div>}

      <header className="card mb-4 p-4 sm:p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <h1 className="flex flex-wrap items-center gap-2 text-xl font-semibold text-slate-900">
              {booking.booking_code}
              <BookingStatusBadge status={booking.status} label={labelFor(meta?.booking_statuses, booking.status)} />
              {booking.finance && booking.finance.payment_status !== 'not_priced' && <PaymentStatusBadge status={booking.finance.payment_status} />}
            </h1>
            <p className="mt-1 text-sm text-slate-600">
              <Link to={`/customers/${booking.customer_id}`} className="link">{booking.customer?.full_name}</Link>
              {booking.customer?.phone && <> · {booking.customer.phone}</>}
              {' · '}{airportLabel(booking.origin)} → {airportLabel(booking.destination)}
              {booking.departure_date && <> · {date(booking.departure_date)}</>}
              {' · '}{booking.passenger_count} passenger{booking.passenger_count === 1 ? '' : 's'}
              {booking.pnr && <> · PNR <span className="font-mono font-medium text-slate-800">{booking.pnr}</span></>}
            </p>
          </div>
          <div className="no-print flex flex-wrap items-center gap-2">
            {can('email-tickets') && active && booking.status !== 'inquiry' && (
              <Button variant="secondary" onClick={() => setDialog({ kind: 'ticket-pdf' })}><FileDown className="h-4 w-4" aria-hidden /> Ticket PDF</Button>
            )}
            <ActionMenu actions={more} />
            {primary && <Button onClick={() => setDialog(primary.dialog)}>{primary.icon}{primary.label}</Button>}
          </div>
        </div>
        <p className="mt-3 rounded-md bg-gold/10 px-3 py-2 text-sm text-slate-800">
          <span className="font-semibold">Next step:</span> {booking.next_action || booking.next_step}
        </p>
      </header>

      <Tabs tabs={tabs} active={tab} onChange={(k) => goTo(k as Section)} />

      {tab === 'overview' && <OverviewTab booking={booking} onGoTo={(s) => goTo(s, s === 'documents' ? 'issues' : undefined)} />}
      {tab === 'passengers' && <PassengersFlightsTab booking={booking} onIssueTickets={() => setDialog({ kind: 'issue-tickets' })} onEmailTickets={() => setDialog({ kind: 'email' })} />}
      {tab === 'documents' && <DocumentsIssuesTab key={docSub} booking={booking} initialSub={docSub} onEmailTickets={() => setDialog({ kind: 'email' })} />}
      {tab === 'payments' && <PaymentsTab booking={booking} />}

      <Modal open={dialog?.kind === 'status'} title="Change booking status" onClose={close} wide>
        {dialog?.kind === 'status' && <StatusDialog booking={booking} initialStatus={dialog.status} onDone={close} onIssueTickets={() => setDialog({ kind: 'issue-tickets' })} />}
      </Modal>
      <Modal open={dialog?.kind === 'issue-tickets'} title={`Issue tickets · ${booking.booking_code}`} onClose={close} wide>
        {dialog?.kind === 'issue-tickets' && <IssueTicketsDialog booking={booking} onDone={close} onAddPassenger={() => setDialog({ kind: 'passenger' })} />}
      </Modal>
      <Modal open={dialog?.kind === 'passenger'} title="Add passenger" onClose={close} wide>
        {dialog?.kind === 'passenger' && <PassengerEditor booking={booking} passenger={null} onDone={close} />}
      </Modal>
      <Modal open={dialog?.kind === 'flight'} title="Add flight segment" onClose={close} wide>
        {dialog?.kind === 'flight' && <FlightEditor booking={booking} flight={null} onDone={close} />}
      </Modal>
      <Modal open={dialog?.kind === 'payment'} title={dialog?.kind === 'payment' && dialog.direction === 'supplier_payment' ? 'Pay supplier' : 'Record payment'} onClose={close}>
        {dialog?.kind === 'payment' && <PaymentForm booking={booking} defaultDirection={dialog.direction} onDone={close} />}
      </Modal>
      <Modal open={dialog?.kind === 'email'} title={`Email tickets · ${booking.booking_code}`} onClose={close} wide>
        {dialog?.kind === 'email' && <EmailTicketsDialog booking={booking} onClose={close} />}
      </Modal>
      <Modal open={dialog?.kind === 'ticket-pdf'} title={`Ticket PDF · ${booking.booking_code}`} onClose={close}>
        {dialog?.kind === 'ticket-pdf' && <TicketPdfDialog booking={booking} onClose={close} />}
      </Modal>
      <Modal open={dialog?.kind === 'issue'} title={`Report issue · ${booking.booking_code}`} onClose={close} wide>
        {dialog?.kind === 'issue' && <IssueForm booking={booking} onDone={close} />}
      </Modal>
    </div>
  )
}
