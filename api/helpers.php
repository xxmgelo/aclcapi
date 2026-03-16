<?php

function read_json()
{
    $raw = file_get_contents("php://input");
    if ($raw === false || trim($raw) === "") {
        return null;
    }

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            "error" => "Invalid JSON payload",
        ]);
        exit;
    }

    return $data;
}

function respond($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function map_student_row($row)
{
    return [
        "id" => (int)$row["id"],
        "StudentID" => $row["student_id"],
        "Name" => $row["name"],
        "Program" => $row["program"],
        "YearLevel" => $row["year_level"],
        "Gmail" => $row["gmail"],
    ];
}
