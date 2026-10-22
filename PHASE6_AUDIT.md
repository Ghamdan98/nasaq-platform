# Phase 6 audit — 18 October 2026

## Follow-up: employee selection

The user encountered `The selected employee id is invalid`. Read-only inspection found zero employees and zero contracts in the configured local SQLite database. No synthetic records were added to it. The calculator now loads existing employees and their contracts into selectors; changing the employee clears the contract. Final and leave calculations support simulation without an employee, while saving still requires a registered employee and the UI disables save for simulations. Supplied nonexistent IDs continue to fail validation. The empty employee list explains the simulation/save distinction. Final employee/contract validation messages are Arabic.

Follow-up verification: `php artisan test` passed **121 tests / 470 assertions**; `npm run build` passed; the isolated real HTTP/Chrome suite passed **6 tests**, including both simulations without an employee, disabled save, selecting registered records, saving, approval, adjustments and exports. The normal frontend and updated calculator module both responded HTTP 200. Earlier counts below document the initial audit run.

**OVERALL VERDICT: PARTIALLY OPERATIONAL.** The implemented final-settlement and leave workflows run through a real Chrome browser, React, HTTP, Laravel, and an isolated database. This is not a finding that every legal scenario or every navigation module is implemented.

## Required findings

| # | Area | Result | Evidence and limitations |
|---|---|---|---|
| 1 | Environment | PASS | PHP 8.2.12, Composer 2.10.1, Node 24.16.0, npm 11.13.0, Laravel 12.70.0, Vite 8.3.4. Laravel boots; local debug is enabled. |
| 2 | Database | PASS / PARTIAL | Actual configured connection is SQLite (`database/database.sqlite`), with all 10 migrations applied. MySQL TCP port 3306 is reachable, but MySQL credentials/schema were not tested because the application is not configured to use it. |
| 3 | Backend availability | PASS | Routes registered; real requests served by the isolated PHP API at `127.0.0.1:8001`. The initially attempted sandboxed `artisan serve` on 8000 did not yield a reachable API. Direct PHP serving with `public/index.php` succeeded. |
| 4 | Frontend availability | PASS | Vite reported actual development ports 5173/5174. Final browser verification uses the production build served by Vite preview at 5174. `/api` proxies to the isolated backend. |
| 5 | Authentication | PASS / PARTIAL | Real HR, reviewer, and admin logins exercised. Sanctum bearer authorization works. Invalid/unauthenticated requests are rejected. Public registration can no longer assign admin. Login rejects inactive accounts. UI logout revokes the current token; authenticated 401 responses clear browser credentials. Local-storage token exposure to injected JavaScript remains a risk. |
| 6 | Browser console/network | PASS | Main final and admin journeys collect uncaught page errors and finish with none. Success requests reach real Laravel. Validation returns 422. A separate, deliberately aborted request verifies error display. Earlier runs exposed missing imports, helper scope, test selectors, and test-server configuration problems; they were repaired before successful verification. |
| 7 | API contract | PASS / PARTIAL | Both calculation routes validate final payloads; HR dispatch retains transaction type. Save uses the same FormRequest hooks, recalculates, and ignores client totals. Employee/contract consistency and reversed service/leave dates are validated. Unsupported termination strings return 422. Advanced legal verification fields and purpose-specific wage exclusions are not fully exposed in the UI. |
| 8 | Settlement calculations | PASS for tested assumptions | Both real HTTP calculation endpoints return EOS 12,000 (4 years), 27,000 (7 years), 4,000 (4-year resignation), 18,000 (7-year resignation), and 45,000 (10-year resignation), on a 6,000 wage. These are EOS amounts, not an assumption about the entire settlement. |
| 9 | Leave calculations | PASS for tested inputs | Opening 20 + accrued 10 − used 5 = available 25; requested 10 leaves 15; daily wage 200 and payable 2,000. React displays backend balances and money. Leave mode hides termination fields. Active-service cash replacement is blocked by the engine and workflow restrictions. Accrual is supplied as input; automatic statutory accrual is not implemented. |
| 10 | Bugs discovered | REPAIRED / PARTIAL | See defect register below. Legal and module limitations remain explicitly listed. |
| 11 | Root causes | IDENTIFIED | Contract fields discarded during validation, engine dispatch defaulting to final settlement, missing icon import, nested download helper, wrong response labels, stale calculation state, wrong wage-base wiring, financial rounding inconsistency, and missing authorization checks. |
| 12 | Files changed | RECORDED | See file inventory below. No business database reset or migration was performed. |
| 13 | Regression tests | PASS | Added `Phase6AuditTest.php` (15 tests), real HTTP scenarios, four browser journeys, isolated fixture seeder, and Playwright configuration. Existing assertions were retained. Three existing history tests now authenticate as their saved transaction owner instead of an unrelated HR account, matching the repaired privacy boundary. |
| 14 | Persistence | PASS | Calculation-only calls leave transaction counts unchanged. Final and leave saves return 201, create financial records/snapshots/references, and reopen. Browser and backend tests verify save/reload. |
| 15 | Adjustments | PASS | Browser admin changes calculated EOS 18,000 to approved 17,500; original remains 18,000; difference is −500. Revert succeeds. Backend tests cover reasons, totals, adjustment audit, original preservation, and locked approved records. |
| 16 | Workflow | PASS for implemented paths | Browser HR submits and reviewer approves. Backend tests cover rejection, reasons, changes requested, cancellation, legal-review restrictions, locks, reopen permissions, version snapshots, audit history, and conflicting second approval. |
| 17 | Security | PARTIAL | HR history/details/submit and legacy history are scoped to the owner; exports already enforce ownership. Reviewer cannot adjust financial items or mutate employees/contracts/salary sources. Rule writes, global audit reads, and employee deletion now require admin. Legacy zero-value save is disabled with explicit HTTP 410 guidance. Public self-registration still creates HR accounts; account provisioning and full deployment hardening need an organizational policy. |
| 18 | Exports | PASS / PARTIAL | XLSX and PDF actually exist. Browser downloads verify ZIP/XLSX and `%PDF` signatures. Existing backend export tests inspect seven workbook sheets, saved values, adjustments, references, workflow state, and audit events. PDF uses mPDF with A4/RTL/Arabic shaping configured. Arabic print was visually inspected with saved values, warnings and signatures, and no navigation under print media. PDF glyph placement and physical A4 pagination have not been visually certified. |
| 19 | PHPUnit | PASS | Final completed full run: **119 tests, 452 assertions**, 8.44 seconds. Initial baseline was 104 tests, 381 assertions. An intervening concurrent run failed from Windows paging-file exhaustion and was superseded by the completed sequential run. |
| 20 | Frontend build | PASS | `cd frontend; npm run build` succeeds, transforming 1,933 modules. Sandbox-only attempts failed from child-process restrictions; permitted execution succeeded. `npm run lint` exits 0 with eight warnings (unused imports and existing effect/dependency warnings). |
| 21 | Browser E2E / HTTP | PASS | `E2E_BASE_URL=http://127.0.0.1:5174 npm run test:e2e`: five tests: one real HTTP matrix and four Chrome journeys. No success-path API mocks. The failure-path test intentionally aborts one request. See `frontend/e2e/README.md` for exact PowerShell commands. |
| 22 | Working features | PASS | Authentication, final calculator, supplied leave balances, backend financial results, save/history/details, authorized adjustments/revert, review/approval, version/audit services, XLSX/PDF download, saved print document. |
| 23 | Partially working features | PARTIAL | Legal-rule administration has API endpoints but no complete UI and is not dynamically integrated into the static calculation rules. Legal exclusions require further verified policy. Retirement has backend handling with notice legal review but no complete UI journey. Print and PDF require final pagination review. |
| 24 | Missing features | NOT IMPLEMENTED | Dashboard, employee/contract management screens, legal rules screen, settings, header search, notification service, profile menu, and help module. Their dead navigation actions are disabled/labeled unavailable. Contract expiry, mutual agreement, Article 77, death, disability, force majeure, and business closure do not have complete dedicated transaction calculations. Unsupported API codes are rejected rather than shown as valid. Employer termination uses the supported `termination` code; employee resignation uses `resignation`. |
| 25 | Remaining blockers | PARTIAL | No claim of universal legal completeness, MySQL integration, production deployment, duplicate-save idempotency, or concurrent reference-allocation safety. Static legal references/effective dates and all specialized legal scenarios require substantive review. Environment disk/paging capacity is low and caused interrupted installs/test attempts. |

