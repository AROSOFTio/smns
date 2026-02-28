# Control-to-System Mapping

This mapping links governance controls to policy artifacts, SOP execution records, and in-system technical evidence.

## 1. Documented Data Governance Policy

### Policy Artifacts

- `docs/governance/policies/Data-Governance-Policy.md`
- `docs/governance/policies/Roles-and-Responsibilities-Policy.md`

### In-System Evidence

- Role model and status: `Seed/schema.sql` (`users.role`, `users.status`)
- Session isolation and role validation architecture:
  - `core/session check/Role-Based-Session-Isolation-Architecture.md`
- Finance data governance lifecycle states:
  - `core/FeeStructureGovernance.php` (draft, pending_approval, approved, published, archived)

### Audit Record Template

- `docs/governance/templates/Audit-Evidence-Register.csv`

---

## 2. Defined Roles and Responsibilities (Academic, Finance, IT)

### Policy Artifacts

- `docs/governance/policies/Roles-and-Responsibilities-Policy.md`
- `docs/governance/sops/SOP-Access-Provisioning-and-Role-Review.md`

### In-System Evidence

- Role definitions in authentication data model:
  - `Seed/schema.sql`
- Role-based module access and sessions:
  - `core/session check/Role-Based-Session-Isolation-Architecture.md`
- Academic approvals:
  - `views/admin/student_requests.php`
  - `views/admin/results/submitted.php`
  - `views/admin/results/provisional.php`
- Finance workflows:
  - `views/finance/fee-structures.php`
  - `core/FeeStructureGovernance.php`

### Audit Record Templates

- `docs/governance/templates/Access-Review-Log.csv`
- `docs/governance/templates/Audit-Evidence-Register.csv`

---

## 3. Records Retention and Archival Rules

### Policy Artifacts

- `docs/governance/policies/Records-Retention-and-Archival-Policy.md`
- `docs/governance/sops/SOP-Retention-Backup-Purge-and-Restore.md`

### In-System Evidence

- Retention settings UI and persistence:
  - `views/admin/settings/index.php`
- Backup creation and retention enforcement:
  - `scripts/backup_cron.php`
  - `views/admin/system/health.php`
- Log purge controls:
  - `views/admin/system/health.php` (`purge_logs`)
- Archival examples:
  - `notification_archive` usage in:
    - `api/notifications.php`
    - `views/admin/notifications/archive.php`
    - `views/student/notifications.php`

### Audit Record Templates

- `docs/governance/templates/Retention-Purge-Log.csv`
- `docs/governance/templates/Audit-Evidence-Register.csv`

---

## 4. Change-Management and Approval Process

### Policy Artifacts

- `docs/governance/policies/Change-Management-and-Approval-Policy.md`
- `docs/governance/sops/SOP-Change-Request-to-Deployment.md`
- `docs/governance/templates/Change-Request-Form.md`

### In-System Evidence

- Multi-step approval/publish and immutable state transitions:
  - `core/FeeStructureGovernance.php`
  - `views/finance/fee-structures.php`
- Academic results change audit and publish confirmation:
  - `views/admin/results/submitted.php` (`results_audit` write path)
  - `views/admin/results/provisional.php` (audit confirmation before publish)
  - `views/admin/students/edit.php` (`student_profile_audit` write path for profile corrections)
  - `Seed/schema.sql` (`student_profile_audit` table definition)
  - `Seed/schema.sql` (`results_audit` table + immutable audit triggers)
  - `includes/functions.php` (`ensureAuditTraceabilityInfrastructure` bootstrap + immutable trigger guard)
  - `views/admin/results/audit.php` (audit export CSV/Excel)
  - `views/admin/students/audit.php` (profile audit export CSV/Excel)
  - `views/admin/change-tracker.php` + `views/admin/activity-recovery.php` (cross-module audit retrieval and exports)
- Operational/admin change trails:
  - `views/admin/system/health.php` (backup/purge actions logged and notified)
  - `Seed/schema.sql` (`activity_logs`)

### Audit Record Templates

- `docs/governance/templates/Change-Request-Form.md`
- `docs/governance/templates/Audit-Evidence-Register.csv`

---

## 5. Audit Readiness Checklist

- [x] Governance policies documented
- [x] SOP templates defined
- [x] Control-to-system evidence traceability documented
- [ ] Organization-specific owner names and approval signatures completed
- [ ] Periodic evidence logs populated from operations

---

## 6. Academic Structure & Standards

### In-System Evidence

- Semester/term structure and academic calendar relationships:
  - `Seed/schema.sql` (`academic_years`, `semesters`, `semester_registrations`)
  - `views/admin/academic-calendar.php`
- Standard credit unit model and course-to-term alignment:
  - `Seed/schema.sql` (`courses.credit_hours`, `courses.semester_offered`, registration/result foreign keys)
  - `views/student/course-registration.php`
- GPA / CGPA rules and repeatable computation:
  - `Seed/schema.sql` (`student_gpas`, `sp_calculate_student_gpa`)
  - `views/student/results.php`
  - `views/student/transcript.php`
