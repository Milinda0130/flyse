/**
 * On phones, `.table-base` tables are shown as stacked cards (see app.css). Each cell
 * needs its column heading as a data-label; this keeps those labels in sync for every
 * table on the page, including ones rendered later, so pages need no extra markup.
 */
function labelTables(root: ParentNode) {
  root.querySelectorAll<HTMLTableElement>('table.table-base').forEach((table) => {
    const headings = Array.from(table.tHead?.rows[0]?.cells ?? []).map((th) => th.textContent?.trim() ?? '')
    Array.from(table.tBodies).forEach((body) => {
      Array.from(body.rows).forEach((row) => {
        Array.from(row.cells).forEach((cell, i) => {
          const label = headings[i] ?? ''
          if (cell.getAttribute('data-label') !== label) cell.setAttribute('data-label', label)
        })
      })
    })
  })
}

export function startResponsiveTables() {
  let scheduled = false
  const run = () => {
    scheduled = false
    labelTables(document)
  }
  // Only structural changes are observed, so setting attributes cannot loop.
  new MutationObserver(() => {
    if (!scheduled) {
      scheduled = true
      requestAnimationFrame(run)
    }
  }).observe(document.body, { childList: true, subtree: true, characterData: true })
  run()
}
