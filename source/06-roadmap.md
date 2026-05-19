# MyInvoice.cz — Roadmap a plán implementace

> Stav 2026-05-01: **M0–M6 + M5b dokončeno.**
> Detailní popis fází M0–M6 níže je zachován jako historická reference / dokumentace funkcionality.

## Souhrn milníků

| Milník | Rozsah | Stav | Akceptace |
|---|---|---|---|
| **M0** | Bootstrap repa, infra, build | ✅ | `php api/bin/migrate.php` projde, SPA loaduje `/login` |
| **M1** | Auth (login + brute-force + reset + IP allowlist + Turnstile) | ✅ | Login s 2-bucket brute-force ochranou, password reset přes email |
| **M2** | Klienti + zakázky + ARES/VIES | ✅ | Klient s ARES, zakázka 1:N, fakturační emaily per zakázka |
| **M3** | Faktura draft + editor + sumace + dashboard | ✅ | Live VAT recompute, group by month, KPI tiles |
| **M4** | Vystavení + PDF + QR + email | ✅ | mPDF + Twig + QR (CZK SPAYD / EUR SEPA EPC) + Symfony Mailer + DKIM |
| **M5** | Klonování + výkaz víceprací + proforma + storno/dobropis | ✅ | Per-row clone + bulk reissue, work_report → 2. strana PDF, credit_note červený header |
| **M5b** | Bank import (GPC/ABO) + auto-matching | ✅ | Upload GPC, auto-match VS+amount → faktura `paid`, manuální párování |
| **M6** | Polish: dashboard charty, activity log, users, settings, číselníky, ZIP | ✅ | Top klienti pie 2026/2025, audit log, CRUD pro user/měny/DPH/země, ZIP export |

### Co zbývá nad rámec původního plánu
- EN UI překlady (vue-i18n nainstalovaný, propojit na auth.user.locale)
- Cron skripty (`bin/cron-cleanup.php`, `cron-backup.php`, `cron-bank-scan.php`)
- GPC scanner pro auto-import z konfigurovaného adresáře (zatím jen ruční upload)
- PHPUnit pokrytí pro M5/M6 akce
- Email šablony v DB + admin editor (zatím hardcoded Twig v `api/templates/email/`)

### Konsolidace migrací (2026-05-01)
Migrace 0001–0006 byly sloučeny do jednoho `0001_init.sql` pro snazší fresh install
(včetně seed dat). Min. MariaDB 10.6 (`VARBINARY(16)` pro IP, dříve INET6 vyžadoval 10.10).

### CLI nástroje (mimo M0)
- `api/bin/setup.php` — interaktivní úvodní zřízení (cfg + DB + ARES → admin)
- `api/bin/reset.php` — wipe všech user-data (CLI only, vyžaduje "ANO")
- `api/bin/migrate.php` — pending migrace

---

## M0 — Bootstrap (1 týden)

### Backend
- [ ] `composer init`, instalace závislostí (Slim 4, PHP-DI, Twig, Monolog, mPDF, Symfony Mailer)
- [ ] `cfg.php` template + loader v `Config/Config.php`
- [ ] DI kontejner setup (PHP-DI), bind interfaces
- [ ] Slim app skeleton, ErrorHandlerMiddleware, JSON response factory
- [ ] PDO connection s charset utf8mb4, ATTR_EMULATE_PREPARES=false
- [ ] `bin/migrate.php` (jednoduchý — sleduje `migrations` tabulku, načítá `db/migrations/NNNN_*.sql`)
- [ ] Migration `0001_init.sql` se všemi tabulkami z `02-database.md`
- [ ] Migration `0002_seed_codebooks.sql` (vat_rates, currencies, countries)
- [ ] `GET /api/health` endpoint

### Frontend
- [ ] `pnpm create vue@latest` + TypeScript + router + pinia
- [ ] Tailwind 4 + custom theme (paleta z 05-design.md)
- [ ] Inter + Geist Mono fonty do `/styles/fonts/`
- [ ] Layout shell (TopBar, Sidebar, Outlet)
- [ ] Vue Router se 2 page placeholdery: Login, Dashboard
- [ ] Axios instance s base URL z `import.meta.env.VITE_API_BASE`
- [ ] i18n CZ/EN setup (vue-i18n + lazy load)

