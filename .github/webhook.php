<?php
/**
 * webhook.php
 * SwitchBot物理ボタンOFF検知 → 自動ON叩き戻し
 * リトライ処理（最大3回）とログ記録機能付き
 */

// ==================== 定数設定 ====================
const MAX_RETRIES = 3;                      // 最大リトライ回数
const INITIAL_RETRY_DELAY = 1;              // 初回リトライ待機時間（秒）
const RETRY_BACKOFF_MULTIPLIER = 2;         // 指数バックオフ倍率
const API_TIMEOUT = 5;                      // API呼び出しタイムアウト（秒）
const LOG_FILE = __DIR__ . '/webhooks.log'; // ログファイルパス

// ==================== ヘルパー関数 ====================

/**
 * SwitchBot APIを呼び出してプラグをONにする
 * @param int $attempt 試行回数（1-based）
 * @return bool 成功した場合true
 */
function switchBotTurnOn($attempt = 1) {
    // 環境変数から SwitchBot API トークンとデバイスIDを取得
    $token = getenv('SWITCHBOT_API_TOKEN');
    $deviceId = getenv('SWITCHBOT_DEVICE_ID');
    
    if (!$token || !$deviceId) {
        logToFile("ERROR", "環境変数が未設定です (API_TOKEN or DEVICE_ID)");
        return false;
    }
    
    $url = "https://api.switch-bot.com/v1.1/devices/{$deviceId}/commands";
    
    $headers = [
        'Authorization: ' . $token,
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    $payload = json_encode([
        'commandType' => 'command',
        'command' => 'turnOn'
    ]);
    
    logToFile("INFO", "SwitchBot API呼び出し: 試行 {$attempt}/" . MAX_RETRIES);
    
    // cURL でリクエスト送信
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => API_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FAILONERROR => false  // エラーレスポンスも取得
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // ネットワークエラーチェック
    if ($curlError) {
        logToFile("WARN", "cURL エラー: {$curlError} (試行 {$attempt})");
        return retryIfPossible($attempt);
    }
    
    // HTTPステータスコード確認
    if ($httpCode !== 200) {
        logToFile("WARN", "API呼び出し失敗: HTTP {$httpCode} - {$response} (試行 {$attempt})");
        return retryIfPossible($attempt);
    }
    
    // レスポンス解析
    $result = json_decode($response, true);
    if (!isset($result['statusCode']) || $result['statusCode'] != 0) {
        logToFile("WARN", "API レスポンスエラー: " . json_encode($result) . " (試行 {$attempt})");
        return retryIfPossible($attempt);
    }
    
    logToFile("SUCCESS", "プラグを ON にしました (試行 {$attempt})");
    return true;
}

/**
 * リトライの判定と実行
 * @param int $attempt 現在の試行回数
 * @return bool 成功した場合true
 */
function retryIfPossible($attempt) {
    if ($attempt >= MAX_RETRIES) {
        logToFile("ERROR", "リトライ上限に達しました。プラグの復旧に失敗しました。");
        return false;
    }
    
    // 指数バックオフで待機時間を計算
    $delaySeconds = INITIAL_RETRY_DELAY * pow(RETRY_BACKOFF_MULTIPLIER, $attempt - 1);
    logToFile("INFO", "{$delaySeconds}秒後にリトライします...");
    
    // 待機（最大5秒）
    usleep(min($delaySeconds, 5) * 1000000);
    
    return switchBotTurnOn($attempt + 1);
}

/**
 * ログファイルに記録
 * @param string $level ログレベル (INFO, WARN, SUCCESS, ERROR)
 * @param string $message ログメッセージ
 */
function logToFile($level, $message) {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$level}] {$message}\n";
    
    // ファイルとPHPエラーログの両方に出力
    file_put_contents(LOG_FILE, $logLine, FILE_APPEND | LOCK_EX);
    error_log($message);
}

/**
 * リクエストの署名を検証（SwitchBotの正規性確認）
 * @param string $body リクエストボディ
 * @param string $signature リクエストヘッダーの署名
 * @return bool 署名が正当な場合true
 */
function verifyWebhookSignature($body, $signature) {
    $secret = getenv('SWITCHBOT_WEBHOOK_SECRET');
    
    if (!$secret) {
        logToFile("WARN", "WEBHOOK_SECRETが設定されていません。署名検証がスキップされます。");
        return true; // 本番ではfalseにして署名検証を必須に
    }
    
    $calculated = hash_hmac('sha256', $body, $secret);
    
    if ($calculated !== $signature) {
        logToFile("ERROR", "Webhook署名検証エラー。不正なリクエストの可能性があります。");
        return false;
    }
    
    return true;
}

// ==================== メイン処理 ====================

// リクエストボディを取得
$entityBody = file_get_contents('php://input');
logToFile("INFO", "Webhook受信: " . substr($entityBody, 0, 200)); // 最初の200文字のみ記録

// 署名検証（optional: 環境変数があれば検証）
$signature = $_SERVER['HTTP_X_SWITCHBOT_SIGNATURE'] ?? '';
if ($signature && !verifyWebhookSignature($entityBody, $signature)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// JSON デコード
$data = json_decode($entityBody, true);
if (!$data) {
    logToFile("ERROR", "JSON デコード失敗: {$entityBody}");
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

// WoPlugUS デバイスの OFF イベントをチェック
if (isset($data['context']['deviceType']) && $data['context']['deviceType'] === 'WoPlugUS') {
    $state = $data['context']['powerState'] ?? '';
    
    if (strtolower($state) === 'off') {
        
        // 会員サイトからの正規操作（フラグがある場合）は無視
        if (file_exists(__DIR__ . '/operator_flag.txt')) {
            logToFile("INFO", "会員サイトからの正規操作が検出されました。自動復旧をスキップします。");
            unlink(__DIR__ . '/operator_flag.txt');
            http_response_code(200);
            echo json_encode(['status' => 'ok', 'action' => 'skipped']);
            exit;
        }
        
        // 物理ボタン等による OFF → 自動 ON 叩き戻し開始
        logToFile("INFO", "=== 物理ボタン OFF 検知 ===");
        
        // リトライ処理付きで ON コマンド送信
        $success = switchBotTurnOn();
        
        // レスポンス返却
        http_response_code(200);
        echo json_encode([
            'status' => $success ? 'ok' : 'error',
            'action' => 'turn_on_triggered',
            'retries' => MAX_RETRIES
        ]);
        exit;
    }
}

// デバイスタイプが WoPlugUS ではない、または OFF でない場合
logToFile("INFO", "処理対象外のイベント: deviceType=" . ($data['context']['deviceType'] ?? 'undefined') . ", state=" . ($state ?? 'undefined'));

http_response_code(200);
echo json_encode(['status' => 'ok', 'action' => 'ignored']);