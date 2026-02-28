# Records Retention and Archival Policy

## 1. Metadata

- Policy Owner: `Head of IT`
- Business Owners: `Academic Registrar`, `Finance Manager`
- Approved By: `Principal / Governance Committee`
- Effective Date: `2026-02-28`
- Review Frequency: `Annual`
- Version: `1.0`

## 2. Purpose

Define retention periods, archival rules, purge controls, and restoration expectations for SMNS records.

## 3. Retention Rules

Default baseline unless legal/regulatory obligations require longer retention:

| Record Type | Minimum Retention | Archive Rule | Purge Authority |
|---|---:|---|---|
| Activity logs | 90 days | Keep online log store | IT + Admin |
| Database backups | 30 days (and max files cap) | Stored in backup location with rotation | IT + Admin |
| Notification archives | 12 months | User-level archive table | Admin/Data Owner |
| Semester registration history | 7 years | Keep historical rows; no destructive purge without approval | Academic + IT |
| Results and GPA records | 7 years minimum | Preserve for transcript integrity | Academic + IT |
| Finance transactions | 7 years minimum | Preserve for audit and reconciliation | Finance + IT |

## 4. Archival Controls

1. Archival actions must preserve traceability metadata (`archived_at`, actor when available).
2. Archived data must remain searchable for authorized users.
3. Hard deletes are restricted and must follow approved change process.
4. Where legal requests require identity removal but historical records must remain, anonymization must be used in preference to hard delete.

## 5. Purge Controls

1. Purges run only through approved admin/system paths or approved scripts.
2. Purges must be logged with actor, date/time, scope, and result count.
3. Pre-purge backup or rollback capability is required for high-risk data classes.

## 6. Recovery and Restore

1. Restore procedures must be documented and tested at least quarterly.
2. Restore tests must record backup identifier, recovery point, duration, and outcome.
3. Failed restore tests require remediation plan and re-test date.

## 7. Exceptions

Retention exceptions require documented owner approval and expiry date.
