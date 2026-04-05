# SMNS System Diagrams

This document provides presentation-ready diagrams for the Seminary Management and Results System (`SMNS`) based on the current codebase and database structure.

It includes:

- a `DFD` for showing how information moves through the system
- an `ERD` for showing the main data model
- a `Flowchart` for showing the end-to-end operational process

## 1. Data Flow Diagram

This `DFD` is the best high-level diagram for describing how the SMNS system works to non-technical stakeholders.

```mermaid
flowchart LR
    student[Student]
    lecturer[Lecturer]
    admin[Admin]
    finance[Finance Staff]
    verifier[External Verifier]

    auth((Authentication\nand Role Access))
    studentRecords((Student Records\nManagement))
    registration((Semester and Course\nRegistration))
    resultMgmt((Results Entry,\nApproval and Publish))
    financeMgmt((Fees, Invoices\nand Payments))
    transcriptMgmt((Transcript Eligibility,\nRelease and Verification))
    reporting((Reports,\nNotifications and Audit))

    users[(Users)]
    students[(Students)]
    academics[(Programs,\nAcademic Years,\nSemesters,\nCourses)]
    registrations[(Semester Registrations\nand Course Registrations)]
    results[(Results,\nStudent GPAs,\nResults Audit)]
    financeStore[(Fees Structure,\nInvoices,\nPayments,\nStudent Balances)]
    transcriptStore[(Transcript Rights,\nIssuances,\nVerification Ledger)]
    supportStore[(Student Requests,\nNotifications,\nActivity Logs)]

    student --> auth
    lecturer --> auth
    admin --> auth
    finance --> auth

    auth <--> users
    auth --> studentRecords

    admin --> studentRecords
    student --> studentRecords
    studentRecords <--> students
    studentRecords <--> academics

    student --> registration
    admin --> registration
    registration <--> academics
    registration <--> registrations
    registration <--> students

    lecturer --> resultMgmt
    admin --> resultMgmt
    resultMgmt <--> registrations
    resultMgmt <--> results
    resultMgmt <--> academics

    student --> financeMgmt
    finance --> financeMgmt
    admin --> financeMgmt
    financeMgmt <--> financeStore
    financeMgmt <--> students
    financeMgmt <--> academics

    student --> transcriptMgmt
    admin --> transcriptMgmt
    verifier --> transcriptMgmt
    transcriptMgmt <--> students
    transcriptMgmt <--> registrations
    transcriptMgmt <--> results
    transcriptMgmt <--> financeStore
    transcriptMgmt <--> transcriptStore

    admin --> reporting
    finance --> reporting
    reporting <--> supportStore
    reporting <--> results
    reporting <--> financeStore
    reporting <--> transcriptStore
```

## 2. Entity Relationship Diagram

This `ERD` focuses on the main academic, user, finance, and transcript entities that describe the current SMNS data model.

