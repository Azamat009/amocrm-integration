<?php
require __DIR__ . '/../src/Integration.php';

$data = json_decode(file_get_contents('php://input'), true);
(new Integration())->handleWebhook($data);