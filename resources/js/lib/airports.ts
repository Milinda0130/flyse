/**
 * Common airports for search-as-you-type in the trip form. Offline list (no live flight API);
 * users may still type any other city or code.
 */
import airports from '../../data/airports.json'

export type Airport = { code: string; city: string; name: string; country: string }

// Shared with the server (ticket PDFs): resources/data/airports.json
export const AIRPORTS: Airport[] = airports

const byCode = new Map(AIRPORTS.map((a) => [a.code, a]))

const timezoneByCountry: Record<string, string> = {
  'Sri Lanka': 'Asia/Colombo', Maldives: 'Indian/Maldives', India: 'Asia/Kolkata', Nepal: 'Asia/Kathmandu', Bangladesh: 'Asia/Dhaka',
  Pakistan: 'Asia/Karachi', Qatar: 'Asia/Qatar', 'United Arab Emirates': 'Asia/Dubai', Oman: 'Asia/Muscat', Bahrain: 'Asia/Bahrain',
  Kuwait: 'Asia/Kuwait', 'Saudi Arabia': 'Asia/Riyadh', Jordan: 'Asia/Amman', Israel: 'Asia/Jerusalem', Türkiye: 'Europe/Istanbul',
  Egypt: 'Africa/Cairo', Sweden: 'Europe/Stockholm', Denmark: 'Europe/Copenhagen', Norway: 'Europe/Oslo', Finland: 'Europe/Helsinki',
  'United Kingdom': 'Europe/London', Ireland: 'Europe/Dublin', France: 'Europe/Paris', Netherlands: 'Europe/Amsterdam', Belgium: 'Europe/Brussels',
  Germany: 'Europe/Berlin', Switzerland: 'Europe/Zurich', Austria: 'Europe/Vienna', Italy: 'Europe/Rome', Spain: 'Europe/Madrid',
  Portugal: 'Europe/Lisbon', Greece: 'Europe/Athens', Poland: 'Europe/Warsaw', Czechia: 'Europe/Prague', Russia: 'Europe/Moscow',
  Singapore: 'Asia/Singapore', Malaysia: 'Asia/Kuala_Lumpur', Thailand: 'Asia/Bangkok', Philippines: 'Asia/Manila', Vietnam: 'Asia/Ho_Chi_Minh',
  'Hong Kong': 'Asia/Hong_Kong', China: 'Asia/Shanghai', 'South Korea': 'Asia/Seoul', Japan: 'Asia/Tokyo', 'New Zealand': 'Pacific/Auckland',
  'South Africa': 'Africa/Johannesburg', Kenya: 'Africa/Nairobi', Ethiopia: 'Africa/Addis_Ababa',
}

const timezoneByAirport: Record<string, string> = {
  CGK: 'Asia/Jakarta', DPS: 'Asia/Makassar',
  SYD: 'Australia/Sydney', MEL: 'Australia/Melbourne', BNE: 'Australia/Brisbane', PER: 'Australia/Perth', ADL: 'Australia/Adelaide',
  JFK: 'America/New_York', EWR: 'America/New_York', IAD: 'America/New_York', ORD: 'America/Chicago', LAX: 'America/Los_Angeles', SFO: 'America/Los_Angeles',
  YYZ: 'America/Toronto', YVR: 'America/Vancouver', YUL: 'America/Toronto',
  IST: 'Europe/Istanbul', SAW: 'Europe/Istanbul',
}

/** IANA timezone for a known airport, including airport-specific zones in multi-zone countries. */
export function airportTimezone(value: string | null | undefined): string | null {
  if (!value) return null
  const airport = byCode.get(value.toUpperCase())
  if (!airport) return null

  return timezoneByAirport[airport.code] ?? timezoneByCountry[airport.country] ?? null
}

/** "Colombo (CMB)" for a known code, otherwise the text as entered. */
export function airportLabel(value: string | null | undefined): string {
  if (!value) return '—'
  const a = byCode.get(value.toUpperCase())
  return a ? `${a.city} (${a.code})` : value
}

/** Matches by code first, then city, then airport name or country. */
export function searchAirports(term: string, limit = 8): Airport[] {
  const q = term.trim().toLowerCase()
  if (!q) return AIRPORTS.slice(0, limit)
  const score = (a: Airport) => {
    if (a.code.toLowerCase() === q) return 0
    if (a.code.toLowerCase().startsWith(q)) return 1
    if (a.city.toLowerCase().startsWith(q)) return 2
    if (a.city.toLowerCase().includes(q)) return 3
    if (a.name.toLowerCase().includes(q) || a.country.toLowerCase().startsWith(q)) return 4
    return 9
  }
  return AIRPORTS.map((a) => ({ a, s: score(a) })).filter((x) => x.s < 9).sort((x, y) => x.s - y.s).slice(0, limit).map((x) => x.a)
}
