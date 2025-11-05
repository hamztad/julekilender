<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// Last inn wp-config.php hvis den ikke allerede er lastet
if (!defined('OPENAI_KEY')) {
    $wp_config_path = $_SERVER['DOCUMENT_ROOT'] . '/wp-config.php';
    if (file_exists($wp_config_path)) {
        require_once($wp_config_path);
    }
}

if (!defined('OPENAI_KEY')) {
    echo json_encode(['error' => 'API-nøkkel ikke konfigurert. Legg til OPENAI_KEY i wp-config.php']);
    exit;
}
$key = OPENAI_KEY;

// Sjekk at filen finnes
if (!isset($_FILES['file'])) {
  echo json_encode(['error' => 'Ingen fil mottatt.']);
  exit;
}

if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
  echo json_encode([
    'error' => 'Opplasting feilet',
    'code' => $_FILES['file']['error'],
    'size' => $_FILES['file']['size'] ?? 0
  ]);
  exit;
}

$tmp = $_FILES['file']['tmp_name'];
$fileName = $_FILES['file']['name'];
// Detekter filtype fra filnavn eller bruk webm som default
$mimeType = 'audio/webm';
if (preg_match('/\.(mp3|mpeg)$/i', $fileName)) {
  $mimeType = 'audio/mpeg';
} elseif (preg_match('/\.(m4a|mp4)$/i', $fileName)) {
  $mimeType = 'audio/mp4';
} elseif (preg_match('/\.(webm)$/i', $fileName)) {
  $mimeType = 'audio/webm';
}

// --- Send til OpenAI Whisper API ---
$ch = curl_init("https://api.openai.com/v1/audio/transcriptions");
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => [
    "Authorization: Bearer $key"
  ],
  CURLOPT_POSTFIELDS => [
    "model" => "whisper-1",
    "file" => new CURLFile($tmp, $mimeType, $fileName),
    "translate" => false,
    // Tving norsk som standard språk for transkripsjon
    "language" => "no",
    // Lett prompt som forankrer domenet og språket
    "prompt" => "Transkriber i naturlig norsk. Vanlige ord: jul, advent, luke, nisse, juleaften, gave, pepperkake."
]
]);

$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
  echo json_encode(['error' => "cURL-feil: $error"]);
  exit;
}

// --- Forsøk å lese JSON fra OpenAI ---
$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
  echo json_encode([
    'error' => 'Kunne ikke lese JSON fra OpenAI',
    'raw' => substr($response, 0, 500)
  ]);
  exit;
}

// --- Håndter feil fra OpenAI ---
if (isset($data['error'])) {
  echo json_encode([
    'error' => $data['error']['message'] ?? 'Ukjent feil fra OpenAI',
    'type' => $data['error']['type'] ?? '',
    'code' => $data['error']['code'] ?? ''
  ]);
  exit;
}

// --- Returner kun teksten (som frontend forventer) ---
if (isset($data['text'])) {
  echo json_encode(['text' => trim($data['text'])], JSON_UNESCAPED_UNICODE);
} else {
  echo json_encode(['error' => 'Ingen tekst mottatt fra Whisper.']);
}