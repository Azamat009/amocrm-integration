<?php
require __DIR__ . '/../src/Integration.php';

$data = $_POST;

file_put_contents(__DIR__ . '/webhook.log', print_r($data, true) . "\n", FILE_APPEND);

(new Integration())->handleWebhook($data);

http_response_code(200);
echo "Webhook received";