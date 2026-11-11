import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api, cleanParams } from '../lib/api'
import { qk } from '../lib/queryKeys'
import type {
  AgencySettings, AuditEntry, Booking, Customer, DashboardSummary, Expense, Issue, IssuesReport, Meta,
  OutstandingReport, Paginated, Payment, ReportOverview, SalesReport, Supplier, TicketEmailInfo, User,
  Airline, Quotation, QuotationEmailInfo, QuotationRow,
} from '../lib/types'

/** All server reads, one hook per resource. Lists keep the previous page visible while the next one loads. */

type Filters = Record<string, string | number | undefined>

const get = async <T>(url: string, params?: Filters) => (await api.get<T>(url, { params: params && cleanParams(params) })).data

export function useMeta() {
  return useQuery({ queryKey: qk.meta, queryFn: () => get<Meta>('/meta'), staleTime: 5 * 60_000 })
}

// ---- Dashboard

export function useDashboard() {
  return useQuery({ queryKey: qk.dashboard.summary(), queryFn: () => get<DashboardSummary>('/dashboard/summary'), refetchInterval: 2 * 60_000 })
}

/** Number of active bookings departing within 48 hours (top-bar alert). */
export function useDepartureAlerts() {
  return useQuery({
    queryKey: qk.dashboard.departureAlerts(),
    queryFn: async () => {
      const from = new Date()
      const to = new Date(Date.now() + 2 * 86_400_000)
      const r = await get<Paginated<Booking>>('/bookings', {
        departure_from: from.toISOString().slice(0, 10),
        departure_to: to.toISOString().slice(0, 10),
        status: 'pending_payment,confirmed,ticketed,changed,on_hold',
        per_page: 1,
      })
      return r.meta.total
    },
    refetchInterval: 5 * 60_000,
  })
}

// ---- Bookings

export function useBookings(filters: Filters) {
  return useQuery({ queryKey: qk.bookings.list(filters), queryFn: () => get<Paginated<Booking>>('/bookings', filters), placeholderData: keepPreviousData })
}

export function useBooking(id: string | number | undefined) {
  return useQuery({ queryKey: qk.bookings.detail(id ?? ''), queryFn: () => get<Booking>(`/bookings/${id}`), enabled: id !== undefined && id !== '' })
}

export function useBookingActivity(id: string | number, page: number) {
  return useQuery({ queryKey: qk.bookings.activity(id, page), queryFn: () => get<Paginated<AuditEntry>>(`/bookings/${id}/activity`, { page }), placeholderData: keepPreviousData })
}

/** Ticket email dialog data and history. Polls every 3 s while an email is queued or sending. */
export function useTicketEmail(bookingId: number, enabled = true) {
  return useQuery({
    queryKey: qk.bookings.ticketEmail(bookingId),
    queryFn: () => get<TicketEmailInfo>(`/bookings/${bookingId}/ticket-email`),
    enabled,
    refetchInterval: (query) => (query.state.data?.history.some((e) => e.status === 'queued' || e.status === 'sending') ? 3000 : false),
  })
}

// ---- Customers & suppliers

export function useCustomers(filters: Filters) {
  return useQuery({ queryKey: qk.customers.list(filters), queryFn: () => get<Paginated<Customer>>('/customers', filters), placeholderData: keepPreviousData })
}

export function useCustomer(id: string | number | undefined) {
  return useQuery({
    queryKey: qk.customers.detail(id ?? ''),
    queryFn: () => get<{ customer: Customer; bookings: Booking[] }>(`/customers/${id}`),
    enabled: id !== undefined,
  })
}

/** Customer suggestions for the picker: any length of text (empty = first customers A-Z). */
export function useCustomerSearch(term: string, enabled: boolean) {
  const q = term.trim()
  return useQuery({
    queryKey: qk.customers.search(q),
    queryFn: async () => (await get<Paginated<Customer>>('/customers', { q, per_page: 10 })).data,
    enabled,
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  })
}

export function useSuppliers() {
  return useQuery({ queryKey: qk.suppliers.all, queryFn: () => get<Supplier[]>('/suppliers') })
}

// ---- Issues, payments, expenses

export function useIssues(filters: Filters) {
  return useQuery({ queryKey: qk.issues.list(filters), queryFn: () => get<Paginated<Issue>>('/issues', filters), placeholderData: keepPreviousData })
}

