import { useMutation, useQueryClient, type QueryClient, type QueryKey } from '@tanstack/react-query'
import { api, downloadFile } from '../lib/api'
import { qk } from '../lib/queryKeys'
import type { AgencySettings, Booking, BookingEmail, Customer, DocumentFile, EmailType, Expense, Issue, IssueUpdate, Payment, Supplier, TicketEmailPreview, User,
  Airline, Quotation, QuotationEmail, QuotationPdf } from '../lib/types'

/**
 * All server writes. Each hook refreshes exactly the cached data its change affects,
 * so pages never invalidate caches themselves.
 */

type Payload = Record<string, unknown>

function invalidate(client: QueryClient, ...keys: QueryKey[]) {
  return Promise.all(keys.map((queryKey) => client.invalidateQueries({ queryKey })))
}

/** Anything that changes a booking or its children affects these views. */
const BOOKING_VIEWS: QueryKey[] = [qk.bookings.all, qk.dashboard.all, qk.customers.all, qk.reports.all, qk.audit.all]

// ---- Bookings and their child records

export function useSaveBooking() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, payload }: { id?: number | string; payload: Payload }) =>
      (id ? await api.put<Booking>(`/bookings/${id}`, payload) : await api.post<Booking>('/bookings', payload)).data,
    onSuccess: (booking) => {
      client.setQueryData(qk.bookings.detail(booking.id), booking)
      return invalidate(client, ...BOOKING_VIEWS, qk.payments.all)
    },
  })
}

/** Create or update a booking child record (passenger, flight segment or ticket). */
function useSaveChild(collection: 'passengers' | 'flights', itemPath: string) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ bookingId, id, payload }: { bookingId: number; id?: number; payload: Payload }) =>
      (id ? await api.put(`/${itemPath}/${id}`, payload) : await api.post(`/bookings/${bookingId}/${collection}`, payload)).data,
    onSuccess: () => invalidate(client, ...BOOKING_VIEWS, qk.issues.all),
  })
}

function useDeleteChild(itemPath: string) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id }: { id: number }) => { await api.delete(`/${itemPath}/${id}`) },
    onSuccess: () => invalidate(client, ...BOOKING_VIEWS, qk.issues.all),
  })
}

export const useSavePassenger = () => useSaveChild('passengers', 'passengers')
export const useDeletePassenger = () => useDeleteChild('passengers')
export const useSaveFlight = () => useSaveChild('flights', 'booking-flights')
export const useDeleteFlight = () => useDeleteChild('booking-flights')
/** Edit an existing ticket (new tickets are recorded with useIssueTickets, which requires the e-ticket). */
export function useUpdateTicket() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Payload }) => (await api.put(`/tickets/${id}`, payload)).data,
    onSuccess: () => invalidate(client, ...BOOKING_VIEWS),
  })
}

/**
 * Issue tickets: ticket numbers + the e-ticket (uploaded now or an existing document), optionally
 * marking the booking Ticketed. The server saves everything or nothing.
 */
export function useIssueTickets() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ bookingId, form, onProgress }: { bookingId: number; form: FormData; onProgress?: (percent: number | null) => void }) =>
      (await api.post<{ booking_status: string }>(`/bookings/${bookingId}/tickets`, form, {
        onUploadProgress: (e) => onProgress?.(e.total ? Math.round((e.loaded / e.total) * 100) : null),
      })).data,
    onSuccess: () => invalidate(client, ...BOOKING_VIEWS, qk.issues.all),
  })
}

/** Change a booking's status with the details that step needs (server enforces the rules). */
export function useChangeBookingStatus() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Payload }) => (await api.post<Booking>(`/bookings/${id}/status`, payload)).data,
    onSuccess: (booking) => {
      client.setQueryData(qk.bookings.detail(booking.id), booking)
      return invalidate(client, ...BOOKING_VIEWS, qk.payments.all, qk.issues.all)
    },
  })
}
export const useDeleteTicket = () => useDeleteChild('tickets')

