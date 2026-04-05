# SMNS UML System Design

This document provides UML-style system design diagrams for the Seminary Management and Results System (`SMNS`).

It includes:

- `Use Case Diagrams` for system actors and functions
- `Class Diagram` for the main data structures and relationships
- `Sequence Diagrams` for important system operations

## 1. Use Case Diagram

This use case view shows the main system actors and the major functions they interact with.

Note:
- Mermaid does not have a native UML use-case syntax like PlantUML.
- For report use, the diagram below is structured as a use-case diagram using actors and oval-like function nodes.

```mermaid
flowchart LR
    student[Student]
    lecturer[Lecturer]
    admin[Admin]
    finance[Finance Staff]
    verifier[External Verifier]

    subgraph SMNS[SMNS System]
        uc1([Login])
        uc2([Manage Student Profile])
        uc3([Register for Semester])
        uc4([Register Courses])
        uc5([View Results])
        uc6([Submit Student Request])
        uc7([View Payments and Balances])
        uc8([View Transcript])

        uc9([View Assigned Courses])
        uc10([Enter Marks])
        uc11([Submit Results])

        uc12([Manage Students])
        uc13([Configure Programs, Courses,\nAcademic Years and Semesters])
        uc14([Approve Registrations])
        uc15([Review and Publish Results])
        uc16([Grant Transcript Rights])
        uc17([Generate Reports])
        uc18([Manage Users and Permissions])

        uc19([Manage Fee Structures])
        uc20([Generate Invoices])
        uc21([Verify Payments])
        uc22([Update Student Balances])

        uc23([Verify Transcript Authenticity])
    end

    student --> uc1
    student --> uc2
    student --> uc3
    student --> uc4
    student --> uc5
    student --> uc6
    student --> uc7
    student --> uc8

    lecturer --> uc1
    lecturer --> uc9
    lecturer --> uc10
    lecturer --> uc11

    admin --> uc1
    admin --> uc12
    admin --> uc13
    admin --> uc14
    admin --> uc15
    admin --> uc16
    admin --> uc17
    admin --> uc18

    finance --> uc1
    finance --> uc19
    finance --> uc20
    finance --> uc21
    finance --> uc22

    verifier --> uc23
```

## 2. Class Diagram

This class diagram models the core SMNS data structures and their relationships at application level.

```mermaid
classDiagram
    class User {
        +int id
        +string username
        +string email
        +string role
        +string status
        +login()
        +logout()
    }

    class Student {
        +int id
        +int user_id
        +string student_id
        +string admission_number
        +string first_name
        +string last_name
        +string academic_status
        +string discipline_status
        +registerSemester()
        +registerCourses()
        +viewResults()
        +viewTranscript()
    }

    class Lecturer {
        +int id
        +int user_id
        +string first_name
        +string last_name
        +string department
        +viewAssignedCourses()
        +enterMarks()
        +submitResults()
    }

    class Admin {
        +int id
        +int user_id
        +string first_name
        +string last_name
        +approveRegistration()
        +publishResults()
        +grantTranscriptRights()
        +generateReports()
    }

    class FinanceStaff {
        +int id
        +int user_id
        +string first_name
        +string last_name
        +createInvoice()
        +verifyPayment()
        +updateBalance()
    }

    class Program {
        +int id
        +string program_code
        +string program_name
        +int duration_years
    }

    class AcademicYear {
        +int id
        +string year_name
        +string status
    }

    class Semester {
        +int id
        +int academic_year_id
        +string semester_name
        +int semester_number
        +string status
    }

    class Course {
        +int id
        +string course_code
        +string course_name
        +int program_id
        +int level_year
        +int semester_offered
    }

    class SemesterRegistration {
        +int id
        +int student_id
        +int semester_id
        +int year_of_study
        +string status
        +approve()
    }

    class CourseRegistration {
        +int id
        +int student_id
        +int course_id
        +int semester_id
        +string status
    }

    class Result {
        +int id
        +int student_id
        +int course_id
        +int semester_id
        +int entered_by
        +int approved_by
        +decimal total_marks
        +string grade
        +decimal grade_points
        +string status
        +submit()
        +approve()
        +publish()
    }

    class StudentGPA {
        +int id
        +int student_id
        +int semester_id
        +decimal semester_gpa
        +decimal cumulative_gpa
        +calculate()
    }

    class Invoice {
        +int id
        +int student_id
        +int semester_id
        +decimal total_amount
        +string status
    }

    class Payment {
        +int id
        +int student_id
        +int semester_id
        +decimal amount_paid
        +string payment_method
        +string payment_status
        +verify()
    }

    class StudentBalance {
        +int id
        +int student_id
        +int semester_id
        +decimal total_fees
        +decimal total_paid
        +decimal balance
        +updateBalance()
    }

    class TranscriptDownloadRight {
        +int id
        +int student_id
        +string status
        +grant()
        +revoke()
    }

    class TranscriptIssuance {
        +int id
        +int student_id
        +string transcript_hash
        +string verification_code
        +string verification_token
        +string status
        +issue()
        +verify()
    }

    class StudentRequest {
        +int id
        +int student_id
        +string request_type
        +string status
        +submit()
        +review()
    }

    User <|-- Student
    User <|-- Lecturer
    User <|-- Admin
    User <|-- FinanceStaff

    Program "1" --> "*" Student : enrolls
    Program "1" --> "*" Course : offers
    AcademicYear "1" --> "*" Semester : contains
    Student "1" --> "*" SemesterRegistration : submits
    Student "1" --> "*" CourseRegistration : makes
    Semester "1" --> "*" SemesterRegistration : groups
    Semester "1" --> "*" CourseRegistration : groups
    Course "1" --> "*" CourseRegistration : selected_in
    Lecturer "1" --> "*" Result : enters
    Admin "1" --> "*" Result : approves
    Student "1" --> "*" Result : receives
    Course "1" --> "*" Result : belongs_to
    Semester "1" --> "*" Result : published_in
    Student "1" --> "*" StudentGPA : accumulates
    Student "1" --> "*" Invoice : billed
    Student "1" --> "*" Payment : pays
    Student "1" --> "*" StudentBalance : has
    Student "1" --> "0..1" TranscriptDownloadRight : granted
    Student "1" --> "*" TranscriptIssuance : issued
    Student "1" --> "*" StudentRequest : raises
```

