# Interoperability and Integration Guide

## API Support (REST/JSON)
- `GET /smns/api/integrations/finance_sync.php?format=json`
- `GET /smns/api/integrations/lms_sync.php?format=json`
- Existing platform APIs remain available under `/smns/api/`.

## Finance System Integration
- Finance sync feed endpoint:
  - `GET /smns/api/integrations/finance_sync.php`
- Supported formats:
  - `format=json`
  - `format=csv`
  - `format=xml`
- Optional filters:
  - `semester_id=6`
  - `from=2025-01-01`
  - `to=2025-12-31`
  - `limit=1000`

## Payment Gateway Integration
- Existing payment endpoints:
  - `POST /smns/api/payments/initiate.php`
  - `GET|POST /smns/api/payments/status.php`
  - `POST /smns/api/payments/webhook.php`

## LMS / E-learning Integration
- LMS sync feed endpoint:
  - `GET /smns/api/integrations/lms_sync.php`
- Supported formats:
  - `format=json`
  - `format=csv`
  - `format=xml`
- Optional filters:
  - `semester_id=6`
  - `program_id=2`
  - `include_pending=1`
  - `limit=10000`

## Data Export (CSV / PDF / XML)
- CSV: available in transcript, payments ledger, reports, and audits.
- PDF: print-friendly pages can be exported through browser Print to PDF.
- XML:
  - Transcript: `GET /smns/views/student/transcript.php?export=xml`
  - Finance sync: `GET /smns/api/integrations/finance_sync.php?format=xml`
  - LMS sync: `GET /smns/api/integrations/lms_sync.php?format=xml`

## Authentication
- Integration token (recommended for external systems):
  - Header: `X-Integration-Token: <token>`
  - Or query string: `token=<token>`
- Configure token in:
  - `config.php`: `INTEGRATION_API_TOKEN`
  - env var override: `SMNS_INTEGRATION_API_TOKEN`

