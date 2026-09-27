<?php
/* Jeu d'essai du codec SIA DC-09, sans Jeedom ni réseau.
 *
 *   php tests/test_codec.php
 *
 * Les deux premières trames ont été produites par une implémentation
 * indépendante (chiffrement AES d'une autre bibliothèque, CRC recalculé à
 * part) : elles vérifient que le codec lit ce qu'émet un autre que lui. */

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

$key = 'AAAAAAAAAAAAAAAA';
$keys = function () use ($key) { return array($key); };

/* CRC-16/ARC, valeur de contrôle du catalogue des CRC. */
check('CRC de « 123456789 »', AjaxSiaCodec::crc('123456789') === 'BB3D');

/* Trame chiffrée venue d'ailleurs : bourrage de zéros suivi de « | », pas de
 * compte répété dans les données. */
$frame = '05BB0076"*SIA-DCS"7654L0#1111[C07DA1EB73C617857476EE5D79436CEE5E0084485E79EC56B0D5D1F3F6E648B278050BBCCBC8FE5DCA7E3C80DBDD4945';
$now = gmmktime(16, 4, 10, 7, 9, 2020);
$m = AjaxSiaCodec::parse($frame, $keys, $now);
check('trame chiffrée externe acceptée', $m['status'] === 'ok');
check('trame chiffrée externe : compte', $m['account'] === '1111');
check('trame chiffrée externe : événement WA 000', count($m['events']) === 1 && $m['events'][0]['code'] === 'WA' && $m['events'][0]['addr'] === '000');
check('trame chiffrée externe : écart de -8 s', $m['skew'] === -8);
check('trame chiffrée externe : dans la fenêtre', !AjaxSiaCodec::outsideWindow($m));

/* Trame en clair avec récepteur, ligne et extensions. */
$frame = '73580056"SIA-DCS"0002R1L232#78919[#78919|Nri1/BA012][X012W34.1][Y045N12.2]_14:12:04,09-25-2026';
$m = AjaxSiaCodec::parse($frame, null, gmmktime(14, 12, 4, 9, 25, 2026));
check('trame en clair acceptée', $m['status'] === 'ok');
check('récepteur et ligne relus', $m['receiver'] === 'R1' && $m['line'] === 'L232');
check('données sans le compte', $m['data'] === 'Nri1/BA012');
check('extensions relues', $m['xdata'] === array('X012W34.1', 'Y045N12.2'));
check('groupe et zone', $m['events'][0]['ri'] === '1' && $m['events'][0]['addr'] === '012');
$ack = AjaxSiaCodec::ack($m);
check('ACK en clair', strpos($ack, '"ACK"0002R1L232#78919[]') !== false);
check('ACK encadré par LF et CR', $ack[0] === "\n" && substr($ack, -1) === "\r");
$body = substr(trim($ack), 8);
check('ACK : CRC et longueur', substr(trim($ack), 0, 4) === AjaxSiaCodec::crc($body) && hexdec(substr(trim($ack), 4, 4)) === strlen($body));

/* Aller-retour du codec, en clair et chiffré, pour chaque longueur de clé. */
foreach (array('0123456789ABCDEF', '0123456789ABCDEF01234567', '0123456789ABCDEF0123456789ABCDEF') as $k) {
    $raw = AjaxSiaCodec::build('SIA-DCS', 42, 'ABC123', 'Nri2/OP7', $k);
    $frames = AjaxSiaCodec::extractFrames($raw);
    $m = AjaxSiaCodec::parse($frames[0], function () use ($k) { return array('FAUSSECLEFAUSSEC', $k); });
    check('aller-retour AES-' . (strlen($k) * 8), $m['status'] === 'ok' && $m['events'][0]['code'] === 'OP' && $m['events'][0]['addr'] === '7' && $m['key'] === $k);
    $ack = trim(AjaxSiaCodec::ack($m));
    check('ACK chiffré AES-' . (strlen($k) * 8), strpos($ack, '"*ACK"0042R0L0#ABC123[') !== false);
    $plain = AjaxSiaCodec::decrypt(substr($ack, strpos($ack, '[') + 1), $k);
    check('ACK chiffré relisible AES-' . (strlen($k) * 8), $plain !== null && preg_match('/^0+\]_\d{2}:\d{2}:\d{2},\d{2}-\d{2}-\d{4}$/', $plain) === 1);
}

