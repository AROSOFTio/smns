<?php
/**
 * Fix Grade D: Update grade_point from 1.00 to 2.00
 * This ensures that a 50% (D grade) is correctly valued as 2.00 grade points
 * and displays in green (passing) instead of red (failing)
 */

require_once 'config.php';

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Fix Grade D - SMNS</title>
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css'>
    <style>
        body { padding: 20px; background: #f5f5f5; }
        .container { max-width: 900px; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .badge-demo { padding: 5px 10px; border-radius: 4px; color: white; display: inline-block; margin: 5px; }
        .badge-success { background: #28a745; }
        .badge-danger { background: #dc3545; }
    </style>
</head>
<body>
<div class='container'>";

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    echo "<h2>🔧 Fixing Grade D (50% = 2.00 grade points)</h2>";
    echo "<hr>";
    
    // Check current state
    echo "<h3>📊 Current Grade D Configuration:</h3>";
    $stmt = $conn->query("SELECT grade_letter, min_mark, max_mark, grade_point, pass_status FROM grades WHERE grade_letter = 'D'");
    $currentGradeD = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($currentGradeD) {
        $needsFix = ($currentGradeD['grade_point'] != 2.00);
        
        echo "<table class='table table-bordered table-sm' style='width: auto;'>";
        echo "<tr><th>Grade</th><th>Min %</th><th>Max %</th><th>Grade Points</th><th>Status</th></tr>";
        echo "<tr>";
        echo "<td><strong>{$currentGradeD['grade_letter']}</strong></td>";
        echo "<td>{$currentGradeD['min_mark']}</td>";
        echo "<td>{$currentGradeD['max_mark']}</td>";
        $gpColor = ($currentGradeD['grade_point'] == 2.00) ? 'success' : 'error';
        echo "<td><span class='$gpColor'>{$currentGradeD['grade_point']}</span></td>";
        echo "<td>" . ucfirst($currentGradeD['pass_status']) . "</td>";
        echo "</tr>";
        echo "</table>";
        
        if ($needsFix) {
            echo "<p class='alert alert-warning'>⚠️ Grade D currently has <strong>{$currentGradeD['grade_point']}</strong> points. It should be <strong>2.00</strong></p>";
            
            // 1. Update the grades table
            echo "<h3>✅ Step 1: Updating grades table...</h3>";
            $stmt = $conn->prepare("UPDATE grades SET grade_point = 2.00 WHERE grade_letter = 'D' AND min_mark = 50.00");
            $stmt->execute();
            $affected = $stmt->rowCount();
            echo "<p class='success'>✓ Updated grades table: {$affected} row(s) affected</p>";
            
            // 2. Update all existing results with grade 'D'
            echo "<h3>✅ Step 2: Updating existing student results...</h3>";
            $stmt = $conn->prepare("UPDATE results SET grade_points = 2.00 WHERE grade = 'D' AND total_marks BETWEEN 50.00 AND 54.99");
            $stmt->execute();
            $affected = $stmt->rowCount();
            echo "<p class='success'>✓ Updated results table: {$affected} student result(s) affected</p>";
            
            // 3. Verify the changes
            echo "<h3>✅ Step 3: Verifying changes...</h3>";
            $stmt = $conn->query("SELECT grade_letter, min_mark, max_mark, grade_point, pass_status FROM grades WHERE grade_letter = 'D'");
            $updatedGradeD = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo "<table class='table table-bordered table-sm' style='width: auto;'>";
            echo "<tr><th>Grade</th><th>Min %</th><th>Max %</th><th>Grade Points</th><th>Status</th></tr>";
            echo "<tr>";
            echo "<td><strong>{$updatedGradeD['grade_letter']}</strong></td>";
            echo "<td>{$updatedGradeD['min_mark']}</td>";
            echo "<td>{$updatedGradeD['max_mark']}</td>";
            echo "<td><span class='success'>{$updatedGradeD['grade_point']}</span></td>";
            echo "<td>" . ucfirst($updatedGradeD['pass_status']) . "</td>";
            echo "</tr>";
            echo "</table>";
            
            if ($updatedGradeD['grade_point'] == 2.00) {
                echo "<div class='alert alert-success'>";
                echo "<h4>✅ SUCCESS!</h4>";
                echo "<p>Grade D has been successfully updated:</p>";
                echo "<ul>";
                echo "<li>Grade 'D' (50-54.99%) now has <strong>2.00</strong> grade points (was {$currentGradeD['grade_point']})</li>";
                echo "<li>Grade 'D' will now display as: <span class='badge-demo badge-success'>D</span> (green/passing)</li>";
                echo "<li>All {$affected} existing student result(s) with grade 'D' have been updated</li>";
                echo "</ul>";
                echo "</div>";
            } else {
                echo "<div class='alert alert-danger'>";
                echo "<p class='error'>✗ ERROR: Grade D still has {$updatedGradeD['grade_point']} grade points</p>";
                echo "</div>";
            }
        } else {
            echo "<div class='alert alert-info'>";
            echo "<h4>ℹ️ Already Fixed</h4>";
            echo "<p>Grade D already has the correct value of <strong>2.00</strong> grade points.</p>";
            echo "<p>Badge display: <span class='badge-demo badge-success'>D</span> (green = passing)</p>";
            echo "</div>";
        }
    } else {
        echo "<p class='error'>✗ ERROR: Grade 'D' not found in grades table</p>";
    }
    
    echo "<hr>";
    echo "<h3>📋 Summary of Changes:</h3>";
    echo "<div class='row'>";
    echo "<div class='col-md-6'>";
    echo "<h5>Before:</h5>";
    echo "<ul>";
    echo "<li>Grade D = 1.00 grade points</li>";
    echo "<li>Badge: <span class='badge-demo badge-danger'>D</span> (red = failing)</li>";
    echo "<li>50% was considered poor performance</li>";
    echo "</ul>";
    echo "</div>";
    echo "<div class='col-md-6'>";
    echo "<h5>After:</h5>";
    echo "<ul>";
    echo "<li>Grade D = 2.00 grade points</li>";
    echo "<li>Badge: <span class='badge-demo badge-success'>D</span> (green = passing)</li>";
    echo "<li>50% is correctly recognized as passing</li>";
    echo "</ul>";
    echo "</div>";
    echo "</div>";
    
    echo "<hr>";
    echo "<div class='alert alert-info'>";
    echo "<h5>🔄 Next Steps:</h5>";
    echo "<ol>";
    echo "<li>Refresh the student portal to see updated grade colors</li>";
    echo "<li>All students with grade 'D' will now see green badges</li>";
    echo "<li>GPA calculations will reflect the correct 2.00 points for grade 'D'</li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<p class='text-center mt-4'>";
    echo "<a href='views/student/dashboard.php' class='btn btn-primary'>View Student Dashboard</a> ";
    echo "<a href='views/admin/dashboard.php' class='btn btn-secondary'>Admin Dashboard</a>";
    echo "</p>";
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h4>❌ Error Occurred</h4>";
    echo "<p><strong>Message:</strong> " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}

echo "</div></body></html>";
?>
