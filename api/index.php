<?php

require __DIR__ . "/cors.php";
echo json_encode([
    "message" => "ACLC API is running",
    "endpoints" => [
        "GET /api/students.php",
        "POST /api/students.php",
        "POST /api/students_bulk.php",
        "GET /api/student.php?student_id=...",
        "PUT /api/student.php",
        "DELETE /api/student.php?student_id=...",`r`n        "POST /api/admin_login.php",`r`n        "GET /api/admins.php",`r`n        "POST /api/admins.php",
    ],
]);


