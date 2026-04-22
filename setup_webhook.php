<?php
/**
 * setup_webhook.php
 * SwitchBot webhook セットアップスクリプト
 * 
 * 使用方法:
 *   curl http://luxewave.jp/switchbot/setup_webhook.php
 */

require_once __DIR__ . '/api_utils.php';

header('Content-Type: application/json; charset=utf-8');

// ==================== セットアップ処理 ====================

$results = [];

// 1. 環境変数の確認
$results['step1_env_check'] = checkEnvironmentVariables();
if (!$results['step1_env_check']['success']) {
    http_response_code(500);
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. デバイス状態の確認
$results['step2_device_status'] = checkDeviceStatus();
if (!$results['step2_device_status']['success']) {
    http_response_code(500);
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. Webhook URL の構築
$webhookUrl = buildWebhookUrl();
$results['step3_webhook_url'] = [
    'success' => true,
    'url' => $webhookUrl,
    'message' => 'Webhook URL を構築しました'
];

// 4. Webhook を登録
$results['step4_register_webhook'] = registerWebhook($webhookUrl);

// 5. 登録済み Webhook の一覧を取得
$results['step5_list_webhooks'] = listWebhooks();

// 成功レスポンス
http_response_code(200);
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// ==================== ヘルパー関数 ====================

/**
 * 環境変数を確認
 */
function checkEnvironmentVariables() {
    $token = getenv('SWITCHBOT_API_TOKEN');
    $deviceId = getenv('SWITCHBOT_DEVICE_ID');
    
    $missing = [];
    if (!$token) $missing[] = 'SWITCHBOT_API_TOKEN';
    if (!$deviceId) $missing[] = 'SWITCHBOT_DEVICE_ID';
    
    if (!empty($missing)) {
        return [
            'success' => false,
            'error' => '環境変数が未設定です: ' . implode(', ', $missing),
            'required' => $missing
        ];
    }
    
    return [
        'success' => true,
        'message' => '環境変数は正しく設定されています',
        'device_id' => substr($deviceId, 0, 10) . '...' // マスク表示
    ];
}

/**
 * デバイス状態を確認
 */
function checkDeviceStatus() {
    $result = getSwitchBotDeviceStatus();
    
    if (!$result['success']) {
        return [
            'success' => false,
            'error' => $result['error'],
            'httpCode' => $result['httpCode'] ?? null
        ];
    }
    
    $data = $result['data'];
    return [
        'success' => true,
        'message' => 'デバイスに接続できました',
        'device_type' => $data['data']['deviceType'] ?? 'Unknown',
        'power_state' => $data['data']['power'] ?? 'Unknown'
    ];
}

/**
 * Webhook URL を構築
 */
function buildWebhookUrl() {
    // 現在のスキーム、ホスト、パスから構築
    $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    
    // setup_webhook.php の親ディレクトリの webhook.php を指定
    $baseDir = dirname($_SERVER['REQUEST_URI']);
    $webhookUrl = "{$scheme}://{$host}{$baseDir}/webhook.php";
    
    return $webhookUrl;
}

/**
 * Webhook を SwitchBot に登録
 */
function registerWebhook($webhookUrl) {
    $token = getenv('SWITCHBOT_API_TOKEN');
    
    if (!$token) {
        return [
            'success' => false,
            'error' => 'API トークンが未設定です'
        ];
    }
    
    // Webhook 登録用 API
    $url = "https://api.switch-bot.com/v1.1/webhook/setupWebhook";
    
    $headers = [
        'Authorization: ' . $token,
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    $payload = json_encode([
        'action' => 'setupWebhook',
        'url' => $webhookUrl,
        'deviceId' => getenv('SWITCHBOT_DEVICE_ID')
    ]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return [
            'success' => false,
            'error' => "cURL エラー: {$curlError}"
        ];
    }
    
    $result = json_decode($response, true);
    
    if ($httpCode !== 200) {
        return [
            'success' => false,
            'httpCode' => $httpCode,
            'error' => $result['message'] ?? $response
        ];
    }
    
    return [
        'success' => true,
        'message' => 'Webhook を登録しました',
        'webhook_url' => $webhookUrl
    ];
}

/**
 * 登録済み Webhook 一覧を取得
 */
function listWebhooks() {
    $token = getenv('SWITCHBOT_API_TOKEN');
    
    if (!$token) {
        return [
            'success' => false,
            'error' => 'API トークンが未設定です'
        ];
    }
    
    $url = "https://api.switch-bot.com/v1.1/webhook/queryWebhook";
    
    $headers = [
        'Authorization: ' . $token,
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return [
            'success' => false,
            'error' => "cURL エラー: {$curlError}"
        ];
    }
    
    $result = json_decode($response, true);
    
    if ($httpCode !== 200) {
        return [
            'success' => false,
            'httpCode' => $httpCode,
            'error' => $result['message'] ?? $response
        ];
    }
    
    return [
        'success' => true,
        'message' => '登録済み Webhook 一覧を取得しました',
        'webhooks' => $result['data'] ?? []
    ];
}
