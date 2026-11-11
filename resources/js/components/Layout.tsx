import { useState, type FormEvent } from 'react'
import {
  Building2, ChartColumn, FileText, LayoutDashboard, LogOut, Menu, Plane, Receipt, Search, Settings, Ticket, TriangleAlert, Users, Wallet, X,
  type LucideIcon,
} from 'lucide-react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { useDepartureAlerts, useMeta } from '../hooks/queries'
import { ThemeToggle } from '../lib/theme'
import LiveClock from './LiveClock'
import backdrop from '../../asset/airplane.webp'
import type { Abilities } from '../lib/types'

type NavItem = { to: string; label: string; icon: LucideIcon; ability?: keyof Abilities }

const nav: NavItem[] = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/bookings', label: 'Bookings', icon: Ticket },
  { to: '/quotations', label: 'Flight Quotations', icon: FileText },
  { to: '/customers', label: 'Customers', icon: Users },
  { to: '/issues', label: 'Issues', icon: TriangleAlert },
  { to: '/payments', label: 'Payments', icon: Wallet },
  { to: '/reports', label: 'Reports', icon: ChartColumn, ability: 'view-reports' },
  { to: '/expenses', label: 'Expenses', icon: Receipt, ability: 'manage-expenses' },
  { to: '/suppliers', label: 'Suppliers', icon: Building2 },
  { to: '/airlines', label: 'Airlines & Logos', icon: Plane },
  { to: '/settings', label: 'Settings', icon: Settings, ability: 'manage-settings' },
]

export default function Layout() {
  const { user, logout, can } = useAuth()
  const { data: meta } = useMeta()
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [menuOpen, setMenuOpen] = useState(false)

  const { data: alerts } = useDepartureAlerts()

  const submitSearch = (e: FormEvent) => {
    e.preventDefault()
    if (search.trim()) navigate(`/bookings?q=${encodeURIComponent(search.trim())}`)
  }

  const links = nav.filter((n) => !n.ability || can(n.ability))

  return (
    <div className="min-h-screen lg:flex">
      <div className="app-backdrop no-print" style={{ backgroundImage: `url(${backdrop})` }} aria-hidden />

      {/* Sidebar: static on laptops and up, slide-out drawer on phones and tablets in portrait. */}
      <aside
        className={`no-print fixed inset-y-0 left-0 z-40 flex w-64 max-w-[85vw] flex-col border-r border-gold/20 bg-ink text-neutral-300 shadow-2xl transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:w-60 lg:translate-x-0 lg:shadow-none 3xl:w-72 ${menuOpen ? 'translate-x-0' : '-translate-x-full'}`}
        aria-label="Main navigation"
      >
        <div className="flex h-14 shrink-0 items-center gap-2 border-b border-white/10 px-4">
          <img src="/images/flyse-logo.png" alt="" aria-hidden className="h-9 w-auto shrink-0" />
          <span className="flex-1 truncate text-sm font-semibold tracking-wide text-white">{meta?.agency_name ?? 'FLYSE TRAVELS AB'}</span>
          <button type="button" className="rounded p-1 text-neutral-400 hover:bg-white/10 hover:text-white lg:hidden" onClick={() => setMenuOpen(false)} aria-label="Close menu">
            <X className="h-5 w-5" aria-hidden />
          </button>
        </div>
        <nav className="flex-1 space-y-0.5 overflow-y-auto p-2">
          {links.map((n) => (
            <NavLink
              key={n.to}
              to={n.to}
              end={n.to === '/'}
              onClick={() => setMenuOpen(false)}
              className={({ isActive }) => `flex items-center gap-3 rounded-md px-3 py-2.5 text-sm transition ${isActive ? 'bg-gold/15 font-medium text-gold-light ring-1 ring-inset ring-gold/30' : 'hover:bg-white/5 hover:text-white'}`}
            >
              <n.icon className="h-5 w-5 shrink-0" strokeWidth={1.75} aria-hidden /> {n.label}
            </NavLink>
          ))}
        </nav>
        {/* Signed-in user and sign out, pinned to the bottom of the sidebar. */}
        <div className="shrink-0 space-y-2 border-t border-white/10 p-3">
          <div className="flex justify-center sm:hidden"><ThemeToggle /></div>
          <div className="flex items-center gap-2 rounded-lg bg-white/5 p-2 ring-1 ring-inset ring-white/10">
            <NavLink to="/profile" onClick={() => setMenuOpen(false)} className="flex min-w-0 flex-1 items-center gap-2.5 rounded-md p-1 hover:bg-white/5" title="My account">
              <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gold text-sm font-semibold text-ink">{initials(user?.name)}</span>
              <span className="min-w-0 text-sm leading-tight">
                <span className="block truncate font-medium text-white">{user?.name}</span>
                <span className="block truncate text-xs text-neutral-400">{user?.role_label}</span>
              </span>
            </NavLink>
            <button
              type="button"
              onClick={() => logout().then(() => navigate('/login'))}
              className="shrink-0 rounded-md p-2 text-neutral-400 transition hover:bg-red-500/15 hover:text-red-300"
              title="Sign out"
              aria-label="Sign out"
            >
              <LogOut className="h-4 w-4" aria-hidden />
            </button>
          </div>
        </div>
      </aside>
      {menuOpen && <div className="fixed inset-0 z-30 bg-black/60 backdrop-blur-sm lg:hidden" onClick={() => setMenuOpen(false)} aria-hidden />}

      <div className="min-w-0 flex-1">
        <header className="no-print sticky top-0 z-20 flex h-14 items-center gap-2 border-b border-slate-200 bg-surface/90 px-3 backdrop-blur sm:gap-3 sm:px-4">
          <button type="button" className="shrink-0 rounded p-2 text-slate-600 hover:bg-slate-100 lg:hidden" onClick={() => setMenuOpen(true)} aria-label="Open menu" aria-expanded={menuOpen}>
            <Menu className="h-5 w-5" aria-hidden />
          </button>
          <form onSubmit={submitSearch} className="relative min-w-0 flex-1" role="search">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden />
            <input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search code, PNR, ticket, passenger, phone"
              className="input max-w-xl pl-9"
              aria-label="Global booking search"
              enterKeyHint="search"
            />
          </form>
          {!!alerts && (
            <button
              type="button"
              onClick={() => navigate('/')}
              className="flex shrink-0 items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800 ring-1 ring-amber-300"
              title="Bookings with departures in the next 48 hours"
            >
              <Plane className="h-3.5 w-3.5" aria-hidden />
              {alerts}<span className="hidden md:inline"> departing within 48h</span>
            </button>
          )}
          <LiveClock />
          <ThemeToggle className="hidden shrink-0 sm:inline-flex" />
        </header>
        <main className="mx-auto max-w-[96rem] px-3 py-4 sm:p-6 3xl:max-w-none 3xl:px-10">
          <Outlet />
        </main>
      </div>
    </div>
  )
}

function initials(name?: string) {
  return (name ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join('')
}
