<?php
// Add this at the very top to ensure no output before headers
ob_start();
require 'db.php';

// Set headers first
header('Content-Type: application/json');

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

    // Insert into database
    $stmt = $pdo->prepare("INSERT INTO events (id, type, status, image, imageAlt, countdown, date, title, description, location, featured, category) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $success = $stmt->execute([
        $_POST['id'],
        $_POST['type'],
        $_POST['status'],
        $imagePath,
        $_POST['imageAlt'],
        $_POST['countdown'] ?? null,
        $_POST['date'],
        $_POST['title'],
        $_POST['description'],
        $_POST['location'],
        isset($_POST['featured']) ? 1 : 0,
        $_POST['category']
    ]);
    
    if (!$success) {
        throw new Exception('Failed to save to database');
    }
    
    // Regenerate JS file automatically
    require 'generate_js.php';
    
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