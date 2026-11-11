export type Option = { value: string; label: string }

export type Abilities = Record<
  | 'edit-records' | 'view-passport' | 'view-costs' | 'view-reports' | 'manage-payments' | 'delete-documents'
  | 'manage-expenses' | 'manage-users' | 'manage-settings' | 'view-audit' | 'record-supplier-payment' | 'record-receipt' | 'email-tickets' | 'verify-documents' | 'manage-airlines' | 'delete-airlines',
  boolean
>

export type User = {
  id: number; name: string; email: string; role: string; role_label: string; is_active: boolean
  last_login_at: string | null; created_at: string | null
}

export type Meta = {
  booking_statuses: Option[]; trip_types: Option[]; supplier_types: Option[]; passenger_types: Option[]; flight_statuses: Option[]
  cabin_classes: Option[]; ticket_statuses: Option[]; document_types: Option[]; document_sources: Option[]; verification_statuses: Option[]; quotation_statuses: Option[]; baggage_statuses: Option[]; refundabilities: Option[]; yes_no_unknown: Option[]; issue_types: Option[]
  issue_sources: Option[]; issue_severities: Option[]; issue_statuses: Option[]; payment_directions: Option[]
  payment_statuses: Option[]; user_roles: Option[]; currencies: string[]; default_currency: string
  payment_methods: string[]; expense_categories: string[]; agency_name: string; users: { id: number; name: string }[]
}

export type Paginated<T> = {
  data: T[]
  meta: { current_page: number; last_page: number; total: number; per_page: number; from: number | null; to: number | null }
}

export type Customer = {
  id: number; full_name: string; phone: string | null; email: string | null; nationality: string | null
  bookings_count?: number; address?: string | null; date_of_birth?: string | null; passport_number?: string | null
  passport_expiry?: string | null; notes?: string | null; created_at?: string
}

export type Supplier = {
  id: number; name: string; supplier_type: string; contact_person: string | null; phone: string | null
  email: string | null; notes: string | null; is_active: boolean; bookings_count?: number
}

export type Passenger = {
  id: number; booking_id: number; full_name_as_document: string; passenger_type: string; date_of_birth: string | null
  nationality: string | null; passport_number: string | null; passport_expiry: string | null; phone: string | null; notes: string | null
}

export type Flight = {
  id: number; booking_id: number; segment_order: number; airline: string; flight_number: string; origin_code: string
  destination_code: string; departure_at: string; arrival_at: string | null; origin_timezone: string | null
  destination_timezone: string | null; cabin_class: string; baggage_allowance: string | null; status: string; notes: string | null
}

export type Ticket = {
  id: number; booking_id: number; passenger_id: number; booking_flight_id: number | null; ticket_number: string
  airline_pnr: string | null; issued_at: string | null; status: string; fare_conditions: string | null
  e_ticket_document_id: number | null; e_ticket?: { id: number; original_name: string } | null
}

export type DocumentFile = {
  id: number; booking_id: number | null; passenger_id: number | null; issue_id: number | null; document_type: string
  original_name: string; mime_type: string; size_bytes: number; uploaded_by?: { id: number; name: string } | null; created_at: string
  booking_flight_id: number | null; notes: string | null; ticket_numbers?: string[]
  /** Alternative ticket PDFs only; null for official agency documents. */
  source_type?: string | null; source_label?: string | null; source_description?: string | null
  verification_status?: VerificationStatus | null; verification_label?: string | null
  verified_by?: { id: number; name: string } | null; verified_at?: string | null; verification_note?: string | null
}

export type VerificationStatus = 'pending' | 'verified' | 'rejected'
export type EmailType = 'official' | 'alternative'

export type IssueUpdate = {
  id: number; update_text: string; status_from: string | null; status_to: string | null
  author?: { id: number; name: string } | null; created_at: string
}

export type Issue = {
  id: number; booking_id: number; booking?: { id: number; booking_code: string; pnr: string | null; customer: string | null }
  booking_flight_id: number | null; flight?: Flight | null; issue_type: string; source: string; severity: string; status: string
  summary: string; description: string | null; next_action: string | null; reported_at: string; due_at: string | null
  resolved_at: string | null; is_overdue: boolean; customer_notified: boolean; resolution_notes: string | null
  refund_reference: string | null; assigned_to: number | null; assignee?: { id: number; name: string } | null
  creator?: { id: number; name: string } | null; updates?: IssueUpdate[]; documents?: DocumentFile[]
  flight_history?: AuditEntry[]
}

