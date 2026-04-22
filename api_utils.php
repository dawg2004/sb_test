<?php
/**
 * api_utils.php
 * SwitchBot API 共通ユーティリティ関数
 */

/**
 * SwitchBot API に HTTP リクエストを送信
 * @param string $endpoint APIエンドポイント
 * @param string $method HTTPメソッド (GET, POST, DELETE)
 * @param array $payload リクエストボディ（POSTの場合）
 * @return array APIレスポンス or エラー情報
 */
function switchBotApiRequest($endpoint, $method = 'GET', $payload = null) {
    $token = getenv('SWITCHBOT_API_TOKEN');
    $deviceId = getenv('SWITCHBOT_DEVICE_ID');
    
    if (!$token || !$deviceId) {
        return [
            'success' => false,
            'error' => '環境変数が未設定です (SWITCHBOT_API_TOKEN or SWITCHBOT_DEVICE_ID)'
        ];
    }
    
    $url = "https://api.switch-bot.com/v1.1{$endpoint}";
    
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
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_FAILONERROR => false
    ]);
    
    if ($payload && in_array($method, ['POST', 'PUT'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    
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
        'data' => $result,
        'httpCode' => $httpCode
    ];
}

/**
 * SwitchBot デバイスの現在の状態を取得
 * @return array デバイス情報
 */
function getSwitchBotDeviceStatus() {
    $deviceId = getenv('SWITCHBOT_DEVICE_ID');
    return switchBotApiRequest("/devices/{$deviceId}/status");
}

/**
 * SwitchBot プラグをONにする
 * @return bool 成功した場合true
 */
function switchBotTurnOn($attempt = 1) {
    $deviceId = getenv('SWITCHBOT_DEVICE_ID');
    
    if (!$deviceId) {
        error_log("ERROR: SWITCHBOT_DEVICE_ID が未設定です");
        return false;
    }
    
    $payload = [
        'commandType' => 'command',
        'command' => 'turnOn'
    ];
    
    $result = switchBotApiRequest("/devices/{$deviceId}/commands", 'POST', $payload);
    
    if (!$result['success']) {
        error_log("警告: API呼び出し失敗 (試行 {$attempt}): " . $result['error']);
        
        if ($attempt >= 3) {
            error_log("エラー: リトライ上限に達しました");
            return false;
        }
        
        // 指数バックオフで待機
        $delaySeconds = pow(2, $attempt - 1);
        usleep($delaySeconds * 1000000);
        
        return switchBotTurnOn($attempt + 1);
    }
    
    error_log("成功: プラグを ON にしました");
    return true;
}
