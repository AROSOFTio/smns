# SOP: Disaster Recovery and Continuity

## 1. Purpose

Provide executable steps for continuity operation, disaster response, and recovery validation.

## 2. Scope

- Production SMNS application runtime
- Database and backup stores
- Monitoring and alerting controls

## 3. Frequency

- Uptime review: daily
- Monitoring alert review: daily
- Restore drill: quarterly (minimum)
- DR tabletop exercise: semi-annual

## 4. Procedure

1. Confirm uptime monitor status and current 30-day availability percentage.
2. Review unresolved health and uptime alerts.
3. Verify latest backup availability and backup integrity metadata.
4. Run restore drill and capture outcome details.
5. If disaster is declared, execute recovery sequence:
   - isolate affected environment
   - restore from approved recovery point
   - validate core services (auth, student, finance, reporting)
   - communicate service status to stakeholders.
6. Record RTO and RPO achieved in the drill/incident evidence log.

## 5. Failure Handling

1. If uptime target (<99%) is breached, open incident and assign owner.
2. If restore drill fails, suspend destructive maintenance until remediation is complete.
3. Re-run drill after corrective action and attach evidence.

## 6. Evidence

- `templates/Disaster-Recovery-Drill-Log.csv`
- `templates/Audit-Evidence-Register.csv`
- System health and uptime alert records

