<?php

require __DIR__ . "/cors.php";
require __DIR__ . "/db.php";
require __DIR__ . "/helpers.php";

$db = db();
$method = $_SERVER["REQUEST_METHOD"];

function fetch_all_students($db)
{
    $sql = "SELECT s.*, f.*
            FROM (
                SELECT * FROM bse_students
                UNION ALL
                SELECT * FROM bsis_students
            ) AS s
            LEFT JOIN student_financials f ON f.student_id = s.student_id
            ORDER BY s.id DESC";
    $result = $db->query($sql);
    if (!$result) {
        respond(["error" => "Failed to fetch students"], 500);
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = map_student_row($row);
    }

    return $rows;
}

function fetch_basic_students($db)
{
    $sql = "SELECT id, student_id, name, program, year_level, gmail FROM bse_students
            UNION ALL
            SELECT id, student_id, name, program, year_level, gmail FROM bsis_students
            ORDER BY id DESC";
    $result = $db->query($sql);
    if (!$result) {
        respond(["error" => "Failed to fetch students"], 500);
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            "id" => (int)$row["id"],
            "StudentID" => $row["student_id"],
            "Name" => $row["name"],
            "Program" => $row["program"],
            "YearLevel" => $row["year_level"],
            "Gmail" => $row["gmail"],
        ];
    }

    return $rows;
}

function map_basic_student_with_id_row($row)
{
    return [
        "id" => isset($row["id"]) ? (int)$row["id"] : 0,
        "StudentID" => $row["student_id"] ?? "",
        "Name" => $row["name"] ?? "",
        "Program" => $row["program"] ?? "",
        "YearLevel" => $row["year_level"] ?? "",
        "Gmail" => $row["gmail"] ?? "",
    ];
}

function find_student_by_gmail($db, $gmail)
{
    if ($gmail === "") {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT student_id, name, program, gmail FROM bse_students WHERE LOWER(TRIM(gmail)) = LOWER(TRIM(?))
         UNION ALL
         SELECT student_id, name, program, gmail FROM bsis_students WHERE LOWER(TRIM(gmail)) = LOWER(TRIM(?))
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

function find_existing_student_by_student_id($db, $student_id)
{
    if ($student_id === "") {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT student_id, name, program, year_level, gmail FROM bse_students WHERE TRIM(student_id) = TRIM(?)
         UNION ALL
         SELECT student_id, name, program, year_level, gmail FROM bsis_students WHERE TRIM(student_id) = TRIM(?)
         LIMIT 1"
    );

    if (!$stmt) {
        respond(["error" => "Failed to validate StudentID uniqueness", "details" => $db->error], 500);
    }

    $stmt->bind_param("ss", $student_id, $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
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

function insert_program_student($db, $program, $student_id, $name, $year_level, $gmail)
{
    $program_value = strtolower($program);
    $target = null;

    if (strpos($program_value, "bse") !== false) {
        $target = "bse_students";
    } else if (strpos($program_value, "bsis") !== false) {
        $target = "bsis_students";
    }

    if (!$target) {
        respond(["error" => "Program must include BSE or BSIS"], 422);
    }

    $stmt = $db->prepare(
        "INSERT INTO {$target}
        (student_id, name, program, year_level, gmail)
         VALUES (?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        respond(["error" => "Failed to prepare insert"], 500);
    }

    $stmt->bind_param(
        "sssss",
        $student_id,
        $name,
        $program,
        $year_level,
        $gmail
    );

    try {
        if (!$stmt->execute()) {
            error_log("students.php insert failed: " . $stmt->error);
            respond(["error" => "Failed to create student", "details" => $stmt->error], 500);
        }
    } catch (mysqli_sql_exception $exception) {
        $message = $exception->getMessage();
        if (stripos($message, "Duplicate entry") !== false) {
            respond(["error" => "This StudentID already exists"], 422);
        }
        error_log("students.php insert exception: " . $message);
        respond(["error" => "Failed to create student", "details" => $message], 500);
    }

    $new_id = $stmt->insert_id;
    $stmt->close();

    $db->query(
        "INSERT IGNORE INTO student_financials (student_id) VALUES ('" . $db->real_escape_string($student_id) . "')"
    );

    $result = $db->query(
        "SELECT s.*, f.* FROM {$target} s LEFT JOIN student_financials f ON f.student_id = s.student_id WHERE s.id = " . (int)$new_id
    );
    if (!$result || $result->num_rows === 0) {
        respond(["message" => "Student created"], 201);
    }

    $row = $result->fetch_assoc();
    $view = trim((string)($_GET["view"] ?? ""));
    respond(["student" => $view === "full" ? map_student_row($row) : map_basic_student_with_id_row($row)], 201);
}

if ($method === "GET") {
    $view = trim((string)($_GET["view"] ?? ""));
    $fields = trim((string)($_GET["fields"] ?? ""));
    if ($fields === "basic" || $view !== "full") {
        respond(fetch_basic_students($db));
    }

    respond(fetch_all_students($db));
}

if ($method === "POST") {
    $data = unwrap_student_payload(read_json());
    if (!$data) {
        respond(["error" => "Payload required"], 400);
    }

    $student_id = trim((string)first_student_value($data, ["StudentID", "student_id"]));
    $name = trim((string)first_student_value($data, ["Name", "name"]));

    if ($student_id === "" || $name === "") {
        respond(["error" => "StudentID and Name are required"], 422);
    }

    $program = trim((string)first_student_value($data, ["Program", "program"]));
    $year_level = trim((string)first_student_value($data, ["YearLevel", "year_level"]));
    $gmail = trim((string)first_student_value($data, ["Gmail", "gmail"]));

    if (find_existing_student_by_student_id($db, $student_id)) {
        respond(["error" => "This StudentID already exists"], 422);
    }

    if ($gmail !== "" && find_student_by_gmail($db, $gmail)) {
        respond(["error" => "This Gmail account is already assigned to another student"], 422);
    }

    insert_program_student($db, $program, $student_id, $name, $year_level, $gmail);
}

function target_table_for_program($db, $program)
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

function unwrap_student_payload($payload)
{
    if (!is_array($payload)) {
        return null;
    }

    if (array_keys($payload) === range(0, count($payload) - 1)) {
        $first = $payload[0] ?? null;
        return is_array($first) ? $first : null;
    }

    return $payload;
}

function first_student_value($data, $keys, $default = "")
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data)) {
            continue;
        }

        $value = $data[$key];
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === "") {
            continue;
        }

        return $value;
    }

    return $default;
}