// ---- Documents

export function useUploadDocuments() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ bookingId, form, onProgress }: { bookingId: number; form: FormData; onProgress?: (percent: number | null) => void }) =>
      (await api.post<DocumentFile[]>(`/bookings/${bookingId}/documents`, form, {
        onUploadProgress: (e) => onProgress?.(e.total ? Math.round((e.loaded / e.total) * 100) : null),
      })).data,
    onSuccess: () => invalidate(client, qk.bookings.all, qk.issues.all, qk.audit.all),
  })
}

export function useArchiveDocument() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id }: { id: number }) => { await api.delete(`/documents/${id}`) },
    onSuccess: () => invalidate(client, qk.bookings.all, qk.issues.all, qk.expenses.all, qk.audit.all),
  })
}

/** Fetches a private file through the authorised API, then downloads or opens it. */
export function useDownload() {
  return useMutation({
    mutationFn: ({ url, filename, inline = false }: { url: string; filename: string; inline?: boolean }) => downloadFile(url, filename, inline),
  })
}

// ---- Ticket emails

/** Renders the real email template for review (sends nothing). */
export function usePreviewTicketEmail() {
  return useMutation({
    mutationFn: async ({ bookingId, documentIds, recipient, emailType = 'official', officialPending = false }: { bookingId: number; documentIds: number[]; recipient: string | null; emailType?: EmailType; officialPending?: boolean }) =>
      (await api.post<TicketEmailPreview>(`/bookings/${bookingId}/ticket-email/preview`, { document_ids: documentIds, recipient, email_type: emailType, official_pending: officialPending })).data,
  })
}

/** Sends (or queues) the ticket email. The idempotency key makes a repeated request return the same attempt. */
export function useSendTicketEmail() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async (payload: {
      bookingId: number; document_ids: number[]; recipient: string | null; idempotency_key: string; confirm_resend: boolean
      email_type: EmailType; confirm_reviewed?: boolean; official_pending?: boolean
    }) => {
      const { bookingId, ...body } = payload
      return (await api.post<BookingEmail>(`/bookings/${bookingId}/ticket-email`, body)).data
    },
    onSuccess: (_e, vars) => invalidate(client, qk.bookings.ticketEmail(vars.bookingId), qk.bookings.all, qk.audit.all),
  })
}

export function useRetryTicketEmail() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id }: { id: number; bookingId: number }) => (await api.post<BookingEmail>(`/booking-emails/${id}/retry`)).data,
    onSuccess: (_e, vars) => invalidate(client, qk.bookings.ticketEmail(vars.bookingId), qk.bookings.all),
  })
}

/** Printed on the generated ticket PDF; entered by staff when creating it. */
export type TicketCodes = { reservation_code: string; airline_pnr: string }

/** Creates the agency ticket PDF(s) from the booking records (stored as pending alternative documents). */
export function useGenerateTickets() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ bookingId, passengerIds, codes }: { bookingId: number; passengerIds: number[]; codes: TicketCodes }) =>
      (await api.post<DocumentFile[]>(`/bookings/${bookingId}/generated-tickets`, { passenger_ids: passengerIds, ...codes })).data,
    onSuccess: () => invalidate(client, qk.bookings.all, qk.audit.all),
  })
}

/** Opens the generated PDF for one passenger in a new tab without saving it. */
export function usePreviewGeneratedTicket() {
  return useMutation({
    mutationFn: ({ bookingId, passengerId, codes }: { bookingId: number; passengerId: number; codes: TicketCodes }) =>
      downloadFile(`/bookings/${bookingId}/generated-tickets/preview?${new URLSearchParams({ passenger_id: String(passengerId), ...codes })}`, 'ticket-preview.pdf', true),
  })
}