## Defect register and repairs

| Observed failure | Root cause | Repair / regression evidence |
|---|---|---|
| Leave page crashes | `CalendarDays` used without import | Import restored; real browser leave journey. |
| Leave calculate runs final settlement | `transaction_type` removed from validated payload | Explicit validated dispatch; real HTTP and browser balance assertions. |
| Final HR calculate accepts invalid fields or fails with exceptions | It passed raw request data directly into the engine | Resolve correct FormRequest; unknown type/negative salary regressions. |
| Save bypasses employee/contract and date hooks | Only `rules()` were reused | Resolve FormRequest during save; reversed-service and leave-period regression tests. |
| Excel/PDF button throws undefined-helper error | `download()` was inside `request()` | Helper moved to module scope; real downloads and signature checks. |
| Financial rows lose Arabic item names/status | React expected `title`/`valid`, backend sends `name_ar`/`entitled` | Map actual fields/statuses; browser asserts EOS row amount. |
| Leave summary gross is zero; balance/daily wage misleading | Response is `gross_leave_amount`; frontend used final-only fields and calculated daily wage itself | Use backend response, render leave payable row and remaining balance. |
| Editing inputs can save old results | Form edits did not invalidate `calculatedForm` | Clear results/save state on edit; browser regression. |
| Leave compensation chooses wrong day field | PHP `??`/comparison precedence and incomplete overdraw check | Parenthesized subtype comparison, validate both quantities; API regression. |
| Leave and notice use wrong wage | Engine ignored purpose-specific salary bases | Wire leave/notice bases; regression verifies distinct EOS/leave/notice/salary items. |
| Resignation receives employer-paid notice money | Positive compensation entered entitlements despite `not_entitled` | Zero employee entitlement and require review for missing employee notice; regression. No automatic debt deduction is invented. Nonordinary/fixed-contract notice cases remain review-only. |
| Rounded totals differ after save | Deduction totals summed unrounded values while persistence stores rounded line items | Sum rounded line amounts; fractional-cent save/calculate regression. |
| Zero leave forces legal review | Zero treated as missing | Remove false warning; zero-balance regression. |
| Registration grants admin | Caller could choose role | Force public registrations to HR; privilege-escalation regression. |
| HR reads another HR user's transactions | History/show lacked ownership constraints | Owner scope and forbidden cross-owner regression; submit also scoped. |
| Reviewer can mutate financial sources; global audit exposed | CRUD endpoints only required authentication | Explicit role checks and regression across six resources plus audit. |
| Legacy save fabricates an approved zero record | Legacy store accepted arbitrary workflow status and wrote zeros | Explicit 410 response directing clients to `/api/hr-transactions`; regression. Legacy records remain readable within permission scope. |
| Silent malformed backend response | JSON parsing swallowed failures | Explicit response error; credential expiry resets session UI. |
| Fake identities, precomputed service and dead controls | Static mock inputs and unbound buttons | Remove fake fields/service period, require actual record IDs for save, disable unavailable controls, add real logout, show backend salary base. |
| Print exposes raw ISO timestamps | Raw date fields rendered in RTL text | Format transaction/last-working dates; browser checks ISO timestamp suffix is absent. |

