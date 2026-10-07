# 10. EMPLOYEE — Traceability

**Implemented slice (2026-10-08):** 10-02 (profile depth, structure links), 10-06 (service book),
10-07, 10-08 (departments/designations), 10-11 (attendance list/day sheet), 10-12 (manual entry,
reason mandatory), 10-14 (correction of a saved row — audited, reason mandatory), 10-16 (unapplied
absence tracked; retroactive approval reconciles), 10-18 (month-wise summary per employee),
10-19 (request → approval → balance → attendance chain, balance enforced), 10-20 (calendar),
10-21 (balances ledger), 10-22 (leave types incl. half-day entitlement), and 10-47's foundation
(headcount/attendance inputs). Payroll (10-25…10-33), overtime (10-34), loans (10-35), bonuses
(10-36), performance (10-37…10-39), training/recruitment/disciplinary/policies (10-40…10-43)
and gratuity/PF (10-46) remain planned — see `docs/REMAINING_WORK.md`.

**Known deviations in the implemented slice (honest, not silent):**
- Attendance is entered manually or by import; a biometric/GPS device integration does not exist
  (10-13 GPS configuration and the `device` source are reserved, nothing writes them yet).
- Late *fines* are not implemented: lateness is measured and reported, the deduction rule engine
  belongs to payroll (10-15 half done — detection yes, fine rule no).
- Overtime minutes are recorded and summed (10-17 detection only); the ×2 holiday-work OT
  entitlement is a payroll rule and is not applied here.
- 10-06 service book renders the real chronology from attendance/leave/documents, but career
  entries (transfer/promotion) do not exist yet because those actions (10-04) are not built.
- Approvals run on the direct permission `leave.approve`, not yet through the shared
  Workflow engine (approval_requests); wiring to WF comes with 10-25/10-26.

Baseline (`BL`) applies. Key spec rules enforced here: employee type ≠ hardcoded role (employee links to user + DB-driven roles); Leave flow `Leave Request → Approval → Leave Balance → Attendance Interpretation → Payroll`; absent without approved leave = auditable **unapplied absence**; retroactive leave approval reconciles attendance; salary deduction rules DB-driven (Super Admin controls formula + effective date) with understandable breakdown notification; **Salary acknowledgement flow**: `Salary Prepared → Approved → Payment Recorded → Awaiting Acknowledgement → Employee "I Received" → Acknowledged` — state persisted in DB, survives refresh/logout/login/restart; unacknowledged notice visible to Super Admin/Admin.

Shared services: `Hr\Actions\{CreateEmployee, TransferEmployee, PromoteEmployee, IncrementSalary, ResignEmployee, ExitEmployee, IssueExperienceLetter, RecordAttendance, OverrideAttendance, RegularizeAttendance, ApplyLeave, ApproveLeavePath (Workflow), GeneratePayroll, ApprovePayroll, RecordSalaryPayment, AcknowledgeSalary, RequestOvertime, ApproveOvertime, RequestLoan, ApproveLoan, CreateBonus, SaveDeductionRule, CreateTraining, PostJobApplication, RecordDisciplinaryAction, ProvisionGratuity, ProcessExitSettlement}` · `Hr\Services\{AttendanceService, LeaveBalanceService, AbsenceReconciler, PayrollCalculator, DeductionRuleEvaluator, AcknowledgementService, ServiceBookService, GratuityPfService}` · engines: Workflow, Documents (payslips/letters), Notification, Accounting (payroll/expense postings), Audit.

