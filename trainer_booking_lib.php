<?php
// trainer_booking_lib.php
// Shared helpers for trainer availability + pending trainer approval.

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function tb_time_slots(): array {
    return [
        '08:00-09:00',
        '09:00-10:00',
        '10:00-11:00',
        '13:00-14:00',
        '14:00-15:00',
        '15:00-16:00',
        '16:00-17:00',
    ];
}

function tb_slot_label(string $slot): string {
    $parts = explode('-', $slot, 2);
    if (count($parts) !== 2) return $slot;

    $start = DateTime::createFromFormat('H:i', trim($parts[0]));
    $end = DateTime::createFromFormat('H:i', trim($parts[1]));

    if (!$start || !$end) return $slot;
    return $start->format('g:i A') . ' - ' . $end->format('g:i A');
}

function tb_valid_date(string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt && $dt->format('Y-m-d') === $date;
}

function tb_normalize_slug(string $slug): string {
    $slug = strtolower(trim($slug));
    $slug = str_replace('_', '-', $slug);
    return $slug;
}

function tb_table_exists(mysqli $conn, string $table): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    if ($result) $result->free();
    $stmt->close();
    return $exists;
}

function tb_column_exists(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    if ($result) $result->free();
    $stmt->close();
    return $exists;
}

function tb_index_exists(mysqli $conn, string $table, string $index): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    if ($result) $result->free();
    $stmt->close();
    return $exists;
}

function tb_ensure_schema(mysqli $conn): void {
    // Creates tables only if missing. It will NOT duplicate columns already existing.
    $conn->query("CREATE TABLE IF NOT EXISTS trainer_availability (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trainer_id INT NOT NULL,
        facility_slug VARCHAR(255) NULL,
        avail_date DATE NULL,
        time_slot VARCHAR(255) NULL,
        is_available TINYINT(1) NULL DEFAULT 1,
        KEY trainer_id (trainer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS bookings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        facility_slug VARCHAR(100) NOT NULL,
        facility_name VARCHAR(150) NOT NULL,
        date DATE NOT NULL,
        time VARCHAR(20) NOT NULL,
        full_name VARCHAR(150) NOT NULL,
        email VARCHAR(150) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        notes TEXT NULL,
        user_id INT NULL,
        trainer_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!tb_column_exists($conn, 'bookings', 'trainer_id')) {
        $conn->query("ALTER TABLE bookings ADD COLUMN trainer_id INT NULL AFTER user_id");
    }
    if (!tb_column_exists($conn, 'bookings', 'status')) {
        $conn->query("ALTER TABLE bookings ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER email");
    }
    if (!tb_column_exists($conn, 'bookings', 'created_at')) {
        $conn->query("ALTER TABLE bookings ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }

    if (tb_column_exists($conn, 'bookings', 'facility_slug') &&
        tb_column_exists($conn, 'bookings', 'date') &&
        tb_column_exists($conn, 'bookings', 'time') &&
        tb_column_exists($conn, 'bookings', 'trainer_id') &&
        tb_column_exists($conn, 'bookings', 'status') &&
        !tb_index_exists($conn, 'bookings', 'idx_booking_trainer_lookup')) {
        @$conn->query("ALTER TABLE bookings ADD INDEX idx_booking_trainer_lookup (trainer_id, facility_slug, date, time, status)");
    }
}


function tb_first_existing_column(mysqli $conn, string $table, array $columns): ?string {
    foreach ($columns as $column) {
        if (tb_column_exists($conn, $table, $column)) return $column;
    }
    return null;
}

function tb_user_trainer_access(mysqli $conn, int $userId): array {
    $info = [
        'membership_type' => '',
        'membership_label' => 'No membership set',
        'trainer_sessions_remaining' => null,
        'has_trainer_membership' => false,
    ];

    if ($userId <= 0 || !tb_table_exists($conn, 'users')) return $info;

    $membershipCol = tb_first_existing_column($conn, 'users', [
        'membership_type',
        'membership',
        'membership_plan',
        'plan',
        'plan_name',
    ]);

    $sessionCol = tb_first_existing_column($conn, 'users', [
        'trainer_sessions_remaining',
        'sessions_remaining',
        'trainer_sessions_left',
        'training_sessions_left',
    ]);

    if (!$membershipCol && !$sessionCol) return $info;

    $select = [];
    if ($membershipCol) $select[] = "`{$membershipCol}` AS membership_value";
    if ($sessionCol) $select[] = "`{$sessionCol}` AS sessions_value";

    $sql = "SELECT " . implode(', ', $select) . " FROM users WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $info;

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) $result->free();
    $stmt->close();

    if (!$row) return $info;

    $membership = trim((string)($row['membership_value'] ?? ''));
    $info['membership_type'] = strtolower($membership);

    if ($sessionCol) {
        $info['trainer_sessions_remaining'] = (int)($row['sessions_value'] ?? 0);
    }

    $labelMap = [
        'bodybuilding_with_trainer' => 'Bodybuilding (with trainer)',
        'bodybuilding_without_trainer' => 'Bodybuilding (without trainer)',
        'boxing_with_trainer' => 'Boxing (with trainer)',
        'boxing_without_trainer' => 'Boxing (without trainer)',
        'muaythai_with_trainer' => 'Muay Thai (with trainer)',
        'muaythai_without_trainer' => 'Muay Thai (without trainer)',
        'zumba' => 'Zumba',
    ];

    if ($membership !== '') {
        $key = strtolower($membership);
        $info['membership_label'] = $labelMap[$key] ?? $membership;
    }

    $normalized = strtolower(preg_replace('/[^a-z0-9]+/', '_', $membership));
    $isWithoutTrainer = strpos($normalized, 'without_trainer') !== false ||
                        strpos($normalized, 'no_trainer') !== false ||
                        strpos($normalized, 'non_trainer') !== false;
    $isWithTrainer = strpos($normalized, 'with_trainer') !== false ||
                     strpos($normalized, 'trainer_included') !== false ||
                     strpos($normalized, 'personal_trainer') !== false;

    $info['has_trainer_membership'] = ($membership !== '' && !$isWithoutTrainer && $isWithTrainer);

    return $info;
}

function tb_get_active_trainers(mysqli $conn): array {
    $trainers = [];
    if (!tb_table_exists($conn, 'users')) return $trainers;

    $hasStatus = tb_column_exists($conn, 'users', 'status');
    $statusSql = $hasStatus ? "AND LOWER(COALESCE(status,''))='active'" : '';

    $sql = "SELECT id, full_name, username
            FROM users
            WHERE LOWER(role)='trainer' {$statusSql}
            ORDER BY COALESCE(NULLIF(full_name,''), username), username";

    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $name = trim($row['full_name'] ?? '');
            if ($name === '') $name = trim($row['username'] ?? 'Trainer #' . (int)$row['id']);
            $trainers[] = [
                'id' => (int)$row['id'],
                'name' => $name,
            ];
        }
        $result->free();
    }

    return $trainers;
}

