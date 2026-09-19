# Internal Android calling and call tracking

Status: proposed architecture; not implemented or validated on staff devices.

Recorded: 19 September 2026.

## Requirement

The calling team works entirely within India. Staff will receive an Android APK directly; publishing on Google Play is not planned.

Callers should open a customer in the app, tap **Call**, use their phone's normal SIM connection, and have the resulting call duration recorded on our own server. The solution should avoid a paid telephony service such as Exotel.

This document describes the staff calling workflow. It does not change the housing website's Australian audience or introduce calling functionality into the existing site.

## Proposed approach

Package the web interface inside an Android app and add a native Android module for calling, permitted call-log access, and syncing. A WebView wrapper or a visual dial pad alone cannot retrieve call duration.

```text
Staff login → Open customer → Tap Call
                                ↓
                     Create a local call attempt
                                ↓
                     Launch normal SIM calling
                                ↓
                     Read matching call-log entry
                                ↓
                     Queue and sync to our server
                                ↓
                     Show duration in dashboard
```

The initial version can use Android's existing phone interface. Building a complete replacement/default dialler is a separate, larger scope and should only be considered if necessary after device testing.

## Website versus Android app

| Capability | Website or PWA only | Android app with native module and required access |
| --- | --- | --- |
| Record Call button click and selected customer | Yes | Yes |
| Open the phone dialler | Yes, through a `tel:` link | Yes |
| Confirm a call occurred | No | Reconcile with a matching device call record |
| Retrieve device-reported duration | No | Yes, if call-log access is available |
| Sync records to our server | Click information only | Matched call records and staff notes |

A browser timer or the time spent away from the website is not a reliable measure of call duration. Installing the site as a PWA does not grant access to phone call logs.

## Android permissions and direct APK distribution

Direct distribution removes the Google Play publication/review step. It does **not** bypass Android's operating-system permission controls.

- Reading call history requires `READ_CALL_LOG`. Android documents this as a **hard-restricted permission**: the installer on record must allowlist it before the app can hold it. User runtime permission is also required.
- We must test the intended APK installation method on the team's actual phone brands and Android versions. Sending an APK and asking the caller to tap “Allow” is not a guaranteed solution.
- Using `ACTION_DIAL` opens the phone app with a number and lets the caller initiate the call; it does not require `CALL_PHONE`.
- Initiating a call directly requires the applicable Android calling permission and handling. Choose the least-permission approach that meets the workflow.
- Request only permissions necessary for implemented functionality, explain what work-call data is uploaded, and handle denial or revocation gracefully.
- Do not promise support on every Android device before the proof of concept passes.

If call-log access cannot be obtained through a supported installation and permission flow, show the attempt as unverified and offer manual entry. Evaluate managed deployment or a genuine default-dialler implementation separately; neither should be assumed to solve the issue without testing.

## Application components

### Web interface

- Staff login and customer list.
- A Call action tied to a specific customer record.
- Call history showing duration, source, and sync state.
- A short outcome/notes form after the call.

### Native Android module

- Accept a call request from the trusted app interface.
- Save the attempt before launching the phone interface.
- Reconcile attempts with call-log entries when available and when the app resumes.
- Queue matched records locally, including when there is no internet connection.
- Retry uploads through persistent background work, such as WorkManager, when connectivity permits. Immediate background execution is not guaranteed.
- Restrict the WebView bridge to trusted app content; do not expose call-log or calling methods to arbitrary pages or external navigation.

### Own backend and dashboard

- Authenticate the staff member and authorise access to the customer.
- Accept call-record uploads over HTTPS.
- Deduplicate retried uploads using a stable record identifier.
- Store device-reported measurements separately from manually entered outcomes.
- Show pending, synced, unmatched, and manually entered records distinctly.

“No external APIs” here means **no third-party telephony API**. The Android app will still communicate with our own authenticated backend API.

## Suggested record fields

