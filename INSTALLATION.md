# INSTALLATION AND SETUP GUIDE
## Seminary Results Management System

---

## 📋 Table of Contents
1. [System Requirements](#system-requirements)
2. [Installation Steps](#installation-steps)
3. [Database Setup](#database-setup)
4. [Configuration](#configuration)
5. [First Login](#first-login)
6. [Testing the System](#testing-the-system)
7. [Troubleshooting](#troubleshooting)
8. [Security Recommendations](#security-recommendations)

---

## 🖥️ System Requirements

### Server Requirements
- **PHP**: 7.4 or higher
- **MySQL**: 5.7 or higher (or MariaDB 10.2+)
- **Web Server**: Apache 2.4+ or Nginx
- **Memory**: Minimum 256MB RAM (512MB recommended)
- **Disk Space**: Minimum 500MB

### PHP Extensions Required
- PDO
- PDO_MySQL
- mbstring
- openssl
- json
- session
- fileinfo

### Browser Requirements  
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

---

## 📦 Installation Steps

### Step 1: Download/Extract Files
```bash
# If using Git
git clone [repository-url] /path/to/webroot/smns

# Or extract ZIP file to your web server directory
# Example: C:\xampp\htdocs\smns (Windows)
# Example: /var/www/html/smns (Linux)
```

### Step 2: Set File Permissions (Linux/Mac)
```bash
cd /path/to/smns

# Set directory permissions
chmod 755 assets/ includes/ views/ core/ models/ controllers/

# Set writable directories
chmod 777 uploads/ downloads/ logs/ cache/

# Protect configuration
chmod 640 config.php

# Set .htaccess
chmod 644 .htaccess
```

### Step 3: Verify PHP Extensions
Create a file named `phpinfo.php` in the root directory:
```php
<?php
phpinfo();
?>
```
Access it via browser: `http://localhost/smns/phpinfo.php`  
Verify all required extensions are enabled.  
**Delete this file after verification!**

---

## 🗄️ Database Setup

### Step 1: Create Database
**Using phpMyAdmin:**
1. Open phpMyAdmin (http://localhost/phpmyadmin)
2. Click "New" to create database
3. Database name: `smns`
4. Collation: `utf8mb4_unicode_ci`
5. Click "Create"

**Using MySQL Command Line:**
```sql
CREATE DATABASE smns CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Step 2: Import Schema
**Using phpMyAdmin:**
1. Select `smns` database
2. Click "Import" tab
3. Choose file: `Seed/schema.sql`
4. Click "Go"

**Using MySQL Command Line:**
```bash
mysql -u root -p smns < Seed/schema.sql
```

### Step 3: Import Sample Data (Optional but Recommended)
**Using phpMyAdmin:**
1. Select `smns` database
2. Click "Import" tab
3. Choose file: `Seed/seed.sql`
4. Click "Go"

**Using MySQL Command Line:**
```bash
mysql -u root -p smns < Seed/seed.sql
```

---

## ⚙️ Configuration

### Step 1: Copy Configuration File
```bash
# Copy sample config
cp config.sample.php config.php
```

### Step 2: Edit Configuration
Open `config.php` and update the following:

#### Database Settings
```php
define('DB_HOST', 'localhost');          // Your database host
define('DB_NAME', 'smns');               // Database name
define('DB_USER', 'root');               // Database username
define('DB_PASS', '');                   // Database password
```

#### Application Settings
```php
define('BASE_URL', 'http://localhost/smns');  // Your application URL
```

#### Email Settings (Optional - for notifications)
```php
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-app-password');
define('SMTP_SECURE', false); // false for TLS on 587, true for SSL on 465
define('SMTP_FROM_EMAIL', 'your-email@gmail.com');
define('EMAIL_TRANSPORT', 'nodemailer');
define('NODE_BIN', 'node');
define('NODEMAILER_SCRIPT', BASE_PATH . '/scripts/mailer/send-email.js');
```

Install the Node mailer dependency:
```bash
cd scripts/mailer
npm install
```

Standardized email templates now available in the system:
- `credentials_issued` (account credentials)
- `password_reset` (new temporary password)
- `approval_status` (approved/rejected outcomes)
- `request_response` (admin response to student requests)
- `finance_alert` (financial report/finance communication)
- `invite_notice` (role-based portal invitation)

### Step 3: Verify URL Rewriting
Make sure Apache's `mod_rewrite` is enabled:

**Windows (XAMPP):**
Edit `C:\xampp\apache\conf\httpd.conf`:
```apache
# Uncomment this line:
LoadModule rewrite_module modules/mod_rewrite.so

# Find and change AllowOverride None to:
AllowOverride All
```

**Linux:**
```bash
sudo a2enmod rewrite
sudo service apache2 restart
```

---

## 🔐 First Login

### Step 1: Access the System
Open your browser and navigate to:
```
http://localhost/smns
```

### Step 2: Login with Default Credentials

**Administrator:**
- Username: `admin`
- Password: `password`

**Sample Student:**
- Username: `std001`
- Password: `password`

**Sample Lecturer:**
- Username: `prof.johnson`
- Password: `password`

**Sample Finance Staff:**
- Username: `finance1`
- Password: `password`

### Step 3: Change Default Passwords
⚠️ **IMPORTANT**: Change all default passwords immediately!

1. Login as admin
2. Go to Settings → Users
3. Change passwords for all default users

---

## ✅ Testing the System

### Admin Functions
1. **Dashboard**: View system overview
2. **Students**: Add/Edit/View students
3. **Lecturers**: Manage teaching staff
4. **Courses**: Create and manage courses
5. **Programs**: Configure academic programs
6. **Semesters**: Set up academic periods
7. **Reports**: Generate various reports

### Student Functions
1. Login as student (`std001` / `password`)
2. View dashboard with GPA and balance
3. Browse registered courses
4. View results and transcript
5. Check fee statements

### Lecturer Functions
1. Login as lecturer (`prof.johnson` / `password`)
2. View assigned courses
3. Access class lists
4. Enter/submit results

### Finance Functions
1. Login as finance (`finance1` / `password`)
2. Record payments
3. Generate invoices
4. View student balances
5. Generate financial reports

---

## 🔧 Troubleshooting

### Problem: Blank White Page
**Solution:**
1. Enable error reporting in `config.php`:
   ```php
   error_reporting(E_ALL);
   ini_set('display_errors', 1);
   ```
2. Check `logs/error.log` for errors
3. Verify PHP extensions are installed

### Problem: Database Connection Error
**Solution:**
1. Verify database credentials in `config.php`
2. Ensure MySQL service is running
3. Check database exists: `mysql -u root -p -e "SHOW DATABASES;"`

### Problem: Login Not Working
**Solution:**
1. Verify users table has data:
   ```sql
   SELECT * FROM users LIMIT 5;
   ```
2. Re-import `Seed/seed.sql` if needed
3. Clear browser cache and cookies

### Problem: CSS/JS Not Loading
**Solution:**
1. Verify `BASE_URL` in `config.php` is correct
2. Check file permissions on `assets/` directory
3. Clear browser cache

### Problem: File Upload Errors
**Solution:**
1. Check permissions: `chmod 777 uploads/`
2. Verify PHP `upload_max_filesize` and `post_max_size`
3. Create subdirectories if missing

### Problem: Session Errors
**Solution:**
1. Verify PHP sessions are enabled
2. Check session directory permissions
3. Clear browser cookies

---

## 🔒 Security Recommendations

### Production Deployment

#### 1. Disable Error Display
```php
// In config.php
error_reporting(E_ALL);
ini_set('display_errors', 0);  // Set to 0 in production
ini_set('log_errors', 1);
```

#### 2. Change Database Credentials
- Use strong, unique passwords
- Create dedicated database user with limited privileges

#### 3. Enable HTTPS
```apache
# In .htaccess
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

#### 4. Set Secure Session Settings
```php
// In core/Session.php (already configured)
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);  // Enable for HTTPS
```

#### 5. Regular Backups
```bash
# Database backup
mysqldump -u root -p smns > backup_$(date +%Y%m%d).sql

# Files backup
tar -czf smns_backup_$(date +%Y%m%d).tar.gz /path/to/smns
```

#### 6. Update File Permissions
```bash
# Restrictive permissions for production
chmod 750 core/ models/ controllers/
chmod 640 config.php
chmod 700 uploads/ logs/
```

#### 7. Remove Development Files
```bash
rm phpinfo.php
rm -rf tests/
```

#### 8. Configure Firewall
- Allow only necessary ports (80, 443, 3306 for database)
- Restrict database access to localhost only

---

## 📞 Support

For additional help:
- Check documentation in `docs/` folder
- Review code comments
- Contact system administrator

---

## 📝 Additional Notes

### Default System Settings
- Session timeout: 1 hour
- Max login attempts: 5
- Account lockout: 30 minutes
- Max file upload: 5MB
- Records per page: 20

### Customization
All settings can be modified in:
- `config.php` - Application settings
- `includes/constants.php` - System constants
- Database `settings` table - Dynamic settings

---

## ✨ Next Steps

1. ✅ Complete installation
2. ✅ Login and change default passwords
3. ✅ Configure institution settings
4. ✅ Set up current academic year and semester
5. ✅ Add programs and courses
6. ✅ Import or add students and lecturers
7. ✅ Configure fee structure
8. ✅ Begin operations!

---

**Congratulations!** Your Seminary Results Management System is ready to use!

For detailed feature documentation, see the main [README.md](README.md) file.
