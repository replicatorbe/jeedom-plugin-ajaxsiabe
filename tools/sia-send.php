<?php
/* Simulateur de hub : envoie des trames SIA DC-09 au récepteur et lit sa réponse.
 *
 *   php tools/sia-send.php [options] [données SIA ...]
 *
 *   --host H      adresse du récepteur (127.0.0.1)
 *   --port P      port du récepteur (7777)
 *   --udp         UDP au lieu de TCP
 *   --account A   numéro de compte (1234)
 *   --key K       clé de chiffrement (16, 24 ou 32 caractères) ; sans elle, en clair
 *   --type T      SIA-DCS (défaut), ADM-CID ou NULL
 *   --seq N       numéro de séquence de départ (aléatoire)
 *   --skew S      décale l'horodatage de S secondes (tester le refus hors fenêtre)
 *   --demo        joue une séquence type : test, armement, alarme, désarmement,
 *                 sabotage, coupure secteur, batterie, perte de liaison
 *
 * Exemples :
 *   php tools/sia-send.php Nri1/CL5
 *   php tools/sia-send.php --key 0123456789ABCDEF Nri1/BA12
 *   php tools/sia-send.php --type ADM-CID "1401 01 005"
 *   php tools/sia-send.php --type NULL
 *   php tools/sia-send.php --demo --account 5A5A
 *
 * Aucun appareil réel n'est sollicité : c'est ce qui permet d'essayer le
 * plugin sans faire hurler la sirène.
 */

require_once __DIR__ . '/../resources/ajaxsiabed/AjaxSiaCodec.php';

$opt = getopt('', array('host:', 'port:', 'udp', 'account:', 'key:', 'type:', 'seq:', 'skew:', 'demo', 'help'), $restIndex);
$rest = array_slice($argv, $restIndex);
if (isset($opt['help'])) {
    echo preg_replace('/^ \* ?/m', '', substr(file_get_contents(__FILE__), 8, strpos(file_get_contents(__FILE__), '*/') - 8)), "\n";
    exit(0);
}
$host    = isset($opt['host']) ? $opt['host'] : '127.0.0.1';
$port    = isset($opt['port']) ? (int) $opt['port'] : 7777;
$udp     = isset($opt['udp']);
$account = isset($opt['account']) ? strtoupper($opt['account']) : '1234';
$key     = isset($opt['key']) ? $opt['key'] : null;
$type    = isset($opt['type']) ? $opt['type'] : 'SIA-DCS';
$seq     = isset($opt['seq']) ? (int) $opt['seq'] : random_int(1, 9000);
$skew    = isset($opt['skew']) ? (int) $opt['skew'] : 0;

$messages = array();
if (isset($opt['demo'])) {
    $messages = array(
        array('NULL', ''),
        array('SIA-DCS', 'Nri1/CL1'),
        array('SIA-DCS', 'Nri1/BA3'),
        array('SIA-DCS', 'Nri1/OR1'),
        array('SIA-DCS', 'Nri1/NL2'),
        array('SIA-DCS', 'Nri1/OP2'),
        array('SIA-DCS', 'Nri1/TA4'),
        array('SIA-DCS', 'Nri1/TR4'),
        array('SIA-DCS', 'Nri1/WA5'),
        array('SIA-DCS', 'Nri1/WH5'),
        array('SIA-DCS', 'Nri0/AT0'),
        array('SIA-DCS', 'Nri0/AR0'),
        array('SIA-DCS', 'Nri1/XT3'),
        array('SIA-DCS', 'Nri1/XL6'),
        array('SIA-DCS', 'Nri1/XC6'),
        array('ADM-CID', '3441 01 002'),
        array('ADM-CID', '1441 01 002'),
    );
} elseif ($type === 'NULL') {
    $messages[] = array('NULL', '');
} elseif (empty($rest)) {
    fwrite(STDERR, "Rien à envoyer : donnez des données SIA (ex. Nri1/CL5), --type NULL ou --demo.\n");
    exit(1);
} else {
    foreach ($rest as $data) {
        $messages[] = array($type, $data);
    }
}

$failures = 0;
foreach ($messages as $i => $message) {
    list($msgType, $data) = $message;
    $frame = AjaxSiaCodec::build($msgType, ($msgType === 'NULL') ? 0 : ($seq + $i) % 10000, $account, $data, $key, time() + $skew);
    $reply = send($frame, $host, $port, $udp);
    printf("→ %-8s %-16s ", $msgType, $data !== '' ? $data : '-');
    if ($reply === null) {
        echo "aucune réponse\n";
        $failures++;
    } else {
        echo describeReply($reply, $key), "\n";
    }
    if (isset($opt['demo'])) {
        usleep(300000);
    }
}
exit($failures > 0 ? 1 : 0);

function send($_frame, $_host, $_port, $_udp) {
    $errno = 0; $errstr = '';
    $socket = @stream_socket_client(($_udp ? 'udp://' : 'tcp://') . $_host . ':' . $_port, $errno, $errstr, 3);
    if ($socket === false) {
        fwrite(STDERR, "Connexion impossible à $_host:$_port : $errstr\n");
        exit(1);
    }
    fwrite($socket, $_frame);
    stream_set_timeout($socket, 3);
    $reply = '';
    while (strpos($reply, "\r") === false) {
        $chunk = fread($socket, 4096);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $reply .= $chunk;
    }
    fclose($socket);
    return ($reply === '') ? null : $reply;
}

/* Vérifie la réponse comme le ferait le hub : CRC, et déchiffrement de l'ACK. */
function describeReply($_reply, $_key) {
    $frame = trim($_reply, "\n\r");
    if (!preg_match('/^([0-9A-F]{4})([0-9A-F]{4})("(\*?)([A-Z]+)".*)$/s', $frame, $m)) {
        return 'réponse illisible : ' . json_encode($_reply);
    }
    $crcOk = (AjaxSiaCodec::crc($m[3]) === $m[1]);
    $text = $m[5] . ($crcOk ? '' : ' (CRC FAUX)');
    if ($m[4] === '*' && $_key !== null) {
        $hex = substr($m[3], strpos($m[3], '[') + 1);
        $plain = AjaxSiaCodec::decrypt($hex, $_key);
        $text .= ($plain === null) ? ' (déchiffrement de la réponse impossible)' : ' chiffré, contenu ' . ltrim($plain, '0');
    } elseif ($m[5] === 'NAK') {
        $text .= ' ' . substr($m[3], strpos($m[3], '['));
    }
    return $text;
}
