<?php
declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function formatKes(float|int|string $amount): string
{
    return 'KES ' . number_format((float) $amount, 2);
}

function calculateNights(string $checkIn, string $checkOut): int
{
    $start = DateTimeImmutable::createFromFormat('Y-m-d', $checkIn);
    $end = DateTimeImmutable::createFromFormat('Y-m-d', $checkOut);

    if (!$start || !$end || $end <= $start) {
        return 0;
    }

    return (int) $start->diff($end)->days;
}

function getNightDates(string $checkIn, string $checkOut): array
{
    $nights = calculateNights($checkIn, $checkOut);
    if ($nights <= 0) {
        return [];
    }

    $start = new DateTimeImmutable($checkIn);
    $dates = [];
    for ($i = 0; $i < $nights; $i++) {
        $dates[] = $start->modify("+{$i} day")->format('Y-m-d');
    }

    return $dates;
}

function hasRole(array $roles): bool
{
    $currentRole = $_SESSION['role'] ?? '';
    return in_array($currentRole, $roles, true);
}