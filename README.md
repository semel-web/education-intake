# ATO Intake + Console — WordPress deployment

Two pieces:

1. **Static tools** at `code/tools/ato/`, deployed the same way as the URL Checker.
2. **The `ATO Portal` plugin** at `code/wp-content/plugins/ato-portal/`. This is new. It replaces Supabase: it stores data in the site's own database, uses WordPress sign-in, and adds an office settings screen in wp-admin.

The tools do not call any outside service. They talk only to the same WordPress site, through `/wp-admin/admin-ajax.php` (sign-in) and `/wp-json/ato/v1/` (data).

```
code/
  tools/ato/
    index.html          launcher (links to intake + console)
    intake.html         department administrator intake
    console.html        ATO office console
    approve.html        approval page fund managers / PIs / mentors open from their email link
    support.js          runtime (loads React from unpkg)
    ato-store.js        data layer, WordPress version
    data/ato-mock-data.json   console seed packets (pilot only)
  wp-content/plugins/ato-portal/
    ato-portal.php
```

If the Pantheon repo is Composer-managed (`web/wp-content/...`), put the plugin under `web/wp-content/plugins/` and the tools under `web/tools/ato/`.

## How sign-in works

- The tools sit on the same domain as WordPress, so the browser sends the WordPress login cookie with every request, including inside an Elementor iframe.
- On load, the tool asks the site who is signed in. Signed-out visitors get the sign-in card. There are two ways to sign in, both set in **wp-admin → ATO Portal → Sign-in**:
  - **Single sign-on button.** This sends the whole browser window (not just the iframe) to the site's login page. If the site's SSO plugin is configured (UCLA Logon via SAML / Shibboleth), that page *is* UCLA Logon. Afterwards the visitor lands back on the page they started from.
  - **Email + password** with a WordPress account. Use this for testing before SSO is decided. Self sign-up is off by default. When it's on, it's limited to the domains you list.
- Who can do what:

| | Signed out | Any signed-in user | ATO staff | Settings managers |
|---|---|---|---|---|
| Use the intake, see own submissions | — | ✓ | ✓ | ✓ |
| Submit or resubmit own packet | — | ✓ | ✓ | ✓ |
| Open the console, see all packets, set any status | — | — | ✓ | ✓ |
| Edit wp-admin → ATO Portal | — | — | — | ✓ |

- **ATO staff** is a WordPress role, `ato_staff`, granted on the **ATO staff** tab. You can add someone by email before they have an account. They get access on their first sign-in, which works with SSO auto-provisioning. Site administrators always have full access.
- All access rules are enforced by the plugin on the server, not by the page.

## Office-managed content (the CMS piece)

**wp-admin → ATO Portal → Reference tables** edits the NRSA stipends, the UCLA postdoc minimums and the GSR scale that drive the intake's salary engine.

- Add a new effective-dated row when a scale changes. The intake uses the row in effect on each appointment date.
- Saves are validated: same number of values, positivae numbers, real dates.
- The last 10 versions are kept, with who and when.
- **Restore built-in values** returns to what ships in the code.

The plugin also has a REST endpoint (`POST /wp-json/ato/v1/config`) so a future in-console editor can use the same storage.

## Approval route (fund managers, PIs, mentors)

When ATO moves a packet to **Out for signature**, the plugin builds the approval chain from the packet's route and emails the first approvers a personal link to `approve.html`.