```mermaid
erDiagram
    USERS {
        int id PK
        string username
        string email
        string role
        string status
    }

    ADMINS {
        int id PK
        int user_id FK
        string first_name
        string last_name
    }

    LECTURERS {
        int id PK
        int user_id FK
        string first_name
        string last_name
        string department
        string status
    }

    FINANCE_STAFF {
        int id PK
        int user_id FK
        string first_name
        string last_name
        string status
    }

    PROGRAMS {
        int id PK
        string program_code
        string program_name
        int duration_years
        string status
    }

    ACADEMIC_YEARS {
        int id PK
        string year_name
        string status
    }

    SEMESTERS {
        int id PK
        int academic_year_id FK
        string semester_name
        int semester_number
        string status
    }

    STUDENTS {
        int id PK
        int user_id FK
        string student_id
        string admission_number
        int program_id FK
        int current_semester FK
        int entry_semester_id FK
        string academic_status
        string status
    }

    COURSES {
        int id PK
        string course_code
        string course_name
        int program_id FK
        int level_year
        int semester_offered
        decimal credit_hours
        string status
    }

    COURSE_ASSIGNMENTS {
        int id PK
        int lecturer_id FK
        int course_id FK
        int semester_id FK
        string status
    }

    SEMESTER_REGISTRATIONS {
        int id PK
        int student_id FK
        int semester_id FK
        int year_of_study
        string status
    }

    COURSE_REGISTRATIONS {
        int id PK
        int student_id FK
        int course_id FK
        int semester_id FK
        string status
    }

    RESULTS {
        int id PK
        int student_id FK
        int course_id FK
        int semester_id FK
        int entered_by FK
        int approved_by FK
        decimal total_marks
        string grade
        decimal grade_points
        string status
    }

    RESULTS_AUDIT {
        int id PK
        int result_id FK
        int student_id FK
        int course_id FK
        int changed_by_user_id FK
        string change_type
    }

    STUDENT_GPAS {
        int id PK
        int student_id FK
        int semester_id FK
        decimal semester_gpa
        decimal cumulative_gpa
    }

    FEES_STRUCTURE {
        int id PK
        int program_id FK
        int academic_year_id FK
        string fee_type
        decimal amount
        string status
    }

    INVOICES {
        int id PK
        int student_id FK
        int semester_id FK
        decimal total_amount
        string status
    }

    PAYMENTS {
        int id PK
        int student_id FK
        int semester_id FK
        decimal amount_paid
        string payment_method
        string payment_status
    }

    STUDENT_BALANCES {
        int id PK
        int student_id FK
        int semester_id FK
        decimal total_fees
        decimal total_paid
        decimal balance
    }

    STUDENT_REQUESTS {
        int id PK
        int student_id FK
        int user_id FK
        int semester_id FK
        string request_type
        string status
    }

    TRANSCRIPT_DOWNLOAD_RIGHTS {
        int id PK
        int student_id FK
        string status
        int verified_by_user_id FK
    }

    TRANSCRIPT_ISSUANCES {
        int id PK
        int student_id FK
        string transcript_hash
        string verification_code
        string verification_token
        string status
    }

    TRANSCRIPT_ISSUANCE_LEDGER {
        int id PK
        int issuance_id FK
        string action_type
        string current_hash
    }

    NOTIFICATIONS {
        int id PK
        int user_id FK
        string title
        string type
    }

    ACTIVITY_LOGS {
        int id PK
        int user_id FK
        string action
        string module
    }

    USERS ||--o| ADMINS : owns_profile
    USERS ||--o| LECTURERS : owns_profile
    USERS ||--o| FINANCE_STAFF : owns_profile
    USERS ||--o| STUDENTS : owns_profile

    PROGRAMS ||--o{ STUDENTS : admits
    PROGRAMS ||--o{ COURSES : offers
    PROGRAMS ||--o{ FEES_STRUCTURE : defines

    ACADEMIC_YEARS ||--o{ SEMESTERS : contains
    ACADEMIC_YEARS ||--o{ FEES_STRUCTURE : applies_to

    SEMESTERS ||--o{ COURSE_ASSIGNMENTS : schedules
    SEMESTERS ||--o{ SEMESTER_REGISTRATIONS : registers
    SEMESTERS ||--o{ COURSE_REGISTRATIONS : groups
    SEMESTERS ||--o{ RESULTS : publishes_in
    SEMESTERS ||--o{ STUDENT_GPAS : calculates_for
    SEMESTERS ||--o{ INVOICES : bills_for
    SEMESTERS ||--o{ PAYMENTS : records_for
    SEMESTERS ||--o{ STUDENT_BALANCES : tracks
    SEMESTERS ||--o{ STUDENT_REQUESTS : relates_to

    LECTURERS ||--o{ COURSE_ASSIGNMENTS : teaches
    COURSES ||--o{ COURSE_ASSIGNMENTS : assigned

    STUDENTS ||--o{ SEMESTER_REGISTRATIONS : submits
    STUDENTS ||--o{ COURSE_REGISTRATIONS : enrolls
    COURSES ||--o{ COURSE_REGISTRATIONS : selected

    STUDENTS ||--o{ RESULTS : receives
    COURSES ||--o{ RESULTS : assessed_in
    LECTURERS ||--o{ RESULTS : enters
    ADMINS ||--o{ RESULTS : approves

    RESULTS ||--o{ RESULTS_AUDIT : audited_by
    STUDENTS ||--o{ RESULTS_AUDIT : traced_for
    COURSES ||--o{ RESULTS_AUDIT : traced_for
    USERS ||--o{ RESULTS_AUDIT : changes

    STUDENTS ||--o{ STUDENT_GPAS : accumulates

    STUDENTS ||--o{ INVOICES : billed
    STUDENTS ||--o{ PAYMENTS : pays
    STUDENTS ||--o{ STUDENT_BALANCES : owes

    STUDENTS ||--o{ STUDENT_REQUESTS : raises
    USERS ||--o{ STUDENT_REQUESTS : authenticates

    STUDENTS ||--o| TRANSCRIPT_DOWNLOAD_RIGHTS : granted_right
    USERS ||--o{ TRANSCRIPT_DOWNLOAD_RIGHTS : verifies
    STUDENTS ||--o{ TRANSCRIPT_ISSUANCES : issued
    TRANSCRIPT_ISSUANCES ||--o{ TRANSCRIPT_ISSUANCE_LEDGER : chained_into

    USERS ||--o{ NOTIFICATIONS : receives
    USERS ||--o{ ACTIVITY_LOGS : performs
```

