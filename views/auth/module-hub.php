<?php
require_once '../../config.php';

$moduleMap = [
    'admin' => [
        'label' => 'Admin Dashboard',
        'url' => BASE_URL . '/views/admin/dashboard.php',
        'desc' => 'System and academic administration',
        'color' => '#dc3545',
        'icon' => 'fa-shield-alt'
    ],
    'student' => [
        'label' => 'Student Dashboard',
        'url' => BASE_URL . '/views/student/dashboard.php',
        'desc' => 'Registration, results, payments, services',
        'color' => '#007bff',
        'icon' => 'fa-user-graduate'
    ],
    'lecturer' => [
        'label' => 'Lecturer Dashboard',
        'url' => BASE_URL . '/views/lecturer/dashboard.php',
        'desc' => 'Courses, marks, submissions, reports',
        'color' => '#28a745',
        'icon' => 'fa-chalkboard-teacher'
    ],
    'finance' => [
        'label' => 'Finance Dashboard',
        'url' => BASE_URL . '/views/finance/dashboard.php',
        'desc' => 'Invoices, payments, verification, balances',
        'color' => '#ffc107',
        'icon' => 'fa-coins'
    ]
];

$availableModules = [];
foreach (array_keys($moduleMap) as $module) {
    $auth = new Auth($module);
    if ($auth->isLoggedIn()) {
        $availableModules[$module] = $moduleMap[$module];
    }
}

if (empty($availableModules)) {
    header('Location: ' . BASE_URL . '/views/auth/login.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Module Hub - <?php echo e(APP_NAME); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            background: url('../../assets/img/seminary.jpeg') no-repeat center center fixed;
            background-size: cover;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .overlay {
            min-height: 100vh;
            background: rgba(15, 23, 42, 0.58);
            padding: 26px 12px;
        }
        .wrap { max-width: 980px; margin: 0 auto; }
        .head { margin-bottom: 14px; color: #fff; }
        .head h1 { font-size: 30px; margin: 0 0 6px; }
        .head p { margin: 0; color: #dbeafe; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .card-m {
            border-radius: 12px;
            background: rgba(255,255,255,0.96);
            padding: 16px;
            text-decoration: none;
            color: #0f172a;
            border: 2px solid transparent;
            box-shadow: 0 14px 32px rgba(0,0,0,0.14);
        }
        .card-m:hover { text-decoration: none; color: #0f172a; transform: translateY(-1px); }
        .card-m.locked {
            opacity: 0.72;
            background: rgba(241,245,249,0.96);
            cursor: not-allowed;
            box-shadow: none;
        }
        .module-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 15px;
            margin-bottom: 8px;
        }
        .card-m h3 { font-size: 19px; margin: 0 0 4px; }
        .card-m p { margin: 0; font-size: 13px; color: #475569; }
        .badge-access { font-size: 10px; letter-spacing: .6px; }
        .bar { margin-top: 14px; display: flex; gap: 8px; flex-wrap: wrap; }
        @media (max-width: 768px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="overlay">
<div class="wrap">
    <div class="head">
        <h1>One Login, Four Modules</h1>
        <p>Open any dashboard your account is permitted to access.</p>
    </div>

    <div class="grid">
        <?php foreach ($moduleMap as $module => $cfg): ?>
            <?php $allowed = isset($availableModules[$module]); ?>
            <?php if ($allowed): ?>
            <a class="card-m" href="<?php echo e($cfg['url']); ?>" style="border-color: <?php echo e($cfg['color']); ?>;">
                <span class="module-icon" style="background: <?php echo e($cfg['color']); ?>;">
                    <i class="fas <?php echo e($cfg['icon']); ?>"></i>
                </span>
                <h3><?php echo e($cfg['label']); ?></h3>
                <p><?php echo e($cfg['desc']); ?></p>
                <div class="mt-2"><span class="badge badge-success badge-access">ACCESS GRANTED</span></div>
            </a>
            <?php else: ?>
            <div class="card-m locked" style="border-color: #cbd5e1;">
                <span class="module-icon" style="background: #94a3b8;">
                    <i class="fas <?php echo e($cfg['icon']); ?>"></i>
                </span>
                <h3><?php echo e($cfg['label']); ?></h3>
                <p><?php echo e($cfg['desc']); ?></p>
                <div class="mt-2"><span class="badge badge-secondary badge-access">NO ACCESS</span></div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="bar">
        <a class="btn btn-light btn-sm" href="<?php echo e(BASE_URL); ?>/views/auth/login.php">Back to Login</a>
        <a class="btn btn-outline-danger btn-sm" href="<?php echo e(BASE_URL); ?>/views/auth/logout.php?all=1">Logout All</a>
    </div>
</div>
</div>
</body>
</html>