- Order follows the route: fund managers first (every fund manager on the packet, in parallel), then the PI, then any faculty mentor or supplement PI. Each step waits for the one before it.
- Approvers need no site account. The link carries a random single-use token. Only a SHA-256 hash of it is stored. Links expire 30 days after the first request.
- The approval page shows the packet (trainee, title code, dates, FTE, funding lines, documents, the route) and asks for a typed full name. **Approve** passes it on; **Request changes** (note required) returns the packet to the department administrator with the note and cancels the other open links.
- When everyone has approved, the packet moves to **Fully signed** automatically. The submitter and the office inbox are emailed.
- The decision, typed name, time and IP address are recorded on the approval and in the packet's audit trail.
- Approvers who haven't acted get a reminder every 3 days, with a new link. The previous link stops working.
- In the console, each approver shows on the packet's **Approval route** with **Resend email**, **Copy link** (to send it another way), **Change email**, and **Record approval** (for an approval given on paper, by email or in DocuSign; a note is required).
- If ATO moves a packet off Out for signature, open links are cancelled. Sending it out again starts a fresh round.
- `approve.html` must be reachable **without signing in**. Don't put it behind a login-required page or a site-wide "members only" rule. Set its address under **Email → Approval page link** if you embed it elsewhere.
- **Open question for the office and compliance:** whether this typed-name approval can replace the DocuSign signature, or runs alongside it. The console's execution checklist still includes "Confirm DocuSign envelope complete".

## Roster upload and reappointment reminders

**wp-admin → ATO Portal → Roster & reminders.**

- Upload the **Employee Rosters Report** export as .xlsx or .csv. Upload the postdoc and GSR exports one after the other with *Add or update*, or use *Replace* to start fresh.
- Only these columns are read: Employee Full Name, Employee ID, Job Code, FTE, Job Effective Date, Expected End Date, Salary Plan Grade Code, Job Step Number, Job Annual Rate, Job Monthly Rate, Employee Work Email, Employee Citizenship Status Desc, Department. Home address, birth date, ethnicity, emergency contacts and every other column are ignored and never stored. The uploaded file is deleted right after it's read.
- Rows are grouped per person into a main appointment plus supplement (3253 + 3252, 3254 + 3252, 3145/3155 + 3160), with postdoc level and GSR point. The intake's **Existing appointment** lookup uses the uploaded roster instead of its built-in sample roster.
- `.xlsx` needs the PHP zip extension (standard on Pantheon). If it's missing, upload CSV.
- A daily job (WP-Cron, hook `ato_daily`) sends reappointment reminders for each appointment ending within 120 days: at 120, 90 and 60 days, then weekly to 14 days, then every 3 days. The office inbox is copied from 14 days. Each step is sent once.
- Reminders go to the department lines you set (`Department: email, email`; `*` for all others), plus the submitter and PI for appointments filed through the portal. Emails use initials and the last 4 digits of the employee ID unless **Include the trainee's full name** is ticked.
- Reminders stop once a reappointment for that person is submitted in the portal. At the end date with nothing on file, the portal files a system **termination notice** in the console.
- In pilot mode, **Pretend today is** lets you test the cadence without waiting. **Run the daily job now** runs it on demand.
- Pantheon runs WP-Cron about hourly. A once-a-day job is fine with that.

## Email notifications

**wp-admin → ATO Portal → Email.** Off by default. Mail goes through `wp_mail`, so it uses whatever SMTP or mail plugin the site already has. On Pantheon, outbound mail from PHP is unreliable without one, so ask the web team which mail plugin the site uses.

What gets sent (each can be turned off):

- **New submission / resubmission / visa inquiry** → office inbox.
- **Receipt** → the department administrator who submitted.
- **Returned for correction** → submitter, with the return note.
- **Office moves a packet forward** (out for signature, fully signed, filed, active, closed, and the visa-case statuses) → submitter.
- **Assigned to you** → the analyst, unless they assigned it to themselves.
- **Console email and reminder** → the address in the console, sent as written. Bulk reminders are logged only.
- **Approval requested / reminder** → each approver on the route (always on while email is on).
- **All approvals in / Changes requested** → office inbox, and the submitter.
- **Reappointment reminders** → see Roster and reappointment reminders.

Safeguards:

- Automated messages carry only the packet ID, type, form, title code and a link. No trainee name, salary, funding or visa detail. Console-written emails are sent as the analyst wrote them, so the console templates should follow the same rule.
- **Allowed recipient domains** (default `ucla.edu, mednet.ucla.edu`, subdomains included). Anything else is not sent and is flagged in the packet's audit trail.
- **Pilot redirect** sends every message to one test inbox instead, with `[TEST]` in the subject and the real recipients listed at the top. The domain filter is skipped while it's set, so test addresses like `@example-notreal.edu` still show up. Use it on Dev and Test, and clear it at go-live.
- Every automated send adds a line to the packet's audit trail. The Email tab shows the last 50 attempts and has a **Send test email** button.

Good next candidates for this screen: help-drawer text, the rule catalog wording, the route chain per form, reminder cadence, and an announcement banner.

## Install on Pantheon

1. Commit both folders to the site repo. Push to **Dev**.
2. On Dev: **Plugins → ATO Portal → Activate**. This creates three tables (`wp_ato_packets`, `wp_ato_packet_updates`, `wp_ato_packet_events`) and the `ato_staff` role.
3. Go to **ATO Portal → ATO staff** and add the office testers.
4. Go to **ATO Portal → Sign-in** and choose SSO and/or password sign-in.
5. Create two Elementor pages, one for the intake and one for the console. Each gets a full-width section, no padding, and an HTML widget:

```html
<iframe src="/tools/ato/intake.html" title="ATO Appointment Intake"
        style="width:100%;height:1400px;border:0;display:block"
        allow="clipboard-write" loading="lazy"></iframe>
```
```html
<iframe src="/tools/ato/console.html" title="ATO Office Console"
        style="width:100%;height:1100px;border:0;display:block"
        allow="clipboard-write" loading="lazy"></iframe>
```

6. Promote to **Test**, then **Live**. Pantheon moves code up, but the database does not move up. Activate the plugin on Live and add staff there. The tables are also created automatically the first time an admin opens wp-admin.

## CDNs and page security

- React 18.3.1 and React DOM 18.3.1 from unpkg, with SRI hashes in `support.js`.
- Babel standalone 7.29.0 from unpkg. Loaded only if a component needs transpiling.
- Fonts from Google Fonts: Plus Jakarta Sans, IBM Plex Mono.
- Each page sets a Content-Security-Policy `<meta>` tag:
  - `connect-src 'self'`: the pages can only talk to this site.
  - `script-src` allows `'unsafe-eval'` because the runtime compiles each page's logic in the browser. Mention this in the security review. The URL Checker build has the same requirement if it uses the same runtime.

## Data and privacy

- This tool is **not** browser-only like the URL Checker. Packets are stored in the WordPress database on Pantheon. A packet can include trainee name, employee ID, email, citizenship / visa status, title code, salary and FAU lines.
- **Confirm with UCLA IT security that this site is approved for that data classification (likely P3) before real packets go in.** Until then, use the fabricated test data only.
- No SSN, date of birth, home address or similar fields exist anywhere in the tool.
- No file uploads are stored. The Documents page records only which attachments were confirmed, so nothing lands in `wp-content/uploads`.
- API responses are sent `Cache-Control: no-store` and marked `noindex`. Signed-in traffic bypasses Pantheon's page cache.
- Password sign-in is rate-limited: 10 attempts per 15 minutes per IP. Password reset uses WordPress's own email.
- **Pilot mode** (Data tab) lets staff wipe all test data. Turn it off before go-live, which disables the wipe everywhere.

## Known limitations