/** Records a person's review of an alternative ticket PDF (verified, or rejected with a reason). */
export function useVerifyDocument() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, status, note }: { id: number; status: 'verified' | 'rejected'; note?: string | null }) =>
      (await api.patch<DocumentFile>(`/documents/${id}/verification`, { status, note })).data,
    onSuccess: () => invalidate(client, qk.bookings.all, qk.audit.all),
  })
}

// ---- Issues

export function useSaveIssue() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ bookingId, id, payload }: { bookingId?: number; id?: number; payload: Payload }) =>
      (id ? await api.patch<Issue>(`/issues/${id}`, payload) : await api.post<Issue>(`/bookings/${bookingId}/issues`, payload)).data,
    onSuccess: (issue) => {
      client.setQueryData(qk.issues.detail(issue.id), issue)
      return invalidate(client, qk.issues.all, ...BOOKING_VIEWS)
    },
  })
}

export function useAddIssueUpdate() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ issueId, payload }: { issueId: number | string; payload: Payload }) =>
      (await api.post<IssueUpdate>(`/issues/${issueId}/updates`, payload)).data,
    onSuccess: () => invalidate(client, qk.issues.all, ...BOOKING_VIEWS),
  })
}

// ---- Payments

export function useSavePayment() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ bookingId, id, payload }: { bookingId: number; id?: number; payload: Payload }) =>
      (id ? await api.patch<Payment>(`/payments/${id}`, payload) : await api.post<Payment>(`/bookings/${bookingId}/payments`, payload)).data,
    onSuccess: () => invalidate(client, qk.payments.all, ...BOOKING_VIEWS),
  })
}

// ---- Customers & suppliers

export function useSaveCustomer() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, payload }: { id?: number; payload: Payload }) =>
      (id ? await api.put<Customer>(`/customers/${id}`, payload) : await api.post<Customer>('/customers', payload)).data,
    onSuccess: () => invalidate(client, qk.customers.all, qk.bookings.all, qk.audit.all),
  })
}

export function useDeleteCustomer() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id }: { id: number }) => { await api.delete(`/customers/${id}`) },
    onSuccess: () => invalidate(client, qk.customers.all, qk.audit.all),
  })
}

export function useSaveSupplier() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, payload }: { id?: number; payload: Payload }) =>
      (id ? await api.put<Supplier>(`/suppliers/${id}`, payload) : await api.post<Supplier>('/suppliers', payload)).data,
    onSuccess: () => invalidate(client, qk.suppliers.all, qk.bookings.all, qk.reports.all, qk.audit.all),
  })
}

// ---- Expenses

export function useSaveExpense() {
  const client = useQueryClient()
  return useMutation({
    // Always POST: the body is multipart so it can carry the receipt file.
    mutationFn: async ({ id, body }: { id?: number; body: FormData }) =>
      (await api.post<Expense>(id ? `/expenses/${id}` : '/expenses', body)).data,
    onSuccess: () => invalidate(client, qk.expenses.all, qk.reports.all, qk.audit.all),
  })
}

export function useDeleteExpense() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id }: { id: number }) => { await api.delete(`/expenses/${id}`) },
    onSuccess: () => invalidate(client, qk.expenses.all, qk.reports.all, qk.audit.all),
  })
}

// ---- Administration

export function useSaveUser() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, payload }: { id?: number; payload: Payload }) =>
      (id ? await api.put<User>(`/users/${id}`, payload) : await api.post<User>('/users', payload)).data,
    // The meta list feeds the "assigned to" dropdowns.
    onSuccess: () => invalidate(client, qk.users.all, qk.meta, qk.audit.all),
  })
}

export function useUploadLogo() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async (file: File | null) => {
      if (!file) return (await api.delete<AgencySettings>('/settings/logo')).data
      const form = new FormData()
      form.append('logo', file)
      return (await api.post<AgencySettings>('/settings/logo', form)).data
    },
    onSuccess: (settings) => {
      client.setQueryData(qk.settings.all, settings)
      return invalidate(client, qk.settings.logo, qk.audit.all)
    },
  })
}