export type Payment = {
  id: number; booking_id: number; booking?: { id: number; booking_code: string }; direction: string; amount: string
  currency: string; method: string; transaction_reference: string | null; transaction_date: string; status: string
  notes: string | null; creator?: { id: number; name: string } | null; created_at: string
}

/** Amounts are strings in the booking currency; null means "not entered yet" (never shown as 0). */
export type Finance = {
  currency: string; customer_due: string | null; customer_received: string; customer_refunded: string; customer_paid: string
  customer_balance: string | null; payment_status: string; refund_pending: string; supplier_total?: string | null
  supplier_paid?: string; supplier_refunded?: string; supplier_balance?: string | null; gross_margin?: string | null
}

export type ChecklistItem = { key: string; label: string; section: 'overview' | 'passengers' | 'documents' | 'payments' }

export type Booking = {
  id: number; booking_code: string; customer_id: number
  customer?: { id: number; full_name: string; phone: string | null; email: string | null }
  supplier_id: number | null; supplier?: { id: number; name: string } | null; pnr: string | null; booking_date: string
  currency: string; status: string; payment_due_date: string | null; ticketing_deadline: string | null
  trip_type: string; origin: string | null; destination: string | null; departure_date: string | null; return_date: string | null
  cabin_class: string | null; passenger_count: number; requested_route: string | null
  cancellation_reason: string | null; cancelled_at: string | null
  checklist?: ChecklistItem[]; next_step?: string; ticketing_problems?: string[]; allowed_transitions?: string[]
  selling_total: string | null; service_fee: string | null; supplier_total?: string | null; next_action: string | null; notes: string | null
  route: string | null; first_departure_at: string | null; airlines: string[] | null; passengers_count?: number
  open_issues_count?: number; finance: Finance | null; passengers?: Passenger[]; flights?: Flight[]; tickets?: Ticket[]
  documents?: DocumentFile[]; issues?: Issue[]; payments?: Payment[]; creator?: { id: number; name: string } | null
  created_at: string; updated_at: string
}

export type AuditEntry = {
  id: number; user?: { id: number; name: string } | null; entity_type: string; entity_id: string; booking_id: number | null
  action: string; before: Record<string, unknown> | null; after: Record<string, unknown> | null; created_at: string
}

export type Expense = {
  id: number; expense_date: string; category: string; description: string; amount: string; currency: string
  payment_method: string | null; receipt?: DocumentFile | null; creator?: { id: number; name: string } | null
}

// ---- Dashboard, reports and settings responses

export type UpcomingDeparture = Flight & { booking: { id: number; booking_code: string; pnr: string | null; status: string; customer: string | null; phone: string | null; passengers_count: number } }
export type MonthlyRow = { group: string; currency: string; bookings: number; sales: string; cost: string; margin: string }
export type DashboardSummary = {
  bookings: { total: number; by_status: Record<string, number>; this_month: number }
  issues: { open: number; overdue: number; list: Issue[] }
  refunds_awaiting: { open_refund_issues: number; pending_refund_payments: number }
  customer_balances: { count: number; totals: { currency: string; total: string }[]; overdue: { booking_id: number; booking_code: string; customer: string; balance: string; currency: string; payment_due_date: string }[] }
  upcoming_departures: UpcomingDeparture[]
  ticketing_deadlines: { id: number; booking_code: string; customer: string; status: string; ticketing_deadline: string }[]
  supplier_payables?: { currency: string; total: string }[]
  monthly?: MonthlyRow[]
}

export type SalesRow = { group: string; currency: string; bookings: number; sales: string; cost: string; margin: string }
export type SalesTotal = { currency: string; bookings: number; sales: string; cost: string; gross_margin: string; expenses: string; net_estimate: string; incomplete: number }
export type SalesReport = { rows: SalesRow[]; totals: SalesTotal[] }

