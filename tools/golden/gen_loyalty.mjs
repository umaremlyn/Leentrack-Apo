// Generates golden vectors by running the ORIGINAL, UNMODIFIED lib/loyalty/math.ts from the production repo (read-only).
// Usage: TRACK_DIR=/path/to/Track node --experimental-strip-types tools/golden/gen_loyalty.mjs > backend/tests/Golden/loyalty_checkout.json
import { pathToFileURL } from "node:url"
import path from "node:path"

const track = process.env.TRACK_DIR
if (!track) { console.error("Set TRACK_DIR to a checkout of the production repo"); process.exit(2) }
const { checkoutDiscounts, loyaltyExample } = await import(pathToFileURL(path.join(track, "lib/loyalty/math.ts")).href)

const subtotals = [0, 0.01, 99.99, 1234.56, 5000, 33.33]
const groupPcts = [0, 7.5, 10, 33.33, 99, 150, -5]
const manuals = [0, 50, 99999]
const points = [{ customerPoints: 0, pointsWanted: 0 }, { customerPoints: 5, pointsWanted: 3 }, { customerPoints: 50, pointsWanted: 500 }]
const values = [100, 50.5]
const cases = []
for (const subtotal of subtotals) for (const groupPct of groupPcts) for (const manual of manuals)
  for (const p of points) for (const pointValue of values) for (const [groupOn, loyaltyOn] of [[true, true], [false, true], [true, false]]) {
    const input = { subtotal, manual, groupPct, groupOn, loyaltyOn, customerPoints: p.customerPoints, pointValue, pointsWanted: p.pointsWanted }
    cases.push({ input, output: checkoutDiscounts(input) })
  }
const examples = [[10000, 100, "₦"], [5000, 50, "$"], [0, 1, "₦"], [10000.5, 33.33, "₦"]].map(([a, b, c]) => ({ input: [a, b, c], output: loyaltyExample(a, b, c) }))
console.log(JSON.stringify({ source: "lib/loyalty/math.ts", generated_by: "tools/golden/gen_loyalty.mjs", count: cases.length, cases, examples }))
