# Roles and Responsibilities Policy

## 1. Metadata

- Policy Owner: `Head of IT`
- Co-Owners: `Academic Registrar`, `Finance Manager`
- Approved By: `Principal / Governance Committee`
- Effective Date: `2026-02-28`
- Review Frequency: `Annual`
- Version: `1.0`

## 2. Purpose

Define who is accountable and responsible for governance controls across academic, finance, and IT processes.

## 3. Role Definitions

- `Academic`: controls academic calendar, registration/approval, results workflows.
- `Finance`: controls fee structures, invoicing, payments, financial communications.
- `IT`: controls security, backups, logging, configuration, and change operations.
- `Admin`: executes approved operations in the system UI and monitors controls.

## 4. RACI Matrix (Core Governance Controls)

| Control Area | Academic | Finance | IT | Admin |
|---|---|---|---|---|
| Data Governance Policy | C | C | A/R | R |
| Access Control and Role Assignment | C | C | A/R | R |
| Enrollment/Result Approval Workflows | A/R | I | C | R |
| Fee Version Approval/Publish Workflow | I | R | C | A/R |
| Backup and Recovery | I | I | A/R | R |
| Retention and Purge Execution | C | C | A/R | R |
| Change Request Assessment | C | C | A/R | R |
| Production Release Approval | C | C | A | R |
| Audit Evidence Collection | C | C | A/R | R |

Legend:

- `A` = Accountable
- `R` = Responsible
- `C` = Consulted
- `I` = Informed

## 5. Segregation of Duties

1. No single user should request, approve, and deploy high-risk changes alone.
2. Approver roles for academic and finance workflows must be separate from request originators where practical.
3. Super-admin or delegated governance authority is required for irreversible actions.

## 6. Minimum Review Cadence

1. Access review: quarterly.
2. Retention and purge report: monthly.
3. Backup restore test: quarterly.
4. Governance pack review: annual.

## 7. Escalation

Control breaches are escalated in order:

1. Module owner (Academic/Finance/IT)
2. Head of IT
3. Principal / Governance Committee