export type OutstandingReport = {
  customer_balances: { booking_id: number; booking_code: string; customer: string; phone: string | null; currency: string; customer_due: string; customer_paid: string; balance: string; payment_due_date: string | null; status: string }[]
  customer_totals: { currency: string; total: string }[]
  supplier_payables: { booking_id: number; booking_code: string; supplier: string; currency: string; supplier_total: string; supplier_paid: string; balance: string }[]
  supplier_totals: { currency: string; total: string }[]
}

export type IssuesReport = {
  total: number; open: number; open_refund_issues: number
  by_type: { type: string; label: string; total: number; open: number; resolved: number }[]
  cancellations: { booking_id: number; booking_code: string; customer: string; pnr: string | null; cancelled_on: string }[]
  refunds: { payment_id: number; booking_id: number; booking_code: string; direction: string; amount: string; currency: string; status: string; transaction_date: string; reference: string | null }[]
}

export type AgencySettings = { agency_name: string; agency_address: string; agency_phone: string; agency_email: string; agency_website?: string; agency_logo_url?: string; default_currency: string; currencies: string[]; payment_methods: string[]; expense_categories: string[] }

export type RankedRow = { name: string; bookings: number; sales: string; margin: string | null; share?: number }
export type ReportOverview = {
  currency: string
  period: { from: string; to: string; previous_from: string; previous_to: string }
  kpis: OverviewKpis & { customer_outstanding: string; supplier_payable: string }
  previous: OverviewKpis
  monthly: { month: string; label: string; bookings: number; sales: string; cost: string; margin: string }[]
  by_airline: RankedRow[]
  by_route: RankedRow[]
  by_supplier: (RankedRow & { share: number })[]
  top_customers: RankedRow[]
  by_status: { status: string; label: string; count: number; share: number }[]
  payment_methods: { name: string; amount: string; share: number }[]
  issues_by_type: { type: string; label: string; open: number; resolved: number }[]
}
export type OverviewKpis = {
  bookings: number; passengers: number; sales: string; cost: string; margin: string; margin_pct: number | null
  expenses: string; net: string; avg_booking_value: string; cancelled: number; cancellation_rate: number | null; inquiries: number
  incomplete?: number
}

// ---- Ticket emails

export type EmailAttachment = { document_id: number; name: string; size_bytes: number; passengers: string[]; tickets: string[] }

export type BookingEmail = {
  id: number; booking_id: number; email_type: EmailType; email_type_label: string; reviewed_confirmed: boolean; official_pending: boolean
  recipient: string; recipient_is_override: boolean; subject: string
  attachments: EmailAttachment[]; attachment_count: number; total_bytes: number
  status: 'queued' | 'sending' | 'sent' | 'failed'; status_label: string; mailer: string | null; delivered_by_provider: boolean
  failure_reason: string | null; attempts: number; requested_by?: string | null
  requested_at: string; sent_at: string | null; failed_at: string | null
}

export type TicketEmailInfo = {
  customer: { name: string | null; email: string | null }
  subject: string
  subjects: Record<EmailType, string>
  documents: EmailableDocument[]
  alternative_documents: EmailableDocument[]
  limits: { max_total_bytes: number; max_file_bytes: number }
  booking_problem: string | null
  mail_problem: string | null
  delivers_mail: boolean
  mailer: string
  queued: boolean
  history: BookingEmail[]
}

export type EmailableDocument = {
  id: number; name: string; size_bytes: number; passengers: string[]; tickets: string[]; readable: boolean; sendable: boolean
  uploaded_at: string | null; uploaded_by: string | null
  source_type: string | null; source_label: string | null; source_description: string | null
  verification_status: VerificationStatus | null; verified_by: string | null; verified_at: string | null; verification_note: string | null
}

export type TicketEmailPreview = {
  email_type: EmailType; subject: string; recipient: string; recipient_is_override: boolean; attachments: EmailAttachment[]; total_bytes: number; html: string
}

// ---- Flight quotations (manual; never a reservation)

export type Airline = {
  id: number; name: string; iata_code: string | null; icao_code: string | null; is_active: boolean
  has_logo: boolean; logo_version: number | null; segments_count?: number
}

export type QuotationStatus = 'draft' | 'ready' | 'sent' | 'accepted' | 'rejected' | 'expired' | 'converted'
export type BaggageState = 'included' | 'not_included' | 'unknown'

