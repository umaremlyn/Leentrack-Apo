// Golden vectors for sale editing (lib/sales/edit-math.ts) and customer documents (lib/documents/model.ts + build.ts),
// produced by running the ORIGINAL, UNMODIFIED sources. They are copied to a scratch dir only to make the extension-less
// import in build.ts resolvable under Node; file contents are not changed except that one import specifier.
// Usage: TRACK_DIR=/path/to/Track node --experimental-strip-types tools/golden/gen_sales.mjs <outDir>
import fs from "node:fs"
import os from "node:os"
import path from "node:path"
import { pathToFileURL } from "node:url"

const track = process.env.TRACK_DIR
const outDir = process.argv[2]
if (!track || !outDir) { console.error("TRACK_DIR and <outDir> required"); process.exit(2) }
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), "golden-"))
const cp = (rel, patch = (s) => s) => { const dst = path.join(tmp, path.basename(rel)); fs.writeFileSync(dst, patch(fs.readFileSync(path.join(track, rel), "utf8"))); return dst }
const editPath = cp("lib/sales/edit-math.ts")
const modelPath = cp("lib/documents/model.ts")
const buildPath = cp("lib/documents/build.ts", (s) => s.replace('from "./model"', 'from "./model.ts"'))
const { computeEdit } = await import(pathToFileURL(editPath).href)
const M = await import(pathToFileURL(modelPath).href)
const B = await import(pathToFileURL(buildPath).href)

// ---------- edit maths ----------
const olds = [
  { subtotal: 1000, discount: 0, tax: 0, total: 1000 },
  { subtotal: 1000, discount: 100, tax: 67.5, total: 967.5 },     // 7.5 %
  { subtotal: 33.33, discount: 0, tax: 2.5, total: 35.83 },         // 7.5 % of 33.33 = 2.49975 -> 2.50
  { subtotal: 500, discount: 50, tax: 33.33, total: 483.33 },       // "unusual history": not a clean percentage
  { subtotal: 100, discount: 100, tax: 5, total: 5 },               // base <= 0
]
const lineSets = [
  [{ product_id: "p1", quantity: 1, unit_price: 1000 }],
  [{ product_id: "p1", quantity: 3, unit_price: 19.99 }, { product_id: "p2", quantity: 2, unit_price: 0.1 }],
  [{ product_id: "p1", quantity: 7, unit_price: 33.33 }],
  [{ product_id: "p1", quantity: 2, unit_price: 250.5 }, { product_id: "p2", quantity: 1, unit_price: 0.01 }],
  [{ product_id: "p1", quantity: 100000, unit_price: 9999.99 }],
]
const discounts = [0, 0.01, 100, 50.5, 5000, 1e9, -3]
const paids = [0, 483.33, 967.5, 5000]
const editCases = []
for (const old of olds) for (const lines of lineSets) for (const d of discounts) for (const paid of paids) {
  editCases.push({ input: { lines, discount: d, old, paid }, output: computeEdit(lines, d, old, paid) })
}

// ---------- documents ----------
const ctxA = { businessName: "Acme Stores", branding: { logo_data: null, phone: " 0803 542 4318 ", email: "", address: "1 Market Rd", payment_details: "GTB 0123456789", footer_note: "Thanks!" }, currencyCode: "NGN", currencySymbol: "₦", taxRate: 7.5 }
const ctxB = { businessName: "   ", branding: null }
const items = [{ name: "Rice 5kg", price: 6500, quantity: 2, total: 13000 }, { name: "Haircut", price: 1500.5, quantity: 1, total: 1500.5 }]
const sales = [
  { sale_number: "SALE-1792775102403-1800", date: "2026-11-02T10:00:00.000Z", customer: { name: "Mr. Mahmud", phone: "0803 542 4318" }, items, subtotal: 14500.5, discount: 500, tax: 0, total: 14000.5, payment_method: "cash", amount_paid: 15000, balance_due: 0, change: 999.5, is_credit: false, payment_status: "completed" },
  { sale_number: "SALE-1792775102403-1801", date: "2026-11-02T10:00:00.000Z", customer: { name: "Ada Obi" }, items, subtotal: 14500.5, discount: 0, tax: 1087.54, total: 15588.04, payment_method: "bank_transfer", amount_paid: 5000, balance_due: 10588.04, change: 0, is_credit: true, payment_status: "pending" },
  { sale_number: "INV-77", date: "2026-11-02T10:00:00.000Z", customer: null, items, subtotal: 14500.5, discount: 0, tax: 0, total: 14500.5, payment_method: "card", payment_status: "completed" },
  { sale_number: "RCT-5-6", date: "2026-11-02T10:00:00.000Z", customer: { name: "Ada Obi" }, items: [], subtotal: 100, discount: 0, tax: 0, total: 100, payment_method: "credit", amount_paid: 100, balance_due: 0, is_credit: true, payment_status: "completed", revised_at: "2026-11-03T08:00:00.000Z", notes: "Edited" },
  { sale_number: "SALE-9", date: "2026-11-02T10:00:00.000Z", customer: { name: "Zed" }, items, subtotal: 100, discount: 0, tax: 0, total: 100, payment_method: "mobile_money", amount_paid: 0, balance_due: 100, is_credit: true, payment_status: "pending" },
]
const docs = []
for (const ctx of [ctxA, ctxB]) for (const s of sales) docs.push({ input: { sale: s, ctx }, output: B.documentFromSale(s, ctx) })
const payments = [{ reference: "SALE-1792775102403-1801", date: "2026-11-05T12:00:00.000Z", customer: { name: "Ada Obi", phone: "0803" }, amount: 5000, method: "cash", previousBalance: 10588.04, newBalance: 5588.04 }, { reference: "SALE-1", date: "2026-11-05T12:00:00.000Z", customer: { name: "Zed" }, amount: 100, method: "bank_transfer", previousBalance: 100, newBalance: 0 }]
const payDocs = payments.map((p) => ({ input: { payment: p, ctx: ctxA }, output: B.documentFromPayment(p, ctxA) }))

