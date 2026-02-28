# SOP: Change Request to Deployment

## 1. Purpose

Provide a controlled lifecycle for planned and emergency system changes.

## 2. Required Artifacts

- `templates/Change-Request-Form.md`
- Technical review notes
- Approval record(s)
- Test evidence
- Deployment and rollback notes

## 3. Normal Change Flow

1. Raise change request with scope, risk, impact, and rollback.
2. IT performs technical impact analysis.
3. Relevant business owner approves (Academic/Finance/Admin).
4. Implement and test in non-production context.
5. Deploy with implementation checklist.
6. Validate production behavior and close request.

## 4. Emergency Change Flow

1. Log emergency reason and business risk.
2. Obtain expedited approval (minimum IT + one business owner).
3. Apply fix and verify service recovery.
4. Complete retrospective review within 2 business days.

## 5. Evidence

Record all details in `templates/Change-Request-Form.md` and keep references in audit register.
