<?php
require __DIR__ . '/../src/Integration.php';

$data = json_decode(file_get_contents('php://input'), true);

file_put_contents('webhook.log', print_r($data, true) . "\n", FILE_APPEND);

(new Integration())->handleWebhook($data);

http_response_code(200);
echo "Webhook received";