- Transcript generation (print/export standard format):
  - `views/student/transcript.php` (term-by-term transcript, SGPA/CGPA, CSV/Excel/XML export, print-to-PDF official output)
  - `views/verify/transcript.php` (digital verification by student ID + verification code/hash)
  - `views/admin/results/view-slip.php` (official term result slip print view)
- Graduation and award tracking:
  - `Seed/schema.sql` (`students.graduation_*`, `student_graduation_awards`)
  - `includes/functions.php` (`ensureAuditTraceabilityInfrastructure` bootstrap for graduation/award schema)
  - `views/admin/students/graduation-awards.php`
  - `views/admin/students/view.php`

---

## 7. Reliability & Continuity

### Policy Artifacts

- `docs/governance/policies/Service-Reliability-and-Continuity-Policy.md`
- `docs/governance/sops/SOP-Disaster-Recovery-and-Continuity.md`
- `docs/governance/sops/SOP-Retention-Backup-Purge-and-Restore.md`

### In-System Evidence

- Availability target and reliability controls:
  - `config.php` (`UPTIME_SLO_TARGET_PERCENT`, `RESTORE_DRILL_MAX_AGE_DAYS`)
- Public uptime probe endpoint:
  - `api/health/uptime.php`
- Scheduled uptime monitoring + alerting:
  - `scripts/uptime_monitor.php`
  - `views/admin/system/health.php` (`uptime_slo` check and manual probe action)
- Disaster-recovery restore drill evidence:
  - `scripts/restore_test_drill.php`
  - `views/admin/system/health.php` (`restore_drill` check and manual drill action)
- Backup and health alerting baseline:
  - `scripts/backup_cron.php`
  - `scripts/system_health_monitor.php`
  - `views/admin/system/health.php`

### Audit Record Templates

- `docs/governance/templates/Disaster-Recovery-Drill-Log.csv`
- `docs/governance/templates/Audit-Evidence-Register.csv`

---

## 8. Compliance & Legal Readiness

### Policy Artifacts

- `docs/governance/policies/Data-Governance-Policy.md`
- `docs/governance/policies/Records-Retention-and-Archival-Policy.md`
- `docs/governance/sops/SOP-Data-Subject-Rights-and-Cross-Border-Handling.md`

### In-System Evidence

- Student access to own records:
  - `views/student/dashboard.php`
  - `views/student/results.php`
  - `views/student/transcript.php`
  - `views/student/payments.php`
- Correction/data-rights request process:
  - `views/student/services.php` (request options + history)
  - `views/student/submit-request.php` (validated request submission)
  - `views/admin/student_requests.php` (admin approval/rejection with response trace)
  - `views/admin/students/edit.php` + `views/admin/students/audit.php` (`student_profile_audit` correction evidence)
- Data deletion/anonymization rules:
  - `views/admin/students/delete.php` (anonymize-first + guarded hard-delete flow)
  - `docs/governance/policies/Records-Retention-and-Archival-Policy.md` (hard-delete restrictions)
- Cross-border data handling awareness:
  - `docs/governance/sops/SOP-Data-Subject-Rights-and-Cross-Border-Handling.md` (vendor/location/legal-basis checklist)
- Institutional and regulatory reporting:
  - `views/admin/reports/index.php` (institutional reporting dashboards + CSV/Excel/XML regulatory export)

### Audit Record Templates

- `docs/governance/templates/Audit-Evidence-Register.csv`
- `docs/governance/templates/Change-Request-Form.md`

---

## 9. Operations & People

### Policy Artifacts

- `docs/governance/policies/Roles-and-Responsibilities-Policy.md`
- `docs/governance/sops/SOP-Operations-Runbook-and-Staff-Training.md`
- `docs/governance/sops/SOP-Incident-Handling-and-Escalation.md`

### In-System Evidence

- Written SOPs for staff operations:
  - `docs/governance/sops/SOP-Access-Provisioning-and-Role-Review.md`
  - `docs/governance/sops/SOP-Change-Request-to-Deployment.md`
  - `docs/governance/sops/SOP-Operations-Runbook-and-Staff-Training.md`
- Separation of duties (academic vs finance):
  - `Seed/schema.sql` (`users.role`)
  - `core/session check/Role-Based-Session-Isolation-Architecture.md`
  - `views/admin/results/*` (academic flows)
  - `views/finance/*` and `views/admin/finance/*` (finance workflows)
- Incident handling process evidence:
  - `docs/governance/sops/SOP-Incident-Handling-and-Escalation.md`
  - `views/admin/system/health.php`
  - `views/admin/activity-recovery.php`
  - `scripts/system_health_monitor.php`
  - `scripts/uptime_monitor.php`

### Audit Record Templates

- `docs/governance/templates/User-Training-Register.csv`
- `docs/governance/templates/Incident-Register.csv`
- `docs/governance/templates/Audit-Evidence-Register.csv`