- Console seed packets (`data/ato-mock-data.json`) load only while **Pilot mode** and **Show the built-in sample packets** are both on (Data tab). Turning pilot mode off hides them. They are never written to the database.
- The console checks for new data every 5 seconds per open tab. That's fine for an office of this size. If Pantheon reports PHP worker pressure, lower it.
- No self-contained (inlined) fallback build yet. It would still need the plugin, since the data lives on the server.
- The roster comes from a manual upload. There is no live UCPath feed, so the lookup and reminders are only as fresh as the last upload.
- Any signed-in user can search the whole uploaded roster from the intake lookup (names, employee IDs, work emails, title codes, dates). Pay rates are returned to ATO staff only. Restricting the lookup by department needs department data on each WordPress account.
- An analyst can still click **Advance** past Out for signature. That cancels the open approval links and is logged, so treat it as an override.
- Uninstalling the plugin leaves its tables (now six: packets, updates, events, signatures, roster, reminders) and options in place on purpose. Delete data from the Data tab first if needed.

## Test checklist (run on Dev, then Test)

1. Open `/tools/ato/intake.html` signed out. The sign-in card shows the options enabled in settings, with no console errors.
2. Sign in with a password account. The intake opens, and **My submissions** is empty.
3. Turn SSO on. Click the button inside the Elementor page. The full window goes to the login page, then returns to the same Elementor page, signed in.
4. Complete a guided postdoc packet and submit it. It appears under My submissions.
5. As a second non-staff account, confirm that packet is not visible. Open `/wp-json/ato/v1/packets` directly: only your own packets come back.
6. Open the console as a non-staff user and confirm the no-access screen.
7. Open the console as ATO staff. The packet is under **New submissions**. Assign it, add a note, return it to the submitter.
8. As the submitter, use **Open and fix**, then resubmit. The same packet id updates and the audit trail shows both submissions.
9. Edit a reference-table value. Reload the intake and confirm the Funding page figure changed. Restore the built-in values.
10. Remove a staff member and confirm they lose console access on reload.
11. Sign out from the tool, then reload. You stay signed out.
12. Repeat 2–8 inside the Elementor iframes, including print and copy.
13. Turn pilot mode off and confirm **Reset shared test data** in the console now refuses.
14. **Email tab:** turn email on, set the office inbox and a pilot redirect to your own address, and send a test. It arrives with `[TEST]` in the subject.
15. Submit a packet. You get two redirected emails: the office notice and the receipt. The packet's audit trail shows both.
16. In the console, assign the packet to another staff member, return it with a note, then advance a resubmission to Filed. Each sends one email, and the return email includes the note.
17. Send a console email to an `@example-notreal.edu` address. The console says it wasn't sent, and the audit trail flags the blocked domain.
18. Turn email off. Submitting sends nothing, and the Email tab log stays unchanged.
19. **Approval route.** Turn email back on. Submit a packet with a fund manager and PI who have `mednet.ucla.edu` test addresses (or keep the pilot redirect on). In the console, advance it to Out for signature. Each fund manager gets an "Approval requested" email, and the Approval route panel shows "Approval link sent".
20. Open the link in a private window, not signed in. The packet shows. Approve with a typed name. The PI's email goes out next.
21. Open the same link again. It says the approval is recorded. Open an old reminder link, and it says the link is no longer valid.
22. As the PI, **Request changes** with a note. The packet returns to the submitter with the note, and remaining links are cancelled. Resubmit, validate, and send it out again: a fresh round starts.
23. In the console, use **Copy link**, **Change email** and **Record approval** on an open step. Each writes to the audit trail.
24. Approve every step. The packet moves to Fully signed by itself, and the submitter and office are emailed.
25. **Roster.** Upload the six TEST roster workbooks with *Add or update*. 203 people load. The intake's Existing appointment lookup lists them.
26. Upload a file with no "Employee ID" column. It's refused with a clear message, and nothing changes.
27. **Reminders.** Set *Pretend today is* to 2027-06-15 and run the daily job. Reminders go out (initials only), and the table shows the last reminder for each person. Run it again: nothing new.
28. Set it to 2027-10-01 and run. "Appointment ended" emails go out, and termination notices appear in the console. Clear the date.
29. Confirm `wp cron event list` (or a cron plugin) shows `ato_daily` scheduled.
