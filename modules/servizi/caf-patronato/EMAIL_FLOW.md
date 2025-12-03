# CAF/Patronato Email Flow

This document explains the updated notification pipeline for the CAF/Patronato module, how the UI behaves when resending customer emails, and what to monitor in the dedicated log.

## 1. Overview
- Operator notifications and customer confirmations are now sent through `App\Services\CAFPatronato\PracticesService` to keep business logic in one place.
- Every email attempt (operator + customer) is mirrored to `logs/patronato_mail.log` via `caf_patronato_log_mail_event()`.
- The create form blocks notifications if no primary Patronato operator is configured; the practices table and resend modal surface missing customer emails so operators can fix data before sending.

## 2. Operator Notification Flow
1. `modules/servizi/caf-patronato/create.php` resolves the **primary Patronato operator** through `caf_patronato_primary_operator()`.
2. If the operator (or their email) is missing the form forces a warning, disables the notification checkbox, and legacy sync is skipped.
3. When present, the operator metadata is forwarded to `PracticesService`, which dispatches the internal notification email and logs the event (`type = operator_mail`).

## 3. Customer Email + Resend UI
- The practices list now shows a dedicated **Email cliente** column. Saved addresses render as light badges, missing contacts show a red warning with helper text.
- The "Reinvia" action button switches styling when no email is stored so operators can immediately see they must type an address.
- The resend modal summarizes the last known recipient (if any) and prevents confirmation until a valid email is provided when none is stored. Inline validation shares the same `invalid-feedback` block used for server errors, so the operator receives immediate guidance.
- Successful resend calls display the actual destination inside the toast (`Email inviata a foo@example.com`).

## 4. Logging & Troubleshooting
- **Path**: `logs/patronato_mail.log` (configurable via `CAF_PATRONATO_MAIL_LOG_PATH`). The file is created automatically if it does not exist.
- **Line format**: `[ISO8601 timestamp][TYPE][OK|KO] recipient {json context}`. Example:
  ```
  [2025-12-03T10:42:17+01:00][CUSTOMER_MAIL][OK] assistito@example.com {"practice_id":123,"message":"customer-confirmation"}
  ```
- **Inspect**: `tail -f logs/patronato_mail.log` (from project root) while re-sending to confirm both operator and customer events.
- **Rotation**: integrate the file with the existing logrotate job or, manually, `cp logs/patronato_mail.log logs/patronato_mail.log.$(date +%Y%m%d)` followed by `: > logs/patronato_mail.log`. Always make sure the web user still owns the file after truncation.
- **Failure workflow**: a `KO` entry logs the error message returned by the mailer; check `storage/logs/laravel.log` (if applicable) and retry via the UI once the root cause is fixed.

## 5. Test Coverage
Run `./vendor/bin/phpunit tests/Services/CAFPatronato/PracticesServiceTest.php` to ensure service-level regressions are caught. Frontend behavior is exercised manually via the practices dashboard; once deployed, confirm the resend modal enforces validation and that `patronato_mail.log` receives both successful and failing attempts.
