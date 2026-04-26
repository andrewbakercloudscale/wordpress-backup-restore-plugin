# CloudScale Backup & Restore — UX Audit
**Date:** 26 April 2026  
**Auditor:** Claude Sonnet 4.6

---

## Executive Summary

The plugin is feature-rich and well-structured at a high level. The tab + card layout is logical, buttons are colour-coded by risk, and Explain modals exist for every major section. However, the design has grown organically and shows several recurring problems: (1) first-time users are likely to miss the Explain buttons entirely and have no other help; (2) terminology is inconsistent across tabs; (3) high-risk operations (restore, watchdog removal) don't surface enough friction; (4) a handful of labels are genuinely confusing even for technical users. The fixes are mostly copy and minor layout changes — no architectural work required.

---

## Issues

### 🔴 CRITICAL

---

**C1 — "Explain…" buttons are invisible to most users**  
*Tab: All tabs*

The only contextual help in the plugin lives behind pill-shaped black buttons with gold text in card headers. There is no tooltip, no `?` icon, no inline hint, and no on-boarding state. In user testing, small non-labelled buttons in headers are regularly missed. A first-time user configuring S3 or the watchdog has no way to discover this help exists unless they happen to hover over every card header.

**Proposed fix:** Replace or supplement the pill button with a `?` icon that has a visible `title` tooltip on hover ("Click for a full explanation of this section"), and add a single first-run banner at the top of the page: *"New here? Every section has an Explain button — click ? in any card header for a full walkthrough."* Dismiss to wp_user_meta so it only shows once per user.

**Sign-off:** ☐

---

**C2 — "Restore from Uploaded File" card lacks a danger affordance until the modal**  
*Tab: Local Backups*

The card header is red, but the card body looks like a normal file-upload form. The words "this will overwrite your live database" do not appear until a modal opens after upload. A user who uploads a file thinking they are just "loading" a backup will be surprised by the destructive warning appearing only after a slow upload completes.

**Proposed fix:** Add a single amber warning box at the top of the card body (before the file picker): *"⚠ Restoring overwrites your live database or files. Take a server snapshot before proceeding."* This primes the user before they invest time in the upload.

**Sign-off:** ☐

---

**C3 — Watchdog "Remove" button has no confirmation**  
*Tab: Automatic Crash Recovery → Watchdog Script Setup*

The red "Remove" button next to "Install Now" immediately removes the cron watchdog script. If a user accidentally clicks it, crash recovery silently stops working. There is no "Are you sure?" step, no undo, and no visible change to the card (the status text updates but is easy to miss).

**Proposed fix:** Add a JS `confirm()` dialog: *"Remove the watchdog? Automatic Crash Recovery will stop working until you reinstall it."* Alternatively use an inline confirm pattern (button changes to "Confirm Remove" on first click, reverts after 5s).

**Sign-off:** ☐

---

### 🟠 HIGH

---

**H1 — "Run Table Repairs" label is technically wrong**  
*Tab: Local Backups → Backup Schedule card*

The checkbox reads "Run Table Repairs automatically after each scheduled backup" but the help text explains it runs `OPTIMIZE TABLE`. These are different MySQL operations — REPAIR TABLE fixes corruption, OPTIMIZE TABLE reclaims fragmentation. A DBA reading this would distrust the plugin.

**Proposed fix:** Rename to "Optimise tables after each scheduled backup" and update the help text to: *"Runs OPTIMIZE TABLE on InnoDB tables with overhead after the scheduled backup completes. This reclaims fragmented space and can improve query performance. It does not modify your data."*

**Sign-off:** ☐

---

**H2 — Notifications card: "Plugin rollbacks" checkbox appears without context**  
*Tab: Local Backups → Notifications card*

Each notification channel has a "Plugin rollbacks" checkbox, but there is no explanation of what a plugin rollback is, who triggers it, or where to configure it. A user who has not visited the Automatic Crash Recovery tab will have no idea what they are subscribing to.