### Infra
- [ ] `web.config` (IIS) a `.htaccess` (Apache) s URL rewrite
- [ ] `.gitignore` (cfg.local.php, storage, vendor, node_modules, dist)
- [ ] `README.md` s lokálním setupem
- [ ] `CLAUDE.md` s konvencemi pro AI asistenci
- [ ] Git repo, první commit

**Akceptace:** `php bin/migrate.php` proběhne, otevření `https://dev.myinvoice.cz/` zobrazí login screen (i prázdný), `GET /api/health` vrátí `{ "status": "ok", "db": true }`.

---

## M1 — Autentizace + setup + IP allowlist (1.5 týden)

### Backend
- [ ] **First-run setup**: `FirstRunLockMiddleware` (423 pokud `users` prázdná, kromě whitelistu)
- [ ] `GET /api/auth/setup-status`, `POST /api/auth/setup` (idempotentní, jen při prázdné `users`)
- [ ] **IP allowlist**: `IpAllowlistMiddleware` s podporou IPv4/IPv6 + CIDR (`/24`, `/56`, `/64` …)
  - Helper `IpMatcher::matches(string $ip, array $rules): bool`
  - Načítání pravidel z `cfg.php` + override z `settings` tabulky
  - Logování zablokovaných requestů (action `security.ip_blocked`)
- [ ] `users` tabulka už z M0 — `bin/seed.php` vytvoří admin usera (jen pro dev; v prod přes setup wizard)
- [ ] `PasswordHasher` service (bcrypt cost 12 + pepper z cfg)
- [ ] `BruteForceGuard` service:
  - Redis adapter (INCR + EXPIRE)
  - MariaDB MEMORY adapter (INSERT + COUNT v okně)
  - Factory podle `cfg['redis']['enabled']`
- [ ] `SessionManager` (Redis nebo DB)
- [ ] `LoginAction`, `LogoutAction`, `MeAction`
- [ ] `ChangePasswordAction` (vyžaduje current, invaliduje ostatní sessions)
- [ ] `ForgotPasswordAction`, `ResetPasswordAction`
- [ ] `AuthMiddleware`, `CsrfMiddleware`, `RateLimitMiddleware`
- [ ] `ActivityLogger` service + logging `auth.login`, `auth.login_failed`, `auth.password_changed`
- [ ] Email šablony: `password_reset.cs.twig` + `.en.twig`
- [ ] `Mailer` service (Symfony Mailer + Twig render, DSN z `cfg.smtp.*`)

### Frontend
- [ ] **Setup wizard** (`/setup`):
  - Krok 1: admin (jméno, email, heslo + síla)
  - Krok 2: dodavatel s ARES lookupem (volitelný, lze přeskočit)
  - Krok 3: hotovo, redirect na login
  - Wizard se aktivuje pokud `GET /auth/setup-status` vrátí `needs_setup=true`
  - Router guard: pokud `needs_setup`, vše přesměrovat na `/setup`
- [ ] `Login.vue` se submit handlerem
- [ ] `useAuthStore` (Pinia): user, csrfToken, login(), logout(), refresh()
- [ ] Router guard: redirect na `/login` pro chráněné routes
- [ ] Captcha integrace: Cloudflare Turnstile (UI widget + backend `TurnstileVerifier` service, klíče v `cfg.captcha.*`)
- [ ] `ForgotPassword.vue`, `ResetPassword.vue`
- [ ] `ChangePassword.vue` (modal v user menu)

**Akceptace:**
1. Čerstvá instalace → otevření URL přesměruje na `/setup`, lze vytvořit admina
2. Po setupu už `/setup` vrací 409, login funguje
3. Lze se přihlásit s validními creds → cookie + CSRF token uložen
4. 10 špatných pokusů během 15 min → lockout
5. Forgot → email s linkem → reset funguje
6. Změna hesla invaliduje ostatní sessions
7. Activity log obsahuje login/logout/failed záznamy + `setup.completed`
8. Když je `cfg.php` `ip_allowlist.enabled=true` a request přijde z neuvedené IP → 403 + log
9. CIDR matching funguje pro IPv4 (`192.168.1.0/24`) i IPv6 (`2001:db8::/56`)

---

## M2 — Klienti, zakázky, ARES/VIES (1 týden)

