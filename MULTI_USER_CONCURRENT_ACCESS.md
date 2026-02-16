# Multi-User Concurrent Access - FIXED AND ENHANCED

## Problem That Was Fixed

**BEFORE:** When User A logged in, it destroyed ALL sessions globally, logging out User B, User C, etc. Additionally, session validation conflicts prevented multiple roles from accessing simultaneously.

**NOW:** Each user has their own independent session. Multiple users can log in simultaneously without interfering with each other. Session validation is isolated per role.

---

## Recent Enhancement (Session Isolation Fix)

### What Was Fixed
The system had **module-isolated session keys** (e.g., `admin_user_id`, `student_user_id`) but the **session validation** was still using **global keys** (`session_role`, `fingerprint`) which caused conflicts.

**Example of the Problem:**
1. Admin logs in → Sets `$_SESSION['session_role'] = 'admin'` and `$_SESSION['fingerprint'] = hash(...'admin'...)`
2. Student tries to access → Checks if `$_SESSION['session_role'] === 'student'` → **FAILS** (it's still 'admin')
3. Student gets logged out even though they should have independent access

**The Fix:**
Changed all session validation keys to use **module prefixes**:
- `$_SESSION['session_role']` → `$_SESSION['admin_session_role']`, `$_SESSION['student_session_role']`, etc.
- `$_SESSION['fingerprint']` → `$_SESSION['admin_fingerprint']`, `$_SESSION['student_fingerprint']`, etc.

Now each role has completely isolated session validation!

---

## How Sessions Work Now

### Understanding PHP Sessions

Each user's browser stores a **unique session ID** in a cookie. This session ID is different for every user:

```
User A's Browser → Cookie: SMNS_ADMIN_SESSION = abc123xyz
User B's Browser → Cookie: SMNS_STUDENT_SESSION = def456uvw
User C's Browser → Cookie: SMNS_ADMIN_SESSION = ghi789rst
```

Even though User A and User C both have admin roles (same cookie NAME), they have **different session IDs** (different cookie VALUES), so their sessions are completely separate.

**NEW:** Because each role uses a different cookie name (SMNS_ADMIN_SESSION, SMNS_STUDENT_SESSION, etc.), you can also:
- Open multiple tabs in the SAME browser
- Login to different roles in each tab
- All tabs remain active simultaneously!

Example: In one Firefox browser:
- Tab 1: Admin logged in (uses SMNS_ADMIN_SESSION cookie)
- Tab 2: Student logged in (uses SMNS_STUDENT_SESSION cookie)
- Tab 3: Lecturer logged in (uses SMNS_LECTURER_SESSION cookie)
- Tab 4: Finance logged in (uses SMNS_FINANCE_SESSION cookie)

All work at the same time because Firefox stores each cookie separately! ✓

### Session Isolation By Role AND User

```
┌─────────────────────────────────────────────────────┐
│ User A (Admin) - Browser 1                          │
│ Cookie: SMNS_ADMIN_SESSION = SessionID_A            │
│ Session Data: {user_id: 1, role: admin, ...}        │
└─────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────┐
│ User B (Student) - Browser 2                        │
│ Cookie: SMNS_STUDENT_SESSION = SessionID_B          │
│ Session Data: {user_id: 5, role: student, ...}      │
└─────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────┐
│ User C (Admin) - Browser 3                          │
│ Cookie: SMNS_ADMIN_SESSION = SessionID_C            │
│ Session Data: {user_id: 2, role: admin, ...}        │
└─────────────────────────────────────────────────────┘

All three users are logged in SIMULTANEOUSLY ✓
```

---

## What Changed in The Code

### 1. **Login Process (Auth.php)** - FIXED ✓

**BEFORE (WRONG):**
```php
// This destroyed ALL users' sessions!
$this->clearAllRoleSessions(); // BAD!
```

**NOW (CORRECT):**
```php
// Only clears THIS user's session data
session_regenerate_id(true);
$_SESSION = []; // Clears only THIS user's data
$this->session = new Session($user['role']);
```

### 2. **Logout Process (logout.php)** - FIXED ✓

**BEFORE (WRONG):**
```php
// This destroyed ALL users' sessions!
foreach ($roles as $role) {
    session_name('SMNS_' . strtoupper($role) . '_SESSION');
    session_start();
    $_SESSION = [];
    session_destroy(); // Destroyed everyone's sessions!
}
```

**NOW (CORRECT):**
```php
// Find THIS user's active session
foreach ($roles as $role) {
    session_name('SMNS_' . strtoupper($role) . '_SESSION');
    session_start();
    
    if (isset($_SESSION['logged_in'])) {
        // Found THIS user's session - destroy only THIS one
        $auth = new Auth($role);
        $auth->logout(); // Destroys only THIS user's session
        break;
    }
}
```

### 3. **Session Names Still Provide Role Isolation**

The role-specific session names still serve an important purpose:

**For the SAME user in the SAME browser:**
- If logged in as admin → can't also be logged in as student
- Switching roles requires logout and login
- Prevents one person from being logged into multiple roles simultaneously

**For DIFFERENT users in DIFFERENT browsers:**
- Each has their own unique session ID
- Complete independence
- No interference

### 4. **Module-Isolated Session Storage** - NEW!

All session data is now stored with module prefixes to prevent conflicts:

**Session Keys Structure:**
```php
// BEFORE (Conflicted):
$_SESSION['session_role'] = 'admin';      // ❌ Global key
$_SESSION['fingerprint'] = 'hash...';     // ❌ Gets overwritten

// NOW (Isolated):
$_SESSION['admin_session_role'] = 'admin';        // ✅ Module-specific
$_SESSION['admin_fingerprint'] = 'hash_admin';    // ✅ Won't conflict
$_SESSION['admin_user_id'] = 1;                   // ✅ Isolated
$_SESSION['admin_logged_in'] = true;              // ✅ Independent

$_SESSION['student_session_role'] = 'student';    // ✅ Different module
$_SESSION['student_fingerprint'] = 'hash_student';// ✅ Different hash
$_SESSION['student_user_id'] = 42;                // ✅ Different user
$_SESSION['student_logged_in'] = true;            // ✅ Coexists!
```

**How This Enables True Multi-Access:**
1. Admin logs in → Creates `admin_*` session keys
2. Student accesses (same browser session) → Creates `student_*` session keys
3. Session validation checks `admin_fingerprint` for admin, `student_fingerprint` for student
4. **No conflicts!** Each module has its own validation state

---

## Testing Multi-User Access

### Test 1: Multiple Users, Same Role

1. **Computer 1** - Login as Admin User A
2. **Computer 2** - Login as Admin User B
3. **Computer 3** - Login as Admin User C

**Result:** ✅ All three admins can work simultaneously
- User A can view students
- User B can add courses
- User C can generate reports
- **No one gets logged out!**

### Test 2: Multiple Users, Different Roles

1. **Browser 1** - Login as Admin
2. **Browser 2** - Login as Student
3. **Browser 3** - Login as Lecturer
4. **Browser 4** - Login as Finance

**Result:** ✅ All four users work independently
- Admin manages system
- Student views grades
- Lecturer enters marks
- Finance records payments
### Test 2: Multiple Users, Different Roles

1. **Browser 1** - Login as Admin
2. **Browser 2** - Login as Student
3. **Browser 3** - Login as Lecturer
4. **Browser 4** - Login as Finance

**Result:** ✅ All four users work independently
- Admin manages system
- Student views grades
- Lecturer enters marks
- Finance records payments
- **All at the same time!**

### Test 3: Multiple Roles, One Browser (NEW!)

**NOW SUPPORTED:** You can login to different roles in different tabs of the SAME browser!

1. **Firefox Tab 1** - Login as Admin
2. **Firefox Tab 2** - Open new tab, login as Student
3. **Firefox Tab 3** - Open new tab, login as Lecturer
4. **Firefox Tab 4** - Open new tab, login as Finance

**Result:** ✅ All four roles active in same browser!
- Each role uses its own cookie (SMNS_ADMIN_SESSION, SMNS_STUDENT_SESSION, etc.)
- Tabs don't interfere with each other
- Perfect for testing or multi-role administrators
- Switch between tabs freely - all remain logged in!

**How This Works:** 
- Each role has a unique session cookie name
- Firefox stores different cookies separately
- No conflicts even in the same browser!

### Test 4: Same User, Different Browsers (Still Works)

1. **Chrome** - Login as Admin
2. **Edge** - Login as Student (same account if multi-role user)

**Result:** ✅ Both sessions active
- Different browsers = different session cookies
- Can have both open for testing purposes

---

## Session Security Features (Still Active)

Each user's session is still protected by:

✅ **IP Validation** - Session locked to user's IP address (shared across all modules)
✅ **User Agent Validation** - Session locked to user's browser (shared across all modules)
✅ **Role Validation** - User role must match session role (**module-isolated**)
✅ **Fingerprint Validation** - Unique security fingerprint (**module-isolated**)
✅ **Session Token** - Unique token per login session (**module-isolated**)
✅ **Timeout** - Auto-logout after 1 hour of inactivity (tracked per module)
✅ **Session Regeneration** - ID refreshed every 30 minutes

**Key Point:** Security features marked as **module-isolated** use prefixed keys (e.g., `admin_fingerprint`, `student_fingerprint`) so they don't conflict between different roles accessing simultaneously.

But these security features apply **per user**, not globally!

---

## Real-World Scenario Examples

### Scenario 1: School Office
```
9:00 AM - Admin at Front Desk logs in → Active ✓
9:30 AM - Finance Officer logs in → Both Active ✓
10:00 AM - Registrar logs in → All Three Active ✓
11:00 AM - Dean logs in → All Four Active ✓

Everyone works simultaneously without issues!
```

### Scenario 2: Student Lab
```
20 Students login at same time
- Student 1 → Views grades ✓
- Student 2 → Views grades ✓
- Student 3 → Registers for courses ✓
- ...
- Student 20 → Views timetable ✓

All 20 students work independently!
```

### Scenario 3: Lecturer Marking
```
5 Lecturers marking results simultaneously
- Lecturer A → Enters Theology marks ✓
- Lecturer B → Enters Philosophy marks ✓
- Lecturer C → Enters Church History marks ✓
- Lecturer D → Enters Biblical Studies marks ✓
- Lecturer E → Reviews submitted marks ✓

All can work in parallel!
```

---

## How Session Data is Stored

### Server-Side Files (in tmp/ directory):

```
sess_abc123xyz (User A's Admin Session)
├── user_id: 1
├── role: admin
├── username: admin_user
├── fingerprint: [hash]
└── ...

sess_def456uvw (User B's Student Session)
├── user_id: 5
├── role: student
├── username: student_user
├── fingerprint: [hash]
└── ...

sess_ghi789rst (User C's Admin Session)
├── user_id: 2
├── role: admin
├── username: another_admin
├── fingerprint: [hash]
└── ...
```

Each file is **completely independent**. Destroying one doesn't affect the others.

---

## What Happens When You Login

```
1. User enters credentials
   ↓
2. System validates password
   ↓
3. Generate NEW unique session ID for THIS user
   ↓
4. Clear any OLD session data for THIS user
   ↓
5. Create fresh session with role-specific name
   ↓
6. Store THIS user's data in THIS user's session
   ↓
7. Send session cookie to THIS user's browser
   ↓
8. Other users' sessions remain untouched ✓
```

---

## What Happens When You Logout

```
1. System identifies YOUR session (YOUR session ID from YOUR cookie)
   ↓
2. Destroy YOUR session file on server
   ↓
3. Delete YOUR session cookie in YOUR browser
   ↓
4. Other users' sessions remain active ✓
```

---

## Key Takeaway

**Each user's browser has its own unique session ID.**

When the code does:
```php
$_SESSION = []; // Clear session
session_destroy(); // Destroy session
```

It ONLY affects the session associated with the **current request's session ID** (from the current user's cookie).

