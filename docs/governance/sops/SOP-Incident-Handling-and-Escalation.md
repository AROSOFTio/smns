# SOP: Incident Handling and Escalation

## 1. Purpose

Provide a standard process for detecting, triaging, containing, resolving, and documenting incidents across SMNS operations.

## 2. Scope

- Availability incidents
- Security incidents
- Data integrity incidents
- Workflow/transaction incidents (academic or finance impact)

## 3. Severity Levels

- `SEV-1`: Critical service outage, active security breach, major data risk
- `SEV-2`: Major degradation or high-risk functional failure
- `SEV-3`: Moderate issue with workaround
- `SEV-4`: Low-impact defect/inquiry

## 4. Incident Workflow

1. Detect and log incident in `templates/Incident-Register.csv`.
2. Assign incident owner and severity.
3. Contain impact (disable affected path, rollback, or isolate user/process).
4. Notify stakeholders based on severity.
5. Investigate root cause using logs and system evidence.
6. Implement fix and validate service restoration.
7. Close incident with documented timeline, corrective actions, and preventive actions.

## 5. Escalation Matrix

1. Initial owner: module lead (`Academic`, `Finance`, or `Admin Operations`)
2. If unresolved within agreed SLA or SEV-1/SEV-2: escalate to `Head of IT`
3. Governance/business impact: escalate to `Principal / Governance Committee`

## 6. Technical Evidence Sources

- `views/admin/system/health.php` (health checks, uptime, backup/restore signals)
- `scripts/system_health_monitor.php`
- `scripts/uptime_monitor.php`
- `scripts/restore_test_drill.php`
- `views/admin/activity-recovery.php`
- `views/admin/change-tracker.php`

## 7. Post-Incident Review

1. Conduct review within 5 business days for SEV-1/SEV-2.
2. Capture root cause, missed controls, and preventive actions.
3. Raise change requests for required permanent fixes.
4. Update relevant SOP/training materials where process gaps are identified.
