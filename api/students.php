<?php

require __DIR__ . "/cors.php";
require __DIR__ . "/db.php";
require __DIR__ . "/helpers.php";

$db = db();
$method = $_SERVER["REQUEST_METHOD"];

function fetch_all_students($db)
{
    $sql = "SELECT * FROM bse_students UNION ALL SELECT * FROM bsis_students ORDER BY id DESC";
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

function insert_program_student($db, $program, $student_id, $name, $year_level, $gmail, $financials)
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
        (student_id, name, program, year_level, gmail, total_fee, base_total_fee, discount_percent, downpayment, prelim, midterm, pre_final, finals, total_balance, payment_mode, full_payment_amount)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        respond(["error" => "Failed to prepare insert"], 500);
    }

    $stmt->bind_param(
        "sssssdddddddddsd",
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
        $financials["full_payment_amount"]
    );

    if (!$stmt->execute()) {
        respond(["error" => "Failed to create student", "details" => $stmt->error], 500);
    }

    $new_id = $stmt->insert_id;
    $stmt->close();

    $result = $db->query("SELECT * FROM {$target} WHERE id = " . (int)$new_id);
    if (!$result || $result->num_rows === 0) {
        respond(["message" => "Student created"], 201);
    }

    $row = $result->fetch_assoc();
    respond(["student" => map_student_row($row)], 201);
}

if ($method === "GET") {
    respond(fetch_all_students($db));
}

if ($method === "POST") {
    $data = read_json();
    if (!$data) {
        respond(["error" => "Payload required"], 400);
    }

    $student_id = trim((string)($data["StudentID"] ?? ""));
    $name = trim((string)($data["Name"] ?? ""));

    if ($student_id === "" || $name === "") {
        respond(["error" => "StudentID and Name are required"], 422);
    }

    $program = trim((string)($data["Program"] ?? ""));
    $year_level = trim((string)($data["YearLevel"] ?? ""));
    $gmail = trim((string)($data["Gmail"] ?? ""));
    $financials = normalized_student_financials($data);

    insert_program_student($db, $program, $student_id, $name, $year_level, $gmail, $financials);
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

    $target = target_table_for_program($db, $program);
    if (!$target) {
        respond(["error" => "Program must include BSE or BSIS"], 422);
    }

    $record = find_student_by_student_id($db, $student_id);
    if (!$record) {
        respond(["error" => "Student not found"], 404);
    }

    $currentTable = $record["table"];

    if ($currentTable !== $target) {
        $deleteStmt = $db->prepare("DELETE FROM {$currentTable} WHERE student_id = ?");
        if ($deleteStmt) {
            $deleteStmt->bind_param("s", $student_id);
            $deleteStmt->execute();
            $deleteStmt->close();
        }

        $insertStmt = $db->prepare(
            "INSERT INTO {$target}
            (student_id, name, program, year_level, gmail, total_fee, base_total_fee, discount_percent, downpayment, prelim, midterm, pre_final, finals, total_balance, payment_mode, full_payment_amount)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$insertStmt) {
            respond(["error" => "Failed to move student"], 500);
        }
        $insertStmt->bind_param(
            "sssssdddddddddsd",
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
            $financials["full_payment_amount"]
        );
        if (!$insertStmt->execute()) {
            respond(["error" => "Failed to move student", "details" => $insertStmt->error], 500);
        }
        $insertStmt->close();
    } else {
        $stmt = $db->prepare(
            "UPDATE {$currentTable}
             SET name = ?, program = ?, year_level = ?, gmail = ?,
                 total_fee = ?, base_total_fee = ?, discount_percent = ?,
                 downpayment = ?, prelim = ?, midterm = ?, pre_final = ?, finals = ?,
                 total_balance = ?, payment_mode = ?, full_payment_amount = ?
             WHERE student_id = ?"
        );

        if (!$stmt) {
            respond(["error" => "Failed to prepare update"], 500);
        }

        $stmt->bind_param(
            "ssssdddddddddsds",
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
            $student_id
        );

        if (!$stmt->execute()) {
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

    if ($student_id === "") {
        respond(["error" => "student_id is required"], 400);
    }

    $record = find_student_by_student_id($db, $student_id);
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