It does NOT affect other users' sessions with different session IDs!

---

## Summary

✅ **Multiple users can login simultaneously**
✅ **Each user has independent session**  
✅ **No interference between users**
✅ **Role-based isolation still works (per user)**
✅ **Security features still active (per user)**
✅ **Sessions only destroyed for the user who logs out**
✅ **Login doesn't affect other logged-in users**
✅ **Module-isolated session validation** - No conflicts between role-based session checks
✅ **Multi-role access in same browser (NEW!)** - Open admin, student, lecturer, finance in different tabs!

**The system now supports true multi-user concurrent access!**

**NEW FEATURE:** Each role uses its own session cookie name (SMNS_ADMIN_SESSION, SMNS_STUDENT_SESSION, etc.), allowing you to:
- Open multiple tabs in Firefox (or any browser)
- Login to different roles in each tab
- All tabs remain active simultaneously
- Perfect for testing or administrators managing multiple portals

---

## Recent Enhancements (February 16, 2026)

### Enhancement 1: Module-Isolated Session Validation

**Problem:** Session validation keys (`session_role`, `fingerprint`) were stored globally, causing validation failures when multiple roles tried to access the system simultaneously.

**Solution:** All session validation keys are now **module-prefixed**:

| Old (Global) | New (Module-Prefixed) |
|--------------|----------------------|
| `$_SESSION['session_role']` | `$_SESSION['admin_session_role']`, `$_SESSION['student_session_role']`, etc. |
| `$_SESSION['fingerprint']` | `$_SESSION['admin_fingerprint']`, `$_SESSION['student_fingerprint']`, etc. |

### Enhancement 2: Role-Specific Session Cookie Names

**Problem:** All roles used the same session name (`SMNS_SESSION`), meaning you couldn't login to different roles in different tabs of the same browser.

**Solution:** Each role now has its own session cookie name:

| Role | Session Cookie Name |
|------|---------------------|
| Admin | `SMNS_ADMIN_SESSION` |
| Student | `SMNS_STUDENT_SESSION` |
| Lecturer | `SMNS_LECTURER_SESSION` |
| Finance | `SMNS_FINANCE_SESSION` |

**Benefit:** You can now open Firefox (or any browser) and login to all four roles in different tabs simultaneously!

### Files Modified
- `core/Session.php` - Updated `__construct()` to use role-specific session names
- `core/Session.php` - Updated `initializeSession()`, `validateSession()`, `regenerateId()` for module isolation
- Session validation now checks module-specific keys instead of global keys

### Result
✅ Admin can access admin portal  
✅ Student can access student portal  
✅ Lecturer can access lecturer portal  
✅ Finance can access finance portal  
✅ **All at the same time without any interference!**
✅ **Even in the same browser, different tabs!**

---

**Seminary Results Management System**  
Multi-User Concurrent Access Documentation  
Last Updated: February 16, 2026
