import { Mail } from 'lucide-react'
import { errorMessage } from '../lib/api'
import { useAuth } from '../auth/AuthContext'
import { useTicketEmail } from '../hooks/queries'
import { useRetryTicketEmail } from '../hooks/mutations'
import { dateTime, fileSize } from '../lib/format'
import { Button, Card, Empty } from './ui'
import { EmailStatusPill } from './EmailTicketsDialog'
import { DocumentKindBadge } from './Badges'

/** Who emailed which tickets to whom, when, and the outcome. Updates itself while sending. */
export default function EmailHistory({ bookingId, onEmail }: { bookingId: number; onEmail?: () => void }) {
  const { can } = useAuth()
  const { data, isLoading } = useTicketEmail(bookingId, can('email-tickets'))
  const retry = useRetryTicketEmail()
  if (!can('email-tickets')) return null
  const history = data?.history ?? []

  return (
    <Card
      title={<span className="flex items-center gap-2"><Mail className="h-4 w-4 text-brand-600" aria-hidden /> Ticket emails</span>}
      actions={onEmail && <Button size="sm" onClick={onEmail}>Email tickets</Button>}
      bodyClass=""
    >
      {isLoading ? null : history.length === 0 ? <Empty>No ticket emails sent yet.</Empty> : (
        <ul className="divide-y divide-slate-100">
          {history.map((e) => (
            <li key={e.id} className="px-4 py-3 text-sm">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="min-w-0 break-all font-medium text-slate-900">
                  {e.recipient}{e.recipient_is_override && <span className="ml-1 text-xs font-normal text-amber-700">(one-off address)</span>}
                </span>
                <span className="flex flex-wrap items-center gap-1.5"><DocumentKindBadge alternative={e.email_type === 'alternative'} /><EmailStatusPill email={e} /></span>
              </div>
              <div className="mt-0.5 text-xs text-slate-500">
                {dateTime(e.requested_at)} by {e.requested_by ?? 'unknown'} · {e.attachment_count} PDF ({fileSize(e.total_bytes)})
                {e.sent_at && <> · sent {dateTime(e.sent_at)}</>}{e.attempts > 1 && <> · {e.attempts} attempts</>}
              </div>
              <div className="mt-0.5 break-all text-xs text-slate-600">{e.attachments.map((a) => a.name).join(', ')}</div>
              {e.status === 'failed' && (
                <div className="mt-1.5 flex flex-wrap items-center gap-2">
                  <span className="text-xs text-red-700">{e.failure_reason}</span>
                  <Button size="sm" variant="secondary" loading={retry.isPending && retry.variables?.id === e.id}
                    onClick={() => retry.mutate({ id: e.id, bookingId }, { onError: (err) => alert(errorMessage(err)) })}>Retry</Button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
      {history.length > 0 && <p className="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">“Sent” means the mail server accepted the email. It does not prove the customer received or opened it.</p>}
    </Card>
  )
}