### Backend
- [ ] `ClientRepository`, `ClientCreateAction`, `ClientUpdateAction`, `ClientListAction`, `ClientGetAction`
- [ ] `ProjectRepository` + akce
- [ ] `AresClient` service (Guzzle, REST JSON, retry × 1, cache → `ares_cache`)
- [ ] `ViesClient` service (PHP SoapClient, cache → `vies_cache`)
- [ ] `LookupAresAction`, `LookupViesAction`
- [ ] Validace IČO (8 číslic, modulo 11 checksum)
- [ ] Validace `billing_emails` (max 3, validní email)

### Frontend
- [ ] `ClientList.vue` (tabulka, search, archived filter)
- [ ] `ClientForm.vue` (vytvoření/edit):
  - Tlačítko „Načíst z ARES" vedle pole IČO
  - Tlačítko „Ověřit DIČ ve VIES" vedle pole DIČ
  - Sekce „Fakturační emaily" s 0-3 řádky
  - Toggle Reverse charge
- [ ] `ProjectList.vue` per klient
- [ ] `ProjectForm.vue` se všemi volitelnými poli a default hodnotami
- [ ] `useClientsStore`, `useProjectsStore`

**Akceptace:**
1. Zadám IČO a kliknu „Načíst z ARES" → předvyplní firmu, adresu, DIČ
2. Vytvořím klienta a 2 zakázky
3. Editace klienta funguje, archivace skryje z listu
4. ARES odpověď je cachovaná 24h (druhý lookup je instant)

---

## Cross-cutting requirements (zapojit do dotčených milníků)