/* Mauvaise clé, pas de clé. */
$raw = AjaxSiaCodec::build('SIA-DCS', 1, '1234', 'Nri1/CL1', $key);
$frames = AjaxSiaCodec::extractFrames($raw);
$m = AjaxSiaCodec::parse($frames[0], function () { return array('BBBBBBBBBBBBBBBB'); });
check('mauvaise clé refusée', $m['status'] === 'decrypt');
$m = AjaxSiaCodec::parse($frames[0], null);
check('trame chiffrée sans clé refusée', $m['status'] === 'decrypt');

/* CRC et longueur. extractFrames() vide son tampon : on repart de la trame. */
$bad = '0000' . substr($frames[0], 4);
check('CRC faux refusé', AjaxSiaCodec::parse($bad, $keys)['status'] === 'crc');
check('texte quelconque refusé', AjaxSiaCodec::parse('bonjour', $keys)['status'] === 'format');

/* Fenêtre d'horodatage : 40 s dans le passé, 20 s dans le futur. */
$raw = AjaxSiaCodec::build('SIA-DCS', 2, '1234', 'Nri1/CL1', $key, 1000000 - 41);
$frames = AjaxSiaCodec::extractFrames($raw);
check('41 s dans le passé : hors fenêtre', AjaxSiaCodec::outsideWindow(AjaxSiaCodec::parse($frames[0], $keys, 1000000)));
$raw = AjaxSiaCodec::build('SIA-DCS', 2, '1234', 'Nri1/CL1', $key, 1000000 + 21);
$frames = AjaxSiaCodec::extractFrames($raw);
check('21 s dans le futur : hors fenêtre', AjaxSiaCodec::outsideWindow(AjaxSiaCodec::parse($frames[0], $keys, 1000000)));
$raw = AjaxSiaCodec::build('SIA-DCS', 2, '1234', 'Nri1/CL1', $key, 1000000 - 39);
$frames = AjaxSiaCodec::extractFrames($raw);
check('39 s dans le passé : accepté', !AjaxSiaCodec::outsideWindow(AjaxSiaCodec::parse($frames[0], $keys, 1000000)));
$raw = AjaxSiaCodec::build('SIA-DCS', 2, '1234', 'Nri1/CL1', null, 1000000 - 3600);
$frames = AjaxSiaCodec::extractFrames($raw);
check('en clair : pas de fenêtre', !AjaxSiaCodec::outsideWindow(AjaxSiaCodec::parse($frames[0], null, 1000000)));
$nak = trim(AjaxSiaCodec::nak(AjaxSiaCodec::parse($frames[0], null, 1000000), 1000000));
check('NAK avec l\'heure du récepteur', strpos($nak, '"NAK"0000R0L0A0[]' . gmdate('_H:i:s,m-d-Y', 1000000)) !== false);

/* Test de liaison. */
foreach (array(null, $key) as $k) {
    $raw = AjaxSiaCodec::build('NULL', 0, '1234', '', $k);
    $frames = AjaxSiaCodec::extractFrames($raw);
    $m = AjaxSiaCodec::parse($frames[0], $keys);
    check('NULL ' . ($k ? 'chiffré' : 'en clair'), $m['status'] === 'ok' && $m['type'] === 'NULL' && empty($m['events']));
}

/* Contact ID. */
$raw = AjaxSiaCodec::build('ADM-CID', 3, '1234', '1401 01 005');
$frames = AjaxSiaCodec::extractFrames($raw);
$m = AjaxSiaCodec::parse($frames[0]);
check('Contact ID 1401 : désarmement par l\'utilisateur 5', $m['events'][0]['code'] === 'OP' && $m['events'][0]['addr'] === '005' && $m['events'][0]['cid'] === '1401');
$m = AjaxSiaCodec::parseContactId('3441 01 002');
check('Contact ID 3441 : mode nuit', $m[0]['code'] === 'NL');
$m = AjaxSiaCodec::parseContactId('1130 01 012');
check('Contact ID 1130 : intrusion zone 12', $m[0]['code'] === 'BA' && $m[0]['addr'] === '012');

