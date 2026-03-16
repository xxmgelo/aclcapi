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
        "INSERT INTO {$target} (student_id, name, program, year_level, gmail)
         VALUES (?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        respond(["error" => "Failed to prepare insert"], 500);
    }

    $stmt->bind_param("sssss", $student_id, $name, $program, $year_level, $gmail);

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

    insert_program_student($db, $program, $student_id, $name, $year_level, $gmail);
}

respond(["error" => "Method not allowed"], 405);
