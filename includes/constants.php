<?php
/**
 * Application Constants
 */

// User Roles
define('ROLE_ADMIN', 'admin');
define('ROLE_LECTURER', 'lecturer');
define('ROLE_STUDENT', 'student');
define('ROLE_FINANCE', 'finance');

// User Status
define('STATUS_ACTIVE', 'active');
define('STATUS_INACTIVE', 'inactive');
define('STATUS_SUSPENDED', 'suspended');
define('STATUS_GRADUATED', 'graduated');
define('STATUS_WITHDRAWN', 'withdrawn');

// Registration Status
define('REG_PENDING', 'pending');
define('REG_APPROVED', 'approved');
define('REG_REJECTED', 'rejected');
define('REG_DROPPED', 'dropped');

// Result Status
define('RESULT_DRAFT', 'draft');
define('RESULT_SUBMITTED', 'submitted');
define('RESULT_APPROVED', 'approved');
define('RESULT_PUBLISHED', 'published');

// Payment Status
define('PAYMENT_PENDING', 'pending');
define('PAYMENT_PARTIAL', 'partial');
define('PAYMENT_PAID', 'paid');
define('PAYMENT_OVERDUE', 'overdue');

// Payment Methods
define('PAYMENT_CASH', 'cash');
define('PAYMENT_BANK', 'bank_transfer');
define('PAYMENT_MOBILE', 'mobile_money');
define('PAYMENT_CHEQUE', 'cheque');
define('PAYMENT_CARD', 'card');

// Academic Standing
define('STANDING_GOOD', 'Good Standing');
define('STANDING_PROBATION', 'Probation');
define('STANDING_SUSPENSION', 'Suspension');

// Notification Types
define('NOTIF_INFO', 'info');
define('NOTIF_SUCCESS', 'success');
define('NOTIF_WARNING', 'warning');
define('NOTIF_ERROR', 'error');

// Semester Numbers
define('SEMESTER_1', 1);
define('SEMESTER_2', 2);
define('SEMESTER_BOTH', 3);

// Grade Pass Status
define('GRADE_PASS', 'pass');
define('GRADE_FAIL', 'fail');
