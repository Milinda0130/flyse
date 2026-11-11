import { useAuth } from '../auth/AuthContext'
import type { Meta } from '../lib/types'
import type { ValidationErrors } from '../lib/api'
import { Field, Input, Select, Textarea } from './ui'

export type PassengerDraft = {
  full_name_as_document: string; passenger_type: string; date_of_birth: string; nationality: string
  passport_number: string; passport_expiry: string; phone: string; notes: string
}

export type FlightDraft = {
  airline: string; flight_number: string; origin_code: string; destination_code: string; departure_at: string
  arrival_at: string; origin_timezone: string; destination_timezone: string; cabin_class: string; baggage_allowance: string
  status: string; notes: string
}

export const emptyPassenger = (): PassengerDraft => ({
  full_name_as_document: '', passenger_type: 'adult', date_of_birth: '', nationality: '', passport_number: '', passport_expiry: '', phone: '', notes: '',
})

export const emptyFlight = (previous?: FlightDraft): FlightDraft => ({
  airline: previous?.airline ?? '', flight_number: '', origin_code: previous?.destination_code ?? '', destination_code: '',
  departure_at: '', arrival_at: '', origin_timezone: previous?.destination_timezone ?? '', destination_timezone: '',
  cabin_class: previous?.cabin_class ?? 'economy', baggage_allowance: previous?.baggage_allowance ?? '', status: 'scheduled', notes: '',
})

/** Converts empty strings to null so optional fields are cleared rather than validated. */
export function nullify<T extends Record<string, string>>(draft: T): Record<string, string | null> {
  return Object.fromEntries(Object.entries(draft).map(([k, v]) => [k, v === '' ? null : v]))
}

export function PassengerFields({ value, onChange, errors = {}, prefix = '', meta }: {
  value: PassengerDraft; onChange: (v: PassengerDraft) => void; errors?: ValidationErrors; prefix?: string; meta?: Meta
}) {
  const { can } = useAuth()
  const set = (k: keyof PassengerDraft, v: string) => onChange({ ...value, [k]: v })
  const err = (k: string) => errors[prefix + k]
  return (
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <Field label="Name as on passport *" error={err('full_name_as_document')} className="sm:col-span-2">
        <Input required value={value.full_name_as_document} onChange={(e) => set('full_name_as_document', e.target.value.toUpperCase())} placeholder="SURNAME GIVEN NAMES" />
      </Field>
      <Field label="Type" error={err('passenger_type')}>
        <Select value={value.passenger_type} onChange={(e) => set('passenger_type', e.target.value)} options={meta?.passenger_types ?? []} />
      </Field>
      <Field label="Phone" error={err('phone')}><Input value={value.phone} onChange={(e) => set('phone', e.target.value)} /></Field>
      <Field label="Date of birth" error={err('date_of_birth')} hint="Only if required"><Input type="date" value={value.date_of_birth} onChange={(e) => set('date_of_birth', e.target.value)} /></Field>
      <Field label="Nationality" error={err('nationality')}><Input value={value.nationality} onChange={(e) => set('nationality', e.target.value)} /></Field>
      {can('view-passport') && (
        <Field label="Passport number" error={err('passport_number')} hint="Only if required · encrypted">
          <Input value={value.passport_number} onChange={(e) => set('passport_number', e.target.value.toUpperCase())} autoComplete="off" />
        </Field>
      )}
      <Field label="Passport expiry" error={err('passport_expiry')}><Input type="date" value={value.passport_expiry} onChange={(e) => set('passport_expiry', e.target.value)} /></Field>
    </div>
  )
}

export function FlightFields({ value, onChange, errors = {}, prefix = '', meta, showStatus }: {
  value: FlightDraft; onChange: (v: FlightDraft) => void; errors?: ValidationErrors; prefix?: string; meta?: Meta; showStatus?: boolean
}) {
  const set = (k: keyof FlightDraft, v: string) => onChange({ ...value, [k]: v })
  const err = (k: string) => errors[prefix + k]
  return (
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
      <Field label="Airline *" error={err('airline')} className="lg:col-span-2"><Input required value={value.airline} onChange={(e) => set('airline', e.target.value)} placeholder="Qatar Airways" /></Field>
      <Field label="Flight no. *" error={err('flight_number')}><Input required value={value.flight_number} onChange={(e) => set('flight_number', e.target.value.toUpperCase())} placeholder="QR655" /></Field>
      <Field label="From *" error={err('origin_code')}><Input required maxLength={3} value={value.origin_code} onChange={(e) => set('origin_code', e.target.value.toUpperCase())} placeholder="CMB" /></Field>
      <Field label="To *" error={err('destination_code')}><Input required maxLength={3} value={value.destination_code} onChange={(e) => set('destination_code', e.target.value.toUpperCase())} placeholder="ARN" /></Field>
      <Field label="Cabin" error={err('cabin_class')}><Select value={value.cabin_class} onChange={(e) => set('cabin_class', e.target.value)} options={meta?.cabin_classes ?? []} /></Field>
      <Field label="Departs (local) *" error={err('departure_at')} className="lg:col-span-2"><Input type="datetime-local" required value={value.departure_at} onChange={(e) => set('departure_at', e.target.value)} /></Field>
      <Field label="Arrives (local)" error={err('arrival_at')} className="lg:col-span-2"><Input type="datetime-local" value={value.arrival_at} onChange={(e) => set('arrival_at', e.target.value)} /></Field>
      <Field label="Baggage" error={err('baggage_allowance')} className="lg:col-span-2" hint="From the confirmed itinerary"><Input value={value.baggage_allowance} onChange={(e) => set('baggage_allowance', e.target.value)} placeholder="e.g. 2 × 23 kg" /></Field>
      <Field label="Origin timezone" error={err('origin_timezone')} className="lg:col-span-2"><Input value={value.origin_timezone} onChange={(e) => set('origin_timezone', e.target.value)} placeholder="Asia/Colombo" list="tz-list" /></Field>
      <Field label="Destination timezone" error={err('destination_timezone')} className="lg:col-span-2"><Input value={value.destination_timezone} onChange={(e) => set('destination_timezone', e.target.value)} placeholder="Europe/Stockholm" list="tz-list" /></Field>
      {showStatus && <Field label="Flight status" error={err('status')} className="lg:col-span-2"><Select value={value.status} onChange={(e) => set('status', e.target.value)} options={meta?.flight_statuses ?? []} /></Field>}
      {showStatus && <Field label="Notes" error={err('notes')} className="lg:col-span-6"><Textarea rows={2} value={value.notes} onChange={(e) => set('notes', e.target.value)} /></Field>}
      <TimezoneList />
    </div>
  )
}

const COMMON_TZ = ['Asia/Colombo', 'Asia/Dubai', 'Asia/Qatar', 'Asia/Kolkata', 'Asia/Singapore', 'Asia/Bangkok', 'Asia/Kuala_Lumpur', 'Asia/Riyadh', 'Asia/Tokyo',
  'Europe/London', 'Europe/Paris', 'Europe/Frankfurt', 'Europe/Stockholm', 'Europe/Istanbul', 'Europe/Rome', 'America/New_York', 'America/Toronto', 'Australia/Melbourne', 'Australia/Sydney']

function TimezoneList() {
  return <datalist id="tz-list">{COMMON_TZ.map((tz) => <option key={tz} value={tz} />)}</datalist>
}
