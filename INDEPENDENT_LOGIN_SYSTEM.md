# Independent Login System - Module-Based Authentication

## Overview

Each user role now has its own **independent login page** with role-specific authentication. This provides better security, clearer user experience, and complete module isolation.

---

## Login Pages by Role

### 🔴 Admin Login
**URL:** `http://localhost/smns/views/admin/login.php`
- Color Theme: Red (#dc3545)
- Icon: Shield
- Access: Administrators and staff only
- Validates: Only users with `role = 'admin'` can login

### 🔵 Student Login
**URL:** `http://localhost/smns/views/student/login.php`
- Color Theme: Blue (#007bff)
- Icon: Graduation Cap
- Access: Students only
- Validates: Only users with `role = 'student'` can login

### 🟢 Lecturer Login
**URL:** `http://localhost/smns/views/lecturer/login.php`
- Color Theme: Green (#28a745)
- Icon: Chalkboard Teacher
- Access: Faculty and lecturers only
- Validates: Only users with `role = 'lecturer'` can login

### 🟡 Finance Login
**URL:** `http://localhost/smns/views/finance/login.php`
- Color Theme: Yellow/Gold (#ffc107)
- Icon: Dollar Sign
- Access: Finance staff only
- Validates: Only users with `role = 'finance'` can login

---

## Main Portal Page

**URL:** `http://localhost/smns/` or `http://localhost/smns/index.php`

The landing page shows **4 login cards** with beautiful design:
- Each card has role-specific color and icon
- Click on any card to go to that role's login page
- If already logged in, automatically redirects to appropriate dashboard

---

## How It Works

### 1. **Role-Specific Validation**
Each login page validates the user's role:

```php
// Admin login validates role
if ($result['success']) {
    if ($result['role'] === 'admin') {
        // Allow login
        header('Location: dashboard.php');
    } else {
        // Reject - wrong role
        $error = 'Access denied. This login is for administrators only.';
        $auth->logout(); // Prevent wrong-role login
    }
}
```

### 2. **Session Isolation**
Each module uses its own session:
- Admin login → `SMNS_ADMIN_SESSION`
- Student login → `SMNS_STUDENT_SESSION`
- Lecturer login → `SMNS_LECTURER_SESSION`
- Finance login → `SMNS_FINANCE_SESSION`

### 3. **Already Logged In Check**
Before showing login form, checks if user is already logged in:

```php
// Check if admin is already logged in
session_name('SMNS_ADMIN_SESSION');
@session_start();

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    // Already logged in - go to dashboard
    header('Location: dashboard.php');
    exit;
}
```

### 4. **Logout Redirection**
Logout now redirects to the appropriate login page:
- Admin logs out → Redirects to admin login
- Student logs out → Redirects to student login
- Lecturer logs out → Redirects to lecturer login
- Finance logs out → Redirects to finance login

---

## Benefits of Independent Logins

✅ **Clear User Experience**
- Users know exactly which portal to use
- No confusion about roles
- Role-specific branding and messaging

✅ **Enhanced Security**
- Role validation at login prevents unauthorized access
- Each module has separate authentication entry point
- Wrong-role attempts are immediately rejected

✅ **Better Organization**
- Each department has its own login URL
- Can customize login page per role
- Easier to manage role-specific requirements

✅ **Complete Independence**
- Each module operates independently
- No shared login logic between roles
- Each can have different login requirements if needed

✅ **Professional Appearance**
- Color-coded by role (Red/Blue/Green/Gold)
- Role-specific icons
- Branded for each user type

---

## Access URLs

### For Users:

**Main Portal (Choose Your Role):**
```
http://localhost/smns/
```

**Direct Access by Role:**
```
Admin:     http://localhost/smns/views/admin/login.php
Student:   http://localhost/smns/views/student/login.php
Lecturer:  http://localhost/smns/views/lecturer/login.php
Finance:   http://localhost/smns/views/finance/login.php
```

**General Login (Auto-detects role):**
```
http://localhost/smns/views/auth/login.php
```

### For Bookmarks:
Users can bookmark their specific login page:
- Admins bookmark: `/views/admin/login.php`
- Students bookmark: `/views/student/login.php`
- Lecturers bookmark: `/views/lecturer/login.php`
- Finance staff bookmark: `/views/finance/login.php`

---

## Testing the Independent Logins

### Test 1: Admin Login
1. Go to `http://localhost/smns/views/admin/login.php`
2. Try logging in with student credentials
3. **Expected:** "Access denied. This login is for administrators only."
4. Login with admin credentials
5. **Expected:** Success → Admin dashboard

### Test 2: Student Login
1. Go to `http://localhost/smns/views/student/login.php`
2. Try logging in with admin credentials
3. **Expected:** "Access denied. This login is for students only."
4. Login with student credentials
5. **Expected:** Success → Student dashboard

### Test 3: Multiple Roles (Different Browsers)
1. **Browser 1:** Login as admin at admin login page
2. **Browser 2:** Login as student at student login page
3. **Browser 3:** Login as lecturer at lecturer login page
4. **Expected:** All work independently without interference

### Test 4: Already Logged In
1. Login as student
2. Go to student login page again
3. **Expected:** Automatically redirects to student dashboard

### Test 5: Logout Redirection
1. Login as admin at admin login page
2. Logout from admin dashboard
3. **Expected:** Redirected back to admin login page (not general login)

### Test 6: Main Portal
1. Go to `http://localhost/smns/`
2. **Expected:** See 4 colorful cards for each role
3. Click "Student Login" card
4. **Expected:** Goes to student login page

---

## Login Page Features

Each independent login page includes:

### 1. **Role-Specific Branding**
- Color-coded border and buttons
- Appropriate icon for role
- Role-specific title

### 2. **Security Features**
- CSRF token protection
- Role validation
- Session security
- Input sanitization

### 3. **User-Friendly**
- Clear error messages
- Flash message support
- Remember username on error
- Autofocus on username field

### 4. **Navigation**
- Link to general login (for flexibility)
- Link to forgot password (where applicable)
- Professional footer

---

## Customization Options

You can easily customize each login page:

### Change Colors:
Edit the `<style>` section in each login.php:
```php
.login-card { border-top: 4px solid #YOUR_COLOR; }
.btn-primary { background-color: #YOUR_COLOR; }
```

### Add Logo:
Place logo in `assets/images/logo.png` - it will show on all login pages.

### Change Titles:
Edit the text in `<h2>` and `<p>` tags:
```php
<h2><?php echo APP_SHORT_NAME; ?> - YOUR TITLE</h2>
<p>YOUR SUBTITLE</p>
```

### Add Extra Fields:
Add custom fields to any login form based on role requirements.

---

## File Structure

```
views/
├── admin/
│   ├── login.php          ← Independent admin login
│   └── dashboard.php
├── student/
│   ├── login.php          ← Independent student login
│   └── dashboard.php
├── lecturer/
│   ├── login.php          ← Independent lecturer login
│   └── dashboard.php
├── finance/
│   ├── login.php          ← Independent finance login
│   └── dashboard.php
└── auth/
    ├── login.php          ← General login (auto-redirects)
    └── logout.php         ← Role-aware logout
```

---

## Session Flow Diagram

```
User → Main Portal (index.php)
         ↓
   Choose Role Card
         ↓
    ┌─────┴─────┬─────┬─────┐
    ↓           ↓     ↓     ↓
Admin Login Student Lecturer Finance
    ↓        Login   Login   Login
    ↓           ↓     ↓     ↓
Validate    Validate Validate Validate
Role=admin  Role=std Role=lec Role=fin
    ↓           ↓     ↓     ↓
 Admin       Student Lecturer Finance
Dashboard   Dashboard Dashboard Dashboard
```

---

## Security Mechanisms

### 1. **Role Enforcement**
```php
if ($result['role'] !== 'expected_role') {
    $error = 'Access denied';
    $auth->logout(); // Prevent unauthorized login
}
```

### 2. **Session Validation**
Each session validates:
- IP address match
- User agent match
- Role match
- Session timeout

### 3. **CSRF Protection**
All login forms include CSRF tokens.

### 4. **Input Sanitization**
Username and passwords are sanitized before processing.

---

## Troubleshooting

### Issue: "Access denied" on correct login
**Solution:** Check user role in database matches the login page you're using.

### Issue: Redirect loop
**Solution:** Clear browser cookies and try again.

### Issue: Can't access any login page
**Solution:** Check Apache/PHP is running. Verify config.php settings.

### Issue: Login succeeds but shows error
**Solution:** Verify database connection and users table has correct roles.

---

## Summary

✅ **4 independent login pages** - one for each role  
✅ **Role-specific validation** - rejects wrong-role logins  
✅ **Beautiful main portal** - visual selection of roles  
✅ **Complete isolation** - separate sessions per role  
✅ **Professional design** - color-coded and icon-based  
✅ **Secure implementation** - CSRF, validation, sanitization  
✅ **Smart logout** - redirects to role-specific login  

**Each module is now completely independent with its own entry point!**

---

**Seminary Results Management System**  
Independent Login System Documentation  
Date: February 12, 2026