function tb_is_slot_reserved(mysqli $conn, int $trainerId, string $facilitySlug, string $date, string $slot, ?int $excludeBookingId = null): bool {
    if (!tb_table_exists($conn, 'bookings')) return false;
    if (!tb_column_exists($conn, 'bookings', 'trainer_id')) return false;

    $facilitySlug = tb_normalize_slug($facilitySlug);

    $sql = "SELECT 1 FROM bookings
            WHERE trainer_id = ?
              AND facility_slug = ?
              AND date = ?
              AND time = ?
              AND LOWER(TRIM(status)) IN ('pending','approved','accepted')";

    $types = 'isss';
    $params = [$trainerId, $facilitySlug, $date, $slot];

    if ($excludeBookingId !== null) {
        $sql .= " AND id <> ?";
        $types .= 'i';
        $params[] = $excludeBookingId;
    }

    $sql .= " LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;

    $refs = [$types];
    foreach ($params as $key => $value) $refs[] = &$params[$key];
    call_user_func_array([$stmt, 'bind_param'], $refs);

    $stmt->execute();
    $result = $stmt->get_result();
    $reserved = $result && $result->num_rows > 0;
    if ($result) $result->free();
    $stmt->close();

    return $reserved;
}

function tb_trainer_has_availability(mysqli $conn, int $trainerId, string $facilitySlug, string $date, string $slot): bool {
    if (!tb_table_exists($conn, 'trainer_availability')) return false;
    $facilitySlug = tb_normalize_slug($facilitySlug);

    $sql = "SELECT 1 FROM trainer_availability
            WHERE trainer_id = ?
              AND facility_slug = ?
              AND avail_date = ?
              AND time_slot = ?
              AND COALESCE(is_available, 1) = 1
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('isss', $trainerId, $facilitySlug, $date, $slot);
    $stmt->execute();
    $result = $stmt->get_result();
    $available = $result && $result->num_rows > 0;
    if ($result) $result->free();
    $stmt->close();

    return $available;
}

