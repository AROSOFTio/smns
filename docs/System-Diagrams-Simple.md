# SMNS Simple Report Diagrams

This is a simplified exam and report version of the SMNS diagrams.

Use these when you need:

- fewer entities on one page
- easier explanation during viva or presentation
- cleaner report screenshots

## Simple DFD

```mermaid
flowchart LR
    A[Student] --> B[SMNS]
    C[Lecturer] --> B
    D[Admin] --> B
    E[Finance] --> B
    F[Verifier] --> B

    B --> G[(Student Records)]
    B --> H[(Academic Records)]
    B --> I[(Results Records)]
    B --> J[(Finance Records)]
    B --> K[(Transcript Records)]
```

## Simple ERD

```mermaid
erDiagram
    USERS ||--o| STUDENTS : owns
    USERS ||--o| LECTURERS : owns
    USERS ||--o| ADMINS : owns
    USERS ||--o| FINANCE_STAFF : owns

    PROGRAMS ||--o{ STUDENTS : has
    PROGRAMS ||--o{ COURSES : offers
    ACADEMIC_YEARS ||--o{ SEMESTERS : contains

    STUDENTS ||--o{ COURSE_REGISTRATIONS : makes
    COURSES ||--o{ COURSE_REGISTRATIONS : selected_in
    SEMESTERS ||--o{ COURSE_REGISTRATIONS : grouped_in

    LECTURERS ||--o{ RESULTS : enters
    STUDENTS ||--o{ RESULTS : receives
    COURSES ||--o{ RESULTS : belongs_to
    SEMESTERS ||--o{ RESULTS : published_in

    STUDENTS ||--o{ PAYMENTS : makes
    SEMESTERS ||--o{ PAYMENTS : recorded_in

    STUDENTS ||--o{ TRANSCRIPT_ISSUANCES : gets
```

## Simple Flowchart

```mermaid
flowchart TD
    A([Start]) --> B[Admin creates account]
    B --> C[Student logs in]
    C --> D[Student registers for semester and courses]
    D --> E[Lecturer enters marks]
    E --> F[Admin approves and publishes results]
    F --> G[Finance verifies fees and payments]
    G --> H{Eligible for transcript?}
    H -- No --> I[Block transcript]
    H -- Yes --> J[Admin releases transcript]
    J --> K[System issues transcript with verification]
    K --> L([End])
    I --> L
```

## Recommended Report Usage

- Use this file in the main report body.
- Use the full version in [System-Diagrams.md](/r:/xxxamp/htdocs/smns/docs/System-Diagrams.md) as appendix or technical reference.
