<?php
$pdo = new PDO('mysql:host=localhost;port=3306;dbname=smns', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== academic_years ===\n";
$rows = $pdo->query('SELECT id, year_name, start_date, end_date FROM academic_years ORDER BY start_date')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo $r['id'] . ' | ' . $r['year_name'] . ' | ' . $r['start_date'] . ' -> ' . $r['end_date'] . "\n";
}
