# Quick Access - Login URLs

## Main Portal
**Choose your role visually:**
```
http://localhost/smns/
```

---

## Independent Login Pages

### 🔴 Administration Portal
```
http://localhost/smns/views/admin/login.php
```
- **For:** Administrators and system staff
- **Color:** Red
- **Validates:** Only admin role

---

### 🔵 Student Portal
```
http://localhost/smns/views/student/login.php
```
- **For:** Students
- **Color:** Blue
- **Validates:** Only student role

---

### 🟢 Faculty Portal
```
http://localhost/smns/views/lecturer/login.php
```
- **For:** Lecturers and instructors
- **Color:** Green
- **Validates:** Only lecturer role

---

### 🟡 Finance Portal
```
http://localhost/smns/views/finance/login.php
```
- **For:** Finance department staff
- **Color:** Gold/Yellow
- **Validates:** Only finance role

---

## General Login (Auto-redirects by role)
```
http://localhost/smns/views/auth/login.php
```

---

## Default Test Credentials

### Admin
- **Username:** `admin`
- **Password:** `password`
- **Login at:** `/views/admin/login.php`

### Student
- **Username:** `std001`
- **Password:** `password`
- **Login at:** `/views/student/login.php`

### Lecturer
- **Username:** `prof.johnson`
- **Password:** `password`
- **Login at:** `/views/lecturer/login.php`

### Finance
- **Username:** `finance1`
- **Password:** `password`
- **Login at:** `/views/finance/login.php`

---

## Bookmark These URLs

**For daily use, bookmark the login page for your role:**

| Role | Bookmark URL |
|------|-------------|
| Administrator | `http://localhost/smns/views/admin/login.php` |
| Student | `http://localhost/smns/views/student/login.php` |
| Lecturer | `http://localhost/smns/views/lecturer/login.php` |
| Finance | `http://localhost/smns/views/finance/login.php` |

---

## What Makes Each Login Independent?

✅ **Separate URL** for each role  
✅ **Role validation** - rejects wrong users  
✅ **Unique session** per role  
✅ **Custom branding** (color, icon, text)  
✅ **Independent security** per module  

---

**Try it now:** Visit `http://localhost/smns/` to see all login options!