### IP allowlist HTML stránka — M1
Pokud middleware blokuje request z prohlížeče (Accept: text/html, ne /api/*), místo JSON 403 vrať
`/styles/blocked.html` se značkou MyInvoice, hláškou a aktuální IP. (✅ implementováno v M1)

### Faktury seřazené po měsících podle DUZP — M3 (list) + M4 (PDF)
- Backend `GET /api/invoices` přidá `group_by=month_dut` parametr (default).
- Řazení a grupování: `COALESCE(tax_date, issue_date)` → "YYYY-MM" bucket.
- Frontend `InvoiceList.vue` zobrazí sticky měsíční headery se sumami per měna.

### Dashboard — M3 (rozšíření) + M6 (grafy)
Po přihlášení (`/`) zobrazit:
1. **Sekce „Po splatnosti"** — tabulka nezaplacených faktur s `due_date < today`, řazená dle dní po splatnosti.
2. **Sekce „Nezaplacené (před splatností)"** — issued/sent ale ne paid, due_date >= today.
3. **KPI tiles** — letošní obrat (per měna), letošní obrat YoY (vs. minulý rok), letošní vystaveno (počet), průměrná doba úhrady.
4. **Graf „Obrat po měsících"** — sloupcový graf 12 měsíců (současný rok), porovnání s minulým rokem (line overlay).
5. **Graf „Top klienti YTD"** — horizontal bar chart, top 10 klientů podle obratu YTD.
6. **Tabulka „Obrat per klient"** — letos vs. minulý rok, % změna.

Knihovna: **Chart.js v4** (lehká, dobře integruje s Vue přes `vue-chartjs`).

Backend: `GET /api/dashboard/summary` vrátí všechna agregovaná data v jednom requestu.

---

## M3 — Faktura: draft + editor + sumace (2 týdny)

### Backend
- [ ] `InvoiceRepository`, `InvoiceItemRepository`
- [ ] `InvoiceCreateAction` (draft), `InvoiceUpdateAction`, `InvoiceListAction`, `InvoiceGetAction`, `InvoiceDeleteAction`
- [ ] `InvoiceCalculator` service:
  - Per item: `total_without_vat = round(quantity × unit_price, 2)`, `total_vat = round(total_without_vat × rate / 100, 2)`
  - VAT breakdown: group by rate
  - Rounding: poslední haléř na celkový součet (CZK haléřové vyrovnání = 0 v CZK od 2008, ale necháme placeholder)
- [ ] Default values resolver: `bank_account_id` z dodavatele dle currency, `due_date` z project nebo supplier default
- [ ] Validace: aspoň 1 položka, kladné quantity, validní vat_rate aktivní k `tax_date`

### Frontend
- [ ] `InvoiceList.vue`:
  - Tabulka: varsymbol, klient, datum, splatnost, celkem, status badge
  - Filter bar (status pillsy, datumy, klient autocomplete)
  - Pagination
  - „Nová faktura" CTA
- [ ] `InvoiceEditor.vue` (klíčová obrazovka — viz wireframe v 05-design.md):
  - Sekce klient + zakázka (autocomplete + select)
  - Sekce datumy s auto-výpočtem due_date
  - **Položky** — dynamická tabulka s přidat/smazat řádek, drag-reorder
  - Per-item: popis (textarea), množství, jednotka, cena, DPH (select), vypočtený součet
  - Sticky footer se sumací (bez DPH, DPH breakdown, celkem)
  - Bank account selector (filtrovaný podle currency)
  - Toggle Reverse charge (pre-set z klienta, lze přepsat)
- [ ] `useInvoicesStore`
- [ ] Real-time recompute při změně položky (frontend), backend re-validuje při save

**Akceptace:**
1. Vytvořím novou fakturu z listu → otevře se editor s předvyplněnými datumy a default hodnotami z klienta/zakázky
2. Přidám 3 položky s různými sazbami DPH → součty se počítají správně
3. VAT breakdown ukazuje rozpis per sazba
4. Save vrátí draft s `status='draft'` a `varsymbol=null`
5. Edit funguje, delete funguje (jen draft)

---

## M4 — Vystavení + PDF + QR + email (1.5 týden)

### Backend
- [ ] `VarsymbolGenerator` service (transakce nad `invoice_counters`)
- [ ] `IssueInvoiceAction`:
  - Validace: aspoň 1 položka, valid bank account, atd.
  - Generuj `varsymbol`
  - Zapiš snapshots (client_snapshot, supplier_snapshot, bank_snapshot jako JSON)
  - `status: 'draft' → 'issued'`
- [ ] `MarkPaidAction`, `CancelInvoiceAction`
- [ ] `QrPaymentGenerator` (port z `Payment::qrCode()`):
  - CZK → SPAYD přes `rikudou/czqrpayment`
  - EUR/jiné → SEPA EPC přes `girgias/sepa-qr-data`
  - Render přes `chillerlan/php-qrcode` jako data URI
- [ ] `InvoicePdfRenderer`:
  - Twig render `invoice.twig` (port logiky z `Faktura::renderIt()`)
  - mPDF konfigurace (A4, DejaVu Sans, margins)
  - Cache do `storage/invoices/YYYY-MM/Faktura-YY-MM-NNN.pdf`
  - Reverse charge note pokud aplikovatelné
- [ ] `PdfAction` endpoint (s `?regenerate`, `?download`)
- [ ] `PreviewAction` (HTML render se stejnou Twig šablonou + invoice.css)
- [ ] `SendEmailAction` (`POST /invoices/{id}/send`):
  - Render email šablony (Twig)
  - Symfony Mailer s SMTP transport (DSN podle `cfg.smtp.*`)
  - Příloha PDF
  - Update `sent_at`, `status: 'issued' → 'sent'`
  - Activity log: `email.sent`
- [ ] `SendTestEmailAction` (`POST /invoices/{id}/send-test`):
  - Recipients = vždy `cfg.smtp.from_email` (ignoruje body)
  - Subject prefix `[TEST] `
  - Funguje i pro draft
  - **Neovlivní** `invoice.sent_at` ani `status`
  - Activity log: `email.sent_test`

### Frontend
- [ ] Tlačítka „Vystavit" / „Stáhnout PDF" / „Náhled" / **„Test odeslání"** / „Poslat emailem" / „Označit jako zaplaceno" / „Storno"
- [ ] `InvoicePreview.vue` — iframe s `/api/invoices/{id}/preview`
- [ ] Send email modal:
  - Pre-vyplnit recipients z klienta
  - Editor předmětu + těla (s placeholdery {{varsymbol}}, {{total}})
  - Send button → toast „Odesláno na X emailů"
- [ ] Status badges v listu se aktualizují podle nového stavu

### Email šablony
- `templates/email/invoice_send.cs.twig`:
  ```
  Dobrý den,
  
  v příloze zasílám fakturu č. {{ invoice.varsymbol }} za {{ project.name | default('poskytnuté služby') }}
  na částku {{ total_with_vat | money(currency) }}.
  
  Splatnost: {{ due_date | date('d.m.Y') }}
  Var. symbol: {{ invoice.varsymbol }}
  
  Děkuji za platbu.
  
  S pozdravem,
  {{ supplier.display_name }}
  ```
- `.en.twig` ekvivalent

**Akceptace:**
1. Vystavím draft → dostane `varsymbol` (např. `2026040001`), snapshots se zapíší, status=issued
2. Stáhnu PDF → A4, hlavička s logem, položky, sumace, QR kód vpravo dole
3. QR kód CZK funguje: naskenuju Air Bank → předvyplní účet, částku, VS
4. QR kód EUR funguje s SEPA bankovkou
5. Pošlu na email → klient dostane PDF v příloze, sent_at se nastaví
6. Reverse charge faktura má v PDF vizuální noticeku
7. Po vystavení nelze editovat položky (UI tlačítka jsou disabled, API vrátí 409)

---

## M5 — Klonování + výkaz víceprací + proforma + storno/dobropis + bulk reissue (2 týdny)

### Backend
- [ ] `InvoiceCloner` service:
  - Hluboká kopie všech polí kromě id, varsymbol, snapshots, status, sent_at, paid_at, parent_invoice_id
  - Status = draft, datumy = today
  - Auto-increment měsíce v popiscích (regex `/\b(\d{1,2})\/(\d{4})\b/`)
  - Klon i `work_report` pokud existuje
- [ ] `CloneInvoiceAction` (`POST /invoices/{id}/clone`)
- [ ] `BulkReissueAction` (`POST /invoices/bulk-reissue`) — opakovaně volá `InvoiceCloner` per ID, vrací mapping source→draft
- [ ] `WorkReportRepository` + akce (POST/PUT/DELETE pod fakturou)
- [ ] `WorkReportCalculator` (sumace hodin a částek)
- [ ] Auto-update položky faktury při změně výkazu (pokud `auto_create_invoice_item=true`)
- [ ] Render druhé strany PDF `work_report.twig` — tabulka popis/hodiny/sazba/celkem

#### Proforma + finální faktura
- [ ] `VarsymbolGenerator` rozšířit o per-typ counter (prefix `9` pro proforma, `7` pro credit_note)
- [ ] `IssueInvoiceAction` — pokud `invoice_type='proforma'` nepoužije `tax_date`, generuje varsymbol s prefixem
- [ ] `IssueFinalFromProformaAction` (`POST /invoices/{proforma_id}/issue-final`):
  - Validace: source musí být `proforma` se status `paid`
  - Kopie položek
  - `parent_invoice_id` = id proformy
  - `advance_paid_amount` z body nebo default = `proforma.total_with_vat`
  - `amount_to_pay` = `total_with_vat - advance_paid_amount`
- [ ] PDF render proforma: header „ZÁLOHOVÁ FAKTURA — není daňový doklad", bez DUZP řádku
- [ ] PDF render finální z proformy: pod sumací řádek „Odečet zálohy (faktura X ze dne Y): -částka" + „K úhradě: X Kč"
- [ ] PDF render: pokud `amount_to_pay = 0`, neukáže se QR kód

#### Storno + dobropis
- [ ] `CancelInvoiceAction` (`POST /invoices/{id}/cancel`) s body `{ "mode": "internal" | "credit_note", "reason": "..." }`:
  - `internal`: vytvoří záznam typu `cancellation` s parent, na původní `cancelled_at`, status=`cancelled`
  - `credit_note`: vytvoří **draft** typu `credit_note` se zápornými položkami, parent. Status původní zůstává `issued/sent/paid` dokud se dobropis nevystaví. Po vystavení dobropisu se původní označí jako `cancelled`.
- [ ] PDF render credit_note: header „Opravný daňový doklad č. X", reference „k faktuře Y"

### Frontend
- [ ] „Klonovat" tlačítko v editoru a listu (s confirm)
- [ ] **Bulk akce v listu**: checkboxy v každém řádku + sticky toolbar nahoře s počtem označených a tlačítkem „Vystavit znovu pro další měsíc"
- [ ] „Výkaz víceprací" sekce v editoru (sbalitelná, default zavřená pokud nemá data):
  - Title input
  - Project select
  - Items table (popis, hodiny, sazba, computed total)
  - Sum řádek
  - Toggle „Aktualizovat položku faktury"
- [ ] **Proforma typ v editoru**: type selector (`Faktura` / `Zálohová faktura`), přepnutí skryje/zobrazí DUZP, změní wording v UI
- [ ] **„Vystavit daňový doklad k záloze" tlačítko** v detailu zaplacené proformy → otevře editor finálky s předvyplněným odečtem zálohy
- [ ] **Storno modal**: radio „Pouze interní storno" / „Vystavit dobropis" + textarea reason
- [ ] Náhled dobropisu má vizuální odlišení (červený header „Opravný daňový doklad")
- [ ] V listu faktur status badge rozlišuje typy: `proforma`, `dobropis`, `storno` mají vlastní barvy

### Tests (priorita)
- [ ] Unit test `InvoiceCloner::incrementMonthInDescription()`:
  - `'Konzultace 3/2026'` → `'Konzultace 4/2026'`
  - `'Vícepráce 12/2025'` → `'Vícepráce 1/2026'`
  - `'Něco bez data'` → `'Něco bez data'`
  - `'Část 3/2026 a část 5/2026'` → `'Část 4/2026 a část 6/2026'` (víc matchů)
  - `'13/2026'` → `'13/2026'` (neplatný měsíc, neměnit)
- [ ] Unit test `VarsymbolGenerator` per typ — proforma má `9`, invoice je čisté, credit_note `7`
- [ ] Unit test `IssueFinalFromProformaAction` — `amount_to_pay` se počítá správně, proforma musí být `paid`
- [ ] Integration test bulk reissue — 5 faktur in, 5 draftů out, popisky správně inkrementované

**Akceptace:**
1. Klonování faktury z minulého měsíce → nová faktura s popisky posunutými o měsíc
2. Vytvořím proformu → varsymbol `9YYMMNNNN`, PDF bez DUZP
3. Označím proformu jako paid → tlačítko „Vystavit daňový doklad k záloze" otevře editor s předvyplněným odečtem zálohy a `K úhradě: 0 Kč`
4. Vytvořím výkaz víceprací s 5 řádky → sum se přepočítá
5. PDF má 2 strany: faktura + výkaz s tabulkou
6. Storno faktury (mode=internal) → vyřadí ze sumací, nevytvoří doklad pro klienta
7. Dobropis (mode=credit_note) → vytvoří draft typu `credit_note` se zápornými hodnotami, po vystavení původní = cancelled
8. Bulk reissue 10 faktur → 10 draftů, žádný se neodešle, všechny v stavu `draft`

### Tests (priorita)
- [ ] Unit test `InvoiceCloner::incrementMonthInDescription()`:
  - `'Konzultace 3/2026'` → `'Konzultace 4/2026'`
  - `'Vícepráce 12/2025'` → `'Vícepráce 1/2026'`
  - `'Něco bez data'` → `'Něco bez data'`
  - `'Část 3/2026 a část 5/2026'` → `'Část 4/2026 a část 6/2026'` (víc matchů)
  - `'13/2026'` → `'13/2026'` (neplatný měsíc, neměnit)

**Akceptace:**
1. Klonování faktury z minulého měsíce → nová faktura s popisky posunutými o měsíc
2. Vytvořím výkaz víceprací s 5 řádky → sum se přepočítá
3. Toggle „Aktualizovat položku" → na faktuře je řádek „Vícepráce za měsíc 4/2026" se sumou z výkazu
4. PDF má 2 strany: faktura + výkaz s tabulkou
5. Storno faktury vytvoří credit note s zápornými hodnotami

---

## M6 — Polish: dashboard, activity, exporty, i18n (2 týdny)

### Backend
- [ ] `DashboardAction` — agregované KPI (obrat YTD per měna, po splatnosti, atd.)
- [ ] `ActivityLogAction` (admin only)
- [ ] `UserManagementAction` (admin only)
- [ ] `EmailTemplateAction` — CRUD pro `email_templates` přes admin UI
- [ ] Turnstile polish: monitoring success rate, fail_open behavior validace, action per route
- [ ] Cron skripty:
  - `bin/cron-cleanup.php` (login_attempts, sessions, logs)
  - `bin/cron-backup.php` (mariadb-dump → gzip)
  - `bin/cron-archive-invoices.php` (měsíční ZIP export)

### Frontend
- [ ] `Dashboard.vue` se 4 KPI tiles + 3 tabulkami
- [ ] `ActivityLog.vue` (admin) — filtrovatelný feed
- [ ] `Users.vue` (admin) — CRUD users
- [ ] `EmailTemplates.vue` (admin) — Twig editor pro šablony
- [ ] EN verze UI plně funkční (přepínač v user menu)
- [ ] EN verze faktury (vat labels, „Invoice", „Due date", „Reverse charge — VAT to be accounted by the customer")
- [ ] Empty states + loading skeletons všude
- [ ] Toast notifications napojené na všechny mutace
- [ ] Keyboard shortcuts (Ctrl+S = save draft, Ctrl+N = nová faktura)

### Production checklist
- [ ] HTTPS s Let's Encrypt
- [ ] Rate limity nastavené dle prostředí
- [ ] Log rotace funguje
- [ ] Backup cron běží
- [ ] Monitoring: minimálně uptime check pro `/api/health`
- [ ] Bezpečnostní audit (composer audit, OWASP ZAP)
- [ ] Performance test: 100 faktur v listu se načte < 200ms
- [ ] Migration rollback strategie zdokumentovaná

**Akceptace:**
1. Dashboard ukazuje aktuální KPI
2. Admin vidí activity log a může spravovat usery
3. Klient s `language='en'` dostane fakturu v EN
4. Daily backup je v `storage/backup/`
5. Po-deploy smoke test (login, vytvoř fakturu, vystavit, PDF, email) projde

---

## M5b — Import bankovních výpisů (GPC) (1 týden)

Backend:
- [ ] Migrace `0003_bank_statements.sql` — tabulky `bank_statements` + `bank_transactions`
- [ ] `Service/Bank/GpcParser.php` — parser ABO/GPC formátu
- [ ] `Service/Bank/StatementImporter.php` — dedupe podle `file_hash`, parse, persist, auto-match
- [ ] `Service/Bank/StatementMatcher.php` — exact/partial match na `invoice.varsymbol` + amount
- [ ] `Service/Bank/StatementScanner.php` — scan `cfg.bank_import.scan_root` + podadresáře YYYY-MM
- [ ] `Action/Bank/UploadStatementAction` — `POST /api/bank-statements/upload` (multipart)
- [ ] `Action/Bank/ScanStatementsAction` — `POST /api/bank-statements/scan`
- [ ] `Action/Bank/ListStatementsAction`, `GetStatementAction`, `MatchTransactionAction` (manual link)
- [ ] `bin/cron-bank-scan.php` — denní auto-scan

Frontend:
- [ ] `pages/bank/StatementList.vue` — seznam s filtry (nespárované, podezřelé)
- [ ] `pages/bank/StatementDetail.vue` — tabulka transakcí, manual match autocomplete
- [ ] Settings — konfigurace scan rootu

Akceptace:
1. Upload GPC souboru → výpis se rozparsuje, transakce uložené, automaticky spárované podle VS
2. Příchozí platba s amount = amount_to_pay + VS sedí → faktura přepnuta na `paid` automaticky
3. Scan adresáře projde všechny `*.gpc` v podadresářích YYYY-MM/, dedupe podle hash
4. Cron `bin/cron-bank-scan.php` běží denně, importuje nové výpisy

---

## Mimo scope (M7+)

- **Periodické faktury** — naplánované generování měsíčních faktur
- **ISDOC export** pro účetní
- **Pohoda XML import/export**
- **Multi-měna v jednom invoice** (dnes: jedna měna per faktura)
- **Klientský portál** — klient vidí své faktury, stahuje PDF, online platba
- **Online platba kartou** (GoPay/Stripe integrace)
- **Mobile app** (React Native / PWA)
- **Daňová evidence / podklady pro DPH přiznání**
- **Tarify a smlouvy** (recurring billing s automatickým generováním faktur)
- **Multi-tenant SaaS** verze (refactor schématu)
- **Dark mode**

---

## Rizika

| Riziko | Pravděpodobnost | Dopad | Mitigace |
|---|---|---|---|
| ARES API změny | Střední | Střední | Cache 24h, fallback manuální vyplnění |
| VIES downtime | Vysoká | Nízká | Cache + warning UI, neblokovat uložení |
| mPDF rendering edge cases | Střední | Střední | Testovat s reálnými fakturami z `C:\doc\Faktura\…`, pixel-by-pixel snapshot test |
| MariaDB MEMORY engine omezení | Nízká | Nízká | Malý objem session/brute-force dat; přechod na Redis kdykoli |
| PHP 8.5 stability (nový release) | Střední | Vysoké | Hotovat po RC release, mít fallback PHP 8.4 |
| QR generátor knihovny dependency | Nízká | Nízká | Pinned verze, vlastní fork pokud výpadek |

---

## Po každém milníku

- Demo features uživateli (sám sobě, ale formálně)
- Update README a CLAUDE.md
- Tag verze v gitu (`v0.M.0`)
- Backup DB před deployem na produkci
- Sledovat error rate v `log/app.log` první týden po deploy
