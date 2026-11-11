import { useEffect, useState } from 'react'
import { Clock } from 'lucide-react'

/** Live local date and time for the top bar; ticks every second. Phones show the time only. */
export default function LiveClock() {
  const [now, setNow] = useState(() => new Date())

  useEffect(() => {
    // Align ticks to the start of each second so the display never skips.
    let interval: number | undefined
    const timeout = window.setTimeout(() => {
      setNow(new Date())
      interval = window.setInterval(() => setNow(new Date()), 1000)
    }, 1000 - (Date.now() % 1000))
    return () => {
      window.clearTimeout(timeout)
      window.clearInterval(interval)
    }
  }, [])

  const day = now.toLocaleDateString(undefined, { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' })
  const time = now.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' })
  const shortTime = now.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })

  return (
    <time
      dateTime={now.toISOString()}
      className="flex shrink-0 items-center gap-2 rounded-md border border-slate-200 bg-surface px-2.5 py-1.5 text-sm tabular-nums text-slate-700"
      title={now.toLocaleString(undefined, { dateStyle: 'full', timeStyle: 'long' })}
    >
      <Clock className="hidden h-4 w-4 text-brand-600 sm:block" aria-hidden />
      <span className="hidden whitespace-nowrap text-slate-500 lg:inline">{day}</span>
      <span className="hidden whitespace-nowrap font-medium text-slate-900 sm:inline">{time}</span>
      <span className="whitespace-nowrap font-medium text-slate-900 sm:hidden">{shortTime}</span>
    </time>
  )
}