export type QuotationSegment = {
  id?: number; direction: 'outbound' | 'return'; segment_order?: number
  airline_id: number | null; airline_name: string | null; operating_airline_id: number | null; operating_airline_name: string | null
  flight_number: string; from_code: string; from_name: string | null; to_code: string; to_name: string | null
  departure_at: string; arrival_at: string | null; departure_tz: string | null; arrival_tz: string | null; duration_minutes: number | null
  departure_terminal: string | null; arrival_terminal: string | null; aircraft: string | null; cabin_class: string | null
  baggage_note: string | null; notes: string | null
  duration?: string | null; layover?: string | null
}

export type QuotationExtra = { title: string; amount: string | null; details: string | null }

export type QuotationOption = {
  id?: number; label: string; fare_family: string | null; cabin_class: string | null; currency: string
  adult_fare: string | null; adult_tax: string | null; child_fare: string | null; child_tax: string | null
  infant_fare: string | null; infant_tax: string | null; other_charges: string | null; supplier_cost?: string | null
  checked_bag_status: BaggageState; checked_bag_allowance: string | null; cabin_bag_status: BaggageState; cabin_bag_allowance: string | null
  personal_item_status: BaggageState; personal_item_allowance: string | null; baggage_notes: string | null
  refundability: string; change_permitted: string; change_fee: string | null; fare_difference: string
  cancellation_conditions: string | null; no_show_conditions: string | null; other_conditions: string | null
  extras: QuotationExtra[]; price_valid_until: string | null; notes: string | null; internal_notes?: string | null
  segments: QuotationSegment[]
  pricing?: {
    currency: string; rows: { type: string; title: string; count: number; per_person: string | null; subtotal: string | null }[]
    adult_per_person: string | null; party_total: string | null; margin?: string | null
  }
  legs?: Record<string, { travel_time: string | null; stops: number }>
}

export type QuotationPdf = { id: number; version: number; filename: string; size_bytes: number; generated_by?: string | null; created_at: string }

export type Quotation = {
  id: number; reference: string; status: QuotationStatus; status_label: string; status_changed_at: string | null
  customer_id: number | null; customer?: { id: number; full_name: string; email: string | null; phone: string | null } | null
  trip_type: 'one_way' | 'round_trip'; origin_country: string | null; origin_city: string; origin_code: string | null
  destination_country: string | null; destination_city: string; destination_code: string | null; route: string
  outbound_date: string; return_date: string | null; adults: number; children: number; infants: number
  cabin_class: string; currency: string; valid_until: string | null
  requested_airline: string | null; preferred_departure_time: string | null; max_stops: number | null; requested_baggage: string | null
  customer_requirements: string | null; message_to_customer: string | null; internal_notes?: string | null
  accepted_option_id: number | null; booking?: { id: number; booking_code: string } | null; creator?: string | null
  created_at: string; updated_at: string
  options: QuotationOption[]
  meta: {
    problems: string[]; warnings: string[]; editable: boolean; transitions: QuotationStatus[]
    pdf_is_current: boolean; pdfs: QuotationPdf[]; email_problem: string | null
  }
}

export type QuotationRow = {
  id: number; reference: string; status: QuotationStatus; status_label: string; customer: { id: number; full_name: string | null } | null
  origin: string; destination: string; trip_type: string; outbound_date: string; return_date: string | null; passengers: number
  options_count: number; currency: string; price_range: string | null; price_range_mixed: boolean; valid_until: string | null
  last_email: { status: string; at: string | null } | null; created_at: string
}

export type QuotationEmail = {
  id: number; recipient: string; recipient_is_override: boolean; subject: string; attachment_name: string; size_bytes: number
  pdf_id: number; pdf_version?: number; status: 'queued' | 'sending' | 'sent' | 'failed'; mailer: string | null
  delivered_by_provider: boolean; failure_reason: string | null; attempts: number; requested_by?: string | null
  requested_at: string; sent_at: string | null; failed_at: string | null
}

export type QuotationEmailInfo = {
  customer: { name: string | null; email: string | null }; subject: string; problem: string | null; content_problems: string[]
  pdf_is_current: boolean; latest_pdf: QuotationPdf | null; delivers_mail: boolean; mailer: string; history: QuotationEmail[]
}
