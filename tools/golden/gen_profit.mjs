// Golden vectors for profit (lib/profit/compute.ts) produced by running the ORIGINAL, UNMODIFIED source.
// Period boundaries depend on the process time zone, so run with TZ=Africa/Lagos (the product's default business zone; no DST).
// Usage: TZ=Africa/Lagos TRACK_DIR=/path/to/Track node --experimental-strip-types tools/golden/gen_profit.mjs > backend/tests/Golden/profit.json
import { pathToFileURL } from "node:url"
import path from "node:path"

const track = process.env.TRACK_DIR
if (!track) { console.error("Set TRACK_DIR"); process.exit(2) }
const { computeProfit, periodRange, localDate, soldExTax, unitCostOf } = await import(pathToFileURL(path.join(track, "lib/profit/compute.ts")).href)

// small deterministic PRNG so the file is reproducible
let seed = 20261102
const rnd = () => { seed = (seed * 1664525 + 1013904223) % 4294967296; return seed / 4294967296 }
const pick = (a) => a[Math.floor(rnd() * a.length)]
const money = (max) => Math.round(rnd() * max * 100) / 100

const names = ["Rice", "Beans", "Oil", "Haircut", "Cap", "Soap", "Sugar"]
const cases = []
for (let c = 0; c < 160; c++) {
  const sales = [], items = []
  const nSales = 1 + Math.floor(rnd() * 6)
  for (let i = 0; i < nSales; i++) {
    const id = `s${c}-${i}`
    const nLines = pick([0, 1, 1, 2, 3, 4])
    let subtotal = 0
    const lines = []
    for (let l = 0; l < nLines; l++) {
      const qty = 1 + Math.floor(rnd() * 5)
      const price = money(900) + 0.5
      const total = Math.round(qty * price * 100) / 100
      subtotal += total
      const kind = pick(["normal", "normal", "snapshot", "nocost", "service", "zero"])
      const prodName = pick(names)
      const productCost = kind === "nocost" ? null : kind === "zero" ? 0 : money(price)
      lines.push({
        sale_id: id, product_id: `p-${prodName}`, quantity: qty, unit_price: price, total_price: total,
        unit_cost: kind === "snapshot" ? money(price) : (kind === "service" ? 0 : null),
        products: { name: prodName, cost_price: productCost, item_type: kind === "service" ? "service" : "product" },
      })
    }
    subtotal = Math.round(subtotal * 100) / 100
    const discount = nLines > 0 && rnd() < 0.5 ? Math.min(money(subtotal * 0.3), Math.max(0, subtotal - 0.01)) : 0
    const tax = Math.round((subtotal - discount) * pick([0, 0.075]) * 100) / 100
    sales.push({ id, receipt_number: `R${i}`, created_at: "2026-03-10T10:00:00.000Z", subtotal: nLines > 0 ? subtotal : money(500), discount_amount: discount, tax_amount: tax, total_amount: Math.round((subtotal - discount + tax) * 100) / 100, payment_status: "completed", customer: rnd() < 0.5 ? { name: "Ada" } : null })
    items.push(...lines)
  }
  cases.push({ input: { sales, items }, output: computeProfit(sales, items) })
}

const nows = ["2026-03-15T09:00:00.000Z", "2026-03-31T23:30:00.000Z", "2026-03-01T00:10:00.000Z", "2026-01-05T12:00:00.000Z", "2026-12-31T22:59:59.000Z", "2027-01-01T00:00:00.000Z", "2026-02-28T23:00:00.000Z", "2024-02-29T10:00:00.000Z", "2026-05-31T22:59:59.000Z"]
const periods = []
for (const now of nows) for (const key of ["today", "week", "month", "lastMonth"]) {
  const r = periodRange(key, new Date(now))
  periods.push({ now, key, from: r.from.toISOString(), to: r.to.toISOString(), fromDate: localDate(r.from), toDate: localDate(r.to) })
}
console.log(JSON.stringify({ source: "lib/profit/compute.ts", tz: process.env.TZ, cases, periods }))
