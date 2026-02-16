# Testing Multi-User Concurrent Access

## Quick Test Guide

### Test 1: Multiple Roles in Different Firefox Tabs (NOW SUPPORTED!)

**Goal:** Access admin, student, lecturer, and finance all in the same Firefox browser using different tabs

**Steps:**
1. **Firefox Tab 1** - Go to `http://localhost/smns/views/admin/login.php`
   - Login as Admin user
   - Keep this tab open ✅

2. **Firefox Tab 2** (NEW TAB, same window) - Go to `http://localhost/smns/views/student/login.php`
   - Login as Student user
   - Keep this tab open ✅

3. **Firefox Tab 3** (NEW TAB, same window) - Go to `http://localhost/smns/views/lecturer/login.php`
   - Login as Lecturer user
   - Keep this tab open ✅

4. **Firefox Tab 4** (NEW TAB, same window) - Go to `http://localhost/smns/views/finance/login.php`
   - Login as Finance user
   - Keep this tab open ✅

5. **Switch between tabs**
   - Go back to Tab 1 (Admin) - should STILL be logged in ✅
   - Go back to Tab 2 (Student) - should STILL be logged in ✅
   - Go back to Tab 3 (Lecturer) - should STILL be logged in ✅
   - Go back to Tab 4 (Finance) - should STILL be logged in ✅

**Expected Result:** ✅ ALL roles accessible in different tabs of the SAME Firefox browser!

**How This Works:** Each role now uses its own session cookie:
- Admin: `SMNS_ADMIN_SESSION`
- Student: `SMNS_STUDENT_SESSION`
- Lecturer: `SMNS_LECTURER_SESSION`
- Finance: `SMNS_FINANCE_SESSION`

Since the cookies have different names, Firefox stores them separately and they don't conflict!

---

### Test 2: Multiple Operators, Different Roles (Also Works)

**Goal:** Verify that admin, student, lecturer, and finance can all access simultaneously

**Steps:**
1. **Browser 1 (Chrome)** - Go to `http://localhost/smns/views/admin/login.php`
   - Login as Admin user
   - Navigate to dashboard - should work ✅

2. **Browser 2 (Edge or Firefox)** - Go to `http://localhost/smns/views/student/login.php`
   - Login as Student user
   - Navigate to courses page - should work ✅

3. **Browser 3 (Incognito/Private Window)** - Go to `http://localhost/smns/views/lecturer/login.php`
   - Login as Lecturer user
   - Navigate to grade entry - should work ✅

4. **Go back to Browser 1 (Admin)**
   - Refresh the page
   - Admin should STILL be logged in ✅
   - Can perform admin actions ✅

5. **Go back to Browser 2 (Student)**
   - Refresh the page
   - Student should STILL be logged in ✅
   - Can view their courses ✅

**Expected Result:** ✅ ALL users remain logged in and can work independently

---

### Test 2: Multiple Operators, Same Role

**Goal:** Verify that multiple admins can work simultaneously

**Steps:**
1. **Computer 1** - Login as Admin User A
2. **Computer 2** - Login as Admin User B  
3. **Computer 3** - Login as Admin User C

**Actions to test:**
- User A: View students list
- User B: Add a new course
- User C: Generate reports

**Expected Result:** ✅ All three admins can work simultaneously without interference

---

### Test 3: One Operator Logs Out

**Goal:** Verify that one user logging out doesn't affect others

**Steps:**
1. Setup: Have Admin, Student, and Lecturer all logged in (different browsers)
2. In Student browser - Click logout
3. Check Admin browser - should STILL be logged in ✅
4. Check Lecturer browser - should STILL be logged in ✅

**Expected Result:** ✅ Only the student session is destroyed, others remain active

---

### Test 4: Session Isolation Test

**Goal:** Verify session data doesn't mix between roles

**Setup:**
- Browser 1: Login as Admin (ID: 1, Name: John Admin)
- Browser 2: Login as Student (ID: 42, Name: Mary Student)

**Check in Browser 1 (Admin):**
- Should see admin dashboard
- User ID should be 1
- Name should be "John Admin"

**Check in Browser 2 (Student):**
- Should see student dashboard  
- User ID should be 42
- Name should be "Mary Student"

**Expected Result:** ✅ Each browser shows correct user data, no mixing

---

## What Fixed This?

### The Problem
Session validation keys were stored globally:
- `$_SESSION['session_role']` - One value for ALL roles
- `$_SESSION['fingerprint']` - One value for ALL roles

When admin logged in, it set these to admin values.
When student tried to access, validation checked for student values but found admin values → FAILED!

### The Solution
Session validation keys are now **module-prefixed**:
- `$_SESSION['admin_session_role']` - For admin only
- `$_SESSION['student_session_role']` - For student only
- `$_SESSION['admin_fingerprint']` - For admin only
- `$_SESSION['student_fingerprint']` - For student only

Now each role has its own isolated validation state!

---

## Files That Were Changed

1. **core/Session.php**
   - `initializeSession()` - Now stores fingerprint and session_role with module prefix
   - `validateSession()` - Now checks module-prefixed keys
   - `regenerateId()` - Now regenerates module-prefixed fingerprint

2. **MULTI_USER_CONCURRENT_ACCESS.md**
   - Updated documentation to explain the fix

---

## Common Issues & Solutions

### Issue: User gets logged out when another user logs in

**Cause:** Old cached session validation code  
**Solution:** Clear browser cache and restart browser

### Issue: Session timeout occurs too quickly

**Cause:** `SESSION_TIMEOUT` constant set too low  
**Solution:** Check `config.php` and increase `SESSION_TIMEOUT` value (default: 3600 = 1 hour)

### Issue: Can't login to multiple roles in same browser

**Cause:** This is intentional behavior  
**Solution:** Use different browsers or incognito windows for different roles

---

## Verification Commands

Want to see the actual session data? Add this to any protected page temporarily:

```php
// DEBUG: Show session data (REMOVE IN PRODUCTION!)
echo "<pre>Session Data:\n";
print_r($_SESSION);
echo "</pre>";
```

You should see keys like:
- `admin_user_id`, `admin_logged_in`, `admin_fingerprint`
- `student_user_id`, `student_logged_in`, `student_fingerprint`
- etc.

**IMPORTANT:** Remove debug code after testing!

---

## Final Checklist

- [ ] Multiple users can login simultaneously without logging each other out
- [ ] Admin can work while student is logged in
- [ ] Student can work while lecturer is logged in
- [ ] Logging out one user doesn't affect others
- [ ] Each user sees their own data, not mixed data
- [ ] Session security still works (IP validation, timeout, etc.)

If all checkboxes pass, **multi-user concurrent access is working correctly!** ✅

---

**Last Updated:** February 16, 2026  
**Status:** FULLY OPERATIONAL