const helpers = {
  documentNumber: [["receipt", "SALE-1792775102403-1800"], ["invoice", "SALE-1-2"], ["receipt", "inv-55"], ["invoice", ""], ["receipt", null], ["receipt", "RCP-9"], ["invoice", "weird"]].map(([k, r]) => ({ input: [k, r], output: M.documentNumber(k, r) })),
  statusFor: [{ kind: "receipt", total: 10, balanceDue: 0, amountPaid: 10 }, { kind: "invoice", total: 10, balanceDue: 4, amountPaid: 6 }, { kind: "invoice", total: 10, balanceDue: 10, amountPaid: 0 }, { kind: "payment_receipt", total: 10, balanceDue: 3, amountPaid: 7 }, { kind: "payment_receipt", total: 10, balanceDue: 0, amountPaid: 10 }, { kind: "receipt", total: 10, balanceDue: 0.004, amountPaid: 10 }].map((d) => ({ input: d, output: M.statusFor(d) })),
  paymentMethodLabel: [["cash", false], ["CARD", false], ["transfer", false], ["bank_transfer", false], ["credit sale", false], ["other", true], ["", true], ["", false], [null, false], ["mobile_money", false], ["pos terminal", false]].map(([m, c]) => ({ input: [m, c], output: M.paymentMethodLabel(m, c) })),
  whatsappNumber: ["0803 542 4318", "+234 803 542 4318", "00234803542 4318", "8035424318", "12345", "", null, "+1 (555) 010-9999", "0803-542-4318", "0", "+2348035424318999999"].map((p) => ({ input: p, output: M.whatsappNumber(p) })),
  money: [[1234.5, "₦", "NGN"], [-5, "$", "USD"], [0, "", "GHS"], [1e9, "₦", "NGN"], [0.005, "₦", "NGN"], [2.675, "₦", "NGN"]].map(([a, s, c]) => ({ input: [a, s, c], output: M.money(a, s, c) })),
  fileNameFor: [{ kind: "invoice", number: "INV-179277-1800", customer: { name: "Mr. Mahmud" } }, { kind: "receipt", number: "RCT-1", customer: null }, { kind: "payment_receipt", number: "RCT-1-P22", customer: { name: "Ṣọlá Àdé / O'Neil <script>" } }, { kind: "receipt", number: "R".repeat(60), customer: { name: "A".repeat(50) } }].map((d) => ({ input: d, output: M.fileNameFor(d) })),
  shareMessage: [docs[0].output, docs[1].output, payDocs[0].output, payDocs[1].output, docs[2].output].map((d) => ({ input: d, output: M.shareMessage(d) })),
  titleFor: ["invoice", "receipt", "payment_receipt"].map((k) => ({ input: k, output: M.titleFor(k) })),
}
fs.writeFileSync(path.join(outDir, "edit_math.json"), JSON.stringify({ source: "lib/sales/edit-math.ts", count: editCases.length, cases: editCases }))
fs.writeFileSync(path.join(outDir, "documents.json"), JSON.stringify({ source: "lib/documents/model.ts,build.ts", docs, payDocs, helpers }))
console.log(`edit cases: ${editCases.length}; docs: ${docs.length + payDocs.length}`)
