<?php
// Add this at the very top to ensure no output before headers
ob_start();
session_start();
require 'db.php';

// Set headers first
header('Content-Type: application/json');

// Require admin authentication
if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Admin authentication required']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5000000; // 5MB per image

    // ── Collect every uploaded image into a single ordered gallery list ──
    // Accept: imageFile (legacy single), imageFiles[] (new multi), image (URL),
    // imageUrls[] (multi URL), and existingGallery[] (paths preserved across edits).
    $gallery = [];

    // 1) Existing gallery paths kept by the editor (on edit) — preserve order
    if (!empty($_POST['existingGallery']) && is_array($_POST['existingGallery'])) {
        foreach ($_POST['existingGallery'] as $p) {
            $p = trim((string)$p);
            if ($p !== '') $gallery[] = $p;
        }
    }

    // 2) New URL inputs
    if (!empty($_POST['imageUrls']) && is_array($_POST['imageUrls'])) {
        foreach ($_POST['imageUrls'] as $u) {
            $u = filter_var(trim((string)$u), FILTER_SANITIZE_URL);
            if ($u !== '') $gallery[] = $u;
        }
    }
    if (!empty($_POST['image'])) {
        $u = filter_var(trim((string)$_POST['image']), FILTER_SANITIZE_URL);
        if ($u !== '') $gallery[] = $u;
    }

    // 3) Helper to validate + move one uploaded file
    $processUpload = function($tmpName, $origName, $size, $errCode) use ($allowedTypes, $maxSize, $uploadDir) {
        if ($errCode !== UPLOAD_ERR_OK) throw new Exception('Upload failed (error code ' . $errCode . ')');
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $type = finfo_file($fi, $tmpName);
        finfo_close($fi);
        if (!in_array($type, $allowedTypes, true)) {
            throw new Exception('Only JPG, PNG, GIF, and WebP images are allowed');
        }
        if ($size > $maxSize) {
            throw new Exception('Each image must be smaller than 5MB');
        }
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($origName));
        $fileName = uniqid('', true) . '_' . $safeName;
        $target = $uploadDir . $fileName;
        if (!move_uploaded_file($tmpName, $target)) {
            throw new Exception('Failed to save uploaded image');
        }
        return $target;
    };

    // 4) Multi upload via imageFiles[]
    if (!empty($_FILES['imageFiles']) && is_array($_FILES['imageFiles']['name'])) {
        $files = $_FILES['imageFiles'];
        for ($i = 0; $i < count($files['name']); $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $gallery[] = $processUpload(
                $files['tmp_name'][$i],
                $files['name'][$i],
                $files['size'][$i],
                $files['error'][$i]
            );
        }
    }

    // 5) Legacy single upload via imageFile
    if (!empty($_FILES['imageFile']) && ($_FILES['imageFile']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $gallery[] = $processUpload(
            $_FILES['imageFile']['tmp_name'],
            $_FILES['imageFile']['name'],
            $_FILES['imageFile']['size'],
            $_FILES['imageFile']['error']
        );
    }

    // Dedupe while preserving order
    $gallery = array_values(array_unique($gallery));

    if (empty($gallery)) {
        throw new Exception('Please upload at least one image or provide an image URL');
    }

    $primaryImage = $gallery[0];

    // Validate required fields
    $required = ['id', 'type', 'status', 'imageAlt', 'date', 'title', 'description', 'location', 'category'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Field $field is required");
        }
    }

    // Reject events with a start date in the past
    $dateStart = !empty($_POST['date_start']) ? trim($_POST['date_start']) : null;
    $dateEnd   = !empty($_POST['date_end'])   ? trim($_POST['date_end'])   : null;
    if ($dateStart) {
        $ts = strtotime($dateStart);
        if ($ts === false || $ts < strtotime('today')) {
            throw new Exception('Event start date cannot be in the past.');
        }
    }
    if ($dateEnd && $dateStart && strtotime($dateEnd) < strtotime($dateStart)) {
        throw new Exception('End date cannot be before start date.');
    }

    // Auto-determine status & category from dates
    $status   = $_POST['status'] ?? 'open';
    $category = $_POST['category'] ?? 'upcoming';
    if ($dateStart) {
        $today   = new DateTimeImmutable('today');
        $startDt = new DateTimeImmutable($dateStart);
        $endDt   = $dateEnd ? new DateTimeImmutable($dateEnd) : $startDt;
        if ($today > $endDt) {
            $status   = 'past';
            $category = 'past';
        } elseif ($today >= $startDt && $today <= $endDt) {
            if ($status === 'past') $status = 'open';
            if ($category === 'past') $category = 'current';
        } else {
            if ($status === 'past') $status = 'open';
            if ($category === 'past') $category = 'upcoming';
        }
    }

    // Ensure date_start / date_end / is_free / event_fee / gallery_images columns exist
    foreach ([
        'date_start DATE NULL',
        'date_end DATE NULL',
        'is_free TINYINT(1) NOT NULL DEFAULT 1',
        'event_fee DECIMAL(12,2) NOT NULL DEFAULT 0',
        'gallery_images TEXT NULL'
    ] as $colDef) {
        $colName = explode(' ', $colDef)[0];
        try {
            $pdo->exec("ALTER TABLE events ADD COLUMN $colName " . substr($colDef, strlen($colName) + 1));
        } catch (Exception $e) { /* already exists */ }
    }

    $isFree = !empty($_POST['is_free']) ? 1 : 0;
    $eventFee = $isFree ? 0 : max(0, (float)($_POST['event_fee'] ?? 0));
    $galleryJson = json_encode($gallery, JSON_UNESCAPED_SLASHES);

    // Upsert: replace if id already exists (event edit)
    $stmt = $pdo->prepare("
        INSERT INTO events
            (id, type, status, image, imageAlt, countdown, date, date_start, date_end, title, description, location, featured, category, is_free, event_fee, gallery_images)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            type=VALUES(type), status=VALUES(status), image=VALUES(image), imageAlt=VALUES(imageAlt),
            countdown=VALUES(countdown), date=VALUES(date), date_start=VALUES(date_start), date_end=VALUES(date_end),
            title=VALUES(title), description=VALUES(description), location=VALUES(location), featured=VALUES(featured),
            category=VALUES(category), is_free=VALUES(is_free), event_fee=VALUES(event_fee), gallery_images=VALUES(gallery_images)
    ");

    $success = $stmt->execute([
        $_POST['id'],
        $_POST['type'],
        $status,
        $primaryImage,
        $_POST['imageAlt'],
        $_POST['countdown'] ?? null,
        $_POST['date'],
        $dateStart,
        $dateEnd,
        $_POST['title'],
        $_POST['description'],
        $_POST['location'],
        isset($_POST['featured']) ? 1 : 0,
        $category,
        $isFree,
        $eventFee,
        $galleryJson
    ]);

    if (!$success) {
        throw new Exception('Failed to save to database');
    }

    ob_end_clean();
    echo json_encode(['success' => true, 'gallery_count' => count($gallery)]);
    exit;

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}
?>
