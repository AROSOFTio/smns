# SMNS Data Flow Diagrams

This document presents the Seminary Management and Results System (`SMNS`) using the standard DFD levels used in academic reports.

Use this structure in your report:

1. `Level 0` – Context Diagram
2. `Level 1` – Main subprocesses
3. `Level 2` – Detailed breakdown of major processes

## Level 0: Context Diagram

This level shows the whole system as one process and the external entities that interact with it.

```mermaid
flowchart LR
    student[Student]
    lecturer[Lecturer]
    admin[Admin]
    finance[Finance Staff]
    verifier[External Verifier]

    smns((SMNS System))

    student -->|registration, requests, payments, transcript access| smns
    smns -->|results, notifications, transcript, balances| student

    lecturer -->|marks entry, submitted results| smns
    smns -->|assigned courses, class lists| lecturer

    admin -->|student setup, approvals, release decisions, reports| smns
    smns -->|dashboards, reports, audit data| admin

    finance -->|fees, invoices, payment verification| smns
    smns -->|billing data, balances, payment records| finance

    verifier -->|verification token / code| smns
    smns -->|verification status| verifier
```

## Level 1: Main System Breakdown

This level breaks SMNS into its main functional subprocesses.

```mermaid
flowchart LR
    student[Student]
    lecturer[Lecturer]
    admin[Admin]
    finance[Finance Staff]
    verifier[External Verifier]

    p1((1.0 User and Profile Management))
    p2((2.0 Academic and Registration Management))
    p3((3.0 Results Management))
    p4((4.0 Finance and Billing Management))
    p5((5.0 Transcript and Verification Management))
    p6((6.0 Communication and Reporting))

    d1[(Users)]
    d2[(Students)]
    d3[(Programs / Courses / Semesters)]
    d4[(Registrations)]
    d5[(Results / GPA)]
    d6[(Invoices / Payments / Balances)]
    d7[(Transcript Records)]
    d8[(Requests / Notifications / Logs)]

    student --> p1
    student --> p2
    student --> p4
    student --> p5
    student --> p6

    lecturer --> p3
    admin --> p1
    admin --> p2
    admin --> p3
    admin --> p5
    admin --> p6
    finance --> p4
    verifier --> p5

    p1 <--> d1
    p1 <--> d2

    p2 <--> d2
    p2 <--> d3
    p2 <--> d4

    p3 <--> d4
    p3 <--> d5

    p4 <--> d2
    p4 <--> d6

    p5 <--> d2
    p5 <--> d5
    p5 <--> d6
    p5 <--> d7

    p6 <--> d8
    p6 <--> d5
    p6 <--> d6
    p6 <--> d7
```

## Level 2: Academic and Registration Management

This level expands process `2.0 Academic and Registration Management`.

```mermaid
flowchart LR
    student[Student]
    admin[Admin]

    p21((2.1 Manage Programs and Courses))
    p22((2.2 Manage Academic Years and Semesters))
    p23((2.3 Semester Registration))
    p24((2.4 Course Registration))
    p25((2.5 Registration Approval))

    d1[(Programs)]
    d2[(Courses)]
    d3[(Academic Years)]
    d4[(Semesters)]
    d5[(Semester Registrations)]
    d6[(Course Registrations)]
    d7[(Student Records)]

    admin --> p21
    admin --> p22
    student --> p23
    student --> p24
    admin --> p25

    p21 <--> d1
    p21 <--> d2
    p22 <--> d3
    p22 <--> d4
    p23 <--> d5
    p23 <--> d7
    p24 <--> d6
    p24 <--> d2
    p24 <--> d4
    p25 <--> d5
    p25 <--> d6
```

## Level 2: Results Management

This level expands process `3.0 Results Management`.

```mermaid
flowchart LR
    lecturer[Lecturer]
    admin[Admin]
    student[Student]

    p31((3.1 View Assigned Courses))
    p32((3.2 Enter Marks))
    p33((3.3 Submit Results))
    p34((3.4 Approve and Publish Results))
    p35((3.5 Compute GPA and Standing))

    d1[(Course Assignments)]
    d2[(Course Registrations)]
    d3[(Results)]
    d4[(Student GPA)]
    d5[(Results Audit)]

    lecturer --> p31
    lecturer --> p32
    lecturer --> p33
    admin --> p34
    student --> p35

    p31 <--> d1
    p31 <--> d2
    p32 <--> d3
    p33 <--> d3
    p34 <--> d3
    p34 <--> d5
    p35 <--> d3
    p35 <--> d4
```

## Level 2: Finance and Billing Management

This level expands process `4.0 Finance and Billing Management`.

```mermaid
flowchart LR
    student[Student]
    finance[Finance Staff]
    admin[Admin]

    p41((4.1 Configure Fee Structure))
    p42((4.2 Generate Invoices))
    p43((4.3 Record and Verify Payments))
    p44((4.4 Update Student Balances))
    p45((4.5 Check Financial Clearance))

    d1[(Fee Structure)]
    d2[(Invoices)]
    d3[(Payments)]
    d4[(Student Balances)]
    d5[(Student Records)]

    admin --> p41
    finance --> p42
    finance --> p43
    student --> p43
    finance --> p44
    finance --> p45

    p41 <--> d1
    p42 <--> d1
    p42 <--> d2
    p42 <--> d5
    p43 <--> d3
    p43 <--> d2
    p44 <--> d3
    p44 <--> d4
    p45 <--> d4
    p45 <--> d5
```

## Level 2: Transcript and Verification Management

This level expands process `5.0 Transcript and Verification Management`.

```mermaid
flowchart LR
    student[Student]
    admin[Admin]
    verifier[External Verifier]

    p51((5.1 Check Transcript Eligibility))
    p52((5.2 Grant Transcript Rights))
    p53((5.3 Build Transcript Snapshot))
    p54((5.4 Issue Transcript))
    p55((5.5 Verify Transcript Authenticity))

    d1[(Student Records)]
    d2[(Results / GPA)]
    d3[(Payments / Balances)]
    d4[(Transcript Rights)]
    d5[(Transcript Issuances)]
    d6[(Verification Ledger)]

    student --> p51
    admin --> p52
    student --> p53
    student --> p54
    verifier --> p55

    p51 <--> d1
    p51 <--> d2
    p51 <--> d3
    p52 <--> d4
    p53 <--> d1
    p53 <--> d2
    p53 <--> d4
    p54 <--> d5
    p54 <--> d6
    p55 <--> d5
    p55 <--> d6
```

## Recommended Report Usage

- Use `Level 0` in the system overview section.
- Use `Level 1` in the analysis or design chapter.
- Use the `Level 2` diagrams in the detailed system design section.

## Recommended Order In Your Report

1. Context Diagram (`Level 0`)
2. DFD Level 1
3. DFD Level 2 for Academic and Registration
4. DFD Level 2 for Results
5. DFD Level 2 for Finance
6. DFD Level 2 for Transcript and Verification
