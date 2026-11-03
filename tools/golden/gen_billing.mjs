// Golden vectors for subscription prices: the amounts the ORIGINAL billing page charges (billing-client.tsx calcTotal + toSmallestUnit).
// The price table and cycle table are EXTRACTED FROM THE SOURCE TEXT (not retyped), evaluated, and run through the source's own formulas.
// Usage: TRACK_DIR=/path/to/Track node tools/golden/gen_billing.mjs > backend/tests/Golden/billing.json
import fs from "node:fs"
import path from "node:path"

const track = process.env.TRACK_DIR
if (!track) { console.error("Set TRACK_DIR"); process.exit(2) }
const src = fs.readFileSync(path.join(track, "app/billing/billing-client.tsx"), "utf8")
const grab = (re, what) => { const m = src.match(re); if (!m) { console.error("cannot find " + what); process.exit(3) } return m[1] }
const priceText = grab(/const MONTHLY_PRICE[^=]*=\s*(\{[\s\S]*?\n\})\n/, "MONTHLY_PRICE")
const cycleText = grab(/const CYCLES[^=]*=\s*(\{[\s\S]*?\n\})\n/, "CYCLES")
const MONTHLY_PRICE = new Function("return " + priceText)()
const CYCLES = new Function("return " + cycleText.replace(/badge:\s*"[^"]*"|badge:\s*null/g, "badge: null"))()
// the source's two formulas, copied verbatim from billing-client.tsx (calcTotal / toSmallestUnit)
const calcTotal = (planId, cycle, currency) => { const base = MONTHLY_PRICE[planId]?.[currency] ?? 0; const { months, discount } = CYCLES[cycle]; return base * months * (1 - discount) }
const toSmallestUnit = (amount) => Math.round(amount * 100)
const days = { monthly: 30, quarterly: 92, yearly: 366 }   // from the paystack-webhook CYCLE_CONFIG (checked below)
const hook = fs.readFileSync(path.join(track, "supabase/functions/paystack-webhook/index.ts"), "utf8")
for (const [k, d] of Object.entries(days)) { if (!new RegExp(`${k}:\\s*\\{\\s*days:\\s*${d},`).test(hook)) { console.error("cycle days differ for " + k); process.exit(4) } }

const cases = []
for (const plan of Object.keys(MONTHLY_PRICE)) for (const currency of Object.keys(MONTHLY_PRICE[plan])) for (const cycle of Object.keys(CYCLES)) {
  cases.push({ plan, currency, cycle, base: MONTHLY_PRICE[plan][currency], months: CYCLES[cycle].months, discount: CYCLES[cycle].discount, days: days[cycle], amount_minor: toSmallestUnit(calcTotal(plan, cycle, currency)) })
}
console.log(JSON.stringify({ source: "app/billing/billing-client.tsx, supabase/functions/paystack-webhook", cases }, null, 1))
