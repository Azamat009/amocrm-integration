<?php
require __DIR__ . '/../vendor/autoload.php';

use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\Exceptions\AmoCRMoAuthApiException;
use AmoCRM\Models\NoteModel;
use League\OAuth2\Client\Token\AccessToken;

class Integration {
    private $client;
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $subdomain;

    public function __construct() {
        $this->clientId = getenv('AMOCRM_CLIENT_ID');
        $this->clientSecret = getenv('AMOCRM_CLIENT_SECRET');
        $this->redirectUri = getenv('AMOCRM_REDIRECT_URI');
        $this->subdomain = getenv('AMOCRM_SUBDOMAIN');

        $logMessage = "Constructor: clientId={$this->clientId}, redirectUri={$this->redirectUri}, subdomain={$this->subdomain}\n";
        $this->safeLog(__DIR__ . '/../data/constructor.log', $logMessage);

        $this->client = new AmoCRMApiClient(
            $this->clientId,
            $this->clientSecret,
            $this->redirectUri
        );

        $this->client->setAccountBaseDomain($this->subdomain . '.amocrm.ru');
    }

    public function getAuthUrl(): string
    {
        $oauthClient = $this->client->getOAuthClient();
        $url = $oauthClient->getAuthorizeUrl([
            'state' => bin2hex(random_bytes(16)),
        ]);
        $this->safeLog(__DIR__ . '/../data/auth_url.log', "Generated Auth URL: $url\n");
        return $url;
    }

    public function handleOAuth(string $code): void {
        try {
            $this->safeLog(__DIR__ . '/../data/oauth.log', "Handling OAuth with code: $code\n");
            $token = $this->client->getOAuthClient()->getAccessTokenByCode($code);
            $this->saveTokens($token);
            echo "Authorization success! Tokens saved.";
        } catch (AmoCRMoAuthApiException $e) {
            $error = "OAuth error: " . $e->getMessage() . " | Code: " . $e->getCode();
            error_log($error);
            $this->safeLog(__DIR__ . '/../data/oauth.log', $error . "\n");
            echo "Ошибка авторизации: " . $e->getMessage();
            http_response_code(500);
        }
    }

    public function handleWebhook(array $data): void {
        $this->safeLog(__DIR__ . '/../data/webhook.log', "Webhook data: " . print_r($data, true) . "\n");
        $eventTypes = ['leads', 'contacts'];
        $actions = ['add', 'update'];

        foreach ($eventTypes as $entityType) {
            foreach ($actions as $action) {
                $key = $entityType . '[' . $action . ']';
                if (isset($data[$key])) {
                    foreach ($data[$key] as $entityData) {
                        $this->processEvent($entityType, $action, $entityData);
                    }
                }
            }
        }
    }

    private function processEvent(string $entityType, string $action, array $entityData): void {
        $this->loadTokens();
        $entityId = $entityData['id'];
        $noteText = $this->generateNoteText($action, $entityType, $entityData);
        $this->safeLog(__DIR__ . '/../data/event.log', "Processing event: type=$entityType, action=$action, id=$entityId, note=$noteText\n");
        $this->addNote($entityType, $entityId, $noteText);
    }

    private function generateNoteText(string $action, string $entityType, array $entityData): string {
        $time = date('Y-m-d H:i:s', $entityData[$action === 'add' ? 'date_create' : 'updated_at'] ?? time());

        if ($action === 'add') {
            $responsibleUserId = $entityData['responsible_user_id'];
            try {
                $user = $this->client->users()->getOne($responsibleUserId);
                $userName = $user->getName();
            } catch (Exception $e) {
                $userName = "Unknown (ID: $responsibleUserId)";
                $this->safeLog(__DIR__ . '/../data/error.log', "Failed to fetch user $responsibleUserId: " . $e->getMessage() . "\n");
            }

            return sprintf(
                "%s добавлен(а): %s, ответственный: %s, время: %s",
                $entityType === 'leads' ? 'Сделка' : 'Контакт',
                $entityData['name'] ?? 'Без названия',
                $userName,
                $time
            );
        } elseif ($action === 'update') {
            $changedFields = [];
            if (isset($entityData['name'])) {
                $changedFields[] = "Название: " . $entityData['name'];
            }
            if ($entityType === 'leads' && isset($entityData['status_id'])) {
                $changedFields[] = "Статус: " . $entityData['status_id'];
            }
            $changedText = implode(', ', $changedFields);
            return sprintf(
                "%s изменен(а): %s, время: %s",
                $entityType === 'leads' ? 'Сделка' : 'Контакт',
                $changedText ?: 'без изменений',
                $time
            );
        }
        return '';
    }

    private function addNote(string $entityType, int $id, string $text): void {
        try {
            $notesService = $this->client->notes($entityType);
            $note = new NoteModel();
            $note
                ->setEntityId($id)
                ->setText($text);
            $notesService->addOne($note);
            $this->safeLog(__DIR__ . '/../data/note.log', "Added note for $entityType ID $id: $text\n");
        } catch (Exception $e) {
            $error = "Ошибка добавления заметки для $entityType ID $id: " . $e->getMessage();
            error_log($error);
            $this->safeLog(__DIR__ . '/../data/error.log', $error . "\n");
            echo $error;
        }
    }

    private function saveTokens($token): void {
        $tokenData = [
            'access_token' => $token->getToken(),
            'refresh_token' => $token->getRefreshToken(),
            'expires' => $token->getExpires(),
            'baseDomain' => $this->subdomain . '.amocrm.ru',
        ];
        if (!is_dir(__DIR__ . '/../data')) {
            mkdir(__DIR__ . '/../data', 0755, true);
        }
        $this->safeWriteFile(__DIR__ . '/../data/tokens.json', json_encode($tokenData));
        $this->safeLog(__DIR__ . '/../data/token.log', "Tokens saved: " . print_r($tokenData, true) . "\n");
        echo "Tokens saved.";
    }

    private function loadTokens(): void {
        $filePath = __DIR__ . '/../data/tokens.json';
        if (file_exists($filePath)) {
            $tokenData = json_decode(file_get_contents($filePath), true);
            $accessToken = new AccessToken([
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'],
                'expires' => $tokenData['expires'],
                'baseDomain' => $tokenData['baseDomain'],
            ]);
            if ($accessToken->hasExpired()) {
                $this->safeLog(__DIR__ . '/../data/token.log', "Token expired, refreshing...\n");
                $newAccessToken = $this->client->getOAuthClient()->getAccessTokenByRefreshToken($accessToken->getRefreshToken());
                $this->saveTokens($newAccessToken);
                $accessToken = $newAccessToken;
                echo "Tokens updated.";
            }
            $this->client->setAccessToken($accessToken);
        } else {
            $error = "Токены не найдены. Сначала выполните авторизацию.";
            $this->safeLog(__DIR__ . '/../data/error.log', $error . "\n");
            echo $error;
            exit;
        }
    }

    public function safeLog(string $file, string $message): void {
        if (is_writable(dirname($file)) && (file_exists($file) ? is_writable($file) : true)) {
            file_put_contents($file, $message, FILE_APPEND);
        } else {
            error_log("Cannot write to $file: Permission denied");
        }
    }

    private function safeWriteFile(string $file, string $data): void {
        if (is_writable(dirname($file)) && (file_exists($file) ? is_writable($file) : true)) {
            file_put_contents($file, $data);
        } else {
            error_log("Cannot write to $file: Permission denied");
            echo "Cannot write to $file: Permission denied";
        }
    }
}