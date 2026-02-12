# SMNS Setup Guide

## Quick Setup Steps

### 1. Start XAMPP
- Start Apache
- Start MySQL

### 2. Create Database
Open phpMyAdmin (http://localhost/phpmyadmin) and:

**Option A: Using phpMyAdmin**
1. Click "New" to create a database
2. Name it: `smns`
3. Collation: `utf8mb4_unicode_ci`
4. Click "Import" tab
5. Choose file: `Seed/schema.sql`
6. Click "Go"
7. Choose file: `Seed/seed.sql`
8. Click "Go"

**Option B: Using Command Line**
```bash
# Navigate to XAMPP MySQL bin
cd C:\xxxamp\mysql\bin

# Login to MySQL
mysql.exe -u root -p

# Create database
CREATE DATABASE smns CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smns;

# Import schema
source R:/xxxamp/htdocs/smns/Seed/schema.sql

# Import seed data
source R:/xxxamp/htdocs/smns/Seed/seed.sql
```

### 3. Verify Setup
Navigate to: http://localhost/smns/setup.php

This will check:
- ✓ Configuration file
- ✓ Database connection
- ✓ Tables created
- ✓ Test users exist
- ✓ PHP extensions

### 4. Test Login
Navigate to: http://localhost/smns/test-login.php

This will test:
- ✓ Database connectivity
- ✓ Authentication system
- ✓ Session management
- ✓ Password verification

### 5. Access System
Navigate to: http://localhost/smns/

## Default Test Credentials

All test users have password: `password`

| Role     | Username       | Password |
|----------|---------------|----------|
| Admin    | admin         | password |
| Student  | std001        | password |
| Lecturer | prof.johnson  | password |
| Finance  | finance1      | password |

## Troubleshooting

### "Database connection failed"
- Check XAMPP MySQL is running
- Verify config.php settings (DB_HOST, DB_NAME, DB_USER, DB_PASS)
- Default MySQL port is 3306

### "Users table does not exist"
- Import `Seed/schema.sql` first
- Then import `Seed/seed.sql`

### "Login not working"
- Run http://localhost/smns/test-login.php
- Check if users exist in database
- Verify password hash matches

### "Page not found" errors
- Check Apache is running
- Verify DocumentRoot points to `R:\xxxamp\htdocs`
- Clear browser cache

### Session errors
- Check PHP session.save_path is writable
- Verify session extension is loaded
- Clear browser cookies

## File Structure
```
smns/
├── config.php              # Configuration
├── index.php              # Main entry point
├── setup.php              # Setup verification (NEW)
├── test-login.php         # Login testing (NEW)
├── core/                  # Core classes
│   ├── Auth.php
│   ├── Database.php
│   ├── Security.php
│   └── Session.php
├── views/
│   └── auth/
│       └── login.php      # Unified login page
├── Seed/
│   ├── schema.sql        # Database structure
│   └── seed.sql          # Test data
└── assets/               # CSS, JS, images
```

## Next Steps After Setup

1. ✅ Database created and seeded
2. ✅ Test login working
3. ✅ Session management functional
4. → Browse to http://localhost/smns/
5. → Login with test credentials
6. → Access role-specific dashboard

## Security Note

**IMPORTANT:** Change default passwords before production use!

The default password `password` is only for development/testing.

To create secure passwords, use:
```php
echo password_hash('your_secure_password', PASSWORD_DEFAULT);
```

Then update the password_hash in the users table.