The notice correction was cross-checked against [HRSD Articles 75–76](https://www.hrsd.gov.sa/fil/node/5576001): compensation is owed by the party that fails to give the required notice. This audit does not certify the entire legal rule set.

## Financial assumptions and coverage

Service intervals exclude an additional inclusive end day: exact anniversary dates produce exact years. Partial service uses the existing years + months/12 + days/365 convention. The real HTTP leap-year case `2020-02-29` to `2024-05-15` returns 4 years, 2 months, 16 days, 1,537 elapsed days, and EOS **12,631.51** at wage 6,000. That convention is documented, not represented as a verified universal legal day-count rule.

BCMath keeps authoritative money in Laravel. Tests cover ordinary and resignation awards, unverified Articles 80/81, leave overdraw/blocking, zero balances, negative inputs, supplied salary components/exclusions, multiple and fractional deductions, calculated-versus-approved values and gross-minus-deductions totals. The API supports outstanding salary days/payment inputs; the frontend now asks explicitly for unpaid days (default zero), leave balance, loans and advances instead of silently assuming an unpaid month. Commission/allowance exclusion authority is not established merely by supplying a boolean and reason.

## Changed source inventory

- Backend controllers: `AuthController`, `HrTransactionController`, `SettlementController`, `AuditLogController`, `EmployeeController`, `ContractController`, `SalaryComponentController`, `LaborRuleController`, `SettlementRuleController`, `TerminationTypeController`.
- Requests: `SettlementCalculationRequest.php`, `LeaveSettlementCalculationRequest.php`.
- Services: `SettlementEngine.php`, `LeaveSettlementEngine.php`, `LeaveSettlementCalculator.php`, `LeaveBalanceCalculator.php`, `NoticePeriodCalculator.php`, `DeductionCalculator.php`, `TransactionWorkflowService.php`.
- React: `App.jsx`, `services/api.js`, `SettlementCalculatorPage.jsx`, `TransactionPrintPage.jsx`, `AppHeader.jsx`, `AppSidebar.jsx`, `utils/labels.js`.
- Verification/config: `Phase6AuditTest.php`, owner fixtures in `HrTransactionPersistenceTest.php`, `frontend/e2e/*`, `frontend/playwright.config.js`, `frontend/vite.config.js`, frontend package/lock files and ignore files.

## Commands and evidence

Executed: `php -v`, `composer --version`, `node -v`, `npm -v`, `php artisan about`, `php artisan route:list` (45 routes), `php artisan migrate:status` (10 applied migrations), and `php artisan config:clear` (success).

Final backend evidence: `php artisan route:list > storage/logs/phase6-routes.txt`; `php artisan test > storage/logs/phase6-phpunit.txt`. Full result: 119 passed / 452 assertions. Final frontend: `cd frontend; npm run build`; `npm run lint`; isolated `npm run test:e2e`. Browser fixture setup and exact service commands are in `frontend/e2e/README.md`.

Business data was not reset, seeded, truncated or used for destructive tests. PHPUnit uses `:memory:` SQLite; browser/API writes use only `database/phase6-e2e.sqlite`. No secrets or employee records are included in this report.