export function useUpdateSettings() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async (payload: Payload) => (await api.put<AgencySettings>('/settings', payload)).data,
    onSuccess: (settings) => {
      client.setQueryData(qk.settings.all, settings)
      return invalidate(client, qk.meta, qk.audit.all)
    },
  })
}

export function useChangePassword() {
  return useMutation({
    mutationFn: async (payload: { current_password: string; password: string; password_confirmation: string }) => {
      await api.put('/me/password', payload)
    },
  })
}

// ---- Flight quotations

export function useSaveQuotation() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, payload }: { id?: number; payload: Payload }) =>
      (id ? await api.put<Quotation>(`/flight-quotations/${id}`, payload) : await api.post<Quotation>('/flight-quotations', payload)).data,
    onSuccess: (q) => {
      client.setQueryData(qk.quotations.detail(q.id), q)
      return invalidate(client, qk.quotations.all, qk.audit.all)
    },
  })
}

export function useQuotationStatus() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, ...body }: { id: number; status: string; accepted_option_id?: number; booking_id?: number }) =>
      (await api.post<Quotation>(`/flight-quotations/${id}/status`, body)).data,
    onSuccess: (q) => {
      client.setQueryData(qk.quotations.detail(q.id), q)
      return invalidate(client, qk.quotations.all, qk.audit.all)
    },
  })
}

export function useDuplicateQuotation() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => (await api.post<Quotation>(`/flight-quotations/${id}/duplicate`)).data,
    onSuccess: () => invalidate(client, qk.quotations.all),
  })
}

export function useDeleteQuotation() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => { await api.delete(`/flight-quotations/${id}`) },
    onSuccess: () => invalidate(client, qk.quotations.all, qk.audit.all),
  })
}

export function useGenerateQuotationPdf() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => (await api.post<QuotationPdf>(`/flight-quotations/${id}/pdf`)).data,
    onSuccess: (_pdf, id) => invalidate(client, qk.quotations.detail(id), qk.quotations.email(id)),
  })
}

export function usePreviewQuotationEmail() {
  return useMutation({
    mutationFn: async ({ id, recipient }: { id: number; recipient: string | null }) =>
      (await api.post<{ recipient: string; recipient_is_override: boolean; subject: string; html: string }>(`/flight-quotations/${id}/email/preview`, { recipient })).data,
  })
}

export function useSendQuotationEmail() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, ...body }: { id: number; recipient: string | null; idempotency_key: string; confirm_resend: boolean }) =>
      (await api.post<QuotationEmail>(`/flight-quotations/${id}/email`, body)).data,
    onSuccess: () => invalidate(client, qk.quotations.all, qk.audit.all),
  })
}

export function useRetryQuotationEmail() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ emailId }: { emailId: number; quotationId: number }) => (await api.post<QuotationEmail>(`/quotation-emails/${emailId}/retry`)).data,
    onSuccess: () => invalidate(client, qk.quotations.all),
  })
}

// ---- Airlines & logos

export function useSaveAirline() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, payload }: { id?: number; payload: Payload }) =>
      (id ? await api.put<Airline>(`/airlines/${id}`, payload) : await api.post<Airline>('/airlines', payload)).data,
    onSuccess: () => invalidate(client, qk.airlines.all),
  })
}

export function useAirlineLogoUpload() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, file }: { id: number; file: File | null }) => {
      if (!file) return (await api.delete<Airline>(`/airlines/${id}/logo`)).data
      const form = new FormData()
      form.append('logo', file)
      return (await api.post<Airline>(`/airlines/${id}/logo`, form)).data
    },
    onSuccess: () => invalidate(client, qk.airlines.all, qk.quotations.all),
  })
}

export function useDeleteAirline() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => { await api.delete(`/airlines/${id}`) },
    onSuccess: () => invalidate(client, qk.airlines.all),
  })
}
