<?php

function round_money($value)
{
    return round((float)$value, 2);
}

function parse_money($value)
{
    if ($value === null || $value === "") {
        return 0.0;
    }

    if (is_string($value)) {
        $value = preg_replace("/[^0-9.\-]/", "", $value);
    }

    if (!is_numeric($value)) {
        return 0.0;
    }

    return max(round_money((float)$value), 0.0);
}

function parse_discount_percent($value)
{
    $parsed = parse_money($value);
    if ($parsed < 0) {
        return 0.0;
    }
    if ($parsed > 100) {
        return 100.0;
    }
    return round_money($parsed);
}

function normalize_payment_mode($value)
{
    $normalized = strtolower(trim((string)$value));
    return $normalized === "full" ? "full" : "installment";
}

function distribute_total_across_installments($total)
{
    $normalized_total = parse_money($total);
    $base_amount = floor(($normalized_total / 5) * 100) / 100;
    $allocated = 0.0;

    $values = [];
    for ($index = 0; $index < 5; $index++) {
        if ($index === 4) {
            $value = round_money($normalized_total - $allocated);
        } else {
            $value = round_money($base_amount);
        }

        $values[] = $value;
        $allocated = round_money($allocated + $value);
    }

    return [
        "downpayment" => $values[0],
        "prelim" => $values[1],
        "midterm" => $values[2],
        "pre_final" => $values[3],
        "finals" => $values[4],
    ];
}

function normalized_student_financials($data)
{
    $payment_mode = normalize_payment_mode($data["PaymentMode"] ?? $data["payment_mode"] ?? "");
    $raw_total_fee = parse_money($data["TotalFee"] ?? $data["total_fee"] ?? 0);
    $discount_percent = parse_discount_percent(
        $data["Discount"] ??
        $data["discount_percent"] ??
        $data["DiscountPercent"] ??
        0
    );
    $base_total_fee = parse_money(
        $data["BaseTotalFee"] ??
        $data["base_total_fee"] ??
        $data["OriginalTotalFee"] ??
        $data["original_total_fee"] ??
        0
    );
    if ($base_total_fee <= 0) {
        $base_total_fee = $raw_total_fee;
    }
    $total_fee = round_money(max($base_total_fee * (1 - ($discount_percent / 100)), 0));
    if ($total_fee <= 0 && $raw_total_fee > 0) {
        $total_fee = $raw_total_fee;
    }
    $downpayment = parse_money($data["Downpayment"] ?? $data["downpayment"] ?? 0);
    $prelim = parse_money($data["Prelim"] ?? $data["prelim"] ?? 0);
    $midterm = parse_money($data["Midterm"] ?? $data["midterm"] ?? 0);
    $pre_final = parse_money($data["PreFinal"] ?? $data["pre_final"] ?? 0);
    $finals = parse_money($data["Finals"] ?? $data["finals"] ?? 0);
    $full_payment_amount = parse_money($data["FullPaymentAmount"] ?? $data["full_payment_amount"] ?? 0);
    $raw_total_balance = $data["TotalBalance"] ?? $data["total_balance"] ?? null;
    $has_explicit_total_balance = $raw_total_balance !== null && $raw_total_balance !== "";
    $resolved_raw_total_balance = parse_money($raw_total_balance);
    $installment_total = round_money($downpayment + $prelim + $midterm + $pre_final + $finals);

    if ($installment_total <= 0 && $total_fee > 0 && (!$has_explicit_total_balance || $resolved_raw_total_balance > 0)) {
        $distributed = distribute_total_across_installments($total_fee);
        $downpayment = $distributed["downpayment"];
        $prelim = $distributed["prelim"];
        $midterm = $distributed["midterm"];
        $pre_final = $distributed["pre_final"];
        $finals = $distributed["finals"];
        $installment_total = round_money($downpayment + $prelim + $midterm + $pre_final + $finals);
    }

    if ($payment_mode === "full") {
        if ($total_fee <= 0) {
            $total_fee = max(
                $full_payment_amount,
                parse_money($data["TotalBalance"] ?? $data["total_balance"] ?? 0)
            );
        }

        $full_payment_amount = $total_fee > 0
            ? min($full_payment_amount, $total_fee)
            : $full_payment_amount;

        return [
            "total_fee" => round_money($total_fee),
            "base_total_fee" => round_money($base_total_fee),
            "discount_percent" => round_money($discount_percent),
            "downpayment" => $downpayment,
            "prelim" => $prelim,
            "midterm" => $midterm,
            "pre_final" => $pre_final,
            "finals" => $finals,
            "total_balance" => round_money(max($total_fee - $full_payment_amount, 0)),
            "payment_mode" => $payment_mode,
            "full_payment_amount" => round_money($full_payment_amount),
        ];
    }

    $total_balance = round_money($downpayment + $prelim + $midterm + $pre_final + $finals);
    if ($total_fee < $total_balance) {
        $total_fee = $total_balance;
    }

    return [
        "total_fee" => round_money($total_fee),
        "base_total_fee" => round_money($base_total_fee),
        "discount_percent" => round_money($discount_percent),
        "downpayment" => $downpayment,
        "prelim" => $prelim,
        "midterm" => $midterm,
        "pre_final" => $pre_final,
        "finals" => $finals,
        "total_balance" => $total_balance,
        "payment_mode" => $payment_mode,
        "full_payment_amount" => round_money($full_payment_amount),
    ];
}

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
    $financials = normalized_student_financials($row);

    return [
        "id" => (int)$row["id"],
        "StudentID" => $row["student_id"],
        "Name" => $row["name"],
        "Program" => $row["program"],
        "YearLevel" => $row["year_level"],
        "Gmail" => $row["gmail"],
        "TotalFee" => $financials["total_fee"],
        "BaseTotalFee" => isset($row["base_total_fee"]) ? (float)$row["base_total_fee"] : $financials["base_total_fee"],
        "Discount" => isset($row["discount_percent"]) ? (float)$row["discount_percent"] : $financials["discount_percent"],
        "Downpayment" => $financials["downpayment"],
        "Prelim" => $financials["prelim"],
        "Midterm" => $financials["midterm"],
        "PreFinal" => $financials["pre_final"],
        "Finals" => $financials["finals"],
        "TotalBalance" => $financials["total_balance"],
        "PaymentMode" => $financials["payment_mode"],
        "FullPaymentAmount" => $financials["full_payment_amount"],
        "CanRemind" => isset($row["can_remind"]) ? ((int)$row["can_remind"] === 1) : false,
    ];
}
