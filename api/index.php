<?php

require __DIR__ . "/cors.php";
echo json_encode([
    "message" => "ACLC API is running",
    "endpoints" => [
        "GET /api/students.php - Get all students or one by student_id/id",
        "POST /api/students.php - Create student",
        "PUT /api/students.php - Update student",
        "PATCH /api/students.php - Partially update student",
        "DELETE /api/students.php?student_id=... - Delete student",
        "DELETE /api/students.php?all=1 - Delete all student records",
        "POST /api/admin_login.php",
        "GET /api/admins.php",
        "POST /api/admins.php",
        "PUT /api/admins.php",
        "PATCH /api/admins.php - Partially update admin",
        "DELETE /api/admins.php?id=...",
        "POST /api/reminders.php - Send balance reminder email",
        "POST /api/payment_receipts.php - Send payment receipt email",
    ],
]);