| Field | Purpose |
| --- | --- |
| Attempt ID | Stable ID generated before dialling |
| Staff ID | Authenticated caller; validated by the backend |
| Customer ID | Customer associated with the call request |
| Requested number | Normalised number selected in the app |
| Attempt timestamp | When the caller tapped Call |
| Device call timestamp | Timestamp reported by the matching call-log entry |
| Duration in seconds | Duration reported by Android; nullable until verified |
| Call type | Device-reported type, such as outgoing |
| Phone account/SIM reference | Where available, to help reconcile dual-SIM calls |
| Device call-log ID | Helps prevent duplicate imports on the same installation |
| App installation ID | Distinguishes records across devices/installations |
| Match state | Pending, matched, unmatched, or ambiguous |
| Measurement source | Device call log or manual entry |
| Staff outcome and notes | For example, interested, follow-up needed, or wrong number |
| Sync timestamp | When the server accepted the record |

Do not infer detailed outcomes such as “busy”, “rejected”, or “customer answered” from duration alone. A connected call may reach voicemail or an automated system. Zero duration must not automatically be labelled as one particular failure reason, and missing duration must remain distinct from zero.

## Matching and syncing rules

1. Persist the customer, requested number, attempt ID, and timestamp before launching the call.
2. Look for a recent outgoing call-log entry matching the normalised number and a bounded time window; use the phone account/SIM when available.
3. Allow for call-log updates arriving after the call ends. Retry reconciliation rather than immediately treating an absent entry as a failed call.
4. Never associate the most recent call with a customer without checking it. Repeated calls, changed numbers in the dialler, cancelled attempts, and dual-SIM selection can produce mismatches.
5. Leave ambiguous matches unresolved for review rather than inventing a duration.
6. Upload each matched record idempotently. Network retries must not create additional calls in the dashboard.
7. Sync only relevant work calls associated with app attempts, not the caller's full personal call history.

These records are device-reported operational data, not independently verified carrier billing records.

## Cost expectations

There is no Exotel or other third-party telephony API charge in this architecture. Calls use the caller's existing SIM/carrier plan and ordinary calling terms.

Costs remain for Android development, server hosting, mobile data, maintenance, signing and distributing app updates, and testing across supported phones. Do not describe the entire solution as free or assume a specific carrier plan covers every business calling pattern.

## Proof of concept before full implementation

First collect the callers' phone brands, Android versions, SIM arrangements, and intended APK installation method.

Validate on one representative staff phone:

1. Install the signed APK through the intended distribution flow.
2. Confirm call-log permission can actually be obtained and retained.
3. Log in, select a customer, and initiate a normal SIM call.
4. End the call and compare the imported duration with the phone's call history.
5. Verify the correct staff and customer record appears on our server without duplicates.

Then test:

- Cancelled dialling and calls with no answer.
- Multiple calls to the same number in quick succession.
- A number changed in the phone dialler before calling.
- Dual-SIM selection and permission revocation.
- No internet during/after the call, followed by reconnection.
- App backgrounding, process termination, reopening, and delayed call-log updates.
- Duplicate upload retries and unrelated personal calls.
- Other phone brands and Android versions used by the team.

Proceed to the full app only after the supported installation path, accurate record matching, and server sync are demonstrated. Automatic call recording is not included in this proposal.

## References

- [Android call-log fields and duration](https://developer.android.com/reference/android/provider/CallLog.Calls)
- [Android READ_CALL_LOG permission restrictions](https://developer.android.com/reference/android/Manifest.permission#READ_CALL_LOG)
- [Android phone intents](https://developer.android.com/guide/components/intents-common#Phone)
- [Building a genuine default phone application](https://developer.android.com/develop/connectivity/telecom/dialer-app)
- [WorkManager requests, constraints, and retries](https://developer.android.com/develop/background-work/background-tasks/persistent/getting-started/define-work)
- [Offline-first data queues and syncing](https://developer.android.com/topic/architecture/data-layer/offline-first)
- [Google Play call-log policy, if public distribution is considered later](https://support.google.com/googleplay/android-developer/answer/10208820?hl=en)
