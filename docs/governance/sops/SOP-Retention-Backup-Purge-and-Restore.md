# SOP: Retention, Backup, Purge, and Restore

## 1. Purpose

Execute retention and recovery controls consistently and auditable.

## 2. Frequency

- Backups: daily/weekly per settings
- Purge review: monthly
- Restore drill: quarterly

## 3. Procedure

1. Confirm retention settings in admin system settings.
2. Validate scheduled backup status and latest backup file.
3. Run purge operation per approved retention thresholds.
4. Record purge counts and impacted artifacts.
5. Perform restore test from a recent backup into test environment.
6. Validate data integrity after restore.
7. Document outcome and remediation actions.

## 4. Failure Handling

1. If backup fails, create incident record and notify IT owner.
2. If restore fails, stop further purge operations until resolved.
3. Escalate repeated failures to governance owner.

## 5. Evidence

- Completed `templates/Retention-Purge-Log.csv`
- Backup identifiers and timestamps
- Restore test notes and result
