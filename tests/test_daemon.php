<?php
/* Essai de bout en bout du démon, sans Jeedom : il lance le vrai démon contre un
 * faux callback (serveur PHP intégré), lui envoie des trames comme un hub et
 * vérifie ses réponses, son journal et ce qu'il remet à Jeedom.
 *
 *   php tests/test_daemon.php
 *
 * Chaque cas reprend un défaut trouvé en relecture : ils ne doivent pas
 * revenir. Tout tourne dans un dossier temporaire, sur des ports libres, et
 * est arrêté à la fin, même en cas d'échec. */

require_once __DIR__ . '/../resources/ajaxsiabed/AjaxSiaCodec.php';

$failures = 0;
$checks = 0;
function check($_label, $_condition) {
    global $failures, $checks;
    $checks++;
    if (!$_condition) {
        $failures++;
        echo "ÉCHEC : $_label\n";
    }
}

function freePort() {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr($name, strrpos($name, ':') + 1);
}

/* ------------------------------------------------------------ préparation */

$key = '0123456789ABCDEF';
$dir = sys_get_temp_dir() . '/ajaxsiabe-test-' . getmypid();
@mkdir($dir . '/p/resources/ajaxsiabed', 0775, true);
@mkdir($dir . '/p/core/config', 0775, true);
@mkdir($dir . '/cb', 0775, true);
foreach (glob(__DIR__ . '/../resources/ajaxsiabed/*.php') as $file) {
    copy($file, $dir . '/p/resources/ajaxsiabed/' . basename($file));
}
copy(__DIR__ . '/../core/config/sia_codes.json', $dir . '/p/core/config/sia_codes.json');

$port = freePort();
$orders = freePort();
$web = freePort();
file_put_contents($dir . '/cb/cb.php', '<?php
if (($_GET["apikey"] ?? "") !== "CLE") { http_response_code(401); exit; }
if (isset($_GET["test"])) { echo "OK"; exit; }
if (($_GET["action"] ?? "") === "config") {
    echo json_encode(array("timezone" => "Europe/Brussels", "port" => ' . $port . ', "udp" => 1, "key" => "",
        "strict_time" => 1, "journal_days" => 90, "allowed" => array(),
        "hubs" => array("5A5A" => array("id" => 1, "key" => "' . $key . '"))));
    exit;
}
file_put_contents(__DIR__ . "/events.log", file_get_contents("php://input") . "\n", FILE_APPEND);
echo "OK";
');

$null = array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $dir . '/server.log', 'w'), 2 => array('file', $dir . '/server.log', 'w'));
$server = proc_open(array('php', '-S', '127.0.0.1:' . $web, 'cb.php'), $null, $pipes, $dir . '/cb');
usleep(400000);
$daemon = proc_open(array('php', $dir . '/p/resources/ajaxsiabed/ajaxsiabed.php', '--callback', 'http://127.0.0.1:' . $web . '/cb.php',
                          '--pid', $dir . '/d.pid', '--socketport', (string) $orders, '--loglevel', 'debug'),
                    array(0 => array('pipe', 'r'), 1 => array('file', $dir . '/daemon.log', 'w'), 2 => array('file', $dir . '/daemon.log', 'a')),
                    $daemonPipes);
fwrite($daemonPipes[0], "CLE\n");
fclose($daemonPipes[0]);

register_shutdown_function(function () use ($server, $daemon, $dir) {
    foreach (array($daemon, $server) as $process) {
        if (is_resource($process)) {
            proc_terminate($process, SIGTERM);
            proc_close($process);
        }
    }
    exec('rm -rf ' . escapeshellarg($dir));
});

/* ------------------------------------------------------------ outils */

function order($_cmd) {
    global $orders;
    $socket = @stream_socket_client('tcp://127.0.0.1:' . $orders, $errno, $errstr, 2);
    if ($socket === false) {
        return null;
    }
    fwrite($socket, json_encode(array('apikey' => 'CLE', 'cmd' => $_cmd)) . "\n");
    stream_set_timeout($socket, 3);
    $answer = json_decode((string) fgets($socket), true);
    fclose($socket);
    return $answer;
}

