/**
 * Every TanStack Query cache key in one place.
 *
 * Keys are hierarchical: invalidating `qk.bookings.all` also refreshes every booking
 * list, booking detail and booking activity page. Ids are always strings so
 * `detail(5)` and `detail('5')` hit the same cache entry.
 */
type Filters = Record<string, unknown>

const id = (value: string | number) => String(value)

export const qk = {
  me: ['me'] as const,
  meta: ['meta'] as const,

  dashboard: {
    all: ['dashboard'] as const,
    summary: () => ['dashboard', 'summary'] as const,
    departureAlerts: () => ['dashboard', 'departure-alerts'] as const,
  },

  bookings: {
    all: ['bookings'] as const,
    list: (filters: Filters) => ['bookings', 'list', filters] as const,
    detail: (bookingId: string | number) => ['bookings', 'detail', id(bookingId)] as const,
    activity: (bookingId: string | number, page: number) => ['bookings', 'activity', id(bookingId), page] as const,
    ticketEmail: (bookingId: string | number) => ['bookings', 'ticket-email', id(bookingId)] as const,
  },

  customers: {
    all: ['customers'] as const,
    list: (filters: Filters) => ['customers', 'list', filters] as const,
    detail: (customerId: string | number) => ['customers', 'detail', id(customerId)] as const,
    search: (term: string) => ['customers', 'search', term] as const,
  },

  suppliers: { all: ['suppliers'] as const },

  issues: {
    all: ['issues'] as const,
    list: (filters: Filters) => ['issues', 'list', filters] as const,
    detail: (issueId: string | number) => ['issues', 'detail', id(issueId)] as const,
  },

  payments: {
    all: ['payments'] as const,
    list: (filters: Filters) => ['payments', 'list', filters] as const,
  },

  expenses: {
    all: ['expenses'] as const,
    list: (filters: Filters) => ['expenses', 'list', filters] as const,
  },

  reports: {
    all: ['reports'] as const,
    overview: (filters: Filters) => ['reports', 'overview', filters] as const,
    sales: (filters: Filters) => ['reports', 'sales', filters] as const,
    outstanding: (filters: Filters) => ['reports', 'outstanding', filters] as const,
    issues: (filters: Filters) => ['reports', 'issues', filters] as const,
  },

  quotations: {
    all: ['quotations'] as const,
    list: (filters: Filters) => ['quotations', 'list', filters] as const,
    detail: (quotationId: string | number) => ['quotations', 'detail', id(quotationId)] as const,
    email: (quotationId: string | number) => ['quotations', 'email', id(quotationId)] as const,
  },

  airlines: {
    all: ['airlines'] as const,
    list: (filters: Filters) => ['airlines', 'list', filters] as const,
    logo: (airlineId: number, version: number | null) => ['airlines', 'logo', airlineId, version] as const,
  },

  users: { all: ['users'] as const },
  settings: { all: ['settings'] as const, logo: ['settings', 'logo'] as const },
  audit: {
    all: ['audit'] as const,
    list: (filters: Filters) => ['audit', 'list', filters] as const,
  },
}
