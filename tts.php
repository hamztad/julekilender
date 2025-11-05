<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: audio/mpeg');

// Last inn wp-config.php hvis den ikke allerede er lastet
if (!defined('OPENAI_KEY')) {
    $wp_config_path = $_SERVER['DOCUMENT_ROOT'] . '/wp-config.php';
    if (file_exists($wp_config_path)) {
        require_once($wp_config_path);
    }
}

if (!defined('OPENAI_KEY')) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'API-nøkkel ikke konfigurert. Legg til OPENAI_KEY i wp-config.php']);
    exit;
}
$key = OPENAI_KEY;
$text = $_POST['text'] ?? '';

$ch = curl_init("https://api.openai.com/v1/audio/speech");
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => [
    "Authorization: Bearer $key",
    "Content-Type: application/json"
  ],
  CURLOPT_POSTFIELDS => json_encode([
    "model" => "gpt-4o-mini-tts",
    "voice" => "alloy",     // kan byttes til bl.a. "verse", "soft", "spark"
    "input" => $text
  ])
]);
$response = curl_exec($ch);
curl_close($ch);
echo $response;