**Proposed fix:** Add inline help text under the checkbox: *"Sent when Automatic Crash Recovery rolls back a plugin after detecting a site failure. Configure in the Automatic Crash Recovery tab."* Make "Automatic Crash Recovery tab" a clickable link that switches tabs.

**Sign-off:** ☐

---

**H3 — Cloud provider discovery problem**  
*Tab: Cloud Backups → Cloud Backup Settings card*

The "Providers to include" section shows S3, Google Drive, Dropbox, OneDrive as grayed-out and labeled "(Not configured)". There is no CTA, no link, and no arrow pointing to where configuration happens. A new user has to guess that they must scroll past the schedule settings to find the individual provider cards below.

**Proposed fix:** Each unconfigured provider label should be a link: *"AWS S3 — (Not configured — configure below ↓)"* that anchor-scrolls to the relevant card. Or add a single note: *"Configure each provider in the cards below, then return here to enable them."*

**Sign-off:** ☐

---

**H4 — "Cloud Backup Delay" is confusing to first-time users**  
*Tab: Cloud Backups → Cloud Backup Settings card*

The delay control asks users to set how many minutes after the local backup the cloud sync runs. This is a reasonable design but the label "Cloud Backup Delay" implies the cloud backup is delayed/broken, not that it is intentionally staggered. New users read "delay" as a problem to fix.

**Proposed fix:** Rename to "Cloud sync starts" and rewrite the display text: *"Cloud sync starts [N] minutes after the local backup finishes (minimum 15 min)."* Show the computed time as: *"With your current schedule, cloud sync will start at [TIME]."*

**Sign-off:** ☐

---

**H5 — "File backup days" label appears in the Cloud tab**  
*Tab: Cloud Backups → Cloud Backup Settings card*

The days-of-week checkboxes are labeled "Cloud backup days" in the markup comments but the rendered label says "File backup days" — the same label used on the Local tab. This will confuse any user who reads both tabs.

**Proposed fix:** Label it clearly: *"Cloud sync days"* with help text: *"Cloud sync only runs on days when a local backup is also scheduled. Selecting a day here with no matching local backup day has no effect."*

**Sign-off:** ☐

---

**H6 — Selective restore has no table search**  
*Tab: Local Backups → Restore modal*

The "Specific tables only" restore mode shows a scrollable checkbox list of all database tables. Sites with 100+ tables (WooCommerce, multilingual plugins) will see an overwhelming unsearchable list. A user trying to restore `wp_posts` must scroll through every table.

**Proposed fix:** Add a text filter input above the table list: *"Filter tables…"* that instantly hides non-matching rows client-side. Runs entirely in JS — no server round-trip.

**Sign-off:** ☐

---

**H7 — No retry or clear-error affordance in backup history table**  
*Tab: Local Backups → Local Backups History card*

When cloud sync fails for a specific backup, the row shows a red ✗ and a "Retry" button. When a backup itself fails mid-run, there is no corresponding row entry or error state in the history — the failure is only visible in a status message that scrolls away. Users have no way to know a scheduled backup failed unless they remember to check.

**Proposed fix:** Insert a "Failed" entry in the history table for failed backup runs, with a red badge and a "Run Now" retry button. This mirrors how the existing "Delete Soon" badge surfaces upcoming events.

**Sign-off:** ☐

---

### 🟡 MEDIUM

---

**M1 — Inconsistent Save button labels across cards**

Cards use: "Save Schedule", "Save Retention Settings", "Save Drive Settings", "Save Notification Settings", "Save Automatic Crash Recovery Settings". The pattern is inconsistent — some say "Schedule", some say "Settings", some include the section name, some don't.

**Proposed fix:** Standardise to "Save" everywhere except where the section name is genuinely needed to disambiguate (i.e., only when two Save buttons are visible simultaneously). The card header already names the section.

**Sign-off:** ☐

---

**M2 — Encryption password field has no copy button**

Users setting a password for AES-256 encrypted backups need to store the password somewhere. The field is `type="password"` with a "Show" toggle. There is no copy-to-clipboard button, so a user must show the password then manually select and copy it — error-prone.

**Proposed fix:** Add a clipboard icon button next to "Show" that copies the current value to clipboard and shows a brief "Copied!" confirmation. Same pattern used in the backup filename path display.