function tb_is_trainer_available(mysqli $conn, int $trainerId, string $facilitySlug, string $date, string $slot, ?int $excludeBookingId = null): bool {
    if ($trainerId <= 0) return false;
    if (!tb_valid_date($date)) return false;
    if (!in_array($slot, tb_time_slots(), true)) return false;
    if (!tb_trainer_has_availability($conn, $trainerId, $facilitySlug, $date, $slot)) return false;
    if (tb_is_slot_reserved($conn, $trainerId, $facilitySlug, $date, $slot, $excludeBookingId)) return false;
    return true;
}

function tb_available_slots_for_trainer(mysqli $conn, int $trainerId, string $facilitySlug, string $date): array {
    $facilitySlug = tb_normalize_slug($facilitySlug);
    $slots = [];

    if ($trainerId <= 0 || $facilitySlug === '' || !tb_valid_date($date)) return $slots;

    $sql = "SELECT DISTINCT time_slot
            FROM trainer_availability
            WHERE trainer_id = ?
              AND facility_slug = ?
              AND avail_date = ?
              AND COALESCE(is_available, 1) = 1
            ORDER BY time_slot";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $slots;
    $stmt->bind_param('iss', $trainerId, $facilitySlug, $date);
    $stmt->execute();
    $result = $stmt->get_result();

    $allowed = array_flip(tb_time_slots());
    while ($row = $result->fetch_assoc()) {
        $slot = trim($row['time_slot'] ?? '');
        if (!isset($allowed[$slot])) continue;
        if (!tb_is_slot_reserved($conn, $trainerId, $facilitySlug, $date, $slot)) {
            $slots[] = $slot;
        }
    }

    $result->free();
    $stmt->close();

    usort($slots, function($a, $b) use ($allowed) {
        return $allowed[$a] <=> $allowed[$b];
    });

    return array_values(array_unique($slots));
}

function tb_available_trainers_for_slot(mysqli $conn, string $facilitySlug, string $date, string $slot): array {
    $facilitySlug = tb_normalize_slug($facilitySlug);
    $trainers = [];

    if ($facilitySlug === '' || !tb_valid_date($date) || !in_array($slot, tb_time_slots(), true)) return $trainers;

    $hasStatus = tb_column_exists($conn, 'users', 'status');
    $statusSql = $hasStatus ? "AND LOWER(COALESCE(u.status,''))='active'" : '';

    $sql = "SELECT DISTINCT u.id, u.full_name, u.username
            FROM trainer_availability ta
            INNER JOIN users u ON u.id = ta.trainer_id
            WHERE ta.facility_slug = ?
              AND ta.avail_date = ?
              AND ta.time_slot = ?
              AND COALESCE(ta.is_available, 1) = 1
              AND LOWER(u.role) = 'trainer'
              {$statusSql}
            ORDER BY COALESCE(NULLIF(u.full_name,''), u.username), u.username";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $trainers;
    $stmt->bind_param('sss', $facilitySlug, $date, $slot);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $trainerId = (int)$row['id'];
        if (tb_is_slot_reserved($conn, $trainerId, $facilitySlug, $date, $slot)) continue;

        $name = trim($row['full_name'] ?? '');
        if ($name === '') $name = trim($row['username'] ?? 'Trainer #' . $trainerId);

        $trainers[] = [
            'id' => $trainerId,
            'name' => $name,
        ];
    }

    $result->free();
    $stmt->close();

    return $trainers;
}

function tb_load_facilities(mysqli $conn, string $viewerRole, bool $allAccess = false): array {
    $facilities = [];
    if (!tb_table_exists($conn, 'facilities')) return $facilities;

    $hasActive = tb_column_exists($conn, 'facilities', 'is_active');
    $hasVisible = tb_column_exists($conn, 'facilities', 'visible_to');

    $visibleSelect = $hasVisible ? "visible_to" : "'both' AS visible_to";
    $where = [];
    $types = '';
    $params = [];

    if ($hasActive) $where[] = "is_active = 1";

    if (!$allAccess && !in_array($viewerRole, ['admin', 'staff'], true) && $hasVisible) {
        $where[] = "(LOWER(visible_to) = 'both' OR LOWER(visible_to) = ?)";
        $types .= 's';
        $params[] = $viewerRole;
    }

    $sql = "SELECT id, name, slug, description, image, {$visibleSelect} FROM facilities";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY name ASC";

    if ($params) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) return $facilities;
        $refs = [$types];
        foreach ($params as $key => $value) $refs[] = &$params[$key];
        call_user_func_array([$stmt, 'bind_param'], $refs);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $facilities[] = $row;
        if ($result) $result->free();
        $stmt->close();
    } else {
        if ($result = $conn->query($sql)) {
            while ($row = $result->fetch_assoc()) $facilities[] = $row;
            $result->free();
        }
    }

    return $facilities;
}
