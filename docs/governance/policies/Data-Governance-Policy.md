# Data Governance Policy

## 1. Metadata

- Policy Owner: `Head of IT`
- Business Owners: `Academic Registrar`, `Finance Manager`
- Approved By: `Principal / Governance Committee`
- Effective Date: `2026-02-28`
- Review Frequency: `Annual`
- Version: `1.0`

## 2. Purpose

Define how institutional data is owned, classified, protected, used, retained, and audited across academic, finance, and IT operations.

## 3. Scope

Applies to all SMNS data domains:

- Student identity and profile data
- Enrollment and course registration data
- Results and academic progression data
- Fees, invoices, payments, and finance communications
- Activity logs, notifications, backups, and system metadata

## 4. Governance Principles

1. Accountability: every critical dataset must have a business owner.
2. Least privilege: access is role-based and restricted to job needs.
3. Integrity: changes to key records must be traceable.
4. Availability: backups and recovery procedures must be maintained.
5. Lifecycle control: data creation, use, archive, and disposal are governed.

## 5. Data Classification

- `Confidential`: student PII, finance transactions, credentials, audit logs.
- `Internal`: operational reports, workflow states, notifications.
- `Public`: approved announcements intended for broad audiences.

Classification labels must be applied in design, storage, and reporting decisions.

## 6. Ownership and Stewardship

- Academic data owner: Academic Registrar.
- Finance data owner: Finance Manager.
- Security/control owner: Head of IT.
- System administrator: designated admin users.

Owners define quality rules and approvals; IT enforces technical controls.

## 7. Access and Use

1. Access is role-based (`student`, `lecturer`, `finance`, `admin`).
2. Privileged actions require authenticated module sessions and CSRF validation.
3. Direct database changes outside approved change procedures are prohibited.
4. Shared accounts are not permitted.

## 8. Data Quality and Integrity

1. Workflow approvals must be used for sensitive transitions.
2. Mandatory fields must be validated before write operations.
3. Reconciliation checks for finance and registration data must be performed monthly.
4. Exceptions must be documented and approved.

## 9. Logging and Monitoring

1. Administrative and operational events are logged to activity/audit artifacts.
2. Critical publish/approval flows must maintain approver attribution.
3. Log retention follows the retention policy.

## 10. Data Subject Rights and Correction Requests

1. Students have direct access to their own records through authenticated student portal pages (bio data, results, transcript, payments, and service history).
2. Students can submit formal requests for:
   - record access copies,
   - record correction, and
   - deletion/anonymization review.
3. Correction requests must be reviewed by authorized admins and, where approved, implemented through controlled edit flows that preserve audit history.
4. Rejected requests must include an admin response for traceability.

## 11. Deletion and Anonymization Controls

1. Anonymization is the default compliance action when historical academic records must be retained.
2. Hard delete is treated as high risk and is permitted only under approved governance process.
3. Student identity data disposal actions must be logged with actor, timestamp, reason/reference, and action mode (anonymize or hard delete).

## 12. Cross-Border Data Handling

1. Data owners and IT must identify whether backups, email providers, or integrated services process data outside the institution's primary jurisdiction.
2. Cross-border data transfers require:
   - documented legal basis/contract controls,
   - classification review (especially `Confidential` data),
   - and risk acknowledgment by governance owners.
3. Export of personally identifiable student data to external systems must follow least-privilege and approved business purpose.

## 13. Exceptions

Exceptions require:

1. documented business reason,
2. risk assessment,
3. owner approval, and
4. expiry date with review.

## 14. Enforcement

Policy non-compliance is escalated to governance leadership and may trigger access suspension until corrected.
