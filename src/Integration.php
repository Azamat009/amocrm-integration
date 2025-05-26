<?php
require __DIR__ . '/../vendor/autoload.php';

use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\Exceptions\AmoCRMoAuthApiException;
use AmoCRM\Models\NoteModel;

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
        return $oauthClient->getAuthorizeUrl([
            'state' => bin2hex(random_bytes(16)),
            'mode' => 'post_message',
        ]);
    }

    public function handleOAuth(string $code): void {
        try {
            $token = $this->client->getOAuthClient()->getAccessTokenByCode($code);
            $this->saveTokens($token);
            echo "Authorization success! Tokens saved.";
        } catch (AmoCRMoAuthApiException $e) {
            error_log("OAuth error: " . $e->getMessage() . " | Code: " . $e->getCode());
            echo "Ошибка авторизации: " . $e->getMessage();
            http_response_code(500);
        }
    }

    public function handleWebhook(array $data): void {
        foreach ($data['events'] as $event) {
            $this->processEvent($event);
        }
    }

    private function processEvent(array $event): void {
        $this->loadTokens();

        $entityType = explode('_', $event['type'])[0];
        $entityId = $event[$entityType.'s'][0]['id'];

        $entity = $this->client->{$entityType.'s'}()->getOne($entityId);

        $noteText = $this->generateNoteText($event, $entity);
        $this->addNote($entityType, $entityId, $noteText);
    }

    private function generateNoteText(array $event, $entity): string {
        $type = explode('_', $event['type'])[1];
        $time = date('Y-m-d H:i:s');

        if ($type === 'add') {
            return sprintf(
                "Создана новая %s\nНазвание: %s\nОтветственный: %s\nВремя: %s",
                $entity->name,
                $entity->responsible_user_id,
                $time
            );
        }

        $changes = [];
        foreach ($entity->custom_fields_values as $field) {
            $changes[] = "{$field->field_name}: {$field->values[0]->value}";
        }

        return sprintf(
            "Изменения в %s\n%s\nВремя: %s",
            $entity->name,
            implode("\n", $changes),
            $time
        );
    }

    private function addNote(string $entityType, int $id, string $text): void {
        try {
            $notesService = $this->client->notes($entityType);
            $note = new NoteModel();
            $note
                ->setEntityId($id)
                ->setText($text);
            $notesService->add($note);
        } catch (Exception $e) {
            echo ('Ошибка добавления заметки: ' . $e->getMessage());
        }
    }

    private function saveTokens($token): void {
        file_put_contents(__DIR__.'/../data/tokens.json', json_encode([
            'access_token' => $token->getAccessToken(),
            'refresh_token' => $token->getRefreshToken(),
            'expires' => $token->getExpires()
        ]));
        echo ('Tokens saved.');
    }

    private function loadTokens(): void {
        $filePath = __DIR__.'/../data/tokens.json';
        if (!is_dir(dirname($filePath))) {
            mkdir(dirname($filePath), 0755, true);
        }
        if (file_exists(__DIR__ . '/../data/tokens.json')) {
            $tokens = json_decode(file_get_contents(__DIR__ . '/../data/tokens.json'), true);
            if (time() > $tokens['expires']) {
                $newToken = $this->client->getOAuthClient()->getAccessTokenByRefreshToken($tokens['refresh_token']);
                $this->saveTokens($newToken);
                $tokens = $newToken;
                echo ('Tokens updated.');
            }
        } else {
            echo ('Токены не найдены. Сначала выполните авторизацию.');
        }
    }
}