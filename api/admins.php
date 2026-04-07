<?php

require __DIR__ . "/cors.php";
require __DIR__ . "/db.php";
require __DIR__ . "/helpers.php";

$db = db();
$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {
    $result = $db->query(
        "SELECT id, username, email, full_name, role, avatar, created_at FROM admins ORDER BY id DESC"
    );

    if (!$result) {
        respond(["error" => "Failed to fetch admins"], 500);
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            "id" => (int)$row["id"],
            "username" => $row["username"],
            "email" => $row["email"],
            "full_name" => $row["full_name"],
            "role" => $row["role"],
            "avatar" => $row["avatar"],
            "created_at" => $row["created_at"],
        ];
    }

    respond($rows);
}

if ($method === "POST") {
    $data = read_json();
    if (!$data) {
        respond(["error" => "Payload required"], 400);
    }

    $full_name = trim((string)($data["full_name"] ?? ""));
    $username = trim((string)($data["username"] ?? ""));
    $password = (string)($data["password"] ?? "");
    $email = trim((string)($data["email"] ?? ""));
    $avatar = $data["avatar"] ?? null;
    $maxAvatarBytes = 500 * 1024;

    if ($full_name === "" || $username === "" || trim($password) === "") {
        respond(["error" => "Name, username, and password are required"], 422);
    }

    if ($email === "") {
        $email = $username . "@aclc.local";
    }

    if (is_string($avatar)) {
        $avatar = trim($avatar);
        if ($avatar === "") {
            $avatar = null;
        } else if (strlen($avatar) > $maxAvatarBytes) {
            respond(["error" => "Avatar image too large"], 413);
        }
    } else {
        $avatar = null;
    }

    $stmt = $db->prepare("SELECT id FROM admins WHERE username = ? OR email = ? LIMIT 1");
    if (!$stmt) {
        respond(["error" => "Failed to prepare admin lookup"], 500);
    }

    $stmt->bind_param("ss", $username, $email);
    if (!$stmt->execute()) {
        respond(["error" => "Failed to check admin"], 500);
    }

    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $stmt->close();
        respond(["error" => "Username or email already exists"], 409);
    }

    $stmt->close();

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $db->prepare(
        "INSERT INTO admins (username, email, full_name, password_hash, role, avatar)
         VALUES (?, ?, ?, ?, 'admin', ?)"
    );

    if (!$stmt) {
        respond(["error" => "Failed to prepare admin insert"], 500);
    }

    $stmt->bind_param("sssss", $username, $email, $full_name, $hash, $avatar);

    if (!$stmt->execute()) {
        respond(["error" => "Failed to create admin", "details" => $stmt->error], 500);
    }

    $new_id = $stmt->insert_id;
    $stmt->close();

    $result = $db->query("SELECT id, username, email, full_name, role, avatar, created_at FROM admins WHERE id = " . (int)$new_id);
    if (!$result || $result->num_rows === 0) {
        respond(["message" => "Admin created"], 201);
    }

    $row = $result->fetch_assoc();

    respond([
        "admin" => [
            "id" => (int)$row["id"],
            "username" => $row["username"],
            "email" => $row["email"],
            "full_name" => $row["full_name"],
            "role" => $row["role"],
            "avatar" => $row["avatar"],
            "created_at" => $row["created_at"],
        ],
    ], 201);
}

function find_admin_by_id($db, $id)
{
    $stmt = $db->prepare("SELECT * FROM admins WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return $row;
        }
        $stmt->close();
    }
    return null;
}

if ($method === "PUT" || $method === "PATCH") {
    $data = read_json();
    if (!$data) {
        respond(["error" => "Payload required"], 400);
    }

    $id = (int)($data["id"] ?? ($_GET["id"] ?? 0));
    if ($id === 0) {
        respond(["error" => "Admin id is required for update"], 422);
    }

    $admin = find_admin_by_id($db, $id);
    if (!$admin) {
        respond(["error" => "Admin not found"], 404);
    }

    $full_name = trim((string)($data["full_name"] ?? $admin["full_name"]));
    $username = trim((string)($data["username"] ?? $admin["username"]));
    $email = trim((string)($data["email"] ?? $admin["email"]));
    $role = trim((string)($data["role"] ?? $admin["role"]));
    $avatar = $data["avatar"] ?? $admin["avatar"];
    $password = $data["password"] ?? null;

    if ($full_name === "" || $username === "") {
        respond(["error" => "Name and username are required"], 422);
    }

    $maxAvatarBytes = 500 * 1024;
    if (is_string($avatar)) {
        $avatar = trim($avatar);
        if ($avatar === "") {
            $avatar = null;
        } else if (strlen($avatar) > $maxAvatarBytes) {
            respond(["error" => "Avatar image too large"], 413);
        }
    }

    if ($password !== null && $password !== "") {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare(
            "UPDATE admins SET full_name = ?, username = ?, email = ?, role = ?, avatar = ?, password_hash = ? WHERE id = ?"
        );
        if ($stmt) {
            $stmt->bind_param("ssssssi", $full_name, $username, $email, $role, $avatar, $hash, $id);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $stmt = $db->prepare(
            "UPDATE admins SET full_name = ?, username = ?, email = ?, role = ?, avatar = ? WHERE id = ?"
        );
        if ($stmt) {
            $stmt->bind_param("sssssi", $full_name, $username, $email, $role, $avatar, $id);
            $stmt->execute();
            $stmt->close();
        }
    }

    $updated = find_admin_by_id($db, $id);
    respond([
        "admin" => [
            "id" => (int)$updated["id"],
            "username" => $updated["username"],
            "email" => $updated["email"],
            "full_name" => $updated["full_name"],
            "role" => $updated["role"],
            "avatar" => $updated["avatar"],
            "created_at" => $updated["created_at"],
        ],
    ], 200);
}

if ($method === "DELETE") {
    $id = (int)($_GET["id"] ?? 0);

    if ($id === 0) {
        respond(["error" => "Admin id is required"], 400);
    }

    $admin = find_admin_by_id($db, $id);
    if (!$admin) {
        respond(["error" => "Admin not found"], 404);
    }

    $stmt = $db->prepare("DELETE FROM admins WHERE id = ?");
    if (!$stmt) {
        respond(["error" => "Failed to delete admin"], 500);
    }

    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
        respond(["error" => "Failed to delete admin", "details" => $stmt->error], 500);
    }

    $stmt->close();

    respond(["deleted" => true]);
}

respond(["error" => "Method not allowed"], 405);