## 10.1 Employees

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 10-01 | Employee › Employees › All / Active / Inactive | `GET /employees?status=` | `employees.view` | `EmployeeController@index`, `EmployeeQuery` facets | `employees`, `departments`, `designations` | AUD | `EmployeeListTest` (3 facets), `EmployeeScopeTest` | DONE |
| 10-02 | Employee › Employees › Add Employee | `GET|POST /employees/create` | `employees.create` | `CreateEmployee` (optional user link, branch, grade; role association DB-driven) | `employees`, `users`, `employee_bank_details` | AUD; role≠type assertion | `CreateEmployeeTest`, `EmployeeTypeNotRoleTest` | DONE |
| 10-03 | Employee › Employees › Employee Profile / Documents / Bank Details / ID Cards | `GET|PUT /employees/{id}/*` | `employees.view/edit` | profile CRUD, secure document pipeline, encrypted bank fields, ID card DOC generator | `employees`, `employee_documents`, `employee_bank_details`, `id_cards`, `documents` | AUD diff; secrets encrypted | `EmployeeProfileTest`, `EmployeeBankEncryptedTest`, `EmployeeIdCardPrintTest` | IN PROGRESS |
| 10-04 | Employee › Employees › Employee Transfer / Promotion / Salary Increment | `POST /employees/{id}/{transfer,promote,increment}` | `employees.career` (+ WF) | `TransferEmployee`/`PromoteEmployee`/`IncrementSalary` (effective-dated records, grade change) | `employees`, `service_book_entries`, `salary_grades` | WF approval; salary change effective date drives payroll; AUD | `EmployeeTransferTest`, `PromotionEffectiveDatedTest`, `SalaryIncrementApprovalTest` | PLANNED |
| 10-05 | Employee › Employees › Employee Resignation / Exit / Experience Letter | `POST /employees/{id}/{resign,exit,experience-letter}` | `employees.exit` (+ WF) | `ResignEmployee`/`ExitEmployee` (last working day, clearance checklist, user deactivation) → `ProcessExitSettlement`; `IssueExperienceLetter` DOC | `exits`, `service_book_entries`, `users`, `documents` | WF; ACCT settlement posting; DOC letter; NOT to HR; AUD | `ResignationWorkflowTest`, `ExitDeactivatesUserTest`, `ExperienceLetterTest` | PLANNED |
| 10-06 | Employee › Service Book | `GET /employees/{id}/service-book` | `employees.view` | `ServiceBookService` (chronological career/leave/award entries) | `service_book_entries`, `attendance_records`, `leave_requests` | DOC printable service book; AUD | `ServiceBookTest` (aggregates real history) | DONE |
| 10-07 | Employee › Departments › All / Add | `GET|POST /employees/departments` | `employees.departments` | `DepartmentController` (hierarchy) | `departments` | AUD | `DepartmentCrudTest` | DONE |
| 10-08 | Employee › Designations › All / Add | `GET|POST /employees/designations` | `employees.designations` | `DesignationController` | `designations` | AUD | `DesignationCrudTest` | DONE |
| 10-09 | Employee › Salary Grades | `GET|POST /employees/salary-grades` | `employees.salary_grades` (+ WF) | grade + step CRUD; feeds payroll base | `salary_grades`, `salary_grade_steps` | WF change approval; AUD | `SalaryGradeCrudTest`, `SalaryGradeChangeApprovalTest` | PLANNED |

## 10.2 Attendance

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 10-10 | Employee › Attendance › Live Attendance | `GET /attendance/live` | `attendance.view` | `AttendanceService@live` (in/out state from records) | `attendance_records` | AUD login/out attendance events | `LiveAttendanceTest` | PLANNED |
| 10-11 | Employee › Attendance › Attendance List | `GET /attendance` | `attendance.view` | `AttendanceQuery` (filters: date/branch/dept/status) | `attendance_records` | scope-filtered | `AttendanceListTest` | DONE |
| 10-12 | Employee › Attendance › Manual Attendance Entry | `POST /attendance/manual` | `attendance.manual` | `RecordAttendance` manual (reason mandatory) | `attendance_records` | WF optional; AUD before/after | `ManualAttendanceTest` | DONE |
| 10-13 | Employee › Attendance › GPS Configuration | `GET|PUT /settings/attendance-gps` | `attendance.configure` | geofence/radius settings, consent records | `settings`, `attendance records meta` | AUD config; privacy: approved location only | `AttendanceGpsConfigTest` | PLANNED |
| 10-14 | Employee › Attendance › Manual Override / Regularization | `POST /attendance/{id}/override`, `POST /attendance/regularize` | `attendance.override` (+ WF) | `OverrideAttendance`/`RegularizeAttendance` (employee request → WF → adjust) | `attendance_overrides`, `approval_requests` | WF; retroactive reconciliation with leave; AUD mandatory | `AttendanceOverrideApprovalTest`, `RegularizationReconcilesLeaveTest` | PARTIAL |
| 10-15 | Employee › Attendance › Late Arrivals / Late Fine Rule | `GET /attendance?view=late`, `GET|PUT /attendance/late-fine-rules` | `attendance.view` / `attendance.configure` | late computed from shift + grace; fine rule = DB-driven deduction rule with effective date | `attendance_records`, `work_shifts`, `deduction_rules` | feeds payroll deduction; AUD config | `LateArrivalDetectionTest`, `LateFineRuleAppliedFromDbTest` | PARTIAL |
| 10-16 | Employee › Attendance › Absent Report (incl. unapplied absence) | `GET /attendance/absent` | `attendance.view` | `AbsenceReconciler` report: absent dates without approved leave → **unapplied_absence** state retained | `attendance_records`, `leave_requests` | reconciliation on retroactive leave approval; AUD | `UnappliedAbsenceRetainedTest`, `RetroactiveLeaveReconcilesAbsenceTest` | DONE |
| 10-17 | Employee › Attendance › Holiday Work (Double OT) | `GET /attendance/holiday-work` | `attendance.view` | holiday/halfday work flagged from `holidays` → OT entitlement ×2 per rule | `attendance_records`, `holidays`, `overtime_requests` | ACCT via payroll OT earning | `HolidayWorkDoubleOtTest` | PARTIAL |
| 10-18 | Employee › Attendance › Attendance Reports | `GET /reports/hr/attendance` | `attendance.reports` | `Reporting\AttendanceReport` (punctuality, present%, exports) | `attendance_records` | DOC; AUD | `AttendanceReportTest` | DONE |

