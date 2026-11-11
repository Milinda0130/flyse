import { useState } from 'react'
import { useBookingActivity } from '../../hooks/queries'
import { Card, Empty, Loading, Pagination } from '../../components/ui'
import AuditTable from '../../components/AuditTable'

/** Audit history for one booking (who changed what and when, with previous values). */
export default function ActivityTab({ bookingId, embedded = false }: { bookingId: number; embedded?: boolean }) {
  const [page, setPage] = useState(1)
  const { data, isLoading } = useBookingActivity(bookingId, page)
  const body = (
    <>
      {isLoading ? <Loading /> : !data?.data.length ? <Empty>No activity.</Empty> : <AuditTable entries={data.data} />}
      <Pagination meta={data?.meta} onPage={setPage} />
    </>
  )
  return embedded ? body : <Card title="Activity history" bodyClass="">{body}</Card>
}
