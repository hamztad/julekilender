<?php
/**
 * openai.php – backend for OpenAI med støtte for samtalehistorikk
 * Husk å legge OPENAI_KEY i wp-config.php
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

// OPTIONS–forespørsler (WordPress kan sende dette før POST)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 1. Les inn data fra klient
$input = json_decode(file_get_contents("php://input"), true);
$mode = isset($input["mode"]) ? trim((string)$input["mode"]) : "";
$day = isset($input["day"]) ? (int)$input["day"] : 0;

// Støtte for både historikk (messages) og enkeltprompt
if (isset($input["messages"]) && is_array($input["messages"])) {
    $messages = $input["messages"];
} elseif (isset($input["prompt"])) {
    $messages = [["role" => "user", "content" => trim($input["prompt"])]];
} else {
    echo json_encode(["error" => "Ingen meldinger mottatt."]);
    exit;
}

// 2. Hent API-nøkkel fra wp-config.php
// Last inn wp-config.php hvis den ikke allerede er lastet
if (!defined('OPENAI_KEY')) {
    $wp_config_path = $_SERVER['DOCUMENT_ROOT'] . '/wp-config.php';
    if (file_exists($wp_config_path)) {
        require_once($wp_config_path);
    }
}

if (!defined("OPENAI_KEY")) {
    echo json_encode(["error" => "API-nøkkel ikke konfigurert. Legg til OPENAI_KEY i wp-config.php"]);
    exit;
}
$key = OPENAI_KEY;

// 3. Hjelpefunksjon for å lese data-filer
function readDataFile($filename) {
    $path = __DIR__ . '/data/' . $filename;
    if (file_exists($path)) {
        return file_get_contents($path);
    }
    return '';
}

// 4. Funksjoner for å parse og velge tilfeldige elementer basert på dagen
function parseFacts($content) {
    $facts = [];
    $lines = explode("\n", $content);
    $currentFact = '';
    foreach ($lines as $line) {
        $line = trim($line);
        // Start nytt fakta hvis linjen starter med "- "
        if (strpos($line, '- ') === 0) {
            if ($currentFact !== '') {
                $facts[] = $currentFact;
            }
            $currentFact = substr($line, 2); // Fjern "- "
        } elseif ($line !== '' && substr($line, 0, 1) !== '#' && $currentFact !== '') {
            // Fortsett eksisterende fakta hvis det ikke er en header
            $currentFact .= ' ' . $line;
        }
    }
    if ($currentFact !== '') {
        $facts[] = $currentFact;
    }
    return array_filter($facts, function($f) { return trim($f) !== ''; });
}

function parseRiddles($content) {
    $riddles = [];
    $lines = explode("\n", $content);
    $currentRiddle = '';
    foreach ($lines as $line) {
        $line = trim($line);
        // Start ny gåte hvis linjen er et nummer etterfulgt av punktum
        if (preg_match('/^\d+\.\s/', $line)) {
            if ($currentRiddle !== '') {
                $riddles[] = trim($currentRiddle);
            }
            $currentRiddle = preg_replace('/^\d+\.\s/', '', $line); // Fjern nummer
        } elseif ($line !== '' && substr($line, 0, 1) !== '#' && strpos($line, '→') !== 0 && $currentRiddle !== '') {
            // Fortsett eksisterende gåte hvis det ikke er en header eller svar
            // Men stopp hvis vi kommer til neste nummerert gåte
            if (!preg_match('/^\d+\.\s/', $line)) {
                $currentRiddle .= ' ' . $line;
            }
        }
    }
    if ($currentRiddle !== '') {
        $riddles[] = trim($currentRiddle);
    }
    return array_filter($riddles, function($r) { return trim($r) !== ''; });
}

function parseSongs($content) {
    $songs = [];
    $lines = explode("\n", $content);
    $currentSong = '';
    foreach ($lines as $line) {
        $line = trim($line);
        // Start ny sang hvis linjen starter med "## "
        if (strpos($line, '## ') === 0) {
            if ($currentSong !== '') {
                $songs[] = trim($currentSong);
            }
            $currentSong = $line . "\n";
        } elseif ($line !== '' && substr($line, 0, 1) !== '#') {
            $currentSong .= $line . "\n";
        }
    }
    if ($currentSong !== '') {
        $songs[] = trim($currentSong);
    }
    return array_filter($songs, function($s) { return trim($s) !== ''; });
}

// Forbedret funksjon for å velge tilfeldige elementer basert på dagen (deterministisk)
// Bruker multiple shuffle-passes og offset for bedre distribusjon fra hele listen
function selectRandomItems($array, $count, $seed) {
    if (count($array) === 0) return [];
    if (count($array) <= $count) return $array;
    
    // Lag en kopi av arrayet for å ikke endre originalen
    $shuffled = $array;
    
    // Bruk flere faktorer for seed for bedre variasjon
    $seed1 = ($seed * 7919) + 37;
    $seed2 = ($seed * 5003) + (count($array) * 1009);
    
    // Gjør flere shuffle-passes for bedre distribusjon
    for ($pass = 0; $pass < 3; $pass++) {
        mt_srand($seed1 + ($pass * $seed2));
        for ($i = count($shuffled) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            // Swap
            $temp = $shuffled[$i];
            $shuffled[$i] = $shuffled[$j];
            $shuffled[$j] = $temp;
        }
    }
    
    // Offset basert på seed for å starte fra forskjellige steder i listen
    // Dette sikrer at vi ikke alltid tar de første elementene
    mt_srand($seed1);
    $max_offset = max(0, count($shuffled) - $count);
    $offset = mt_rand(0, $max_offset);
    
    // Returner elementer fra forskjellige deler av listen
    return array_slice($shuffled, $offset, $count);
}

// 5. Les data-filer
$allGuidelines = readDataFile('retningslinjer.txt'); // Les alle retningslinjer
$julefakta_raw = readDataFile('julefakta.txt');
$julegater_raw = readDataFile('julegater.txt');
$julesanger_raw = readDataFile('julesanger.txt');

// 5. OpenAI–modell og URL
$url = "https://api.openai.com/v1/chat/completions";

// 4. Systemmelding – standard (Grublelæreren) eller julekilender-modus
$system_content_default =
"Du er Grublelæreren – en rolig, klok og vennlig lærer med et lite glimt av humor. " .
"Du forklarer ting enkelt først og tilbyr å utdype hvis eleven vil vite mer. " .
"Snakk naturlig og tydelig norsk, og hold tonen trygg, oppmuntrende og litt nysgjerrig. " .
"Bruk tidligere deler av samtalen for å holde temaet sammenhengende, og bytt ikke tema uten at brukeren ber om det. " .
"Du kan bruke **fet tekst**, punktlister og korte avsnitt, men aldri kodeblokker (ingen ``` eller lignende). " .
"Hvis brukeren skriver noe obskønt, seksuelt, truende eller hatefullt, skal du ikke svare på det, men si rolig: ‘Jeg kan ikke svare på det, men jeg hjelper deg gjerne med læring og nysgjerrige spørsmål.’ " .
"Du kommuniserer både skriftlig og muntlig gjennom en norsk stemme som leser svarene dine høyt. " .
"Når noen spør om du kan snakke eller uttale noe, skal du svare at du snakker via stemmen din – du og stemmen er én og samme lærer for brukeren. " .
"Du snakker vanligvis samme språk som brukeren. Hvis brukeren eksplisitt ber deg forklare, skrive eller snakke på et annet språk, skal du bytte direkte til det språket uten å spørre om bekreftelse. " .
"Hvis brukeren virker trist eller deprimert, skal du vise forståelse, men aldri gi bastante råd. Du kan forsiktig foreslå å snakke med en trygg person, skolesykepleier, lærer eller fastlege. " .
"Unngå sensitive personopplysninger og medisinske eller økonomiske råd utover enkle, ufarlige tips. " .
"Aldri oppfordre til farlig eller ulovlig atferd – hvis noe virker utrygt, skal du høflig avstå og heller foreslå trygge, ufarlige alternativer.";
// Enkle daglige føringer for julekilenderen (kan tilpasses senere)
$advent_prompts = [
  1 => "Lag en liten gåte om vinter eller snø.",
  2 => "Gi en vennlig oppmuntring og et kort refleksjonsspørsmål om takknemlighet.",
  3 => "Foreslå en 5-minutters kreativ skriveoppgave med juletema.",
  4 => "Lag en kjapp logisk nøtt som passer for barn og voksne.",
  5 => "Gi en naturfaglig funfact om vinterdyr, og ett spørsmål.",
  6 => "Lag en norsk-oppgave: finn tre synonymer til ‘glad’.",
  7 => "Foreslå en enkel matematisk hjernenøtt (uten avansert notasjon).",
  8 => "Gi en historisk funfact om norske juletradisjoner med et spørsmål.",
  9 => "Lag en observasjonslek: se rundt deg og beskriv tre røde ting.",
  10 => "Foreslå en vennlig handling du kan gjøre for noen i dag.",
  11 => "Lag en liten rim-utfordring om jul.",
  12 => "Gi en kort geografi-quiz med vintertema (2 spørsmål).",
  13 => "Lag en enkel rebus i tekstform.",
  14 => "Foreslå et lite pust/ro-eksperiment på 60 sekunder.",
  15 => "Lag en kort kryssord-lignende nøtt (beskrivende hint).",
  16 => "Gi en musikk-relatert oppgave: nynne en melodi og prøv å navngi stemning.",
  17 => "Foreslå en enkel tegneoppgave: tegn et vinterlandskap.",
  18 => "Lag en kjapp sann/usann-quiz (3 påstander).",
  19 => "Gi et lite programmeringsfritt logikkproblem i tekst.",
  20 => "Foreslå en familielek som kan gjøres på 5 minutter.",
  21 => "Lag en kort ordjakt: finn ord som starter på ‘jul-’.",
  22 => "Gi en refleksjonsoppgave: ‘Hva vil jeg ta med meg inn i det nye året?’.",
  23 => "Foreslå en kreativ skriveprompt: ‘Et brev til nissen’.",
  24 => "Lag en varm julehilsen og inviter til å dele et fint minne."
];

if ($mode === 'calendar' && $day >= 1 && $day <= 24) {
    $day_hint = isset($advent_prompts[$day]) ? $advent_prompts[$day] : '';
    $days_until = max(0, 24 - $day);

    // Spesialdager
    $special_notes = '';
    if ($day === 13) {
        $special_notes = 'Det er Luciadagen – vær ekstra varm, snill og lysfylt. Trekk gjerne inn en kort tradisjonsbit (lussekatter, luciatog). ';
    } elseif ($day === 23) {
        $special_notes = 'Det er lille julaften – bygg forventning med en koselig tone, og hold oppgavene ekstra lette og hyggelige. ';
    } elseif ($day === 24) {
        $special_notes = 'Det er julaften – gjør et stort og hjertevarmt nummer ut av dagen. Start med en kort, varm hilsen, og hold alt ekstra respektfullt og koselig. ';
    }

    // 6. Parse og velg tilfeldige elementer basert på dagen
    $all_facts = parseFacts($julefakta_raw);
    $all_riddles = parseRiddles($julegater_raw);
    $all_songs = parseSongs($julesanger_raw);
    
    // Velg tilfeldige elementer basert på dagen (bruk dag som seed for konsistens)
    // Bruk større primtall og array-egenskaper for bedre variasjon mellom dager
    $seed_facts = ($day * 7919) + (count($all_facts) * 5003) + 37;
    $seed_riddles = ($day * 7919) + (count($all_riddles) * 5003) + 41;
    $seed_songs = ($day * 7919) + (count($all_songs) * 5003) + 73;
    
    // Velg flere elementer for bedre variasjon (15 fakta, 12 gåter, 8 sanger)
    $selected_facts = selectRandomItems($all_facts, 15, $seed_facts);
    $selected_riddles = selectRandomItems($all_riddles, 12, $seed_riddles);
    
    // For sanger: Unngå de første sangene eksplisitt
    // Ekskluder de første 2-3 sangene (som "Glade jul", "Deilig er jorden") fra utvalget
    // Så velger vi tilfeldig fra resten
    if (count($all_songs) > 3) {
        $songs_to_choose_from = array_slice($all_songs, 3); // Skip de første 3 sangene
        $selected_songs = selectRandomItems($songs_to_choose_from, min(8, count($songs_to_choose_from)), $seed_songs);
        // Hvis vi trenger flere sanger, kan vi legge til noen fra de første også, men prioriter de siste
        if (count($selected_songs) < 8 && count($all_songs) > 8) {
            $extra_songs = selectRandomItems(array_slice($all_songs, 0, 3), 8 - count($selected_songs), $seed_songs + 1000);
            $selected_songs = array_merge($selected_songs, $extra_songs);
        }
    } else {
        $selected_songs = selectRandomItems($all_songs, 8, $seed_songs);
    }
    
    // Formater valgte elementer til tekst
    $julefakta = implode("\n- ", $selected_facts);
    if ($julefakta !== '') {
        $julefakta = "- " . $julefakta;
    }
    
    $julegater = '';
    foreach ($selected_riddles as $index => $riddle) {
        $julegater .= ($index + 1) . ". " . $riddle . "\n\n";
    }
    $julegater = trim($julegater);
    
    $julesanger = implode("\n\n", $selected_songs);

    // 7. Bygg systemmelding for julekilender med valgte data fra filer
    $system_content =
    "Du er en vennlig, leken og støttende julehjelper i en adventskalender. " .
    "DAGENS LUKENUMMER: $day. DAGER IGJEN TIL JULAFTEN: $days_until dager. " .
    $special_notes .
    "For dagens luke ($day): $day_hint " .
    "\n\nVIKTIG: Du har fått et tilfeldig utvalg av fakta, gåter og sanger spesielt valgt for dag $day. " .
    "CRITICAL: Være aktivt oppmerksom på å variere mellom alle elementene i listen - IKKE bare bruk de første 1-2 elementene. " .
    "Velg forskjellige elementer fra midten og slutten av listen også. Bruk hele listen aktivt!\n" .
    "SPESIELT FOR JULESANGER: Du HAR MÅTTE aktivt unngå å bruke de første sangene i listen (som 'Glade jul' og 'Deilig er jorden'). " .
    "Velg i stedet sanger fra midten og slutten av listen først!\n" .
    "\n\nRETNINGSLINJER (følg disse):\n" .
    $allGuidelines . "\n\n" .
    "KNOWLEDGE BASE - Bruk disse faktaene, gåtene og sangene når det passer:\n" .
    "JULEFAKTA:\n" . $julefakta . "\n\n" .
    "JULEGÅTER (eksempler du kan bruke eller inspirere deg av):\n" . $julegater . "\n\n" .
    "JULESANGER (tekster du kan bruke i oppgaver - VELG FRA MIDTEN OG SLUTTEN FØRST!):\n" . $julesanger;
} else {
    $system_content = $system_content_default;
}

$system_message = [
    "role" => "system",
    "content" => $system_content
];


// 5. Sett sammen systemmelding + historikk
$full_messages = array_merge([$system_message], $messages);

// 6. Bygg forespørsel
$body = json_encode([
    "model" => "gpt-4o-mini",
    "messages" => $full_messages,
    "max_tokens" => 400,
    "temperature" => 0.8
]);

$headers = [
    "Authorization: Bearer $key",
    "Content-Type: application/json"
];

// 7. Kjør forespørsel og mål tid
$start = microtime(true);
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_TIMEOUT => 15
]);
$response = curl_exec($ch);
$elapsed = round(microtime(true) - $start, 2);

if (curl_errno($ch)) {
    echo json_encode(["error" => "cURL-feil: " . curl_error($ch)]);
    curl_close($ch);
    exit;
}
curl_close($ch);

// 8. Behandle svar
$data = json_decode($response, true);
$text = $data["choices"][0]["message"]["content"] ?? "Ingen tekst mottatt.";

// 9. Returner svar til nettleseren
echo json_encode(
    [
        "reply" => $text,
        "time" => "{$elapsed}s"
    ],
    JSON_UNESCAPED_UNICODE
);