**Sign-off:** ☐

---

**M3 — Table overhead status is only visible inside the Repair card**

If a site has high table overhead (>100 MB), there is no alert at the top of the page or on the tab button. The only indicator is the coloured badge inside the Table Overhead Repair card, which is far down the page.

**Proposed fix:** If overhead exceeds a threshold (e.g. 50 MB), add an amber inline alert at the top of the Local Backups tab: *"⚠ Table overhead is [N] MB — consider running a repair."* This follows the same pattern as the existing low disk space alert already at the top of the tab.

**Sign-off:** ☐

---

**M4 — "Golden Image" term is used without definition**

The term "Golden Image" appears in the AWS AMI and cloud history sections. It is common DevOps jargon but completely opaque to the WordPress site owner audience this plugin targets. There is no tooltip, inline definition, or link to docs.

**Proposed fix:** Replace "Golden Image" with "Verified Backup Snapshot" in all UI labels, or add a `title` tooltip on first use: *"A golden image is a verified backup snapshot used as a known-good restore point."*

**Sign-off:** ☐

---

**M5 — "Backup method: mysqldump or PHP streamed" is shown but not explained**

The System Info card shows "Backup method: mysqldump" or "PHP streamed" with no indication of which is better or whether the user should care. This is alarming if the user expected mysqldump but sees PHP streamed.

**Proposed fix:** Add an inline indicator: green tick for mysqldump ("mysqldump ✓ — fastest and most reliable") and amber for PHP streamed ("PHP streamed — mysqldump not available on this server. Backups will be slower on large databases.").

**Sign-off:** ☐

---

**M6 — Notification channels use inconsistent phrasing**

- "Email"  
- "SMS via Twilio"  
- "Push via ntfy"

These are not parallel. "Email" doesn't say "via [provider]", the others do.

**Proposed fix:** Standardise to: "Email", "SMS (Twilio)", "Push (ntfy)". Keeps them parallel and short.

**Sign-off:** ☐

---

**M7 — The "How It Works" card in Automatic Crash Recovery is orphaned**

The last card in the Automatic Crash Recovery tab is a purple "How It Works" card with a numbered list. It has no Explain button, no header action, and no connection to the surrounding cards. It reads like documentation appended to the bottom.

**Proposed fix:** Either (a) remove it and fold the content into the Settings card Explain modal, or (b) rename it "About Automatic Crash Recovery" and move it to the top of the tab so it acts as an introduction rather than an afterthought.

**Sign-off:** ☐

---

**M8 — Health check "4xx is healthy" is counterintuitive**

The health check URL help text reads: *"A 5xx response or connection failure is treated as unhealthy. 4xx responses (including 404) are treated as healthy."* A 404 meaning "the server is up" is technically correct but reads as backwards to most users.

**Proposed fix:** Rewrite: *"The watchdog checks that your server responds. A 5xx error or timeout = unhealthy (rollback triggered). A 2xx, 3xx, or 4xx response = healthy (server is up and responding, even if the page is not found)."*

**Sign-off:** ☐

---

**M9 — Progress panel disappears after backup without a summary**

After a backup completes, the progress panel hides and the only feedback is the history table updating. There is no "Backup complete — 47 MB, 3m 12s, all components verified ✓" summary message. Users must scroll to the bottom of the page to confirm what happened.

**Proposed fix:** After a successful backup, show a green success banner above the progress panel (or in its place) for 10 seconds: *"✓ Backup complete — [size] · [duration] · [components] · [verification result]."*

**Sign-off:** ☐

---

### 🔵 LOW

---

**L1 — "Randomise start times" checkbox description is developer-focused**

The help text reads: *"Shifts the backup start time by a random +/- 15 minutes each time the schedule is saved. Both local and cloud backups use the same offset so they stay in sync. Recommended when running multiple servers to avoid simultaneous backup load on shared network or storage."* Most users run one server and this is irrelevant noise.

**Proposed fix:** Shorten to: *"Slightly randomises the start time each day to avoid predictable load spikes. Recommended for multi-server setups."*

