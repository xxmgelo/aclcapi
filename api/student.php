<?php

require __DIR__ . "/cors.php";
require __DIR__ . "/db.php";
require __DIR__ . "/helpers.php";

$db = db();
$method = $_SERVER["REQUEST_METHOD"];

function find_student_by_student_id($db, $student_id)
{
    $stmt = $db->prepare("SELECT * FROM bse_students WHERE student_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return ["table" => "bse_students", "row" => $row];
        }
        $stmt->close();
    }

    $stmt = $db->prepare("SELECT * FROM bsis_students WHERE student_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return ["table" => "bsis_students", "row" => $row];
        }
        $stmt->close();
    }

    return null;
}

function find_student_by_id($db, $id)
{
    $stmt = $db->prepare("SELECT * FROM bse_students WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return ["table" => "bse_students", "row" => $row];
        }
        $stmt->close();
    }

    $stmt = $db->prepare("SELECT * FROM bsis_students WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return ["table" => "bsis_students", "row" => $row];
        }
        $stmt->close();
    }

    return null;
}

function find_student_by_gmail($db, $gmail)
{
    if ($gmail === "") {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT 'bse_students' AS source_table, student_id, name, program, gmail FROM bse_students WHERE LOWER(TRIM(gmail)) = LOWER(TRIM(?))
         UNION ALL
         SELECT 'bsis_students' AS source_table, student_id, name, program, gmail FROM bsis_students WHERE LOWER(TRIM(gmail)) = LOWER(TRIM(?))
         LIMIT 1"
    );

    if (!$stmt) {
        respond(["error" => "Failed to validate Gmail uniqueness", "details" => $db->error], 500);
    }

    $stmt->bind_param("ss", $gmail, $gmail);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function target_table_for_program($program)
{
    $program_value = strtolower($program);
    if (strpos($program_value, "bse") !== false) {
        return "bse_students";
    }
    if (strpos($program_value, "bsis") !== false) {
        return "bsis_students";
    }
    return null;
}

function parse_can_remind_value($value)
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    $normalized = strtolower(trim((string)$value));
    if ($normalized === "1" || $normalized === "true" || $normalized === "yes") {
        return 1;
    }

    return 0;
}

if ($method === "GET") {
    $student_id = trim((string)($_GET["student_id"] ?? ""));
    $id = (int)($_GET["id"] ?? 0);
    $fields = trim((string)($_GET["fields"] ?? ""));

    if ($student_id === "" && $id === 0) {
        respond(["error" => "student_id or id is required"], 400);
    }

    $record = $student_id !== ""
        ? find_student_by_student_id($db, $student_id)
        : find_student_by_id($db, $id);

    if (!$record) {
        respond(["error" => "Student not found"], 404);
    }

    if ($fields === "basic") {
        respond(map_basic_student_row($record["row"]));
    }

    respond(map_student_row($record["row"]));
}

