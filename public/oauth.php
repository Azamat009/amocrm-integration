<?php
require __DIR__ . '/../src/Integration.php';

if (!isset($_GET['code'])) {
    header('Location: ' . (new Integration())->getAuthUrl());
    exit;
}

(new Integration())->handleOAuth($_GET['code']);