**Sign-off:** ☐

---

**L2 — "Backup filename prefix" is too prominent**

The prefix field is the first thing in the Retention & Storage card, above retention count and storage estimates. Filename prefix is a rarely-changed setting that most users never need. The more important setting (how many backups to keep) is buried below it.

**Proposed fix:** Move filename prefix to the bottom of the card, or collapse it into a "Advanced" disclosure. Retention count should be the primary control in this card.

**Sign-off:** ☐

---

**L3 — Verify button state is not communicated during check**

When a user clicks "Verify" in the history table, the button disappears and nothing visible happens in the row until the AJAX call returns. On slow servers this takes several seconds with no feedback.

**Proposed fix:** Change the button to a spinner + "Checking…" state immediately on click, before the AJAX call returns.

**Sign-off:** ☐

---

**L4 — The "Backup Now" button in admin toolbar setting is far from the button it adds**

The checkbox "Show Backup Now button in admin toolbar" is in the Backup Schedule card. The effect of checking it appears in the WP admin toolbar at the top of every page — a completely different part of the screen. Users who check it and don't see an immediate change may think it didn't work.

**Proposed fix:** Add inline help: *"After saving, a Backup Now button will appear in your WordPress admin toolbar at the top of the screen."* Optionally add a small screenshot/icon preview.

**Sign-off:** ☐

---

**L5 — "Must-use plugins" and "Drop-ins" are in the component checklist with no explanation**

Most WordPress site owners have never heard of must-use plugins or drop-ins. These two checkboxes appear alongside Database and Media with no indication of what they are or whether typical users need them.

**Proposed fix:** Add inline help next to each: "Must-use plugins (wp-content/mu-plugins/) — plugins that run automatically, cannot be deactivated." and "Drop-ins (wp-content/\*.php) — WordPress override files like advanced-cache.php."

**Sign-off:** ☐

---

## Summary Table

| # | Title | Severity | Tab |
|---|-------|----------|-----|
| C1 | Explain buttons are invisible to most users | 🔴 Critical | All |
| C2 | Restore card missing upfront danger warning | 🔴 Critical | Local Backups |
| C3 | Watchdog Remove has no confirmation | 🔴 Critical | Auto Recovery |
| H1 | "Table Repairs" label is technically wrong | 🟠 High | Local Backups |
| H2 | "Plugin rollbacks" checkbox has no context | 🟠 High | Local Backups |
| H3 | Cloud provider discovery problem | 🟠 High | Cloud Backups |
| H4 | "Cloud Backup Delay" label implies a problem | 🟠 High | Cloud Backups |
| H5 | "File backup days" appears in Cloud tab | 🟠 High | Cloud Backups |
| H6 | Selective restore has no table search | 🟠 High | Local Backups |
| H7 | No failed-backup row in history table | 🟠 High | Local Backups |
| M1 | Inconsistent Save button labels | 🟡 Medium | All |
| M2 | Encryption password has no copy button | 🟡 Medium | Local Backups |
| M3 | Table overhead alert not surfaced at top | 🟡 Medium | Local Backups |
| M4 | "Golden Image" undefined jargon | 🟡 Medium | Cloud Backups |
| M5 | Backup method shown without guidance | 🟡 Medium | Local Backups |
| M6 | Notification channel names inconsistent | 🟡 Medium | Local Backups |
| M7 | "How It Works" card is orphaned | 🟡 Medium | Auto Recovery |
| M8 | "4xx is healthy" reads as backwards | 🟡 Medium | Auto Recovery |
| M9 | No success summary after backup completes | 🟡 Medium | Local Backups |
| L1 | "Randomise start times" is developer jargon | 🔵 Low | Local Backups |
| L2 | Filename prefix too prominent | 🔵 Low | Local Backups |
| L3 | Verify button shows no in-progress state | 🔵 Low | Local Backups |
| L4 | Toolbar button setting needs preview hint | 🔵 Low | Local Backups |
| L5 | Must-use plugins / drop-ins unexplained | 🔵 Low | Local Backups |
