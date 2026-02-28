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
