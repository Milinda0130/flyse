import { Plane } from 'lucide-react'
import { useAirlineLogo } from '../hooks/queries'
import type { Airline } from '../lib/types'

/** Airline logo (aspect ratio kept) or a neutral fallback with the airline code. */
export default function AirlineLogo({ airline, name, className = 'h-6 w-16' }: { airline?: Airline | null; name?: string | null; className?: string }) {
  const { data: url } = useAirlineLogo(airline)
  if (url) return <img src={url} alt={airline?.name ?? name ?? 'Airline logo'} className={`${className} object-contain`} />
  const label = airline?.iata_code ?? (airline?.name ?? name ?? '').slice(0, 2).toUpperCase()
  return (
    <span className={`${className} inline-flex items-center justify-center gap-1 rounded bg-slate-100 text-[10px] font-semibold text-slate-500`} title={airline?.name ?? name ?? undefined}>
      <Plane className="h-3 w-3" aria-hidden />{label}
    </span>
  )
}