if ($method === "PUT") {
    $data = read_json();
    if (!$data) {
        respond(["error" => "Payload required"], 400);
    }

    $student_id = trim((string)($data["StudentID"] ?? ""));
    if ($student_id === "") {
        respond(["error" => "StudentID is required for update"], 422);
    }

    $name = trim((string)($data["Name"] ?? ""));
    $program = trim((string)($data["Program"] ?? ""));
    $year_level = trim((string)($data["YearLevel"] ?? ""));
    $gmail = trim((string)($data["Gmail"] ?? ""));
    $financials = normalized_student_financials($data);
    $has_payment_update = (
        !empty($data["date_paid"]) ||
        !empty($data["DatePaid"]) ||
        !empty($data["downpayment_date"]) ||
        !empty($data["prelim_date"]) ||
        !empty($data["midterm_date"]) ||
        !empty($data["prefinal_date"]) ||
        !empty($data["final_date"]) ||
        !empty($data["total_balance_date"]) ||
        isset($data["downpayment_paid_amount"]) ||
        isset($data["prelim_paid_amount"]) ||
        isset($data["midterm_paid_amount"]) ||
        isset($data["prefinal_paid_amount"]) ||
        isset($data["final_paid_amount"]) ||
        isset($data["total_balance_paid_amount"])
    );

    $target = target_table_for_program($program);
    if (!$target) {
        respond(["error" => "Program must include BSE or BSIS"], 422);
    }

    $record = find_student_by_student_id($db, $student_id);
    if (!$record) {
        respond(["error" => "Student not found"], 404);
    }

    $existing_gmail_record = find_student_by_gmail($db, $gmail);
    if (
        $gmail !== "" &&
        $existing_gmail_record &&
        trim((string)$existing_gmail_record["student_id"]) !== $student_id
    ) {
        respond(["error" => "This Gmail account is already assigned to another student"], 422);
    }

    $currentTable = $record["table"];
    $incoming_can_remind = $data["CanRemind"] ?? $data["can_remind"] ?? null;
    $can_remind = ($incoming_can_remind === null || $incoming_can_remind === "")
        ? (int)($record["row"]["can_remind"] ?? 0)
        : parse_can_remind_value($incoming_can_remind);
    $downpayment_date = $data["downpayment_date"] ?? null;
    $prelim_date = $data["prelim_date"] ?? null;
    $midterm_date = $data["midterm_date"] ?? null;
    $prefinal_date = $data["prefinal_date"] ?? null;
    $final_date = $data["final_date"] ?? null;
    $total_balance_date = $data["total_balance_date"] ?? null;
    $downpayment_paid_amount = $data["downpayment_paid_amount"] ?? null;
    $prelim_paid_amount = $data["prelim_paid_amount"] ?? null;
    $midterm_paid_amount = $data["midterm_paid_amount"] ?? null;
    $prefinal_paid_amount = $data["prefinal_paid_amount"] ?? null;
    $final_paid_amount = $data["final_paid_amount"] ?? null;
    $total_balance_paid_amount = $data["total_balance_paid_amount"] ?? null;
    if ($has_payment_update) {
        $can_remind = 1;
    }

    if ($currentTable !== $target) {
        $deleteStmt = $db->prepare("DELETE FROM {$currentTable} WHERE student_id = ?");
        if ($deleteStmt) {
            $deleteStmt->bind_param("s", $student_id);
            $deleteStmt->execute();
            $deleteStmt->close();
        }

        $insertStmt = $db->prepare(
            "INSERT INTO {$target}
            (student_id, name, program, year_level, gmail, total_fee, base_total_fee, discount_percent, downpayment, prelim, midterm, pre_final, finals, total_balance, payment_mode, full_payment_amount, can_remind,
             downpayment_date, prelim_date, midterm_date, prefinal_date, final_date, total_balance_date,
             downpayment_paid_amount, prelim_paid_amount, midterm_paid_amount, prefinal_paid_amount, final_paid_amount, total_balance_paid_amount)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$insertStmt) {
            respond(["error" => "Failed to move student"], 500);
        }
        $insertStmt->bind_param(
            "sssssdddddddddsdissssssssssss",
            $student_id,
            $name,
            $program,
            $year_level,
            $gmail,
            $financials["total_fee"],
            $financials["base_total_fee"],
            $financials["discount_percent"],
            $financials["downpayment"],
            $financials["prelim"],
            $financials["midterm"],
            $financials["pre_final"],
            $financials["finals"],
            $financials["total_balance"],
            $financials["payment_mode"],
            $financials["full_payment_amount"],
            $can_remind,
            $downpayment_date,
            $prelim_date,
            $midterm_date,
            $prefinal_date,
            $final_date,
            $total_balance_date,
            $downpayment_paid_amount,
            $prelim_paid_amount,
            $midterm_paid_amount,
            $prefinal_paid_amount,
            $final_paid_amount,
            $total_balance_paid_amount
        );
        if (!$insertStmt->execute()) {
            error_log("student.php move insert failed: " . $insertStmt->error);
            respond(["error" => "Failed to move student", "details" => $insertStmt->error], 500);
        }
        $insertStmt->close();
    } else {
        $stmt = $db->prepare(
            "UPDATE {$currentTable}
             SET name = ?, program = ?, year_level = ?, gmail = ?,
                 total_fee = ?, base_total_fee = ?, discount_percent = ?,
                 downpayment = ?, prelim = ?, midterm = ?, pre_final = ?, finals = ?,
                 total_balance = ?, payment_mode = ?, full_payment_amount = ?, can_remind = ?,
                 downpayment_date = ?, prelim_date = ?, midterm_date = ?, prefinal_date = ?, final_date = ?, total_balance_date = ?,
                 downpayment_paid_amount = ?, prelim_paid_amount = ?, midterm_paid_amount = ?, prefinal_paid_amount = ?, final_paid_amount = ?, total_balance_paid_amount = ?
             WHERE student_id = ?"
        );

        if (!$stmt) {
            respond(["error" => "Failed to prepare update"], 500);
        }

        $stmt->bind_param(
            "ssssdddddddddsdisssssssssssss",
            $name,
            $program,
            $year_level,
            $gmail,
            $financials["total_fee"],
            $financials["base_total_fee"],
            $financials["discount_percent"],
            $financials["downpayment"],
            $financials["prelim"],
            $financials["midterm"],
            $financials["pre_final"],
            $financials["finals"],
            $financials["total_balance"],
            $financials["payment_mode"],
            $financials["full_payment_amount"],
            $can_remind,
            $downpayment_date,
            $prelim_date,
            $midterm_date,
            $prefinal_date,
            $final_date,
            $total_balance_date,
            $downpayment_paid_amount,
            $prelim_paid_amount,
            $midterm_paid_amount,
            $prefinal_paid_amount,
            $final_paid_amount,
            $total_balance_paid_amount,
            $student_id
        );

        if (!$stmt->execute()) {
            error_log("student.php update failed: " . $stmt->error);
            respond(["error" => "Failed to update student", "details" => $stmt->error], 500);
        }

        $stmt->close();
    }

    $record = find_student_by_student_id($db, $student_id);
    if (!$record) {
        respond(["message" => "Student updated"], 200);
    }

    respond(["student" => map_student_row($record["row"])], 200);
}