function normalize_delete_payloads($payload)
{
    if (!is_array($payload)) {
        return [];
    }

    if (array_keys($payload) === range(0, count($payload) - 1)) {
        return array_values(array_filter($payload, "is_array"));
    }

    return [$payload];
}

function delete_student_record($db, $record)
{
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

    $financeDelete = $db->prepare("DELETE FROM student_financials WHERE student_id = ?");
    if ($financeDelete) {
        $financeDelete->bind_param("s", $row["student_id"]);
        $financeDelete->execute();
        $financeDelete->close();
    }

    return [
        "id" => isset($row["id"]) ? (int)$row["id"] : 0,
        "StudentID" => $row["student_id"],
        "Name" => $row["name"],
    ];
}

function find_student_by_details($db, $data)
{
    $id = (int)first_student_value($data, ["id"], 0);
    if ($id > 0) {
        $record = find_student_by_id($db, $id);
        if ($record) {
            return $record;
        }
    }

    $original_student_id = trim((string)first_student_value($data, ["OriginalStudentID", "original_student_id"]));
    if ($original_student_id !== "") {
        $record = find_student_by_student_id($db, $original_student_id);
        if ($record) {
            return $record;
        }
    }

    $student_id = trim((string)first_student_value($data, ["StudentID", "student_id"]));
    if ($student_id !== "") {
        $record = find_student_by_student_id($db, $student_id);
        if ($record) {
            return $record;
        }
    }

    $gmail = trim((string)first_student_value($data, ["Gmail", "gmail"]));
    if ($gmail !== "") {
        $stmt = $db->prepare(
            "SELECT * FROM bse_students WHERE LOWER(TRIM(gmail)) = LOWER(TRIM(?)) LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param("s", $gmail);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stmt->close();
                return ["table" => "bse_students", "row" => $row];
            }
            $stmt->close();
        }

        $stmt = $db->prepare(
            "SELECT * FROM bsis_students WHERE LOWER(TRIM(gmail)) = LOWER(TRIM(?)) LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param("s", $gmail);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stmt->close();
                return ["table" => "bsis_students", "row" => $row];
            }
            $stmt->close();
        }
    }

    $name = trim((string)first_student_value($data, ["Name", "name"]));
    $program = trim((string)first_student_value($data, ["Program", "program"]));
    $year_level = trim((string)first_student_value($data, ["YearLevel", "year_level"]));
    if ($name !== "") {
        $sql = "SELECT * FROM bse_students WHERE TRIM(name) = TRIM(?)";
        $types = "s";
        $params = [$name];
        if ($program !== "") {
            $sql .= " AND TRIM(program) = TRIM(?)";
            $types .= "s";
            $params[] = $program;
        }
        if ($year_level !== "") {
            $sql .= " AND TRIM(year_level) = TRIM(?)";
            $types .= "s";
            $params[] = $year_level;
        }
        $sql .= " LIMIT 1";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stmt->close();
                return ["table" => "bse_students", "row" => $row];
            }
            $stmt->close();
        }

        $sql = "SELECT * FROM bsis_students WHERE TRIM(name) = TRIM(?)";
        $types = "s";
        $params = [$name];
        if ($program !== "") {
            $sql .= " AND TRIM(program) = TRIM(?)";
            $types .= "s";
            $params[] = $program;
        }
        if ($year_level !== "") {
            $sql .= " AND TRIM(year_level) = TRIM(?)";
            $types .= "s";
            $params[] = $year_level;
        }
        $sql .= " LIMIT 1";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stmt->close();
                return ["table" => "bsis_students", "row" => $row];
            }
            $stmt->close();
        }
    }

    return null;
}