/* Envoie des octets et rend ce qui revient dans le délai (chaîne vide sinon). */
function exchange($_data, $_udp = false, $_wait = 1.5, $_expected = 1) {
    global $port;
    $socket = stream_socket_client(($_udp ? 'udp://' : 'tcp://') . '127.0.0.1:' . $port, $errno, $errstr, 2);
    fwrite($socket, $_data);
    stream_set_blocking($socket, false);
    $reply = '';
    $deadline = microtime(true) + $_wait;
    while (microtime(true) < $deadline && substr_count($reply, "\r") < $_expected) {
        $read = array($socket); $write = null; $except = null;
        if (@stream_select($read, $write, $except, 0, 100000) > 0) {
            $chunk = fread($socket, 8192);
            if ($chunk === '' || $chunk === false) {
                if (feof($socket)) {
                    break;
                }
                continue;
            }
            $reply .= $chunk;
        }
    }
    fclose($socket);
    return $reply;
}

function ackType($_reply) {
    return preg_match('/"(\*?)(ACK|NAK)"/', $_reply, $m) ? $m[1] . $m[2] : '';
}

function pushed() {
    global $dir;
    usleep(600000);                  // la remise se fait dans un processus fils
    $events = array();
    foreach (@file($dir . '/cb/events.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line) {
        $batch = json_decode($line, true);
        foreach ((array) $batch['events'] as $event) {
            $events[] = $event;
        }
    }
    return $events;
}

function journalEntries() {
    global $dir;
    $entries = array();
    foreach (glob($dir . '/p/data/journal/*.jsonl') as $file) {
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $entries[] = json_decode($line, true);
        }
    }
    return $entries;
}

/* ------------------------------------------------------------ démarrage */

$status = null;
for ($i = 0; $i < 30 && $status === null; $i++) {
    usleep(200000);
    $status = order('status');
}
check('le démon démarre et répond aux ordres', is_array($status) && $status['state'] == 'ok');
if (!is_array($status)) {
    echo file_get_contents($dir . '/daemon.log');
    exit(1);
}
check('écoute TCP et UDP ouvertes sur le port demandé', $status['result']['tcp'] === $port && $status['result']['udp'] === $port);

/* ------------------------------------------------------------ cas */

$reply = exchange(AjaxSiaCodec::build('SIA-DCS', 1, '1234', 'Nri1/CL5'));
check('clair, compte inconnu sans clé : ACK', ackType($reply) === 'ACK');

$reply = exchange(AjaxSiaCodec::build('SIA-DCS', 2, '5A5A', 'Nri1/OP5', $key));
$body = substr(trim($reply), 8);
check('chiffré, hub connu : ACK chiffré', ackType($reply) === '*ACK');
check('ACK chiffré : CRC juste', substr(trim($reply), 0, 4) === AjaxSiaCodec::crc($body));
check('ACK chiffré : relisible avec la clé', AjaxSiaCodec::decrypt(substr($body, strpos($body, '[') + 1), $key) !== null);

$reply = exchange(AjaxSiaCodec::build('SIA-DCS', 3, '5A5A', 'Nri1/OP1'));
check('clair pour un hub chiffré : aucune réponse', $reply === '');

$reply = exchange(AjaxSiaCodec::build('NULL', 0, '5A5A', ''));
check('test de liaison en clair pour un hub chiffré : accusé', ackType($reply) === 'ACK');

$reply = exchange(AjaxSiaCodec::build('SIA-DCS', 4, '5A5A', 'Nri1/CL1', $key, time() - 120));
check('chiffré hors fenêtre : NAK', ackType($reply) === 'NAK');

$reply = exchange(AjaxSiaCodec::build('SIA-DCS', 5, '5A5A', 'Nri1/CL1', 'BBBBBBBBBBBBBBBB'));
check('mauvaise clé : aucune réponse', $reply === '');