if ($method === "DELETE") {
    $student_id = trim((string)($_GET["student_id"] ?? ""));
    $id = (int)($_GET["id"] ?? 0);

    if ($student_id === "" && $id === 0) {
        respond(["error" => "student_id or id is required"], 400);
    }

    $record = $student_id !== ""
        ? find_student_by_student_id($db, $student_id)
        : find_student_by_id($db, $id);

    if (!$record) {
        respond(["error" => "Student not found"], 404);
    }

    $row = $record["row"];
    $table = $record["table"];

    $insert = $db->prepare(
        "INSERT INTO removed_students
        (student_id, name, program, year_level, gmail)
        VALUES (?, ?, ?, ?, ?)"
    );

    if (!$insert) {
        respond(["error" => "Failed to prepare archive"], 500);
    }

    $insert->bind_param(
        "sssss",
        $row["student_id"],
        $row["name"],
        $row["program"],
        $row["year_level"],
        $row["gmail"]
    );

    if (!$insert->execute()) {
        respond(["error" => "Failed to archive student", "details" => $insert->error], 500);
    }

    $insert->close();

    $stmt = $db->prepare("DELETE FROM {$table} WHERE student_id = ?");
    if (!$stmt) {
        respond(["error" => "Failed to delete student"], 500);
    }

    $stmt->bind_param("s", $row["student_id"]);
    if (!$stmt->execute()) {
        respond(["error" => "Failed to delete student", "details" => $stmt->error], 500);
    }

    $stmt->close();

    respond(["deleted" => true]);
}

respond(["error" => "Method not allowed"], 405);
