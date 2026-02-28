# Governance and Compliance Pack

This folder contains the governance artifacts required to make the following controls auditable:

- Documented data governance policy
- Defined roles and responsibilities (academic, finance, IT)
- Records retention and archival rules
- Change-management and approval process
- Compliance and legal readiness (data rights + cross-border awareness)

## Structure

- `policies/`
  - `Data-Governance-Policy.md`
  - `Roles-and-Responsibilities-Policy.md`
  - `Records-Retention-and-Archival-Policy.md`
  - `Change-Management-and-Approval-Policy.md`
  - `Service-Reliability-and-Continuity-Policy.md`
- `sops/`
  - `SOP-Access-Provisioning-and-Role-Review.md`
  - `SOP-Retention-Backup-Purge-and-Restore.md`
  - `SOP-Change-Request-to-Deployment.md`
  - `SOP-Audit-Evidence-Collection.md`
  - `SOP-Disaster-Recovery-and-Continuity.md`
  - `SOP-Data-Subject-Rights-and-Cross-Border-Handling.md`
  - `SOP-Operations-Runbook-and-Staff-Training.md`
  - `SOP-Incident-Handling-and-Escalation.md`
- `templates/`
  - `Change-Request-Form.md`
  - `Access-Review-Log.csv`
  - `Retention-Purge-Log.csv`
  - `Audit-Evidence-Register.csv`
  - `Disaster-Recovery-Drill-Log.csv`
  - `User-Training-Register.csv`
  - `Incident-Register.csv`
- `mapping/`
  - `Control-to-System-Mapping.md`

## Operational Notes

1. Assign named owners in each policy front-matter before production audit.
2. Run SOPs and store completed templates in your internal evidence repository.
3. Review policies at least annually or after major system/process changes.
4. Keep mapping file current whenever new features or controls are added.

## Document Control

- Version: 1.0
- Effective Date: 2026-02-28
- Review Cycle: Annual
- Classification: Internal
