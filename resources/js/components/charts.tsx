import type { ReactNode } from 'react'
import {
  Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts'
import { useTheme } from '../lib/theme'
import { money } from '../lib/format'
import { Empty } from './ui'

/**
 * Shared chart building blocks for the reports dashboard.
 *
 * Colours: the validated categorical palette (slots 1-6), stepped separately for the
 * light and dark card surfaces. Checked with the dataviz validator in both modes,
 * including the donut wrap-around pair. In light mode slots 3-5 sit below 3:1 against
 * white, so every donut also lists names, values and percentages as text.
 */
const PALETTE = {
  light: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300'],
  dark: ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300'],
}

export function useChartTheme() {
  const { isDark } = useTheme()
  return {
    series: isDark ? PALETTE.dark : PALETTE.light,
    grid: isDark ? '#292524' : '#e7e5e4',
    axis: isDark ? '#44403c' : '#d6d3d1',
    tick: isDark ? '#a8a29e' : '#57534e',
    cursor: isDark ? 'rgba(168,162,158,0.12)' : 'rgba(120,113,108,0.10)',
    surface: isDark ? '#191816' : '#ffffff',
  }
}

const tooltipStyle = {
  contentStyle: { background: 'rgb(var(--surface))', border: '1px solid rgb(var(--slate-200))', borderRadius: 8, color: 'rgb(var(--slate-800))', fontSize: 12 },
  labelStyle: { color: 'rgb(var(--slate-600))', fontWeight: 600 },
  itemStyle: { color: 'rgb(var(--slate-800))' },
}

const compact = (v: number) => v.toLocaleString(undefined, { notation: 'compact', maximumFractionDigits: 1 })

/** Card with a title, an optional caption and the chart. */
export function ChartCard({ title, caption, children, className = '', action }: { title: string; caption?: string; children: ReactNode; className?: string; action?: ReactNode }) {
  return (
    <section className={`card flex min-w-0 flex-col ${className}`}>
      <header className="flex flex-wrap items-start justify-between gap-2 px-4 pb-1 pt-4">
        <div>
          <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
          {caption && <p className="mt-0.5 text-xs text-slate-500">{caption}</p>}
        </div>
        {action}
      </header>
      <div className="min-h-0 flex-1 px-2 pb-3 sm:px-4">{children}</div>
    </section>
  )
}

/** Two money series side by side per category (e.g. sales vs margin per month). One shared axis. */
export function GroupedBars({ data, xKey, series, currency, height = 300 }: {
  data: Record<string, string | number>[]
  xKey: string
  series: { key: string; label: string }[]
  currency: string
  height?: number
}) {
  const c = useChartTheme()
  if (!data.length) return <Empty>No data for this period.</Empty>
  return (
    <div style={{ height }} className="w-full">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data} barGap={2} barCategoryGap="22%" margin={{ top: 8, right: 4, left: 0, bottom: 0 }}>
          <CartesianGrid vertical={false} stroke={c.grid} />
          <XAxis dataKey={xKey} tickLine={false} axisLine={{ stroke: c.axis }} tick={{ fontSize: 11, fill: c.tick }} interval="preserveStartEnd" minTickGap={8} />
          <YAxis tickLine={false} axisLine={false} width={48} tick={{ fontSize: 11, fill: c.tick }} tickFormatter={compact} />
          <Tooltip cursor={{ fill: c.cursor }} formatter={(v: number, name: string) => [money(v, currency), name]} {...tooltipStyle} />
          <Legend iconType="circle" iconSize={8} wrapperStyle={{ fontSize: 12, color: c.tick, paddingTop: 4 }} />
          {series.map((s, i) => (
            <Bar key={s.key} dataKey={s.key} name={s.label} fill={c.series[i]} radius={[4, 4, 0, 0]} maxBarSize={26} animationDuration={450} />
          ))}
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}

/** Ranked horizontal bars in one hue (magnitude comparison); names on the left, values in the tooltip. */
export function RankedBars({ data, valueKey, label, currency, height }: {
  data: { name: string; [k: string]: string | number | null }[]
  valueKey: string
  label: string
  currency?: string
  height?: number
}) {
  const c = useChartTheme()
  if (!data.length) return <Empty>No data for this period.</Empty>
  const rows = data.map((d) => ({ ...d, value: Number(d[valueKey] ?? 0) }))
  const h = height ?? Math.max(160, rows.length * 36 + 24)
  return (
    <div style={{ height: h }} className="w-full">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={rows} layout="vertical" barCategoryGap="28%" margin={{ top: 4, right: 12, left: 4, bottom: 0 }}>
          <CartesianGrid horizontal={false} stroke={c.grid} />
          <XAxis type="number" tickLine={false} axisLine={false} tick={{ fontSize: 11, fill: c.tick }} tickFormatter={compact} />
          <YAxis type="category" dataKey="name" tickLine={false} axisLine={false} width={112} tick={{ fontSize: 11, fill: c.tick }}
            tickFormatter={(v: string) => (v.length > 16 ? `${v.slice(0, 15)}…` : v)} />
          <Tooltip cursor={{ fill: c.cursor }} formatter={(v: number) => [currency ? money(v, currency) : v.toLocaleString(), label]} {...tooltipStyle} />
          <Bar dataKey="value" name={label} fill={c.series[0]} radius={[0, 4, 4, 0]} maxBarSize={22} animationDuration={450} />
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}

/** Stacked horizontal bars of two counts per category (e.g. open vs resolved issues). */
export function StackedCountBars({ data, keys }: { data: { label: string; [k: string]: string | number }[]; keys: { key: string; label: string }[] }) {
  const c = useChartTheme()
  if (!data.length) return <Empty>No issues in this period.</Empty>
  return (
    <div style={{ height: Math.max(160, data.length * 36 + 48) }} className="w-full">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data} layout="vertical" barCategoryGap="28%" margin={{ top: 4, right: 12, left: 4, bottom: 0 }}>
          <CartesianGrid horizontal={false} stroke={c.grid} />
          <XAxis type="number" allowDecimals={false} tickLine={false} axisLine={false} tick={{ fontSize: 11, fill: c.tick }} />
          <YAxis type="category" dataKey="label" tickLine={false} axisLine={false} width={124} tick={{ fontSize: 11, fill: c.tick }} />
          <Tooltip cursor={{ fill: c.cursor }} {...tooltipStyle} />
          <Legend iconType="circle" iconSize={8} wrapperStyle={{ fontSize: 12, color: c.tick }} />
          {keys.map((k, i) => (
            // 2px surface stroke = visible gap between stacked segments
            <Bar key={k.key} dataKey={k.key} name={k.label} stackId="s" fill={c.series[i]} stroke={c.surface} strokeWidth={2}
              radius={i === keys.length - 1 ? [0, 4, 4, 0] : 0} maxBarSize={22} animationDuration={450} />
          ))}
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}

/**
 * Part-to-whole donut (at most 6 slices: the API folds the rest into "Other").
 * The legend list beside it carries every name, value and percentage as text.
 */
export function Donut({ data, centerLabel, centerValue, format }: {
  data: { name: string; value: number; share: number }[]
  centerLabel: string
  centerValue: string
  format: (v: number) => string
}) {
  const c = useChartTheme()
  const slices = data.filter((d) => d.value > 0)
  if (!slices.length) return <Empty>No data for this period.</Empty>
  return (
    <div className="flex flex-col items-center gap-4">
      <div className="relative h-44 w-44 shrink-0">
        <ResponsiveContainer width="100%" height="100%">
          <PieChart>
            <Pie data={slices} dataKey="value" nameKey="name" innerRadius="62%" outerRadius="100%" paddingAngle={slices.length > 1 ? 1.5 : 0}
              stroke={c.surface} strokeWidth={2} startAngle={90} endAngle={-270} isAnimationActive={false}>
              {slices.map((s, i) => <Cell key={s.name} fill={c.series[i % c.series.length]} />)}
            </Pie>
            <Tooltip formatter={(v: number, name: string) => [format(v), name]} {...tooltipStyle} />
          </PieChart>
        </ResponsiveContainer>
        <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
          <span className="text-[0.65rem] font-medium uppercase tracking-wide text-slate-500">{centerLabel}</span>
          <span className="text-base font-semibold text-slate-900">{centerValue}</span>
        </div>
      </div>
      {/* Two lines per item so long names and amounts never squeeze each other out. */}
      <ul className="grid w-full min-w-0 gap-x-4 gap-y-2 text-sm min-[480px]:grid-cols-2 lg:grid-cols-1 2xl:grid-cols-2">
        {slices.map((s, i) => (
          <li key={s.name} className="grid min-w-0 grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-2">
            <span className="h-2.5 w-2.5 rounded-full" style={{ background: c.series[i % c.series.length] }} aria-hidden />
            <span className="truncate text-slate-700" title={s.name}>{s.name}</span>
            <span className="text-xs font-medium tabular-nums text-slate-600">{s.share}%</span>
            <span className="col-start-2 col-end-4 truncate text-xs tabular-nums text-slate-900">{format(s.value)}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}
