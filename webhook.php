<?php
/**
 * webhook.php
 * SwitchBot物理ボタンOFF検知 → 自動ON叩き戻し
 * リトライ処理（最大3回）とログ記録機能付き
 */

require_once __DIR__ . '/api_utils.php';

// ==================== 定数設定 ====================
const MAX_RETRIES = 3;                      // 最大リトライ回数
const INITIAL_RETRY_DELAY = 1;              // 初回リトライ待機時間（秒）
const RETRY_BACKOFF_MULTIPLIER = 2;         // 指数バックオフ倍率
const LOG_FILE = __DIR__ . '/webhooks.log'; // ログファイルパス

// ==================== ヘルパー関数 ====================

/**
 * リトライ付きで SwitchBot プラグを ON にする
 * @param int $attempt 試行回数（1-based）
 * @return bool 成功した場合true
 */
function turnOnWithRetry($attempt = 1) {
    logToFile("INFO", "SwitchBot API呼び出し: 試行 {$attempt}/" . MAX_RETRIES);
    
    $result = switchBotTurnOn($attempt);
    
    if ($result) {
        logToFile("SUCCESS", "プラグを ON にしました");
        return true;
    }
    
    return false;
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

// ==================== メイン処理 ====================

// リクエストボディを取得
$entityBody = file_get_contents('php://input');
logToFile("INFO", "Webhook受信: " . substr($entityBody, 0, 200)); // 最初の200文字のみ記録

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
        $success = turnOnWithRetry();
        
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