import { ArrowRight } from 'lucide-react'
import { dateTime } from '../lib/format'
import type { AuditEntry } from '../lib/types'

const SKIP = new Set(['id', 'booking_id', 'created_by', 'updated_by', 'storage_key'])

function show(v: unknown) {
  if (v === null || v === undefined || v === '') return '∅'
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}

/** Readable before → after table of audit entries. */
export default function AuditTable({ entries }: { entries: AuditEntry[] }) {
  return (
    <ul className="divide-y divide-slate-100">
      {entries.map((e) => {
        const keys = Array.from(new Set([...Object.keys(e.before ?? {}), ...Object.keys(e.after ?? {})])).filter((k) => !SKIP.has(k))
        return (
          <li key={e.id} className="px-4 py-3 text-sm">
            <div className="flex flex-wrap justify-between gap-2">
              <span><span className="font-medium">{e.user?.name ?? 'System'}</span> {e.action} <span className="text-slate-600">{e.entity_type} #{e.entity_id}</span></span>
              <span className="text-xs text-slate-500">{dateTime(e.created_at)}</span>
            </div>
            {keys.length > 0 && (
              <div className="mt-1 grid gap-x-4 text-xs text-slate-600 sm:grid-cols-[minmax(8rem,auto)_1fr]">
                {keys.map((k) => (
                  <div key={k} className="contents">
                    <span className="font-medium text-slate-500">{k.replace(/_/g, ' ')}</span>
                    <span className="break-all">
                      {e.action === 'updated'
                        ? <><span className="text-red-700 line-through">{show(e.before?.[k])}</span> <ArrowRight className="inline h-3 w-3 text-slate-400" aria-label="changed to" /> <span className="text-emerald-700">{show(e.after?.[k])}</span></>
                        : show((e.after ?? e.before)?.[k])}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </li>
        )
      })}
    </ul>
  )
}
