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

function ensure_student_financials_table($mysqli)
{
    $sql = "CREATE TABLE IF NOT EXISTS student_financials (
        student_id VARCHAR(50) NOT NULL,
        total_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        base_total_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        downpayment DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        prelim DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        midterm DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        pre_final DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        finals DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        payment_mode VARCHAR(20) NOT NULL DEFAULT 'installment',
        full_payment_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        can_remind TINYINT(1) NOT NULL DEFAULT 0,
        downpayment_date DATETIME NULL,
        prelim_date DATETIME NULL,
        midterm_date DATETIME NULL,
        prefinal_date DATETIME NULL,
        final_date DATETIME NULL,
        total_balance_date DATETIME NULL,
        downpayment_paid_amount DECIMAL(10,2) NULL,
        prelim_paid_amount DECIMAL(10,2) NULL,
        midterm_paid_amount DECIMAL(10,2) NULL,
        prefinal_paid_amount DECIMAL(10,2) NULL,
        final_paid_amount DECIMAL(10,2) NULL,
        total_balance_paid_amount DECIMAL(10,2) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (student_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";

    if (!$mysqli->query($sql)) {
        http_response_code(500);
        echo json_encode([
            "error" => "Failed to ensure student financials table",
            "details" => $mysqli->error,
        ]);
        exit;
    }
}

function migrate_legacy_student_financials($mysqli, $database_name, $table)
{
    $legacy_columns = [
        "total_fee",
        "base_total_fee",
        "discount_percent",
        "downpayment",
        "prelim",
        "midterm",
        "pre_final",
        "finals",
        "total_balance",
        "payment_mode",
        "full_payment_amount",
        "can_remind",
        "downpayment_date",
        "prelim_date",
        "midterm_date",
        "prefinal_date",
        "final_date",
        "total_balance_date",
        "downpayment_paid_amount",
        "prelim_paid_amount",
        "midterm_paid_amount",
        "prefinal_paid_amount",
        "final_paid_amount",
        "total_balance_paid_amount",
    ];

    foreach ($legacy_columns as $column) {
        $stmt = $mysqli->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
        );
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param("sss", $database_name, $table, $column);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result && $result->num_rows > 0;
        $stmt->close();
        if (!$exists) {
            return;
        }
    }

    $copy_sql = "INSERT INTO student_financials (
            student_id, total_fee, base_total_fee, discount_percent, downpayment, prelim, midterm, pre_final, finals,
            total_balance, payment_mode, full_payment_amount, can_remind, downpayment_date, prelim_date, midterm_date,
            prefinal_date, final_date, total_balance_date, downpayment_paid_amount, prelim_paid_amount, midterm_paid_amount,
            prefinal_paid_amount, final_paid_amount, total_balance_paid_amount
        )
        SELECT
            student_id, total_fee, base_total_fee, discount_percent, downpayment, prelim, midterm, pre_final, finals,
            total_balance, payment_mode, full_payment_amount, can_remind, downpayment_date, prelim_date, midterm_date,
            prefinal_date, final_date, total_balance_date, downpayment_paid_amount, prelim_paid_amount, midterm_paid_amount,
            prefinal_paid_amount, final_paid_amount, total_balance_paid_amount
        FROM {$table}
        ON DUPLICATE KEY UPDATE
            total_fee = VALUES(total_fee),
            base_total_fee = VALUES(base_total_fee),
            discount_percent = VALUES(discount_percent),
            downpayment = VALUES(downpayment),
            prelim = VALUES(prelim),
            midterm = VALUES(midterm),
            pre_final = VALUES(pre_final),
            finals = VALUES(finals),
            total_balance = VALUES(total_balance),
            payment_mode = VALUES(payment_mode),
            full_payment_amount = VALUES(full_payment_amount),
            can_remind = VALUES(can_remind),
            downpayment_date = VALUES(downpayment_date),
            prelim_date = VALUES(prelim_date),
            midterm_date = VALUES(midterm_date),
            prefinal_date = VALUES(prefinal_date),
            final_date = VALUES(final_date),
            total_balance_date = VALUES(total_balance_date),
            downpayment_paid_amount = VALUES(downpayment_paid_amount),
            prelim_paid_amount = VALUES(prelim_paid_amount),
            midterm_paid_amount = VALUES(midterm_paid_amount),
            prefinal_paid_amount = VALUES(prefinal_paid_amount),
            final_paid_amount = VALUES(final_paid_amount),
            total_balance_paid_amount = VALUES(total_balance_paid_amount)";
    $mysqli->query($copy_sql);

    foreach ($legacy_columns as $column) {
        $mysqli->query("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
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
    ensure_student_financials_table($mysqli);
    migrate_legacy_student_financials($mysqli, $config["name"], "bse_students");
    migrate_legacy_student_financials($mysqli, $config["name"], "bsis_students");

    return $mysqli;
}
