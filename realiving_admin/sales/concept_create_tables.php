<?php
include "../../connection/connection.php";

// Table for concept styles (Modern, Contemporary, etc.)
$sql1 = "CREATE TABLE IF NOT EXISTS concept_styles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    layout_type ENUM('full', 'two-column') DEFAULT 'full',
    display_order INT DEFAULT 0,
    is_reversed TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

// Table for carousel images
$sql2 = "CREATE TABLE IF NOT EXISTS concept_carousel (
    id INT AUTO_INCREMENT PRIMARY KEY,
    image_path VARCHAR(255) NOT NULL,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($sql1) === TRUE && $conn->query($sql2) === TRUE) {
    echo "Tables created successfully!";
} else {
    echo "Error creating tables: " . $conn->error;
}

$conn->close();
?>