<?php

function ensure_student_column($mysqli, $database_name, $table, $column, $definition)
{
    $stmt = $mysqli->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
         LIMIT 1"
    );

    if (!$stmt) {
        http_response_code(500);
        echo json_encode([
            "error" => "Failed to inspect database schema",
            "details" => $mysqli->error,
        ]);
        exit;
    }

    $stmt->bind_param("sss", $database_name, $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();

    if ($exists) {
        return;
    }

    if (!$mysqli->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}")) {
        http_response_code(500);
        echo json_encode([
            "error" => "Failed to update database schema",
            "details" => $mysqli->error,
        ]);
        exit;
    }
}

function ensure_student_fee_schema($mysqli, $database_name, $table)
{
    $columns = [
        "total_fee" => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        "base_total_fee" => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        "discount_percent" => "DECIMAL(5,2) NOT NULL DEFAULT 0.00",
        "downpayment" => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        "prelim" => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        "midterm" => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        "pre_final" => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        "finals" => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        "total_balance" => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        "payment_mode" => "VARCHAR(20) NOT NULL DEFAULT 'installment'",
        "full_payment_amount" => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        "can_remind" => "TINYINT(1) NOT NULL DEFAULT 0",
    ];

    foreach ($columns as $column => $definition) {
        ensure_student_column($mysqli, $database_name, $table, $column, $definition);
    }
}

function db()
{
    static $mysqli = null;
    if ($mysqli instanceof mysqli) {
        return $mysqli;
    }

    $config = require __DIR__ . "/config.php";
    $mysqli = new mysqli(
        $config["host"],
        $config["user"],
        $config["pass"],
        $config["name"]
    );

    if ($mysqli->connect_errno) {
        http_response_code(500);
        echo json_encode([
            "error" => "Database connection failed",
            "details" => $mysqli->connect_error,
        ]);
        exit;
    }

    $mysqli->set_charset("utf8mb4");
    ensure_student_fee_schema($mysqli, $config["name"], "bse_students");
    ensure_student_fee_schema($mysqli, $config["name"], "bsis_students");

    return $mysqli;
}
