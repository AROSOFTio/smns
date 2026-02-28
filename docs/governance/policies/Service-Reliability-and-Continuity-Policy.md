# Service Reliability and Continuity Policy

## 1. Metadata

- Policy Owner: `Head of IT`
- Business Owners: `Academic Registrar`, `Finance Manager`
- Approved By: `Principal / Governance Committee`
- Effective Date: `2026-02-28`
- Review Frequency: `Annual`
- Version: `1.0`

## 2. Purpose

Define reliability, continuity, disaster-recovery, and monitoring requirements for SMNS.

## 3. Availability Target

1. Service availability target is `>= 99.0%` over a rolling 30-day window.
2. Availability evidence must be captured via automated uptime probes.
3. Any breach of target must trigger incident review and corrective actions.

## 4. Monitoring and Alerting

1. Health checks and uptime probes must run on a scheduled basis.
2. Warning and failure states must trigger alerts to active admin recipients.
3. Monitoring evidence must be retained for audit and post-incident analysis.

## 5. Disaster Recovery

1. Backup frequency and retention must follow approved retention policy.
2. Disaster recovery procedures must define RPO and RTO assumptions.
3. Recovery communication and escalation paths must be documented.

## 6. Restore Testing

1. Restore drills must be executed at least quarterly.
2. Each drill must record source backup, execution time, duration, and outcome.
3. Failed drills require remediation tracking and a retest date.

## 7. Exceptions

Reliability or continuity exceptions require documented approval, risk acceptance, and expiry date.

