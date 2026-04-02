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
    // Verify this is a POST request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    // Handle image upload
    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $imagePath = '';
    
    if (isset($_FILES['imageFile']) && $_FILES['imageFile']['error'] === UPLOAD_ERR_OK) {
        // Validate image
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $fileType = finfo_file($fileInfo, $_FILES['imageFile']['tmp_name']);
        finfo_close($fileInfo);
        
        if (!in_array($fileType, $allowedTypes)) {
            throw new Exception('Only JPG, PNG, and GIF images are allowed');
        }
        
        // Limit file size to 2MB
        if ($_FILES['imageFile']['size'] > 2000000) {
            throw new Exception('Image size must be less than 2MB');
        }
        
        $fileName = uniqid() . '_' . basename($_FILES['imageFile']['name']);
        $targetPath = $uploadDir . $fileName;
        
        if (move_uploaded_file($_FILES['imageFile']['tmp_name'], $targetPath)) {
            $imagePath = $targetPath;
        } else {
            throw new Exception('Failed to upload image');
        }
    } elseif (!empty($_POST['image'])) {
        $imagePath = filter_var($_POST['image'], FILTER_SANITIZE_URL);
    } else {
        throw new Exception('Either upload an image or provide an image URL');
    }

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

    // Ensure date_start / date_end / is_free / event_fee columns exist (safe migration)
    foreach (['date_start DATE NULL', 'date_end DATE NULL', 'is_free TINYINT(1) NOT NULL DEFAULT 1', 'event_fee DECIMAL(12,2) NOT NULL DEFAULT 0'] as $colDef) {
        $colName = explode(' ', $colDef)[0];
        try {
            $pdo->exec("ALTER TABLE events ADD COLUMN $colName " . substr($colDef, strlen($colName) + 1));
        } catch (Exception $e) { /* already exists */ }
    }

    // Insert into database
    $isFree = !empty($_POST['is_free']) ? 1 : 0;
    $eventFee = $isFree ? 0 : max(0, (float)($_POST['event_fee'] ?? 0));

    $stmt = $pdo->prepare("INSERT INTO events (id, type, status, image, imageAlt, countdown, date, date_start, date_end, title, description, location, featured, category, is_free, event_fee) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $success = $stmt->execute([
        $_POST['id'],
        $_POST['type'],
        $status,
        $imagePath,
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
        $eventFee
    ]);
    
    if (!$success) {
        throw new Exception('Failed to save to database');
    }
    
    // Clear any accidental output
    ob_end_clean();
    
    echo json_encode(['success' => true]);
    exit;
    
} catch (Exception $e) {
    // Clear any buffered output
    ob_end_clean();
    
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString() // Remove this in production
    ]);
    exit;
}
?>