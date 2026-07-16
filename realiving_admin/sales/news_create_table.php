<?php
include "../../connection/connection.php";

// Create news table
$sql = "CREATE TABLE IF NOT EXISTS news (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    content LONGTEXT NOT NULL,
    image VARCHAR(255) NOT NULL,
    keywords VARCHAR(255),
    author VARCHAR(100) DEFAULT 'Admin',
    date_uploaded TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    views INT DEFAULT 0,
    featured TINYINT(1) DEFAULT 0,
    status ENUM('published', 'draft') DEFAULT 'published',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

// Create news header table
$sql2 = "CREATE TABLE IF NOT EXISTS news_header (
    id INT AUTO_INCREMENT PRIMARY KEY,
    header_image VARCHAR(255) NOT NULL,
    title VARCHAR(255) DEFAULT 'News & Updates',
    subtitle TEXT DEFAULT 'Stay updated with the latest news, trends, and announcements from Realiving Design Center',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($conn->query($sql) === TRUE && $conn->query($sql2) === TRUE) {
    echo "Tables created successfully!<br>";
    
    // Insert default header data
    $check = $conn->query("SELECT COUNT(*) as count FROM news_header");
    $row = $check->fetch_assoc();
    
    if ($row['count'] == 0) {
        $default_image = 'images/background-image.jpg';
        $default_title = 'News & Updates';
        $default_subtitle = 'Stay updated with the latest news, trends, and announcements from Realiving Design Center';
        
        $stmt = $conn->prepare("INSERT INTO news_header (header_image, title, subtitle) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $default_image, $default_title, $default_subtitle);
        $stmt->execute();
        echo "Default header data inserted!";
    }
} else {
    echo "Error: " . $conn->error;
}

$conn->close();
?>