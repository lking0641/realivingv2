<?php
include "../../connection/connection.php";

$sql = "CREATE TABLE IF NOT EXISTS concept_header (
    id INT AUTO_INCREMENT PRIMARY KEY,
    header_image VARCHAR(255) NOT NULL,
    title VARCHAR(255) DEFAULT 'Concept Designs',
    subtitle TEXT DEFAULT 'A collection of curated cabinet styles blending form and function, crafted to elevate your interiors with personality and precision.',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($conn->query($sql) === TRUE) {
    echo "Table created successfully!<br>";
    
    // Insert default data
    $default_image = 'images/background-image.jpg';
    $default_title = 'Concept Designs';
    $default_subtitle = 'A collection of curated cabinet styles blending form and function, crafted to elevate your interiors with personality and precision.';
    
    $check = $conn->query("SELECT COUNT(*) as count FROM concept_header");
    $row = $check->fetch_assoc();
    
    if ($row['count'] == 0) {
        $stmt = $conn->prepare("INSERT INTO concept_header (header_image, title, subtitle) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $default_image, $default_title, $default_subtitle);
        $stmt->execute();
        echo "Default header data inserted!";
    }
} else {
    echo "Error: " . $conn->error;
}

$conn->close();
?>