$frame = AjaxSiaCodec::build('SIA-DCS', 6, '1234', 'Nri1/BA3');
check('premier envoi : ACK', ackType(exchange($frame)) === 'ACK');
check('réémission : ACK', ackType(exchange($frame)) === 'ACK');

$reply = exchange(AjaxSiaCodec::build('SIA-DCS', 7, '1234', 'Nri1/CL2') . AjaxSiaCodec::build('SIA-DCS', 8, '1234', 'Nri1/OP2'), false, 1.5, 2);
check('deux trames dans un segment : deux ACK', substr_count($reply, '"ACK"') === 2);

$reply = exchange(AjaxSiaCodec::build('SIA-DCS', 9, '1234', 'Nri1/TA4'), true);
check('UDP : ACK', ackType($reply) === 'ACK');

/* Hub en avance de 33 s : refusé tant que son horloge n'est pas connue,
 * accepté ensuite. Une réémission ne compte que pour une mesure. */
$replies = array();
$first = AjaxSiaCodec::build('SIA-DCS', 30, '5A5A', 'Nri1/CL30', $key, time() + 33);
for ($i = 0; $i < 3; $i++) {
    $replies[] = ackType(exchange($first));     // réémissions : une seule mesure
}
foreach (array(31, 32) as $seq) {
    usleep(1100000);                            // une mesure par message plus récent
    $replies[] = ackType(exchange(AjaxSiaCodec::build('SIA-DCS', $seq, '5A5A', 'Nri1/CL' . $seq, $key, time() + 33)));
}
check('hub en avance : NAK tant que trois mesures distinctes manquent', $replies === array('NAK', 'NAK', 'NAK', 'NAK', 'NAK'));
usleep(1100000);
check('hub en avance : accepté une fois son horloge apprise',
      ackType(exchange(AjaxSiaCodec::build('SIA-DCS', 33, '5A5A', 'Nri1/OP33', $key, time() + 33))) === '*ACK');
check('hub en avance : un message vieux de 60 s reste refusé',
      ackType(exchange(AjaxSiaCodec::build('SIA-DCS', 34, '5A5A', 'Nri1/OP34', $key, time() - 60))) === 'NAK');
check('hub en avance : une mesure isolée ne défait pas la correction',
      ackType(exchange(AjaxSiaCodec::build('SIA-DCS', 35, '5A5A', 'Nri1/OP35', $key, time() + 33))) === '*ACK');

/* Rejeu : une vieille trame authentique, séquence changée, trois fois. Elle
 * ne doit ni fausser l'horloge (sinon toutes les alarmes seraient refusées),
 * ni être traitée deux fois quand elle tombe dans la fenêtre. */
foreach (array(40, 41, 42) as $seq) {
    exchange(AjaxSiaCodec::build('SIA-DCS', $seq, '5A5A', 'Nri1/OP40', $key, time() - 86400));
}
check('rejeu de vieilles trames : l\'horloge du hub reste apprise',
      ackType(exchange(AjaxSiaCodec::build('SIA-DCS', 43, '5A5A', 'Nri1/BA43', $key, time() + 33))) === '*ACK');
$fresh = time() + 33;
exchange(AjaxSiaCodec::build('SIA-DCS', 44, '5A5A', 'Nri1/CL44', $key, $fresh));
exchange(AjaxSiaCodec::build('SIA-DCS', 45, '5A5A', 'Nri1/CL44', $key, $fresh));
$replayed = array_filter(journalEntries(), function ($_e) { return isset($_e['seq']) && $_e['seq'] === '0045'; });
check('rejeu dans la fenêtre, séquence changée : doublon', count($replayed) === 1 && reset($replayed)['status'] === 'duplicate');
$status = order('status');
check('état : horloge du hub +33 s', isset($status['result']['clock']['5A5A']) && abs($status['result']['clock']['5A5A'] - 33) <= 1);
check('horloge gardée sur disque', strpos((string) @file_get_contents($dir . '/p/data/clock.json'), '5A5A') !== false);

