# Independent Login System Architecture

## Visual Flow Diagram

```mermaid
graph TD
    Start[User Visits<br/>localhost/smns] --> Portal[Main Portal Page<br/>4 Login Cards]
    
    Portal -->|Click Red Card| AdminLogin[Admin Login<br/>views/admin/login.php]
    Portal -->|Click Blue Card| StudentLogin[Student Login<br/>views/student/login.php]
    Portal -->|Click Green Card| LecturerLogin[Lecturer Login<br/>views/lecturer/login.php]
    Portal -->|Click Gold Card| FinanceLogin[Finance Login<br/>views/finance/login.php]
    
    AdminLogin --> AdminValidate{Role = admin?}
    StudentLogin --> StudentValidate{Role = student?}
    LecturerLogin --> LecturerValidate{Role = lecturer?}
    FinanceLogin --> FinanceValidate{Role = finance?}
    
    AdminValidate -->|Yes| AdminDash[Admin Dashboard<br/>SMNS_ADMIN_SESSION]
    AdminValidate -->|No| AdminError[Access Denied<br/>Admins Only]
    
    StudentValidate -->|Yes| StudentDash[Student Dashboard<br/>SMNS_STUDENT_SESSION]
    StudentValidate -->|No| StudentError[Access Denied<br/>Students Only]
    
    LecturerValidate -->|Yes| LecturerDash[Lecturer Dashboard<br/>SMNS_LECTURER_SESSION]
    LecturerValidate -->|No| LecturerError[Access Denied<br/>Lecturers Only]
    
    FinanceValidate -->|Yes| FinanceDash[Finance Dashboard<br/>SMNS_FINANCE_SESSION]
    FinanceValidate -->|No| FinanceError[Access Denied<br/>Finance Only]
    
    AdminDash --> AdminLogout[Logout]
    StudentDash --> StudentLogout[Logout]
    LecturerDash --> LecturerLogout[Logout]
    FinanceDash --> FinanceLogout[Logout]
    
    AdminLogout --> AdminLogin
    StudentLogout --> StudentLogin
    LecturerLogout --> LecturerLogin
    FinanceLogout --> FinanceLogin
    
    style Portal fill:#f9f9f9,stroke:#333,stroke-width:3px
    style AdminLogin fill:#ffcccc,stroke:#dc3545,stroke-width:2px
    style StudentLogin fill:#cce5ff,stroke:#007bff,stroke-width:2px
    style LecturerLogin fill:#d4edda,stroke:#28a745,stroke-width:2px
    style FinanceLogin fill:#fff3cd,stroke:#ffc107,stroke-width:2px
    style AdminDash fill:#ff9999,stroke:#dc3545,stroke-width:3px
    style StudentDash fill:#99ccff,stroke:#007bff,stroke-width:3px
    style LecturerDash fill:#99ff99,stroke:#28a745,stroke-width:3px
    style FinanceDash fill:#ffeb99,stroke:#ffc107,stroke-width:3px
    style AdminError fill:#f8d7da,stroke:#721c24,stroke-width:2px
    style StudentError fill:#d1ecf1,stroke:#0c5460,stroke-width:2px
    style LecturerError fill:#d1e7dd,stroke:#0f5132,stroke-width:2px
    style FinanceError fill:#fff3cd,stroke:#856404,stroke-width:2px
```

## Key Features

### 🎯 Independent Entry Points
Each role has its own dedicated login page with unique URL:
- **Admin:** Red card → `/views/admin/login.php`
- **Student:** Blue card → `/views/student/login.php`
- **Lecturer:** Green card → `/views/lecturer/login.php`
- **Finance:** Gold card → `/views/finance/login.php`

### 🔒 Role Validation
Each login validates the user's role:
- Admin login only accepts users with `role = 'admin'`
- Student login only accepts users with `role = 'student'`
- Lecturer login only accepts users with `role = 'lecturer'`
- Finance login only accepts users with `role = 'finance'`

**Wrong role = Access Denied!**

### 🎨 Color-Coded Design
- 🔴 **Admin:** Red (#dc3545) - Administrative authority
- 🔵 **Student:** Blue (#007bff) - Academic focus
- 🟢 **Lecturer:** Green (#28a745) - Educational growth
- 🟡 **Finance:** Gold (#ffc107) - Financial transactions

### 🔄 Smart Logout
Logout redirects back to the appropriate role-specific login page:
- Admin logs out → Returns to admin login
- Student logs out → Returns to student login
- Lecturer logs out → Returns to lecturer login
- Finance logs out → Returns to finance login

### 🛡️ Session Isolation
Each role uses a separate session namespace:
- `SMNS_ADMIN_SESSION`
- `SMNS_STUDENT_SESSION`
- `SMNS_LECTURER_SESSION`
- `SMNS_FINANCE_SESSION`

**Complete independence - no interference between roles!**

---

## User Journey Examples

### Example 1: Admin Login
```
1. Visit localhost/smns
2. See 4 colorful cards
3. Click RED "Administration" card
4. Redirected to /views/admin/login.php
5. Enter admin credentials
6. System validates: role === 'admin' ✓
7. Create SMNS_ADMIN_SESSION
8. Redirect to Admin Dashboard
9. Work as admin...
10. Click Logout
11. Redirected back to /views/admin/login.php
```

### Example 2: Student Trying Admin Login (Access Denied)
```
1. Visit /views/admin/login.php directly
2. Enter student credentials
3. System validates: role === 'student' ✗
4. Error: "Access denied. This login is for administrators only."
5. User remains on admin login page
6. No session created - authentication rejected
```

### Example 3: Multi-User Scenario
```
Browser 1: Admin logs in at /views/admin/login.php
          → SMNS_ADMIN_SESSION created ✓
          
Browser 2: Student logs in at /views/student/login.php
          → SMNS_STUDENT_SESSION created ✓
          
Browser 3: Lecturer logs in at /views/lecturer/login.php
          → SMNS_LECTURER_SESSION created ✓
          
Browser 4: Finance logs in at /views/finance/login.php
          → SMNS_FINANCE_SESSION created ✓

All 4 users work simultaneously - complete independence!
```

---

## Benefits Summary

✅ **Clear User Experience** - Users know exactly where to login
✅ **Enhanced Security** - Role validation at entry point
✅ **Complete Independence** - Each module has own login
✅ **Professional Design** - Color-coded and icon-based
✅ **Better Organization** - Logical separation by department
✅ **Flexible Bookmarks** - Users bookmark their specific login
✅ **Smart Redirects** - Logout returns to correct login page
✅ **Multi-User Support** - All roles can work simultaneously

---

**Seminary Results Management System**  
Independent Login System Architecture  
Date: February 12, 2026