## 10.3 Leave Management

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 10-19 | Employee › Leave Management › Leave Requests / Leave Approval | `GET|POST /leave`, `GET /leave/approvals` | `leave.create` / `leave.approve` | `ApplyLeave` → ApprovalEngine; `ApproveLeave` (WF decision → balance deduction → AbsenceReconciler retro pass) | `leave_requests`, `approval_requests`, `leave_balances`, `attendance_records` | WF core flow; NOT to employee/manager; AUD decision; balance persisted | `LeaveRequestApprovalTest`, `LeaveApprovalUpdatesBalanceTest`, `LeaveRejectRestoresBalanceTest`, `LeaveMakerCheckerTest` | DONE |
| 10-20 | Employee › Leave › Leave Calendar | `GET /leave/calendar` | `leave.view` | calendar view of approved/pending leave (branch/dept filters) | `leave_requests` | scope | `LeaveCalendarTest` | DONE |
| 10-21 | Employee › Leave › Leave Balances | `GET /leave/balances` | `leave.view` | `LeaveBalanceService` (entitlement−taken+encashed, period carry-over rules) | `leave_balances` | derived + persisted; reconciliation op | `LeaveBalanceCalculationTest`, `LeaveCarryOverTest` | DONE |
| 10-22 | Employee › Leave › Leave Types | `GET|POST /masters/leave-types` | `masters.manage` (+ `leave.types`) | type CRUD (paid/unpaid, carry-over, accrual, gender/holiday rules) | `leave_types` | AUD config | `LeaveTypeCrudTest` | DONE |
| 10-23 | Employee › Leave › Leave Encashment | `POST /leave/{id}/encash` | `leave.encash` (+ WF) | `EncashLeave` (balance×per-day rate → payroll earning or payment) | `leave_encashments`, `payroll_items` | WF; ACCT via payroll; DOC; AUD | `LeaveEncashmentTest` | PLANNED |
| 10-24 | Employee › Leave › Leave Reports | `GET /reports/hr/leave` | `leave.reports` | liability, pattern, balance reports | `leave_requests`, `leave_balances` | DOC | `LeaveReportTest` | PLANNED |

