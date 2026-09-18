# Lead and Call Management — Simple MVP

## Purpose

Build a simple web application for one lead-management company with 15–22 telecalling employees. Replace Excel with a shared lead list, automatic call tracking, follow-ups, and an owner dashboard.

Operations are India-only: employees and customers use Indian phone numbers, and all dates and daily reports use Indian Standard Time (Asia/Kolkata).

Success means the owner can see who called each lead, when they called, whether the customer connected, the call duration, the current lead stage, and the next follow-up.

## Mandatory: provider-verified calling

The primary purpose is to stop fake, manually reported call activity.

- Employees initiate work calls using the CRM Call button. The server selects the assigned lead number and employee’s owner-managed phone number.
- Employees cannot create manual call logs or set/modify call timestamps, connection outcomes, provider references, or duration. Submitted values are rejected.
- The provider places and bridges the call. Server-verified provider records supply the outcome and customer conversation duration.
- Live dashboard call totals and talk time include only records verified against Exotel. Unverified requests remain visible in call history but do not count as performed calls.
- Notes, stages, and follow-ups are employee-reported business information and never constitute proof of a call.
- Demonstration records are excluded from live reports. The simulator is restricted to local/testing environments and disabled in staging/production.
- A browser application cannot prevent an employee from independently using their personal phone. Such calls do not count in this CRM. This system verifies provider connections and duration, not the truth or quality of what was said.

## 1. Users

- **Owner:** manage employees, add/import leads, assign or reassign leads, and view all activity and reports.
- **Employee:** view assigned leads, initiate calls, add notes, update stages, and schedule or complete follow-ups.

Use only these two roles in the first version. Employees cannot access another employee’s leads by changing a URL or sending a direct request.

## 2. Daily workflow

1. Owner adds leads manually or imports a CSV exported from Excel.
2. Owner assigns leads to employees manually.
3. Employee opens their lead list and clicks **Call** on a lead.
4. The telephony provider calls the employee’s registered phone. After they answer, it connects them to the customer.
5. The application receives provider updates and saves call time, status, and duration automatically.
6. Employee adds discussion notes, updates the lead stage, and sets a follow-up if needed.
7. Owner views team activity and pending work on the dashboard.

Employees continue speaking through their phones; no custom mobile app or browser calling is needed for this version.

**Tracking boundary:** Only calls initiated through the CRM are automatically tracked. Calls dialed directly using a phone’s normal dialer are outside this system. Employees must use the CRM Call button for work calls.

## 3. Essential features

### Leads

- Fields: name, phone number, source, assigned employee, stage, and creation date.
- Stages: New, Contacted, Follow-up, Qualified, Converted, Lost.
- Add and edit leads; search by name or phone; filter by employee and stage.
- CSV import with a sample format, validation, and a summary of imported/skipped rows.
- Normalize phone numbers and flag duplicates within the company instead of silently creating them.
- Accept valid Indian phone numbers, normalize them to international format (+91), and reject non-Indian destinations for calling. Apply the same validation to employee calling numbers and imported leads.
- Show each lead’s calls, notes, stage changes, and follow-ups in one timeline.
- Keep historical calls attributed to the employee who made them after reassignment.

### Calls

Automatically save:

- Lead and employee.
- Provider call IDs, including employee/customer leg IDs where applicable.
- Initiated, connected, and ended timestamps when available.
- Customer connection outcome: connected, no answer, busy, failed, or canceled.
- Customer connected duration in seconds; keep unavailable duration as unknown rather than inventing a value.

Employee adds the discussion notes and business outcome. Technical call status and lead stage remain separate: answering a call does not automatically mean a lead is qualified.

An employee answering their phone does not count as the customer connecting. Count one CRM call attempt even if the provider creates two call legs.

### Follow-ups

- Set a date/time and a short note against a lead.
- Show due-today and overdue lists inside the application.
- Mark a follow-up complete or reschedule it.
- Keep follow-up completion separate from merely attempting another call.

### Owner dashboard

Use simple summary cards and an employee activity table, with a date filter:

- Call attempts and customer-connected calls per employee.
- Total customer connected duration per employee.
- Leads by current stage.
- Leads never attempted through the CRM.
- Follow-ups due today and overdue.
- Converted leads, based on the date they entered Converted.

Keep never-attempted leads, unanswered call attempts, and overdue follow-ups separate. Do not combine them into an ambiguous “missed leads” count.

## 4. Telephony: one provider only

Use one domestic cloud telephony provider for click-to-call and automatic status callbacks. Evaluate **Exotel first**, subject to account onboarding, domestic route availability, pricing, and a successful test call. Integrate only the selected provider.

**Provider decision:** India-only operation is confirmed. Use a domestic calling route with a provider-approved Indian caller ID. Twilio is not the planned provider for this MVP because its published India guidelines say outbound calls to India can only originate from international, non-Indian numbers. Exotel documents an API for connecting two numbers; confirm the required domestic setup with its team before committing.

