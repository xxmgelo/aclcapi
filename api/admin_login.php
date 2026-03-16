<?php

require __DIR__ . "/cors.php";
require __DIR__ . "/db.php";
require __DIR__ . "/helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(["error" => "Method not allowed"], 405);
}

$data = read_json();
if (!$data) {
    respond(["error" => "Payload required"], 400);
}

$identifier = trim((string)($data["identifier"] ?? ""));
$password = (string)($data["password"] ?? "");

if ($identifier === "" || $password === "") {
    respond(["error" => "Identifier and password are required"], 422);
}

$db = db();
$stmt = $db->prepare(
    "SELECT id, username, email, full_name, avatar, password_hash, role FROM admins WHERE username = ? OR email = ? LIMIT 1"
);

if (!$stmt) {
    respond(["error" => "Failed to prepare login query"], 500);
}

$stmt->bind_param("ss", $identifier, $identifier);
if (!$stmt->execute()) {
    respond(["error" => "Login query failed"], 500);
}

$result = $stmt->get_result();
if (!$result || $result->num_rows === 0) {
    respond(["error" => "Invalid credentials"], 401);
}

$row = $result->fetch_assoc();
$stmt->close();

if (!password_verify($password, $row["password_hash"])) {
    respond(["error" => "Invalid credentials"], 401);
}

respond([
    "admin" => [
        "id" => (int)$row["id"],
        "username" => $row["username"],
        "email" => $row["email"],
        "full_name" => $row["full_name"],
        "role" => $row["role"],
        "avatar" => $row["avatar"],
    ],
]);
