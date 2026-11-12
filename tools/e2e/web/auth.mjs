// Sign-up screen, unmigrated-screen notice, sign-out, and "a signed-out browser cannot reopen a protected page".
import { createRequire } from "node:module"
const require = createRequire(import.meta.url)
const { chromium } = require("playwright")
import assert from "node:assert/strict"

const API = process.env.LT_API
const WEB = process.env.LT_WEB
const email = `auth-${Date.now()}@example.test`
const password = "Correct-Horse-9"
const browser = await chromium.launch()
const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage()
let supabaseCalls = 0
page.on("request", (r) => { if (/supabase\.co/.test(r.url())) supabaseCalls++ })
const step = (s) => console.log("•", s)
process.on("uncaughtException", async (e) => { try { await page.screenshot({ path: "/tmp/lt-e2e-failure.png", fullPage: true }); console.error("screenshot: /tmp/lt-e2e-failure.png | url:", page.url(), "| text:", (await page.innerText("body")).slice(0, 300)) } catch {} ; console.error(e); process.exit(1) })

step("register through the real sign-up form")
await page.goto(`${WEB}/register`)
await page.waitForLoadState("networkidle")
await page.getByPlaceholder("Your full name").fill("Auth Owner")
await page.getByPlaceholder("Your shop or company name").fill("Auth Shop")
await page.getByText("Select type").click()
await page.getByRole("option").first().click()
await page.getByPlaceholder("+234 800 000 0000").fill("08031234567")
await page.getByPlaceholder("you@example.com").fill(email)
await page.getByPlaceholder("At least 6 characters").fill(password)
await page.getByPlaceholder("Type again to confirm").fill(password)
await page.locator("#terms").click()
await page.getByRole("button", { name: "Create free account" }).click()
await page.getByText("Check your email").waitFor({ timeout: 20000 })

step("sign in, land on a screen that has not moved yet: a clear notice, no Supabase")
await page.goto(`${WEB}/login`)
await page.waitForLoadState("networkidle")
await page.getByPlaceholder("you@example.com").fill(email)
await page.getByPlaceholder("Enter your password").fill(password)
await page.getByRole("button", { name: "Sign in" }).click()
await page.waitForURL("**/dashboard", { timeout: 20000 })
await page.getByTestId("not-migrated").waitFor({ timeout: 10000 })

step("the migrated screen works and shows who is signed in")
await page.goto(`${WEB}/inventory`)
await page.getByText("Auth Owner").first().waitFor({ timeout: 20000 })

step("sign out returns to the sign-in page and the session is really gone")
const so = page.locator("aside.md\\:flex").getByRole("button", { name: "Sign Out" }).last()
await so.click({ timeout: 10000 })
await page.waitForURL("**/login", { timeout: 15000 })
await page.goto(`${WEB}/inventory`)
await page.waitForURL("**/login", { timeout: 15000 })
const stored = await page.evaluate(() => ({ r: localStorage.getItem("lt_refresh_token"), b: localStorage.getItem("lt_business_id") }))
assert.deepEqual(stored, { r: null, b: null }, "tokens removed from the browser on sign-out")
assert.equal(supabaseCalls, 0, "no Supabase calls in API mode")
await browser.close()
console.log("OK: auth browser flow passed")
