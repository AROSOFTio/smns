# SMNS Simple UML Diagrams

This file contains simpler exam-style UML diagrams for the Seminary Management and Results System (`SMNS`).

These are easier to explain in:

- report writing
- presentations
- viva defense
- printed documentation

## 1. Simple Use Case Diagram

```mermaid
flowchart LR
    student[Student]
    lecturer[Lecturer]
    admin[Admin]
    finance[Finance]

    subgraph SMNS[SMNS System]
        login([Login])
        register([Register Courses])
        results([View or Manage Results])
        payments([Manage Payments])
        transcript([View or Release Transcript])
        reports([Generate Reports])
    end

    student --> login
    student --> register
    student --> results
    student --> transcript

    lecturer --> login
    lecturer --> results

    admin --> login
    admin --> register
    admin --> results
    admin --> transcript
    admin --> reports

    finance --> login
    finance --> payments
    finance --> reports
```

## 2. Simple Class Diagram

```mermaid
classDiagram
    class User {
        +id
        +username
        +email
        +role
    }

    class Student {
        +student_id
        +first_name
        +last_name
        +academic_status
    }

    class Lecturer {
        +first_name
        +last_name
        +department
    }

    class Program {
        +program_code
        +program_name
        +duration_years
    }

    class Course {
        +course_code
        +course_name
        +semester_offered
    }

    class Result {
        +total_marks
        +grade
        +grade_points
        +status
    }

    class Payment {
        +amount_paid
        +payment_method
        +payment_status
    }

    class TranscriptIssuance {
        +verification_code
        +verification_token
        +status
    }

    User <|-- Student
    User <|-- Lecturer
    Program --> Student
    Program --> Course
    Student --> Result
    Course --> Result
    Lecturer --> Result
    Student --> Payment
    Student --> TranscriptIssuance
```

## 3. Simple Sequence Diagram

```mermaid
sequenceDiagram
    actor Student
    actor Admin
    participant SMNS
    participant DB

    Student->>SMNS: Request transcript
    SMNS->>DB: Check results, balance, status
    DB-->>SMNS: Eligibility data
    alt eligible and released
        Admin->>SMNS: Grant transcript rights
        SMNS->>DB: Save transcript release
        Student->>SMNS: Open transcript
        SMNS->>DB: Read transcript data
        DB-->>SMNS: Transcript details
        SMNS-->>Student: Show transcript with verification
    else not eligible
        SMNS-->>Student: Show blocking reasons
    end
```

## Recommended Use

- Use these simpler diagrams in the main report body.
- Use the fuller UML diagrams in [UML-System-Design.md](/r:/xxxamp/htdocs/smns/docs/UML-System-Design.md) as technical support.
