# Flight Ticket Management System

An internal tool for a small ticketing agency. Staff record bookings by hand after arranging tickets with airlines or consolidators. It is **not** a booking engine.

A single Laravel 13 + React project:

- `app/`, `routes/api.php`, `database/`: Laravel REST API (`/api/v1`), Sanctum bearer tokens, MySQL
- `resources/js/`: React 19 + TypeScript app (Tailwind, TanStack Query, Recharts), built by Vite through `laravel-vite-plugin`
- `resources/views/app.blade.php`: the page that loads React. `routes/web.php` sends every non-API URL to it, and React Router takes over from there

## First-time setup

Requires PHP 8.4+ (the locked Symfony 8 packages need it), Composer, Node.js and MySQL.

1. Create the database:
   ```sql
   CREATE DATABASE flight_tickets CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
2. In `.env`, set `DB_USERNAME` / `DB_PASSWORD`, plus `OWNER_EMAIL` and `OWNER_PASSWORD` for the first login.
3. Install and set up the database:
   ```bash
   composer install
   npm install
   php artisan migrate
   php artisan db:seed                      # creates the owner account
   ```
   If `OWNER_PASSWORD` is empty, the seeder generates one and prints it once.

## Running it

```bash
composer dev
```
This starts the Laravel server, queue worker and Vite (hot reload) together. Open **http://localhost:8000**.

Or run them in two terminals: `php artisan serve` and `npm run dev`.

## Tests and build
```bash
php artisan test     # backend feature/unit tests (in-memory SQLite)
npm run build        # TypeScript check + production build into public/build
```
After `npm run build`, `php artisan serve` alone serves the built app (no Vite needed).

## Emailing tickets to customers
On a booking, **Email tickets** (Passengers & Flights, or *More actions*) sends the selected e-ticket PDFs to the customer in **one email**, as real PDF attachments, using the branded template in `resources/views/emails/tickets.blade.php` (plain-text version: `tickets-text.blade.php`).

**How it works**
- Only the booking's **E-ticket** PDFs can be attached (not invoices, passports or receipts). Each file is re-checked in private storage before sending.
- The recipient is the customer's saved email; a one-off address can be entered for a single email without changing the customer record.
- The dialog shows a preview rendered from the real template before anything is sent.
- Every attempt is recorded (Documents & Issues → *Ticket emails*, and the booking history): who sent it, to whom, which files, and **Queued / Sent / Failed** with a readable reason. Failed attempts can be retried.
- Duplicate protection: a double click or repeated request sends only once; sending the same tickets to the same address again needs an explicit tick.
- Only Owner and Booking staff can send; read-only users cannot. Agency name, email (used as Reply-To), phone, website and logo URL come from **Settings → Agency & lists**.

**Quickest way — Ticket PDF** (button on the booking): one window with the reservation code and airline PNR (filled from the booking), the passengers (one PDF each, with Preview) and two buttons, **Download PDF** and **Email to customer** (one confirmation). The PDF is the agency's e-ticket built from the booking's passengers and flights, with the FLYSE logo and details; it is saved in the booking's documents (recorded as created and checked by the staff member) and every email appears under *Ticket emails*. It never changes the booking status.

**Email tickets** (for PDFs you upload, e.g. the official airline e-ticket) first asks which kind:
- **Send Official Ticket**: the booking's *E-ticket* PDFs (received by the agency). Only offered when one exists.
- **Send Alternative Ticket PDF**: always available, also when no official ticket has arrived. Staff upload (*Add PDF*) or pick an *Alternative ticket PDF* of the booking, with its source (customer-provided, supplier-provided, airline via another channel, other authorized source; a description is required for "other"), optional passenger and internal note.
  - An alternative PDF starts **Pending verification**. Only **Verified** PDFs can be sent (verify in the dialog, in Documents, or tick "I have reviewed this PDF" when uploading); **Rejected** ones never. Before sending, staff must also tick that they reviewed the document and the recipient.
  - The email says "Your Ticket Document" and that the document was not issued through the agency's standard process. It only says the official ticket is *still pending* if staff tick that option. The source, notes and verification never appear in the email.
  - Alternative PDFs can't be linked to a ticket as its e-ticket, so they never make a booking *Ticketed*. When the official e-ticket arrives, record it as usual (*Issue tickets*); the alternative PDF and its email history stay.
  - **Create ticket PDF** builds the agency's own ticket PDF from the booking (one per passenger, template `resources/views/pdf/ticket.blade.php`): agency header and logo (Settings → *Logo for ticket PDFs*), passenger, the reservation code and airline PNR (entered when creating it, always printed), date of issue (the ticket number and the internal booking reference are not printed), every flight segment with airports, local times, duration (only when both airport timezones are recorded), airline, cabin, status and baggage. It is titled **E-TICKET**; nothing that isn't recorded is printed. It needs a confirmed booking with passengers and flights. The PDF is saved as an alternative ticket PDF ("Generated by the agency") pending verification, so someone opens and checks it before it can be sent.
  - The history shows *Official ticket* / *Alternative document* on every email, plus who uploaded, verified and sent it.

**Mail provider setup (`.env`)**
```
MAIL_MAILER=smtp
MAIL_SCHEME=smtp            # STARTTLS on 587 (use smtps for port 465)
MAIL_HOST=smtp.your-provider.com
MAIL_PORT=587
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password-or-api-key
MAIL_FROM_ADDRESS=tickets@your-agency-domain.com
MAIL_FROM_NAME="Your Agency"
```
Credentials stay on the server only (never in React or Git). The sender domain usually has to be verified with the provider (SPF/DKIM) or messages may be rejected or land in spam. After changing `.env`, restart `composer dev` / the queue worker.

**Queue worker** — emails are queued (`TICKET_EMAIL_QUEUE=true`) so a slow mail server never blocks the screen. `composer dev` already runs a worker; in production run `php artisan queue:work` under a process manager (Supervisor, NSSM, systemd). Without a worker, emails stay **Queued**. Set `TICKET_EMAIL_QUEUE=false` to send immediately instead.

**Testing safely** — `MAIL_MAILER=log` (the default) writes the full message to `storage/logs/laravel.log` and the screen says "logged only, not delivered". To see real emails without reaching customers, use a sandbox inbox such as Mailtrap or a local Mailpit (`MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`, `MAIL_SCHEME=smtp`). Never test with real customer addresses. Automated tests (`php artisan test`) use a fake mailer and never send.

**Size limits** — at most `TICKET_EMAIL_MAX_MB` (default 18 MB) of attachments per email and `TICKET_EMAIL_MAX_FILE_MB` (10 MB) per file. Gmail accepts up to 25 MB per message including encoding overhead (attachments grow by about a third), and many providers are stricter, so keep the default unless your provider allows more.

**Diagnosing a failed email** — open the booking's *Ticket emails* list for the reason shown to users; the technical detail is in `storage/logs/laravel.log` ("Ticket email failed", with the attempt id). Typical causes: wrong MAIL_* settings, unverified sender address, provider rejecting the size, or a PDF removed from storage. Fix the cause, then press **Retry**.

## Flight quotations
**Flight Quotations** (sidebar) prepares price quotations from flights and fares that staff research themselves. It never searches airlines, reserves seats, buys tickets or takes payments.

**New quotation** is one page:
1. **Trip:** from, to, return/one-way, dates, passengers, cabin, currency. The flights section then appears by itself, already filled with the airports and dates.
2. **Flights & price:** one line per flight (airline, flight no., from, to, departs, arrives, duration; *Add connecting flight* for stops), then fare + taxes per person, baggage and refund. *Add another option* for alternatives.
3. **Create PDF:** saves, downloads the PDF and opens the quotation, where the PDF is shown with **Download PDF** and **Email to customer**.

Everything else (customer, message, validity, date-change rules, extras such as "Rebookable +300", timezones, terminals, notes) is optional and folded under *More*. A customer is not needed; when emailing without one, type the address.

Rules:
- **Prices** are calculated on the server in the option's currency; currencies are never added together. A missing fare makes the total "not complete" (never 0). Supplier cost and margin are internal and never reach the PDF or email.
- **Durations** are exact when both airport timezones are entered, otherwise use the entered flight time. Connections and layovers are worked out from the times. Odd itineraries (wrong date, airports that don't connect, return before outbound) are shown as warnings; nothing is changed automatically.
- **PDF versions:** each change of content creates a new numbered PDF when it is downloaded or emailed. Every email records which version it attached, and old versions stay downloadable.
- **Status:** Draft → Ready to send → Sent (set only when the mail server accepted the email) → Accepted / Rejected / Expired → Converted to booking (links an existing booking of the same customer). None of these change a booking or a ticket.

**Airlines & Logos** (sidebar): add airlines (name, IATA, ICAO) and upload a logo (PNG, JPG or WebP up to 2 MB; SVG must be exported to PNG first). Uploads are re-encoded to a small PNG, kept in private storage and embedded in PDFs (never hot-linked). Good sources: the airline's official brand/media page, or [Wikimedia Commons airline logos](https://commons.wikimedia.org/wiki/Category:Logos_of_airlines). Check the licence before use. An airline used in a quotation can be deactivated but not deleted. Without a logo, the PDF shows the airline name.

The agency logo on the PDF comes from **Settings → Logo for ticket PDFs**; name, address, phone, email and website from **Settings → Agency profile**.

## Roles
| Role | Can do |
|---|---|
| Owner | Everything: users, settings, reports/exports, expenses, supplier payments and refunds, editing or voiding payments, archiving documents, full audit log |
| Staff | Create and edit customers, bookings (including supplier cost), passengers, flights, tickets, documents and issues; record **customer receipts** only |
| Read-only | Search and view. Passport numbers are masked. Never sees supplier cost, margin or supplier transactions |

Every rule is enforced in the API. The React app only hides controls.

## Key rules built in
- **Booking code:** `BK-YYYY-NNNN`, generated under a row lock so two users never get the same code.
- **PNRs** are not assumed to be unique. Search returns every match.
- **Flight times** are local airport times, with optional IANA timezones per segment.
- **Money:** stored as `DECIMAL(14,2)` and calculated in integer cents. Payments must use the booking's currency. Reports group by currency and never add different currencies together.
  - Customer balance = (selling + service fee) − receipts + refunds to customer. A negative balance means a refund is owed.
  - Supplier balance = supplier cost − supplier payments + supplier refunds.
  - Gross margin = (selling + fee) − supplier cost. This is not net profit; the sales report subtracts expenses separately.
  - Pending and voided transactions don't affect balances. Transactions are never deleted.
- **Cancellation:** the booking is kept. Reduce its totals to the amounts retained after cancellation, then record refunds as transactions.
- **Documents:** PDF/JPG/PNG only, checked by content, not filename. Limits are 10 MB per file and 30 MB per upload. Files are stored under generated names in `storage/app/private/documents` and can only be downloaded through the authorised endpoint. "Delete" archives the file (soft delete).
- **Privacy:** passport numbers are encrypted at rest using `APP_KEY`, left out of list endpoints, masked for read-only users and redacted in the audit log.
- **Audit log:** every create, update or delete on bookings, passengers, segments, tickets, payments, documents, issues, customers, suppliers, expenses, users and settings is logged with before/after values, user and time.
- **Issues:** each status change is written to the issue timeline. Logging an issue never changes the booking automatically.

## Backups (do this before go-live)
Back up **both** the database and the documents folder:
```bash
mysqldump --single-transaction -u USER -p flight_tickets > backup-$(date +%F).sql
tar czf documents-$(date +%F).tgz storage/app/private/documents
```
Keep the `.env` `APP_KEY` safe and backed up. Without it, encrypted passport numbers cannot be read.

To restore: `mysql -u USER -p flight_tickets < backup.sql`, then extract the documents archive to the same path. Test a restore before launch.

## Production checklist
- Set `APP_ENV=production` and `APP_DEBUG=false`, and use HTTPS.
- Run `npm run build` on deploy. Laravel serves `public/build`, and the web server's document root is `public/`.
- Run `php artisan config:cache route:cache`.
- Schedule daily backups as above.

## Decisions still to confirm with the owner (plan §16)
Multi-currency exchange rates, the exact cancellation/refund policy, data retention periods, and whether email-inbox integration (phase 2) is needed. The schema leaves room for each of these.