/* Connexions muettes : elles ne doivent pas rendre le récepteur sourd. */
$mute = array();
for ($i = 0; $i < 40; $i++) {
    $mute[] = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 1);
}
usleep(300000);
check('40 connexions muettes : un vrai message a son ACK', ackType(exchange(AjaxSiaCodec::build('SIA-DCS', 10, '1234', 'Nri1/CL3'))) === 'ACK');
foreach ($mute as $socket) {
    if (is_resource($socket)) {
        fclose($socket);
    }
}
$mute = array();
for ($i = 0; $i < 10; $i++) {
    $mute[] = @stream_socket_client('tcp://127.0.0.1:' . $orders, $errno, $errstr, 1);
}
usleep(200000);
$start = microtime(true);
$reply = exchange(AjaxSiaCodec::build('SIA-DCS', 11, '1234', 'Nri1/OP3'));
check('connexions d\'ordres muettes : ACK sans attente', ackType($reply) === 'ACK' && microtime(true) - $start < 0.8);
foreach ($mute as $socket) {
    if (is_resource($socket)) {
        fclose($socket);
    }
}

/* Plusieurs connexions rapides d'une même adresse, chacune avec sa trame. */
$sockets = array();
for ($i = 0; $i < 12; $i++) {
    $socket = stream_socket_client('tcp://127.0.0.1:' . $port);
    fwrite($socket, AjaxSiaCodec::build('SIA-DCS', 20 + $i, '1234', 'Nri1/BA' . (10 + $i)));
    $sockets[] = $socket;
}
usleep(800000);
$acks = 0;
foreach ($sockets as $socket) {
    stream_set_blocking($socket, false);
    if (strpos((string) fread($socket, 8192), 'ACK') !== false) {
        $acks++;
    }
    fclose($socket);
}
check('12 connexions rapides d\'une même adresse : 12 ACK', $acks === 12);

/* Bruit : la connexion est fermée, le journal ne grossit pas. */
$before = count(journalEntries());
exchange(str_repeat("bruit\r", 5000), false, 1.0, 5000);
$after = count(journalEntries());
check('5000 trames de bruit : au plus deux lignes de journal', $after - $before <= 2);

/* ------------------------------------------------------------ bilan */

$events = pushed();
$codes = array_map(function ($_e) { return $_e['type'] . ':' . (isset($_e['events'][0]['code']) ? $_e['events'][0]['code'] : ''); }, $events);
check('remis à Jeedom : armement chiffré du hub connu', in_array('SIA-DCS:OP', $codes));
check('jamais remis : clair pour un hub chiffré', count(array_filter($events, function ($_e) { return $_e['account'] === '5A5A' && !$_e['enc']; })) === 0);
check('réémission remise une seule fois', count(array_filter($events, function ($_e) { return $_e['seq'] === '0006'; })) === 1);
check('chaque message remis porte un identifiant unique',
      count($events) > 0 && count(array_unique(array_column($events, 'uid'))) === count($events));

$statuses = array_count_values(array_column(journalEntries(), 'status'));
check('journal : refus en clair consigné', !empty($statuses['plain']));
check('journal : doublon consigné', !empty($statuses['duplicate']));
check('journal : mauvaise clé consignée', !empty($statuses['decrypt']));
check('journal : hors fenêtre consigné', !empty($statuses['window']));

$status = order('status');
check('le démon répond toujours après tout cela', is_array($status) && $status['state'] == 'ok');

if ($failures > 0) {
    echo "--- fin du log du démon ---\n" . implode("\n", array_slice(file($dir . '/daemon.log', FILE_IGNORE_NEW_LINES), -15)) . "\n";
}
echo ($failures === 0) ? "OK : $checks contrôles\n" : "$failures échec(s) sur $checks contrôles\n";
exit($failures === 0 ? 0 : 1);