## 10.4 Payroll

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 10-25 | Employee › Payroll › Payroll Runs / Generate Payroll | `GET|POST /payroll/runs` | `payroll.run.create` | `GeneratePayroll` (inputs: grade base + attendance + leave + OT + advances recovery + DB deduction rules; period-scoped, idempotent run) | `payroll_runs`, `payroll_items`, `attendance_records`, `leave_requests`, `deduction_rules` | WF submit; **no GL until payment posting**; AUD run generation | `GeneratePayrollTest` (inputs real), `PayrollRunIdempotencyTest`, `PayrollUsesAttendanceAndLeaveTest` | PLANNED |
| 10-26 | Employee › Payroll › Payroll Approval | `GET /payroll/approvals` | `payroll.approve` | ApprovalEngine (amount thresholds, SoD) | `approval_requests`, `payroll_runs` | WF maker-checker; AUD | `PayrollApprovalTest`, `PayrollSelfApprovalBlockedTest` | PLANNED |
| 10-27 | Employee › Payroll › Salary Payment | `POST /payroll/runs/{id}/pay` | `payroll.pay` (+ WF) | `RecordSalaryPayment` (bank/cash/BEFTN; idempotency; moves run → `ack_pending`) | `payroll_runs`, `payments`, `journal_entries` | ACCT: Dr Salary expense, Cr Cash/Bank (net) + deductions payable; **triggers acknowledgement requirement**; NOT payslip+notice; AUD | `SalaryPaymentPostingTest`, `SalaryPaymentIdempotencyTest`, `PaymentMovesRunToAckPendingTest` | PLANNED |
| 10-28 | Employee › Payroll › Payslips | `GET /payroll/runs/{id}/payslips`, `GET /payslips/{id}` | `payroll.payslip.view` (own payslip for employee w/o special perm) | `DocumentRenderer@payslip` (earnings/deductions breakdown, EN/BN) | `payroll_items`, `documents` | DOC; AUD print; **employee sees only own payslip** | `PayslipRenderTest`, `PayslipOwnershipScopeTest` | PLANNED |
| 10-29 | Employee › Payroll › Salary Sheet Print / Salary Register | `GET /payroll/{sheet,register}` | `payroll.reports` | DOC generators (branch/period) | `payroll_runs`, `payroll_items` | DOC; AUD | `SalarySheetPrintTest`, `SalaryRegisterTest` | PLANNED |
| 10-30 | Employee › Payroll › Salary TDS (Section 50) | `GET /payroll/tds` | `payroll.reports` | TDS deduction lines from rules → tax register linkage | `payroll_items`, `tds_registers` | ACCT withholding | `PayrollTdsTest` | PLANNED |
| 10-31 | Employee › Payroll › Employee Advance Recovery | `GET /payroll/advance-recovery` | `payroll.run.create` | recovery schedule consumption into payroll items | `employee_loans`, `loan_repayments`, `payroll_items` | ACCT clearing; AUD | `AdvanceRecoveryTest` (schedule respected) | PLANNED |
| 10-32 | Employee › Payroll › Payroll History | `GET /payroll/history` | `payroll.view` | run history with state timeline | `payroll_runs` | AUD | `PayrollHistoryTest` | PLANNED |
| 10-33 | Employee › Payroll › **Salary Acknowledgement ("I Received")** | employee portal `GET /portal/payroll/ack`, `POST /payroll/ack/{id}` | `payroll.ack.own` (employee, self only) | `AcknowledgeSalary` (persist employee, datetime, device/session meta, payroll ref, audit) + persistent portal modal rendered from DB state until acknowledged; admin inspection view for Super Admin/Admin | `payroll_acknowledgements`, `payroll_runs`, `audit_events`, `notifications` | **DB-persisted: survives refresh/logout/login/browser restart**; idempotency key; NOT reminder rules to unacknowledged; AUD acknowledgement | `SalaryAcknowledgementPersistencyTest` (refresh+logout+login+restart session), `SalaryAcknowledgementIdempotencyTest`, `UnacknowledgedAdminInspectionTest`, `AcknowledgementNotJsOnlyTest` (server returns ack state), `AcknowledgementNotificationOverlayTest` | PLANNED |

## 10.5 Overtime, Loans, Incentives

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 10-34 | Employee › Overtime › Overtime Requests / Approval / Register | `GET|POST /overtime`, `GET /overtime/approvals` | `overtime.create/approve` | `RequestOvertime` → WF → `ApproveOvertime` (hours×rate from shift/rule) | `overtime_requests`, `approval_requests` | WF; feeds payroll OT earning; AUD; NOT decisions | `OvertimeApprovalTest`, `OvertimeFeedsPayrollTest` | PLANNED |
| 10-35 | Employee › Loans & Advances › Loan Requests / Loan Approval / Salary Advances / EMI Schedule | `GET|POST /employee-loans`, `GET /employee-loans/approvals` | `loans.request/approve` | `RequestLoan` → WF → `ApproveLoan` (disbursement + `emI_schedules`) | `employee_loans`, `approval_requests`, `emI_schedules`, `journal_entries` | WF; ACCT disbursement; recovery in payroll; DOC schedule; AUD | `EmployeeLoanApprovalTest`, `LoanEmiScheduleTest`, `SalaryAdvanceRecoveryTest` | PLANNED |
| 10-36 | Employee › Incentives & Bonuses › Festival (Eid-ul-Fitr/Eid-ul-Adha) / Performance Bonus / Sales Commission | `GET|POST /employee-bonuses` | `bonuses.create` (+ WF) | `CreateBonus` (rule-based or individual; festival templates DB-driven, not hardcoded) | `bonuses`, `payroll_items`, `commission_*` | WF; ACCT via payroll; NOT breakdown to employee; AUD | `FestivalBonusTest`, `PerformanceBonusTest`, `SalesCommissionBonusLinkTest` | PLANNED |

