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
    "INSERT INTO bse_students
    (student_id, name, program, year_level, gmail, total_fee, base_total_fee, discount_percent, downpayment, prelim, midterm, pre_final, finals, total_balance, payment_mode, full_payment_amount,
     downpayment_date, prelim_date, midterm_date, prefinal_date, final_date, total_balance_date,
     downpayment_paid_amount, prelim_paid_amount, midterm_paid_amount, prefinal_paid_amount, final_paid_amount, total_balance_paid_amount)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        program = VALUES(program),
        year_level = VALUES(year_level),
        gmail = VALUES(gmail),
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

$bsisStmt = $db->prepare(
    "INSERT INTO bsis_students
    (student_id, name, program, year_level, gmail, total_fee, base_total_fee, discount_percent, downpayment, prelim, midterm, pre_final, finals, total_balance, payment_mode, full_payment_amount,
     downpayment_date, prelim_date, midterm_date, prefinal_date, final_date, total_balance_date,
     downpayment_paid_amount, prelim_paid_amount, midterm_paid_amount, prefinal_paid_amount, final_paid_amount, total_balance_paid_amount)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        program = VALUES(program),
        year_level = VALUES(year_level),
        gmail = VALUES(gmail),
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
    $financials = normalized_student_financials($row);

    $programLower = strtolower($program);
    if (strpos($programLower, "bse") !== false && $bseStmt) {
        $bseStmt->bind_param(
            "sssssdddddddddsdssssssssss",
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
            $row["downpayment_date"] ?? null,
            $row["prelim_date"] ?? null,
            $row["midterm_date"] ?? null,
            $row["prefinal_date"] ?? null,
            $row["final_date"] ?? null,
            $row["total_balance_date"] ?? null,
            $row["downpayment_paid_amount"] ?? null,
            $row["prelim_paid_amount"] ?? null,
            $row["midterm_paid_amount"] ?? null,
            $row["prefinal_paid_amount"] ?? null,
            $row["final_paid_amount"] ?? null,
            $row["total_balance_paid_amount"] ?? null
        );
        $bseStmt->execute();
    } else if (strpos($programLower, "bsis") !== false && $bsisStmt) {
        $bsisStmt->bind_param(
            "sssssdddddddddsdssssssssss",
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
            $row["downpayment_date"] ?? null,
            $row["prelim_date"] ?? null,
            $row["midterm_date"] ?? null,
            $row["prefinal_date"] ?? null,
            $row["final_date"] ?? null,
            $row["total_balance_date"] ?? null,
            $row["downpayment_paid_amount"] ?? null,
            $row["prelim_paid_amount"] ?? null,
            $row["midterm_paid_amount"] ?? null,
            $row["prefinal_paid_amount"] ?? null,
            $row["final_paid_amount"] ?? null,
            $row["total_balance_paid_amount"] ?? null
        );
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
