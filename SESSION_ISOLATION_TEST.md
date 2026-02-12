# Session Isolation Testing Guide

## What Was Implemented

Your Seminary Results Management System has **role-based session isolation with true multi-user support**. Each user has their own independent session, and multiple users can log in simultaneously without interfering with each other.

### Key Features:
1. **Role-Specific Session Names** - Each role uses a different session cookie name:
   - Admin: `SMNS_ADMIN_SESSION`
   - Student: `SMNS_STUDENT_SESSION`
   - Lecturer: `SMNS_LECTURER_SESSION`
   - Finance: `SMNS_FINANCE_SESSION`
   - Public: `SMNS_PUBLIC_SESSION` (for login page)

2. **Per-User Session Isolation** - Each user has a unique session ID:
   - User A's `SMNS_ADMIN_SESSION` = SessionID_A  
   - User B's `SMNS_ADMIN_SESSION` = SessionID_B
   - Both can be logged in as admins simultaneously!

3. **Session Fingerprinting** - Each user's session is tied to:
   - User's IP address
   - Browser user agent
   - Session role
   - Unique session token per login

4. **Session Validation** - Every page load checks:
   - IP hasn't changed
   - User agent hasn't changed
   - Role matches the session role
   - User role matches session role
   - Session hasn't timed out (1 hour)

## How to Test Session Isolation and Multi-User Access

### Test 1: Multiple Users, Same Role (Different Computers/Browsers)
1. **Computer/Browser 1** - Login as Admin User A
2. **Computer/Browser 2** - Login as Admin User B  
3. **Computer/Browser 3** - Login as Admin User C

**Expected Result:** ✅ All admins work simultaneously
- Each admin has their own session
- No one gets logged out
- All can access admin pages independently

### Test 2: Multiple Users, Different Roles
1. **Browser 1** - Login as Admin
2. **Browser 2** - Login as Student
3. **Browser 3** - Login as Lecturer
4. **Browser 4** - Login as Finance

**Expected Result:** ✅ All users work independently
- Admin can manage system
- Student can view grades
- Lecturer can enter marks
- Finance can record payments
- **All at the same time without interference!**

### Test 3: Same User, Same Browser (Role Switching)
1. Login as Admin in Chrome
2. In the SAME Chrome window, logout
3. Login as Student

**Expected Result:** ✅ Only the current user's session is affected
- Admin session cleared for THIS user
- Student session created for THIS user
- Other users remain logged in

### Test 4: Role Protection
1. Login as Student
2. Try to access admin URL directly: `http://localhost/smns/views/admin/dashboard.php`

**Expected Result:** ✅ Should redirect to student dashboard with "Access denied" message
- Student cannot access admin pages
- Student dashboard still works normally

### Test 5: Session Security (Per User)
1. Login successfully
2. Open browser developer tools (F12)
3. Go to Application → Cookies
4. **Observe:** You'll see a cookie like `SMNS_ADMIN_SESSION` (role-specific)
5. Copy the cookie value
6. Open incognito/private window
7. Try to paste the cookie and access protected pages
8. **Expected Result:** Should fail and redirect to login (different IP or user agent)

### Test 6: Session Timeout (Per User)
1. Login successfully
2. Wait 1 hour without any activity
3. Try to access any protected page
4. **Expected Result:** Should redirect to login with "session expired" message

### Test 7: Cross-Role Session Attempt
1. Login as student
2. Note your session cookie name (`SMNS_STUDENT_SESSION`)
3. Open browser dev tools → Application → Cookies
4. Try to manually create `SMNS_ADMIN_SESSION` cookie with same value
5. Access admin page
6. **Expected Result:** Should fail - admin session will be invalid

### Test 8: Multi-User Stress Test
1. Open 10 different browsers/devices
2. Login with 10 different users (mix of roles)
3. All users perform actions simultaneously
4. **Expected Result:** All users work independently without interference

## Technical Details

### Session Flow:

```
Login → Auth::login()
   ↓
Clear all role sessions (admin, student, lecturer, finance)
   ↓
Create new role-specific session (e.g., SMNS_STUDENT_SESSION)
   ↓
Set session data:
   - user_id
   - username
   - role
   - session_role (for validation)
   - session_token (unique per login)
   - login_time
   - fingerprint (SHA-256 hash)
   ↓
Redirect to role dashboard
```

### Page Access Flow:

```
Page Load (e.g., student/dashboard.php)
   ↓
Initialize Session('student') - opens SMNS_STUDENT_SESSION
   ↓
Initialize Auth('student') - uses student session
   ↓
Security::requireRole('student')
   ↓
Validate:
   ✓ Logged in?
   ✓ Session token exists?
   ✓ IP matches?
   ✓ User agent matches?
   ✓ Role matches session_role?
   ✓ User has required role?
   ↓
Grant Access or Redirect
```