## 3. Sequence Diagram: Results Entry and Publishing

This sequence diagram shows how marks move from lecturer entry to student visibility.

```mermaid
sequenceDiagram
    actor Lecturer
    participant SMNS as SMNS Application
    participant Results as Results Module
    participant DB as Database
    actor Admin
    actor Student

    Lecturer->>SMNS: Login
    SMNS->>DB: Validate lecturer account
    DB-->>SMNS: Lecturer authenticated

    Lecturer->>SMNS: Open assigned course
    SMNS->>Results: Load registration list
    Results->>DB: Fetch assigned students and course data
    DB-->>Results: Student/course list
    Results-->>SMNS: Render result sheet

    Lecturer->>SMNS: Enter marks and submit
    SMNS->>Results: Save result rows
    Results->>DB: Insert/Update results with status=submitted
    DB-->>Results: Submission stored
    Results-->>SMNS: Submission success

    Admin->>SMNS: Review submitted results
    SMNS->>Results: Load submitted rows
    Results->>DB: Fetch submitted results
    DB-->>Results: Submitted results
    Results-->>SMNS: Show review list

    Admin->>SMNS: Approve and publish results
    SMNS->>Results: Approve results
    Results->>DB: Update status=approved/published
    Results->>DB: Record audit trail
    DB-->>Results: Results published
    Results-->>SMNS: Publish complete

    Student->>SMNS: View results
    SMNS->>DB: Fetch published results
    DB-->>SMNS: Published result rows
    SMNS-->>Student: Show published results and GPA
```

## 4. Sequence Diagram: Transcript Eligibility and Release

This sequence diagram shows the key transcript-control workflow in the current SMNS implementation.

```mermaid
sequenceDiagram
    actor Student
    actor Admin
    participant SMNS as SMNS Application
    participant Eligibility as Eligibility Engine
    participant Finance as Finance Check
    participant Results as Results Check
    participant Transcript as Transcript Service
    participant DB as Database
    actor Verifier

    Student->>SMNS: Open transcript page
    SMNS->>Eligibility: Evaluate transcript access
    Eligibility->>Results: Check completed studies and retakes
    Results->>DB: Read results and academic status
    DB-->>Results: Academic data
    Results-->>Eligibility: Academic eligibility state

    Eligibility->>Finance: Check outstanding balances
    Finance->>DB: Read balances and payments
    DB-->>Finance: Financial clearance data
    Finance-->>Eligibility: Financial eligibility state

    Eligibility->>DB: Read discipline and transcript rights
    DB-->>Eligibility: Rights and discipline data
    Eligibility-->>SMNS: Eligibility response

    alt Not eligible
        SMNS-->>Student: Show blocking reasons
    else Eligible but no rights
        SMNS-->>Student: Show transcript blocked pending admin release
        Admin->>SMNS: Grant transcript rights
        SMNS->>DB: Save transcript_download_rights
        DB-->>SMNS: Rights granted
    else Eligible and rights granted
        SMNS->>Transcript: Build transcript snapshot
        Transcript->>DB: Read transcript data
        DB-->>Transcript: Transcript rows
        Transcript->>DB: Create or reuse transcript issuance
        DB-->>Transcript: Verification code/token/hash
        Transcript-->>SMNS: Official transcript payload
        SMNS-->>Student: Display official transcript with QR and verification link
    end

    Verifier->>SMNS: Open verification token link
    SMNS->>DB: Look up transcript issuance by token
    DB-->>SMNS: Issued transcript record
    SMNS-->>Verifier: Show transcript authenticity result
```

## 5. Sequence Diagram: Payment Verification and Balance Update

This sequence diagram shows how finance workflows affect student clearance.

```mermaid
sequenceDiagram
    actor Student
    actor Finance
    participant SMNS as SMNS Application
    participant Billing as Billing Module
    participant DB as Database

    Student->>SMNS: View invoice / payment page
    SMNS->>Billing: Load fees, invoices, and balance
    Billing->>DB: Fetch invoice, fee structure, and balances
    DB-->>Billing: Billing records
    Billing-->>SMNS: Current billing state
    SMNS-->>Student: Show balance and payment details

    Finance->>SMNS: Verify payment
    SMNS->>Billing: Process payment verification
    Billing->>DB: Update payment status
    Billing->>DB: Update student balance
    DB-->>Billing: Payment and balance updated
    Billing-->>SMNS: Verification complete

    Student->>SMNS: Refresh payments page
    SMNS->>DB: Fetch updated balance
    DB-->>SMNS: Cleared / outstanding balance
    SMNS-->>Student: Show updated payment clearance
```

## Recommended Use In Your Report

Use these diagrams in this order:

1. Use Case Diagram
2. Class Diagram
3. Sequence Diagram for Results
4. Sequence Diagram for Transcript
5. Sequence Diagram for Payments

That order moves from user perspective, to structure, to process behavior.