/* Données SIA : variantes d'écriture. */
$e = AjaxSiaCodec::parseSiaData('Nri1/CL5');
check('Nri1/CL5', count($e) === 1 && $e[0]['code'] === 'CL' && $e[0]['addr'] === '5' && $e[0]['ri'] === '1');
$e = AjaxSiaCodec::parseSiaData('Nri1BA012');
check('Nri1BA012 sans barre', count($e) === 1 && $e[0]['code'] === 'BA' && $e[0]['addr'] === '012');
$e = AjaxSiaCodec::parseSiaData('NNL2');
check('NNL2 : mode nuit, pas « N » + « NL »', count($e) === 1 && $e[0]['code'] === 'NL' && $e[0]['addr'] === '2');
$e = AjaxSiaCodec::parseSiaData('NL2');
check('NL2 sans « N » de tête', count($e) === 1 && $e[0]['code'] === 'NL');
$e = AjaxSiaCodec::parseSiaData('Nid3/ri2/OP3^Jérôme^');
check('modificateur id et texte', count($e) === 1 && $e[0]['id'] === '3' && $e[0]['ri'] === '2' && $e[0]['text'] === 'Jérôme');
$e = AjaxSiaCodec::parseSiaData('Nri1/BA01/BA02');
check('deux événements, groupe conservé', count($e) === 2 && $e[1]['addr'] === '02' && $e[1]['ri'] === '1');

/* Découpage d'un flux TCP : deux trames collées, puis une trame en deux morceaux. */
$a = AjaxSiaCodec::build('SIA-DCS', 10, '1234', 'Nri1/CL1');
$b = AjaxSiaCodec::build('SIA-DCS', 11, '1234', 'Nri1/OP1');
$buffer = $a . substr($b, 0, 10);
$frames = AjaxSiaCodec::extractFrames($buffer);
check('première trame extraite, seconde en attente', count($frames) === 1 && $buffer === substr($b, 0, 10));
$buffer .= substr($b, 10);
$frames = AjaxSiaCodec::extractFrames($buffer);
check('seconde trame complétée', count($frames) === 1 && $buffer === '' && AjaxSiaCodec::parse($frames[0])['seq'] === '0011');

/* Cas relevés en relecture. */
$raw = AjaxSiaCodec::build('SIA-DCS', 5, '1234', 'Nid3/ri2/OP3^Jérôme^', $key);
$frames = AjaxSiaCodec::extractFrames($raw);
$m = AjaxSiaCodec::parse($frames[0], $keys);
check('chiffré avec texte accentué', $m['status'] === 'ok' && $m['events'][0]['text'] === 'Jérôme');

