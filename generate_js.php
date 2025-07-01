<?php
require 'db.php';

// Get all events from database
$events = $pdo->query("SELECT * FROM events")->fetchAll(PDO::FETCH_ASSOC);

// Initialize with all possible categories
$eventsData = [
    'featured' => [],
    'current' => [],
    'upcoming' => [],
    'conferences' => [],
    'workshops' => [],
    'webinars' => [],
    'past' => []
];

foreach ($events as $event) {
    // Determine the correct category
    $category = $event['category'];
    
    // Remove the category field from the event data
    unset($event['category']);
    
    // Convert featured to boolean
    $event['featured'] = (bool)$event['featured'];
    
    // Add to the appropriate category
    if (array_key_exists($category, $eventsData)) {
        $eventsData[$category][] = $event;
    } else {
        // Fallback to 'upcoming' if category is invalid
        $eventsData['upcoming'][] = $event;
    }
}

// Generate JS file content with proper formatting
$jsContent = "const eventsData = " . json_encode($eventsData, 
    JSON_PRETTY_PRINT | 
    JSON_UNESCAPED_SLASHES |
    JSON_NUMERIC_CHECK) . ";\n";

// Write to file
file_put_contents('data.js', $jsContent);

echo "JavaScript file generated successfully!";
?>