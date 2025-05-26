<?php

header('Content-Type: application/json');
$filePath = __DIR__ . '/../data/tokens.json';
if (file_exists($filePath)) {
    echo file_get_contents($filePath);
} else {
    echo json_encode(['error' => 'File not found']);
}