## 3. System Flowchart

This flowchart shows the main operational path from account creation to transcript verification.

```mermaid
flowchart TD
    A([Start]) --> B[Admin creates user and student, lecturer, or finance account]
    B --> C[User logs in through role-based authentication]
    C --> D{Role?}

    D -- Student --> E[Student completes profile and views dashboard]
    E --> F[Student requests semester registration]
    F --> G[Admin approves semester registration]
    G --> H[System assigns or records course registrations]
    H --> I[Student attends courses and checks invoices or payments]

    D -- Lecturer --> J[Lecturer views assigned courses]
    J --> K[Lecturer enters marks]
    K --> L[Lecturer submits results]

    D -- Finance --> M[Finance configures fees, invoices, and verifies payments]

    D -- Admin --> N[Admin configures programs, courses, academic years, and semesters]
    N --> G
    N --> O[Admin reviews submitted results]

    L --> O
    O --> P{Approved?}
    P -- No --> K
    P -- Yes --> Q[Admin publishes results]
    Q --> R[System computes GPA and updates academic standing]

    I --> S[Student views published results]
    M --> T[System updates student balance]
    R --> U[System evaluates transcript eligibility]
    T --> U

    U --> V{Completed studies, no retakes,\nbills cleared, discipline okay?}
    V -- No --> W[Transcript remains blocked]
    V -- Yes --> X[Admissions or admin grants transcript rights]

    X --> Y[Student opens transcript]
    Y --> Z{Official issuance already exists?}
    Z -- No --> AA[System generates transcript snapshot,\nhash, code, token, and ledger record]
    Z -- Yes --> AB[System reuses active issuance]

    AA --> AC[Transcript displays verification details and QR]
    AB --> AC
    AC --> AD[Third party opens verification link or token page]
    AD --> AE[System validates issued transcript]
    AE --> AF([End])
    W --> AF
```

## Recommended Use In Your Report

- Use the `DFD` in the chapter that explains how the whole system works.
- Use the `ERD` in the database design chapter.
- Use the `Flowchart` in the system process or methodology chapter.

## Notes

- The diagrams are based on the current SMNS codebase and database backup files in this repository.
- The `ERD` is intentionally focused on the main operational entities so it stays readable for academic documentation.
- If you want, these diagrams can also be converted into `draw.io`, `PlantUML`, or image files later.
