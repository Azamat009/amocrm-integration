<?php
ob_start();
require __DIR__ . '/../src/Integration.php';

$integration = new Integration();
$integration->safeLog(__DIR__ . '/../data/oauth.log', "OAuth query params: " . print_r($_GET, true) . "\n");

if (!isset($_GET['code'])) {
    $authUrl = $integration->getAuthUrl();
    header('Location: ' . $authUrl);
    ob_end_flush(); // Flush output and send headers
    exit;
}

$integration->handleOAuth($_GET['code']);
ob_end_flush(); // Flush any remaining output