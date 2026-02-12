# Seminary Results Management System

A comprehensive web-based results management system for seminaries and theological institutions built with PHP, MySQL, CSS, and JavaScript.

## Features

### User Roles
- **Admin**: Full system access and management
- **Lecturer**: Course and results management
- **Student**: View courses, results, and payments
- **Finance**: Payment and invoice management

### Core Functionality
- User authentication and authorization
- Student enrollment management
- Course registration
- Results entry and management
- GPA calculation
- Fee structure and invoicing
- Payment processing
- Transcript generation
- Reporting and analytics
- Activity logging
- Announcements system

## System Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache or Nginx web server
- Modern web browser (Chrome, Firefox, Safari, Edge)

## Installation

1. **Clone or download the project**
   ```bash
   git clone [repository-url]
   ```

2. **Database Setup**
   - Create a new MySQL database named `smns`
   - Import the schema: `Seed/schema.sql`
   - Import sample data (optional): `Seed/seed.sql`

3. **Configuration**
   - Copy `config.sample.php` to `config.php`
   - Update database credentials in `config.php`:
     ```php
     define('DB_HOST', 'localhost');
     define('DB_NAME', 'smns');
     define('DB_USER', 'your_username');
     define('DB_PASS', 'your_password');
     ```
   - Update `BASE_URL` to match your installation path

4. **Set Permissions**
   ```bash
   chmod 755 uploads/ downloads/ logs/ cache/
   chmod 640 config.php
   ```

5. **Access the System**
   - Navigate to your installation URL (e.g., `http://localhost/smns`)
   - Use default credentials to login (see below)

## Default Login Credentials

**Admin:**
- Username: `admin`
- Password: `password`

**Student:**
- Username: `std001`
- Password: `password`

**Lecturer:**
- Username: `prof.johnson`
- Password: `password`

**Finance:**
- Username: `finance1`
- Password: `password`

**⚠️ IMPORTANT: Change these passwords immediately in production!**

## Directory Structure

```
smns/
├── assets/          # CSS, JavaScript, images
├── config/          # Configuration files
├── core/            # Core system classes
├── controllers/     # Application controllers
├── models/          # Data models
├── views/           # View templates
├── includes/        # Shared includes
├── uploads/         # Uploaded files
├── downloads/       # Generated files
├── logs/            # Log files
├── Seed/            # Database schema and seeds
├── config.php       # Main configuration
└── index.php        # Entry point
```

## Key Features

### For Administrators
- Manage students, lecturers, and staff
- Configure academic years and semesters
- Create and manage courses
- Approve course registrations
- Review and publish results
- Manage fee structures
- Generate reports
- System configuration

### For Students
- View enrolled courses
- Check results and GPA
- Download transcripts
- View payment history
- Check fee balances
- Register for courses
- View announcements

### For Lecturers
- View assigned courses
- Enter and submit results
- View class lists
- Manage course materials
- Generate reports

### For Finance Staff
- Record payments
- Generate invoices
- View student balances
- Process refunds
- Generate financial reports

## Security Features

- Password hashing (bcrypt)
- CSRF protection
- XSS prevention
- SQL injection protection
- Session management
- Account lockout on failed attempts
- Activity logging
- Role-based access control

## Technologies Used

- **Backend**: PHP 7.4+
- **Database**: MySQL 5.7+
- **Frontend**: HTML5, CSS3, JavaScript
- **Framework**: Bootstrap 4
- **Libraries**: jQuery, DataTables, Chart.js

## Support

For issues, questions, or contributions, please contact the system administrator.

## License

MIT License - see LICENSE file for details

## Version

Version 1.0.0

---

**Note**: This system is designed for educational institutions. Always backup your database before performing updates or migrations.