export function useIssue(id: string | number | undefined) {
  return useQuery({ queryKey: qk.issues.detail(id ?? ''), queryFn: () => get<Issue>(`/issues/${id}`), enabled: id !== undefined })
}

export function usePayments(filters: Filters) {
  return useQuery({ queryKey: qk.payments.list(filters), queryFn: () => get<Paginated<Payment>>('/payments', filters), placeholderData: keepPreviousData })
}

export function useExpenses(filters: Filters) {
  return useQuery({ queryKey: qk.expenses.list(filters), queryFn: () => get<Paginated<Expense>>('/expenses', filters), placeholderData: keepPreviousData })
}

// ---- Reports

export function useReportOverview(filters: Filters) {
  return useQuery({ queryKey: qk.reports.overview(filters), queryFn: () => get<ReportOverview>('/reports/overview', filters), placeholderData: keepPreviousData })
}

export function useSalesReport(filters: Filters) {
  return useQuery({ queryKey: qk.reports.sales(filters), queryFn: () => get<SalesReport>('/reports/sales', filters), placeholderData: keepPreviousData })
}

export function useOutstandingReport(filters: Filters) {
  return useQuery({ queryKey: qk.reports.outstanding(filters), queryFn: () => get<OutstandingReport>('/reports/outstanding', filters), placeholderData: keepPreviousData })
}

export function useIssuesReport(filters: Filters) {
  return useQuery({ queryKey: qk.reports.issues(filters), queryFn: () => get<IssuesReport>('/reports/issues', filters), placeholderData: keepPreviousData })
}

// ---- Administration

export function useUsers() {
  return useQuery({ queryKey: qk.users.all, queryFn: () => get<User[]>('/users') })
}

export function useAgencySettings() {
  return useQuery({ queryKey: qk.settings.all, queryFn: () => get<AgencySettings>('/settings') })
}

/** The uploaded agency logo as an object URL (fetched with the auth header), or null when none. */
export function useAgencyLogo() {
  return useQuery({
    queryKey: qk.settings.logo,
    queryFn: async () => {
      try {
        const res = await api.get<Blob>('/settings/logo', { responseType: 'blob' })
        return URL.createObjectURL(res.data)
      } catch {
        return null
      }
    },
    staleTime: Infinity,
  })
}

export function useAuditLogs(filters: Filters) {
  return useQuery({ queryKey: qk.audit.list(filters), queryFn: () => get<Paginated<AuditEntry>>('/audit-logs', filters), placeholderData: keepPreviousData })
}

// ---- Flight quotations and airlines

export function useQuotations(filters: Filters) {
  return useQuery({ queryKey: qk.quotations.list(filters), queryFn: () => get<Paginated<QuotationRow>>('/flight-quotations', filters), placeholderData: keepPreviousData })
}

export function useQuotation(id: string | number | undefined) {
  return useQuery({ queryKey: qk.quotations.detail(id ?? ''), queryFn: () => get<Quotation>(`/flight-quotations/${id}`), enabled: Boolean(id) })
}

/** Email dialog data; refreshes itself while an email is queued or being sent. */
export function useQuotationEmail(id: number, enabled = true) {
  return useQuery({
    queryKey: qk.quotations.email(id),
    queryFn: () => get<QuotationEmailInfo>(`/flight-quotations/${id}/email`),
    enabled,
    refetchInterval: (query) => (query.state.data?.history.some((e) => e.status === 'queued' || e.status === 'sending') ? 3000 : false),
  })
}

export function useAirlines(filters: Filters = {}) {
  return useQuery({ queryKey: qk.airlines.list(filters), queryFn: () => get<Airline[]>('/airlines', filters), staleTime: 60_000 })
}

/** An airline logo as an object URL (fetched with the auth header); null when there is none. */
export function useAirlineLogo(airline: Pick<Airline, 'id' | 'has_logo' | 'logo_version'> | null | undefined) {
  return useQuery({
    queryKey: qk.airlines.logo(airline?.id ?? 0, airline?.logo_version ?? null),
    queryFn: async () => {
      try {
        return URL.createObjectURL((await api.get<Blob>(`/airlines/${airline!.id}/logo`, { responseType: 'blob' })).data)
      } catch {
        return null
      }
    },
    enabled: Boolean(airline?.id && airline.has_logo),
    staleTime: Infinity,
  })
}
