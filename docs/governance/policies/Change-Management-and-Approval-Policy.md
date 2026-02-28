# Change Management and Approval Policy

## 1. Metadata

- Policy Owner: `Head of IT`
- Approved By: `Principal / Governance Committee`
- Effective Date: `2026-02-28`
- Review Frequency: `Annual`
- Version: `1.0`

## 2. Purpose

Ensure system changes are planned, reviewed, approved, tested, and auditable before production use.

## 3. Change Categories

- `Standard`: low-risk, pre-approved routine operations.
- `Normal`: planned changes requiring documented review/approval.
- `Emergency`: urgent risk/security/service restoration changes with expedited approval.

## 4. Minimum Change Requirements

All non-standard changes must include:

1. Change request record (scope, reason, impact, risk, rollback).
2. Technical review by IT.
3. Business approval from relevant owner (Academic/Finance/Admin).
4. Test evidence in non-production context where possible.
5. Deployment record with date/time and implementer.
6. Post-change validation and closure notes.

## 5. Approval Rules

1. High-impact changes require both IT and business owner approval.
2. Emergency changes require immediate notification and retrospective review within 2 business days.
3. Destructive operations require explicit approval and backup confirmation.

## 6. Segregation and Auditability

1. Requester and approver should be different persons when possible.
2. Approvals must be recorded and retained.
3. Governance-relevant workflow changes must keep approver IDs and timestamps.

## 7. Rollback and Recovery

1. Each change must define rollback steps and prerequisites.
2. If rollback cannot be automated, manual recovery steps must be documented.
3. Critical change windows must include backup checkpoint references.

## 8. Compliance Evidence

Evidence records are maintained via:

- change request form,
- deployment notes,
- workflow approval records,
- audit and activity logs,
- backup/restore records.