## Files Modified for Session Isolation

1. **core/Session.php**
   - Added role parameter to constructor
   - Role-specific session names
   - Enhanced validation with role checking
   - Fingerprint includes role

2. **core/Auth.php**
   - Added role parameter to constructor
   - Removed `clearAllRoleSessions()` method (was logging out all users!)
   - Now only clears current user's session data on login
   - Creates fresh role-specific session for current user only

3. **core/Security.php**
   - `requireRole()` detects and uses role-specific sessions
   - Validates session role matches user role

4. **All Module Pages**
   - admin/dashboard.php: `new Session('admin')`, `new Auth('admin')`
   - student/dashboard.php: `new Session('student')`, `new Auth('student')`
   - lecturer/dashboard.php: `new Session('lecturer')`, `new Auth('lecturer')`
   - finance/dashboard.php: `new Session('finance')`, `new Auth('finance')`
   - And all sub-pages (list.php, etc.)

5. **includes/header.php**
   - Reuses existing role-specific session/auth objects
   - Falls back to role detection from URL path

6. **includes/functions.php**
   - Updated helpers to use global $session and $auth objects
   - Falls back gracefully if not available

7. **views/auth/login.php**
   - Uses public session for login page
   - Handles flash messages from public session

8. **views/auth/logout.php**
   - Detects current user's active role session
   - Destroys only the current user's session
   - Does NOT affect other users' sessions
   - Uses public session for flash message

9. **index.php**
   - Checks all role sessions to find active login
   - Redirects to appropriate dashboard

## What This Prevents

✅ **Session Hijacking** - IP and user agent must match (per user)
✅ **Session Fixation** - Session ID regenerated on login (per user)
✅ **Cross-Role Interference** - Each role has separate session space (per user)
✅ **Privilege Escalation** - Role validated on every request (per user)
✅ **Session Tampering** - Session role must match user role (per user)
✅ **Multi-User Interference** - Each user has unique session ID
✅ **Concurrent Login Support** - Multiple users can be logged in simultaneously

## Session Cookies You'll See

**Important:** Each user has their own unique session ID stored in their cookie value!

When logged in, check your browser cookies (F12 → Application → Cookies):
- If logged in as admin: `SMNS_ADMIN_SESSION = [your_unique_session_id]`
- If logged in as student: `SMNS_STUDENT_SESSION = [your_unique_session_id]`
- If logged in as lecturer: `SMNS_LECTURER_SESSION = [your_unique_session_id]`
- If logged in as finance: `SMNS_FINANCE_SESSION = [your_unique_session_id]`
- On login page: `SMNS_PUBLIC_SESSION = [your_unique_session_id]`

**For the same user:** You will NOT see multiple SMNS_*_SESSION cookies at once - only the one for your current role.

**For different users:** Each will have their own unique session ID value in their cookie, allowing simultaneous logins!

## Troubleshooting

### "Session expired" repeatedly
- Check if your IP is changing (e.g., VPN, mobile network)
- Check if browser is blocking cookies
- Verify session storage is writable (`tmp/` directory)

### Can't access a page after login
- Clear all browser cookies for localhost
- Try logging in again
- Check that role matches the module you're accessing

### "Access denied" on your own dashboard
- Clear browser cache and cookies
- Login again
- Verify user account has correct role in database

### Session seems to work but then fails
- Check session timeout (default: 1 hour)
- Check session regeneration (every 30 minutes)
- Verify you're not switching networks/IPs

## Best Practices

1. **Always initialize role-specific session first:**
   ```php
   $session = new Session('admin');
   $auth = new Auth('admin');
   ```

2. **Use Security::requireRole() on every protected page:**
   ```php
   Security::requireRole('admin');
   ```

3. **Never manually manipulate session cookies**

4. **Set HTTPS in production:**
   - Update session.cookie_secure to 1 in Session.php
   - Use SSL certificate

5. **Monitor session logs:**
   - Check error_log for unauthorized access attempts

## Production Deployment Checklist

Before going live:
- [ ] Enable HTTPS
- [ ] Set `session.cookie_secure` to `1` in Session.php
- [ ] Set `APP_DEBUG` to `false` in config
- [ ] Configure appropriate session timeout
- [ ] Set up session storage (Redis/Memcached for better performance)
- [ ] Enable rate limiting on login
- [ ] Set up monitoring for unauthorized access attempts

---

**Your system now has enterprise-grade session isolation with true multi-user support!** 

✅ Each user has their own independent session  
✅ Multiple users can log in simultaneously without interference  
✅ Each module operates securely with proper role-based access control  
✅ Role-based isolation prevents privilege escalation  
✅ Security features protect each user's session individually