function get_existing_student_full_row($db, $record)
{
    if (!$record || empty($record["table"]) || empty($record["row"]["student_id"])) {
        return $record["row"] ?? [];
    }

    $table = $record["table"];
    $student_id = $record["row"]["student_id"];
    $stmt = $db->prepare(
        "SELECT s.*, f.* FROM {$table} s
         LEFT JOIN student_financials f ON f.student_id = s.student_id
         WHERE s.student_id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return $record["row"];
    }

    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = ($result && $result->num_rows > 0) ? $result->fetch_assoc() : $record["row"];
    $stmt->close();

    return $row;
}

if ($method === "PUT" || $method === "PATCH") {
    $data = unwrap_student_payload(read_json());
    if (!$data) {
        respond(["error" => "Payload required"], 400);
    }

    $record = find_student_by_details($db, $data);
    if (!$record) {
        respond(["error" => "Student not found"], 404);
    }

    $existingRow = get_existing_student_full_row($db, $record);
    $current_student_id = trim((string)($existingRow["student_id"] ?? ""));
    $student_id = trim((string)first_student_value($data, ["StudentID", "student_id"], $current_student_id));
    $name = trim((string)first_student_value($data, ["Name", "name"], $existingRow["name"] ?? ""));
    $program = trim((string)first_student_value($data, ["Program", "program"], $existingRow["program"] ?? ""));
    $year_level = trim((string)first_student_value($data, ["YearLevel", "year_level"], $existingRow["year_level"] ?? ""));
    $gmail = trim((string)first_student_value($data, ["Gmail", "gmail"], $existingRow["gmail"] ?? ""));
    $normalizedInput = array_merge($existingRow, $data, [
        "student_id" => $student_id,
        "name" => $name,
        "program" => $program,
        "year_level" => $year_level,
        "gmail" => $gmail,
        "StudentID" => $student_id,
        "Name" => $name,
        "Program" => $program,
        "YearLevel" => $year_level,
        "Gmail" => $gmail,
    ]);
    $financials = normalized_student_financials($normalizedInput);
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

    if ($program === "") {
        $program = trim((string)($existingRow["program"] ?? ""));
    }

    $target = target_table_for_program($db, $program);
    if (!$target) {
        respond(["error" => "Program must include BSE or BSIS"], 422);
    }

    $existing_gmail_record = find_student_by_gmail($db, $gmail);
    if (
        $gmail !== "" &&
        $existing_gmail_record &&
        trim((string)$existing_gmail_record["student_id"]) !== $current_student_id
    ) {
        respond(["error" => "This Gmail account is already assigned to another student"], 422);
    }

    $existing_student_id_record = find_existing_student_by_student_id($db, $student_id);
    if (
        $student_id !== "" &&
        $existing_student_id_record &&
        trim((string)$existing_student_id_record["student_id"]) !== $current_student_id
    ) {
        respond(["error" => "This StudentID already exists"], 422);
    }

    $currentTable = $record["table"];
    $incoming_can_remind = $data["CanRemind"] ?? $data["can_remind"] ?? null;
    $can_remind = ($incoming_can_remind === null || $incoming_can_remind === "")
        ? (int)($existingRow["can_remind"] ?? 0)
        : parse_can_remind_value($incoming_can_remind);
    $downpayment_date = array_key_exists("downpayment_date", $data) ? $data["downpayment_date"] : ($existingRow["downpayment_date"] ?? null);
    $prelim_date = array_key_exists("prelim_date", $data) ? $data["prelim_date"] : ($existingRow["prelim_date"] ?? null);
    $midterm_date = array_key_exists("midterm_date", $data) ? $data["midterm_date"] : ($existingRow["midterm_date"] ?? null);
    $prefinal_date = array_key_exists("prefinal_date", $data) ? $data["prefinal_date"] : ($existingRow["prefinal_date"] ?? null);
    $final_date = array_key_exists("final_date", $data) ? $data["final_date"] : ($existingRow["final_date"] ?? null);
    $total_balance_date = array_key_exists("total_balance_date", $data) ? $data["total_balance_date"] : ($existingRow["total_balance_date"] ?? null);
    $downpayment_paid_amount = array_key_exists("downpayment_paid_amount", $data) ? $data["downpayment_paid_amount"] : ($existingRow["downpayment_paid_amount"] ?? null);
    $prelim_paid_amount = array_key_exists("prelim_paid_amount", $data) ? $data["prelim_paid_amount"] : ($existingRow["prelim_paid_amount"] ?? null);
    $midterm_paid_amount = array_key_exists("midterm_paid_amount", $data) ? $data["midterm_paid_amount"] : ($existingRow["midterm_paid_amount"] ?? null);
    $prefinal_paid_amount = array_key_exists("prefinal_paid_amount", $data) ? $data["prefinal_paid_amount"] : ($existingRow["prefinal_paid_amount"] ?? null);
    $final_paid_amount = array_key_exists("final_paid_amount", $data) ? $data["final_paid_amount"] : ($existingRow["final_paid_amount"] ?? null);
    $total_balance_paid_amount = array_key_exists("total_balance_paid_amount", $data) ? $data["total_balance_paid_amount"] : ($existingRow["total_balance_paid_amount"] ?? null);
    if ($has_payment_update) {
        $can_remind = 1;
    }

    if ($currentTable !== $target) {
        $financeUpsert = $db->prepare(
            "INSERT INTO student_financials
            (student_id, total_fee, base_total_fee, discount_percent, downpayment, prelim, midterm, pre_final, finals, total_balance, payment_mode, full_payment_amount, can_remind,
             downpayment_date, prelim_date, midterm_date, prefinal_date, final_date, total_balance_date,
             downpayment_paid_amount, prelim_paid_amount, midterm_paid_amount, prefinal_paid_amount, final_paid_amount, total_balance_paid_amount)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                total_balance_paid_amount = VALUES(total_balance_paid_amount)"
        );
        if (!$financeUpsert) {
            respond(["error" => "Failed to prepare financial upsert"], 500);
        }

        $deleteStmt = $db->prepare("DELETE FROM {$currentTable} WHERE student_id = ?");
        if ($deleteStmt) {
            $deleteStmt->bind_param("s", $current_student_id);
            $deleteStmt->execute();
            $deleteStmt->close();
        }

        $insertStmt = $db->prepare(
            "INSERT INTO {$target}
            (student_id, name, program, year_level, gmail)
             VALUES (?, ?, ?, ?, ?)"
        );
        if (!$insertStmt) {
            respond(["error" => "Failed to move student"], 500);
        }
        $insertStmt->bind_param(
            "sssss",
            $student_id,
            $name,
            $program,
            $year_level,
            $gmail
        );
        if (!$insertStmt->execute()) {
            error_log("students.php move insert failed: " . $insertStmt->error);
            respond(["error" => "Failed to move student", "details" => $insertStmt->error], 500);
        }
        $insertStmt->close();

        if ($current_student_id !== $student_id) {
            $financeRename = $db->prepare("DELETE FROM student_financials WHERE student_id = ?");
            if ($financeRename) {
                $financeRename->bind_param("s", $current_student_id);
                $financeRename->execute();
                $financeRename->close();
            }
        }

        $financeUpsert->bind_param(
            "sdddddddddsdissssssssssss",
            $student_id,
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
        if (!$financeUpsert->execute()) {
            respond(["error" => "Failed to save student financials", "details" => $financeUpsert->error], 500);
        }
        $financeUpsert->close();
    } else {
        $basicStmt = $db->prepare(
            "UPDATE {$currentTable}
             SET student_id = ?, name = ?, program = ?, year_level = ?, gmail = ?
             WHERE student_id = ?"
        );
        if (!$basicStmt) {
            respond(["error" => "Failed to prepare student update"], 500);
        }
        $basicStmt->bind_param("ssssss", $student_id, $name, $program, $year_level, $gmail, $current_student_id);
        if (!$basicStmt->execute()) {
            respond(["error" => "Failed to update student", "details" => $basicStmt->error], 500);
        }
        $basicStmt->close();

        if ($current_student_id !== $student_id) {
            $financeRename = $db->prepare("UPDATE student_financials SET student_id = ? WHERE student_id = ?");
            if (!$financeRename) {
                respond(["error" => "Failed to prepare StudentID update"], 500);
            }
            $financeRename->bind_param("ss", $student_id, $current_student_id);
            if (!$financeRename->execute()) {
                respond(["error" => "Failed to update StudentID", "details" => $financeRename->error], 500);
            }
            $financeRename->close();
        }

        $stmt = $db->prepare(
            "INSERT INTO student_financials
            (student_id, total_fee, base_total_fee, discount_percent, downpayment, prelim, midterm, pre_final, finals, total_balance, payment_mode, full_payment_amount, can_remind,
             downpayment_date, prelim_date, midterm_date, prefinal_date, final_date, total_balance_date,
             downpayment_paid_amount, prelim_paid_amount, midterm_paid_amount, prefinal_paid_amount, final_paid_amount, total_balance_paid_amount)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                total_balance_paid_amount = VALUES(total_balance_paid_amount)"
        );

        if (!$stmt) {
            respond(["error" => "Failed to prepare update"], 500);
        }

        $stmt->bind_param(
            "sdddddddddsdissssssssssss",
            $student_id,
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

        if (!$stmt->execute()) {
            error_log("students.php update failed: " . $stmt->error);
            respond(["error" => "Failed to update student", "details" => $stmt->error], 500);
        }

        $stmt->close();
    }

    $record = find_student_by_student_id($db, $student_id);
    if (!$record) {
        respond(["message" => "Student updated"], 200);
    }

    $result = $db->query(
        "SELECT s.*, f.* FROM {$record["table"]} s LEFT JOIN student_financials f ON f.student_id = s.student_id WHERE s.student_id = '" . $db->real_escape_string($student_id) . "' LIMIT 1"
    );
    $row = $result ? $result->fetch_assoc() : $record["row"];
    $view = trim((string)($_GET["view"] ?? ""));
    respond(["student" => $view === "full" ? map_student_row($row) : map_basic_student_with_id_row($row)], 200);
}

if ($method === "DELETE") {
    $payloads = normalize_delete_payloads(read_json());

    if (count($payloads) === 0) {
        $payloads = [[
            "student_id" => $_GET["student_id"] ?? "",
            "id" => $_GET["id"] ?? 0,
        ]];
    }

    $deleted = [];
    foreach ($payloads as $payload) {
        $student_id = trim((string)($payload["student_id"] ?? $payload["StudentID"] ?? ""));
        $id = (int)($payload["id"] ?? 0);

        if ($student_id === "" && $id === 0) {
            continue;
        }

        $record = $student_id !== ""
            ? find_student_by_student_id($db, $student_id)
            : find_student_by_id($db, $id);
        if (!$record) {
            continue;
        }

        $deleted[] = delete_student_record($db, $record);
    }

    if (count($deleted) === 0) {
        respond(["error" => "Student not found"], 404);
    }

    respond([
        "deleted" => true,
        "count" => count($deleted),
        "students" => $deleted,
    ]);
}

respond(["error" => "Method not allowed"], 405);