/* Bourrage binaire (octets hors ASCII) : accepté, seul compte la fin du texte. */
$plain = "\x81\xF0\x07\x99|#1234|Nri1/CL1]" . AjaxSiaCodec::timestamp();
$plain = str_repeat("\x80", 16 - strlen($plain) % 16) . $plain;
$hex = strtoupper(bin2hex(openssl_encrypt($plain, 'aes-128-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16))));
$body = '"*SIA-DCS"0006L0#1234[' . $hex;
$m = AjaxSiaCodec::parse(AjaxSiaCodec::crc($body) . sprintf('%04X', strlen($body)) . $body, $keys);
check('bourrage binaire accepté', $m['status'] === 'ok' && $m['events'][0]['code'] === 'CL');

/* Bourrage contenant « ] » et « | » : les données sont retrouvées par « #compte| ». */
$plain = "0]0|000|#1234|Nri1/BA7]" . AjaxSiaCodec::timestamp();
$plain = str_repeat('0', 16 - strlen($plain) % 16) . $plain;
$hex = strtoupper(bin2hex(openssl_encrypt($plain, 'aes-128-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16))));
$body = '"*SIA-DCS"0007L0#1234[' . $hex;
$m = AjaxSiaCodec::parse(AjaxSiaCodec::crc($body) . sprintf('%04X', strlen($body)) . $body, $keys);
check('bourrage contenant ] et |', $m['status'] === 'ok' && isset($m['events'][0]) && $m['events'][0]['code'] === 'BA' && $m['events'][0]['addr'] === '7');

/* Longueur écrite en décimal mais CRC juste : acceptée, signalée. */
$body = '"ADM-CID"0247R4F39L4F39#4F39[#4F39|3401 01 057]';
$m = AjaxSiaCodec::parse('5715' . '0047' . $body);
check('longueur fausse, CRC juste : accepté avec remarque', $m['status'] === 'ok' && $m['warning'] !== '' && $m['events'][0]['code'] === 'CL');

/* Compte en minuscules : l'accusé le reprend tel quel. */
$raw = AjaxSiaCodec::build('SIA-DCS', 8, 'abc12', 'Nri1/CL1');
$frames = AjaxSiaCodec::extractFrames($raw);
$m = AjaxSiaCodec::parse($frames[0]);
check('compte en minuscules : recherche en majuscules', $m['account'] === 'ABC12');
check('compte en minuscules : accusé à l\'identique', strpos(AjaxSiaCodec::ack($m), '#abc12[') !== false);

/* Espace dans le corps : conservé, CRC juste. */
$body = '"SIA-DCS"0009L0#1234[#1234|Nri1/BA1^Porte ^]';
$buffer = "\n" . AjaxSiaCodec::crc($body) . sprintf('%04X', strlen($body)) . $body . "\r";
$frames = AjaxSiaCodec::extractFrames($buffer);
check('espace final conservé', AjaxSiaCodec::parse($frames[0])['status'] === 'ok');

/* Données illisibles : accusé, mais signalé. */
$raw = AjaxSiaCodec::build('SIA-DCS', 10, '1234', 'n\'importe quoi');
$frames = AjaxSiaCodec::extractFrames($raw);
$m = AjaxSiaCodec::parse($frames[0]);
check('données illisibles signalées', $m['status'] === 'ok' && empty($m['events']) && strpos($m['warning'], 'aucun événement') !== false);

/* Caractère de contrôle : jamais recopié dans le journal. */
check('caractère de contrôle neutralisé', AjaxSiaCodec::clean("a\nb\x01") === 'a?b?');

/* Contact ID corrigés. */
check('Contact ID 3456 : armement partiel', AjaxSiaCodec::parseContactId('3456 02 000')[0]['code'] === 'CG');
check('Contact ID 3402 : armement de groupe', AjaxSiaCodec::describe(AjaxSiaCodec::parseContactId('3402 02 000')[0]['code'])['e']['arm'] === 'armed');
check('Contact ID 1384 : pile faible', AjaxSiaCodec::parseContactId('1384 01 007')[0]['code'] === 'XT');
check('Contact ID inconnu : code vide, numéro gardé', AjaxSiaCodec::parseContactId('1999 01 001')[0]['code'] === '' && AjaxSiaCodec::parseContactId('1999 01 001')[0]['cid'] === '1999');

/* Dictionnaire. */
$info = AjaxSiaCodec::describe('BA');
check('dictionnaire : BA', $info['c'] === 'alarme' && $info['e']['alarm'] === 'Intrusion' && !empty($info['e']['latch']));
check('dictionnaire : code inconnu lisible', strpos(AjaxSiaCodec::describe('QQ')['l'], 'QQ') !== false);
check('dictionnaire : effets tous libellés', count(array_filter(AjaxSiaCodec::dictionary('codes'), function ($_c) { return $_c['l'] === ''; })) === 0);

echo ($failures === 0) ? "OK : $checks contrôles\n" : "$failures échec(s) sur $checks contrôles\n";
exit($failures === 0 ? 0 : 1);
