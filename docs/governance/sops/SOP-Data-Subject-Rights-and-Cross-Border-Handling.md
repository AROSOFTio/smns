# SOP: Data Subject Rights and Cross-Border Handling

## 1. Purpose

Define operational steps for handling student data-rights requests (access, correction, deletion/anonymization) and documenting cross-border data handling awareness.

## 2. Scope

- Student service requests created in `views/student/services.php` and `views/student/submit-request.php`
- Admin review actions in `views/admin/student_requests.php`
- Student anonymize/delete operations in `views/admin/students/delete.php`

## 3. Roles

- Student: submits request with clear reason/context.
- Admin: reviews, responds, approves/rejects, and records decision.
- Data owner (Academic/IT): consulted for high-risk cases.

## 4. Procedure

1. Student submits request in the Services module:
   - `student_record_access`
   - `student_record_correction`
   - `data_deletion_anonymization`
2. Admin reviews request in Student Requests queue.
3. Admin provides mandatory response note for compliance request types.
4. If correction is approved:
   - execute controlled update via approved admin edit flows,
   - preserve audit evidence (`student_profile_audit` and notification trail).
5. If deletion/anonymization is approved:
   - use anonymization as default action,
   - use hard delete only when formally approved and conditions are satisfied.
6. Notify student through system notifications and email templates where configured.

## 5. Cross-Border Handling Checklist

For each vendor/system that stores or processes SMNS data:

1. Identify processing/storage geography.
2. Identify data classes involved (`Confidential`, `Internal`, `Public`).
3. Record legal/contract controls for cross-border transfer.
4. Record owner approval and next review date.

## 6. Evidence to Retain

- Student request records (`student_requests`)
- Admin response records and notifications
- Profile correction audit records (`student_profile_audit`)
- Deletion/anonymization action logs (`admin_actions`)
- Cross-border vendor review notes

## 7. Review Cadence

- Monthly: open/pending compliance requests
- Quarterly: cross-border handling review
- Annually: SOP and policy alignment review