Before building the full integration, prove one end-to-end call using the intended account:

- Employee receives the first call and the customer receives the second.
- Caller ID is acceptable to the company.
- Both call legs use the agreed India domestic route, with sufficient simultaneous-call capacity for the team.
- Customer connection status and duration are available.
- Provider callbacks reach the application.
- Account onboarding, permitted business use, and charges for both call legs are confirmed.

Sources checked September 18, 2026:

- [Twilio India voice guidelines](https://www.twilio.com/en-us/guidelines/in/voice)
- [Exotel developer documentation](https://developer.exotel.com/)

## 5. Simple implementation

- **Backend:** Laravel.
- **Frontend:** Laravel Blade with Tailwind CSS; responsive pages for desktop and mobile.
- **Database:** MySQL.
- **Background work:** Laravel database-backed queue and scheduler where needed; no Redis dependency for the MVP.
- **Hosting:** Public HTTPS application with MySQL, scheduled tasks, and support for the chosen queue execution method. Use the proposed Hostinger shared plan only after these capabilities are verified; otherwise use a suitable managed server or VPS.
- **Dashboard updates:** Refresh or lightweight polling; no real-time socket infrastructure required.

Build one Laravel application. No separate frontend application, microservices, or public API product is required.

### Minimum data model

- `users`: owner/employees, login details, registered calling number, active status.
- `leads`: contact details, source, assignee, stage, timestamps.
- `calls`: lead, employee, provider references, statuses, timestamps, duration.
- `call_events`: provider callback data and processing status for reliable updates.
- `lead_activities`: notes, stage changes, and assignment history with actor and timestamp.
- `follow_ups`: lead, responsible employee, due time, note, completion time.

Store timestamps in UTC and display them in Asia/Kolkata (IST). Calculate today, overdue work, and dashboard date ranges using IST boundaries. Deactivating an employee must preserve their history and allow pending work to be reassigned.

## 6. Essential reliability and access controls

- Authenticate users and check permissions on every request.
- Keep telephony credentials on the server, outside source control.
- Verify incoming callbacks using the provider’s supported mechanism.
- Handle repeated callbacks without duplicate records or inflated call counts.
- Handle delayed/out-of-order events without reverting a completed call to ringing.
- Prevent duplicate clicks from creating duplicate calls. Do not blindly retry a timed-out call request; establish whether the provider already accepted it.
- Reconcile calls stuck in an unknown state using provider records.
- Track failed processing, back up the database, and verify restoration before launch.

Keep call recording disabled for the first version. Confirm the provider’s onboarding and commercial-calling requirements for the intended use before live calling; establish lead-data access and retention rules.

## 7. Out of scope for the first version

- Multi-company SaaS, subscriptions, and billing.
- Custom Android/iOS apps or native phone call-log synchronization.
- Call recording, transcription, AI summaries, and live call monitoring.
- WhatsApp/SMS campaigns or external reminder notifications.
- Auto-dialers, bulk calling, IVR, and inbound call management.
- Automatic lead assignment, attendance, leaderboards, and advanced analytics.
- Multiple telephony integrations.

## 8. Build order and acceptance

1. Validate the provider with one real employee-to-customer bridged call.
2. Build login, employee management, lead import/list/detail, and manual assignment.
3. Add the Call button, provider callbacks, and automatic call history.
4. Add notes, stages, follow-ups, and the owner dashboard.
5. Pilot with two employees before onboarding the full team.

The MVP is ready when:

- An owner can import and assign leads; employees see only their assigned leads.
- A CRM call automatically records the correct employee, lead, timestamps, customer outcome, and available duration.
- Employee pickup alone does not count as customer connection.
- Busy, no-answer, failed calls, duplicate callbacks, and delayed callbacks produce correct history and totals.
- Employees can update lead stages and manage follow-ups.
- Owner totals match call records, and never-attempted leads and overdue follow-ups are visible.
- Reassignment preserves historical attribution and transfers pending follow-up responsibility.

Estimate delivery after the provider proof and hosting check. The immediate goal is a dependable replacement for Excel for this one team.

## 9. Implementation notes

The MVP is implemented in this folder. See README.md for local startup and demo logins, docs/MANUAL_TESTING.md for a walkthrough, and docs/DEPLOYMENT.md for production setup.

- Local development uses SQLite and Laravel’s development server, avoiding a dependency on the current XAMPP services. MySQL remains the deployment target.
- Laravel Blade and compiled Tailwind assets provide the interface.
- Demo calling is the default and never places telephone calls. The Exotel adapter requires credentials, explicit live activation, public HTTPS callbacks, and a controlled provider pilot.
- Browser verification is manual because Chrome is restricted on this machine. Automated application tests use an isolated database and mocked provider responses.
