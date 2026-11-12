// Drives the REAL Next.js app (API mode) in a real headless Chromium against the REAL PHP API.
// Run through tools/e2e/run-web-browser.sh, which starts everything and sets LT_API, LT_WEB.
import { createRequire } from "node:module"
const require = createRequire(import.meta.url)
const { chromium } = require("playwright")
import assert from "node:assert/strict"

const API = process.env.LT_API
const WEB = process.env.LT_WEB
const email = `browser-${Date.now()}@example.test`
const password = "Correct-Horse-9"

const reg = await fetch(`${API}/auth/register`, {
  method: "POST",
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify({ email, password, full_name: "Browser Owner", business_name: "Browser Shop", business_type: "retail", currency: "NGN" }),
})
assert.ok(reg.status === 202 || reg.status === 201, `register ${reg.status}`)

const browser = await chromium.launch()
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } })
const page = await ctx.newPage()
const problems = []
page.on("pageerror", (e) => problems.push("pageerror: " + e.message))
page.on("console", (m) => { if (m.type() === "error" && !/favicon|hydrat|Failed to load resource/i.test(m.text())) problems.push("console: " + m.text()) })
const failed = []
page.on("response", (r) => { if (r.url().startsWith(API) && r.status() >= 400) failed.push(`${r.status()} ${r.request().method()} ${r.url().replace(API, "")}`) })
const apiCalls = []
page.on("request", (r) => { if (r.url().startsWith(API)) apiCalls.push(`${r.method()} ${r.url().replace(API, "")}`) })
let supabaseCalls = 0
const supabaseUrls = new Set()
page.on("request", (r) => { if (/supabase\.co/.test(r.url())) { supabaseCalls++; supabaseUrls.add(r.method() + " " + r.url().replace(/^https:\/\/[^/]+/, "").split("?")[0]) } })

const step = (s) => console.log("•", s)
process.on("uncaughtException", async (e) => { try { await page.screenshot({ path: "/tmp/lt-e2e-failure.png", fullPage: true }); console.error("screenshot: /tmp/lt-e2e-failure.png | url:", page.url(), "| text:", (await page.innerText("body")).slice(0, 400)) } catch {} ; console.error(e); process.exit(1) })

step("wrong password shows the same wording the page already handles")
await page.goto(`${WEB}/login`)
await page.waitForLoadState("networkidle")
await page.getByPlaceholder("you@example.com").fill(email)
await page.getByPlaceholder("Enter your password").fill("not-the-password")
await page.getByRole("button", { name: "Sign in" }).click()
await page.getByText("Wrong email or password. Please try again.").waitFor({ timeout: 15000 })

step("sign in")
await page.getByPlaceholder("Enter your password").fill(password)
await page.getByRole("button", { name: "Sign in" }).click()
await page.waitForURL("**/dashboard", { timeout: 20000 })

step("inventory is empty, then add a product")
await page.goto(`${WEB}/inventory`)
await page.getByRole("button", { name: /Add Item \/ Service/ }).first().click()
await page.locator("#name").fill("Soap")
await page.locator("#selling_price").fill("100")
await page.locator("#cost_price").fill("60")
await page.locator("#stock_quantity").fill("5")
await page.getByRole("button", { name: "Save Product" }).click()
await page.locator("table").getByText("Soap").waitFor({ timeout: 15000 })
await page.locator("table").getByText("₦100.00").waitFor({ timeout: 5000 })
await page.locator("table").getByText("Low Stock").waitFor({ timeout: 5000 })

step("reload: the session is restored from the refresh token and the product is still there")
await page.reload()
await page.locator("table").getByText("Soap").waitFor({ timeout: 20000 })
assert.ok(!page.url().includes("/login"), "still signed in after a reload")

step("edit: change the stock from 5 to 12 (an audited stock count in API mode)")
await page.locator("table").getByTitle("Edit").click()
await page.locator("#stock_quantity").fill("12")
await page.getByRole("button", { name: "Save Product" }).click()
await page.locator("table").getByText("12", { exact: true }).waitFor({ timeout: 15000 })

step("delete (archive) with the confirmation dialog")
page.once("dialog", (d) => d.accept())
await page.locator("table").getByTitle("Delete").click()
await page.locator("table").getByText("Soap").waitFor({ state: "detached", timeout: 15000 })

step("sign out is not required for the check; verify what the browser talked to")
assert.equal(supabaseCalls, 0, "API mode must not call Supabase at all, but the page called: " + [...supabaseUrls].join(", "))
assert.ok(apiCalls.some((c) => c.startsWith("POST /auth/login")))
assert.ok(apiCalls.some((c) => c.startsWith("POST /stock/adjustments")), "a stock edit goes through the audited adjustment endpoint")
assert.ok(apiCalls.some((c) => c.startsWith("DELETE /products/")))
assert.deepEqual(failed, ["401 POST /auth/login"], "the only failing API call is the deliberate wrong-password sign-in")
assert.deepEqual(problems, [], "no page errors or console errors:\n" + problems.join("\n"))

await browser.close()
console.log("OK: browser flow passed against the real API (%d API calls, 0 Supabase calls)", apiCalls.length)
