<?php

require __DIR__ . "/cors.php";
require __DIR__ . "/db.php";
require __DIR__ . "/helpers.php";

$db = db();
$method = $_SERVER["REQUEST_METHOD"];

if ($method !== "POST") {
    respond(["error" => "Method not allowed"], 405);
}

$data = read_json();
if (!is_array($data)) {
    respond(["error" => "Array payload required"], 400);
}

$bseStmt = $db->prepare(
    "INSERT INTO bse_students (student_id, name, program, year_level, gmail)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        program = VALUES(program),
        year_level = VALUES(year_level),
        gmail = VALUES(gmail)"
);

$bsisStmt = $db->prepare(
    "INSERT INTO bsis_students (student_id, name, program, year_level, gmail)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        program = VALUES(program),
        year_level = VALUES(year_level),
        gmail = VALUES(gmail)"
);

foreach ($data as $row) {
    if (!is_array($row)) {
        continue;
    }

    $student_id = trim((string)($row["StudentID"] ?? ""));
    $name = trim((string)($row["Name"] ?? ""));

    if ($student_id === "" || $name === "") {
        continue;
    }

    $program = trim((string)($row["Program"] ?? ""));
    $year_level = trim((string)($row["YearLevel"] ?? ""));
    $gmail = trim((string)($row["Gmail"] ?? ""));

    $programLower = strtolower($program);
    if (strpos($programLower, "bse") !== false && $bseStmt) {
        $bseStmt->bind_param("sssss", $student_id, $name, $program, $year_level, $gmail);
        $bseStmt->execute();
    } else if (strpos($programLower, "bsis") !== false && $bsisStmt) {
        $bsisStmt->bind_param("sssss", $student_id, $name, $program, $year_level, $gmail);
        $bsisStmt->execute();
    }
}

if ($bseStmt) {
    $bseStmt->close();
}
if ($bsisStmt) {
    $bsisStmt->close();
}

$result = $db->query("SELECT * FROM bse_students UNION ALL SELECT * FROM bsis_students ORDER BY id DESC");
if (!$result) {
    respond(["message" => "Bulk upsert completed"], 200);
}

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = map_student_row($row);
}

respond($rows, 200);
