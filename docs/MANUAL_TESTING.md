# Manual walkthrough

Local URL: **http://127.0.0.1:8000**. The current application uses demo mode; no phones will ring. Browser testing has been left to the user because Chrome is restricted on this machine.

## Owner

Sign in with `owner@telecrm.test` / `DemoOwner!2026`.

1. Open Overview. Check the date filter, call totals, lead pipeline, employee table, and due/overdue lists.
2. Open Leads. Search by name or phone; filter by stage, employee, or never attempted.
3. Add a lead with an Indian number and assign it to Anjali Menon. Try the same number again: it should show a duplicate error.
4. Open Import CSV. Download the sample, add a valid row and a duplicate/invalid row, and import. Check the imported/skipped summary.
5. Open a lead, add a note, update its stage, and schedule a future follow-up. All dates shown are IST.
6. Reassign that lead. Its pending follow-ups should transfer, while historical calls retain the original caller.
7. Add an employee. To deactivate an employee with assigned leads, choose an active replacement; otherwise the action should be refused.
8. Open Settings. Live calling should be disabled/unconfigured. No provider credentials should be visible.
9. Open My account from the bottom-left profile link to change your password if desired.

## Employee

Sign out and sign in with `anjali@telecrm.test` / `DemoEmployee!2026`.

1. Only Anjali's assigned leads should be visible. The Employees and Settings navigation entries should be absent.
2. Open an assigned lead and click **Start demo call**. Choose Connected and a talk duration, then **Finish demo call**.
3. Confirm a single call record appears with the selected duration and outcome.
4. Try a second demo call with No answer. It must not count as a customer connection.
5. Add a discussion note and update the lead stage.
6. Schedule a follow-up, reschedule it, then mark it complete. Making a call alone must not complete it.
7. Refresh Overview and Call history to check the resulting totals.

## Layout

Check desktop and a narrow/mobile window. The menu button should open navigation on mobile. Wide tables should scroll inside their panels. Forms should stack vertically, remain usable, and show validation messages after incorrect input.

## Boundaries

- Demo outcomes are entered by the tester. Live outcomes are read from Exotel; staff cannot manually set live call duration.
- Demo data must never be migrated into production.
- Live telephony needs the separate controlled pilot described in DEPLOYMENT.md.
- Browser test scripts under tests/browser are optional and were not successfully executed on this machine. Do not run them against production; they create sample records.

## Verify the call-evidence boundary

In live mode, a Call request stays unverified until the server reads back the provider record. Only verified attempts, customer connections, and known talk time contribute to the dashboard. Employee notes and stage edits must not increase those numbers. Demo records must disappear from live call reports. These conditions are also covered by automated tests. Real duration capture still requires an approved Exotel account and the controlled live pilot.