## 10.6 Performance, Training, Recruitment, Disciplinary, Policies, Shifts, Holidays, Gratuity

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 10-37 | Employee › Performance › KPI Management | `GET|POST /hr/kpis` | `hr.kpis` | KPI CRUD + targets (employee/role scoped) | `kpi_definitions` | AUD | `KpiCrudTest` | PLANNED |
| 10-38 | Employee › Performance › Performance Reviews / Manager Review | `GET|POST /hr/reviews` | `hr.reviews` (+ WF) | review cycles, ratings, calibration | `performance_reviews`, `approval_requests` | WF sign-off; AUD | `PerformanceReviewTest` | PLANNED |
| 10-39 | Employee › Performance › Self Assessment | `POST /hr/reviews/{id}/self` | `hr.self_assessment` (own record only) | self-assessment submission (immutable after deadline) | `self_assessments` | AUD | `SelfAssessmentScopeTest` | PLANNED |
| 10-40 | Employee › Training › Training Sessions / Create Training / Certificates | `GET|POST /hr/trainings` | `hr.trainings` | session CRUD, enrollment, certificate DOC generation (verify code) | `trainings`, `training_certificates`, `documents` | DOC certificates; NOT reminders; AUD | `TrainingCrudTest`, `TrainingCertificateVerifyTest` | PLANNED |
| 10-41 | Employee › Recruitment › Job Postings / Applications / Interviews / Offer Letters | `GET|POST /hr/recruitment/*` | `hr.recruitment` | posting CRUD, application intake (portal-optional), interview pipeline states, offer DOC | `job_postings`, `applications`, `interviews`, `offer_letters` | WF offer approval; DOC offer; AUD; external job boards = adapters (truthful not-configured) | `JobPostingTest`, `ApplicationPipelineTest`, `OfferLetterApprovalTest` | PLANNED |
| 10-42 | Employee › Disciplinary › Warning Letters / Show Cause / Suspensions | `GET|POST /hr/disciplinary` | `hr.disciplinary` (+ WF) | `RecordDisciplinaryAction` (document templates, response capture) | `disciplinary_actions`, `documents` | WF (show-cause flow); DOC; AUD mandatory; sensitive-view protection | `DisciplinaryActionTest`, `ShowCauseWorkflowTest` | PLANNED |
| 10-43 | Employee › HR Policies | `GET|POST /hr/policies` | `hr.policies` | policy CRUD + versioning + acknowledgement tracking | `hr_policies`, `notice_acknowledgements` | DOC; NOT publish; AUD | `HrPolicyVersioningTest`, `PolicyAcknowledgementTest` | PLANNED |
| 10-44 | Employee › Work Shifts | `GET|POST /hr/shifts` | `hr.shifts` | shift CRUD (times, grace, rotation) feeding attendance/OT rules | `work_shifts` | AUD config | `WorkShiftCrudTest`, `ShiftDrivesLateOtRulesTest` | PLANNED |
| 10-45 | Employee › Holiday Calendar | `GET|POST /hr/holidays` | `hr.holidays` | BD holidays master + company holidays (branch-scoped) | `holidays`, `branches` | feeds attendance/leave/OT; AUD | `HolidayCalendarCrudTest`, `HolidayAffectsAttendanceTest` | PLANNED |
| 10-46 | Employee › Gratuity & PF › Gratuity Provisions / Provident Fund / Exit Settlements | `GET|POST /hr/{gratuity,pf,exit-settlements}` | `hr.gratuity` (+ WF) | `ProvisionGratuity` (formula config, service-based calc shown), PF contributions, `ProcessExitSettlement` | `gratuity_provisions`, `pf_accounts`, `pf_transactions`, `exits`, `journal_entries` | WF settlement approval; ACCT provision/payment; DOC settlement letter; AUD | `GratuityCalculationTest`, `PfContributionTest`, `ExitSettlementApprovalTest` | PLANNED |
| 10-47 | Employee › HR Reports | `GET /reports/hr/*` (headcount, turnover, attendance %, leave liability, payroll cost by branch/dept) | `hr.reports` | `Reporting\HrReports` | `employees`, `attendance_records`, `payroll_runs`, `leave_requests` | DOC export; totals from source; AUD | `HrReportsTest` (5 families) | PLANNED |
