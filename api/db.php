<?php

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

    return $mysqli;
}
