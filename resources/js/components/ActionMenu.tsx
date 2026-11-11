import { useCallback, useEffect, useLayoutEffect, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { ChevronDown } from 'lucide-react'

export type MenuAction = { key: string; label: string; icon?: ReactNode; onSelect: () => void; danger?: boolean }

const GAP = 6 // space between button and menu
const EDGE = 8 // minimum distance from the screen / content edge

/**
 * "More actions" dropdown. The menu is rendered on <body> with fixed positioning and is
 * placed where it fits: under the button (or above it when there is no room below),
 * aligned to the button's right edge, or its left edge when that would push it into the
 * sidebar or off-screen. It never covers the sidebar and follows the button on scroll/resize.
 */
export default function ActionMenu({ label = 'More actions', actions }: { label?: string; actions: MenuAction[] }) {
  const [open, setOpen] = useState(false)
  const [pos, setPos] = useState<{ top: number; left: number } | null>(null)
  const button = useRef<HTMLButtonElement>(null)
  const menu = useRef<HTMLDivElement>(null)

  const place = useCallback(() => {
    const b = button.current?.getBoundingClientRect()
    const m = menu.current
    if (!b || !m) return
    const width = m.offsetWidth
    const height = m.offsetHeight
    // Content area starts after the sidebar when the sidebar is beside the page (laptop and up).
    const main = document.querySelector('main')?.getBoundingClientRect()
    const minLeft = Math.max(EDGE, main ? main.left : 0)
    const maxLeft = window.innerWidth - width - EDGE

    // Prefer right-aligned (menu grows leftwards); fall back to left-aligned; then clamp.
    let left = b.right - width
    if (left < minLeft) left = b.left
    left = Math.max(minLeft, Math.min(left, maxLeft))

    // Below the button if it fits, otherwise above; clamp inside the viewport.
    let top = b.bottom + GAP
    if (top + height > window.innerHeight - EDGE && b.top - GAP - height >= EDGE) top = b.top - GAP - height
    top = Math.max(EDGE, Math.min(top, window.innerHeight - height - EDGE))

    setPos({ top, left })
  }, [])

  // Measure and place before paint so the menu never flashes in the wrong spot.
  useLayoutEffect(() => {
    if (open) place()
    else setPos(null)
  }, [open, place])

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => {
      const t = e.target as Node
      if (!button.current?.contains(t) && !menu.current?.contains(t)) setOpen(false)
    }
    const onKey = (e: globalThis.KeyboardEvent) => {
      if (e.key === 'Escape') { setOpen(false); button.current?.focus() }
    }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    window.addEventListener('resize', place)
    window.addEventListener('scroll', place, true)
    return () => {
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
      window.removeEventListener('resize', place)
      window.removeEventListener('scroll', place, true)
    }
  }, [open, place])

  // Once the menu is placed (visible), move focus into it for keyboard users.
  const placed = pos !== null
  useEffect(() => {
    if (open && placed) menu.current?.querySelector<HTMLButtonElement>('[role=menuitem]')?.focus({ preventScroll: true })
  }, [open, placed])

  /** Up/Down arrows move between items; Home/End jump to the first/last. */
  const onMenuKey = (e: KeyboardEvent<HTMLDivElement>) => {
    const items = Array.from(menu.current?.querySelectorAll<HTMLButtonElement>('[role=menuitem]') ?? [])
    const i = items.indexOf(document.activeElement as HTMLButtonElement)
    const go = (n: number) => { e.preventDefault(); items[(n + items.length) % items.length]?.focus() }
    if (e.key === 'ArrowDown') go(i + 1)
    else if (e.key === 'ArrowUp') go(i - 1)
    else if (e.key === 'Home') go(0)
    else if (e.key === 'End') go(items.length - 1)
    else if (e.key === 'Tab') setOpen(false)
  }

  if (!actions.length) return null

  return (
    <>
      <button ref={button} type="button" className="btn-secondary" aria-haspopup="menu" aria-expanded={open} onClick={() => setOpen((o) => !o)}>
        {label} <ChevronDown className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`} aria-hidden />
      </button>
      {open && createPortal(
        <div
          ref={menu}
          role="menu"
          aria-label={label}
          onKeyDown={onMenuKey}
          style={{ top: pos?.top ?? -9999, left: pos?.left ?? -9999, visibility: pos ? 'visible' : 'hidden' }}
          className="fixed z-[60] w-56 max-w-[calc(100vw-1rem)] overflow-y-auto rounded-md border border-slate-200 bg-surface py-1 shadow-xl max-h-[calc(100vh-1rem)]"
        >
          {actions.map((a) => (
            <button
              key={a.key}
              type="button"
              role="menuitem"
              onClick={() => { setOpen(false); a.onSelect() }}
              className={`flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm hover:bg-slate-50 focus:bg-slate-50 focus:outline-none ${a.danger ? 'text-red-700' : 'text-slate-700'}`}
            >
              {a.icon}{a.label}
            </button>
          ))}
        </div>,
        document.body,
      )}
    </>
  )
}
