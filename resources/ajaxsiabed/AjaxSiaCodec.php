<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/*
 * Codage et décodage des trames SIA DC-09 (ANSI/SIA DC-09-2013), telles que
 * les émet un hub Ajax configuré vers un centre de télésurveillance.
 *
 * Aucune dépendance au coeur de Jeedom : ce fichier est chargé par le démon,
 * par les essais de tests/ et par le simulateur de tools/.
 *
 * Anatomie d'une trame :
 *
 *   <LF> CRC LLLL "id" seq Rrcvr Lpref #acct [données][extensions]_HH:MM:SS,MM-DD-YYYY <CR>
 *        ^^^ ^^^^ └──────────────── corps : CRC et longueur portent sur lui ──────────┘
 *
 *   - CRC  : CRC-16/ARC du corps, 4 chiffres hexadécimaux ;
 *   - LLLL : longueur du corps, « 0 » suivi de 3 chiffres hexadécimaux ;
 *   - id   : SIA-DCS, ADM-CID (Contact ID) ou NULL (test de liaison), précédé
 *            d'un astérisque quand la trame est chiffrée ;
 *   - horodatage en UTC, obligatoire quand la trame est chiffrée.
 *
 * Chiffrement : AES-CBC, vecteur d'initialisation nul, sans remplissage
 * normalisé. Tout ce qui suit le « [ » est chiffré puis écrit en hexadécimal,
 * après un bourrage placé EN TÊTE pour atteindre un multiple de 16 octets. Le
 * « ] » fermant fait partie du texte chiffré : une trame chiffrée n'en a donc
 * pas en clair.
 *
 * La clé est celle saisie dans l'application Ajax : 16, 24 ou 32 caractères,
 * pris tels quels comme octets (AES-128, 192 ou 256).
 */
class AjaxSiaCodec {

    /* Fenêtre d'horodatage de la norme : un message chiffré daté de plus de
     * 40 s dans le passé ou de plus de 20 s dans le futur est refusé. C'est ce
     * qui empêche de rejouer une trame capturée sur le réseau. */
    const WINDOW_PAST   = 40;
    const WINDOW_FUTURE = 20;

    /* Au-delà, un tampon de réception ne contient plus une trame mais du bruit.
     * Une trame de longueur maximale (0FFF) fait 4105 octets avec son en-tête. */
    const MAX_FRAME = 8192;

    /* --------------------------------------------------------------- CRC */

    /* CRC-16/ARC : polynôme 0x8005 réfléchi (0xA001), valeur initiale nulle. */
    public static function crc($_data) {
        $crc = 0;
        $length = strlen($_data);
        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($_data[$i]);
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 1) ? (($crc >> 1) ^ 0xA001) : ($crc >> 1);
            }
        }
        return sprintf('%04X', $crc);
    }

    /* Entoure un corps de son en-tête (CRC, longueur) et des délimiteurs. */
    public static function wrap($_body) {
        return "\n" . self::crc($_body) . sprintf('%04X', strlen($_body)) . $_body . "\r";
    }

    /* ------------------------------------------------------- découpage */

    /*
     * Extrait du tampon les trames complètes, terminées par CR, et y laisse le
     * reste. Le LF de tête est facultatif en pratique : certains émetteurs
     * l'omettent, d'autres enchaînent plusieurs trames dans un seul segment TCP.
     */
    public static function extractFrames(&$_buffer) {
        $frames = array();
        while (($end = strpos($_buffer, "\r")) !== false) {
            /* Seuls le LF de tête et les octets nuls sont retirés : un espace fait
             * partie du corps, l'ôter fausserait le CRC. */
            $frame = trim(substr($_buffer, 0, $end), "\n\0");
            $_buffer = substr($_buffer, $end + 1);
            if ($frame !== '') {
                $frames[] = $frame;
            }
        }
        if (strlen($_buffer) > self::MAX_FRAME) {
            $_buffer = '';
        }
        return $frames;
    }

    /* --------------------------------------------------------- analyse */

    /*
     * Analyse une trame (sans LF ni CR).
     *
     * $_keys est appelé avec le numéro de compte et rend la liste des clés à
     * essayer : la clé propre au hub s'il est connu, puis la clé générale. Les
     * essayer toutes est ce qui permet de découvrir un hub chiffré inconnu.
     *
     * Rend toujours un tableau, même pour une trame illisible : le journal doit
     * pouvoir garder la trace de ce qui a été refusé et pourquoi. 'status' vaut
     * 'ok' ou le motif du refus.
     */
    public static function parse($_frame, $_keys = null, $_now = null) {
        $now = ($_now === null) ? time() : $_now;
        $msg = array(
            'status'    => 'ok',
            'error'     => '',
            'warning'   => '',
            'raw'       => $_frame,
            'crc_ok'    => false,
            'encrypted' => false,
            'type'      => '',
            'seq'       => '',
            'receiver'  => '',
            'line'      => '',
            'account'   => '',
            'account_raw' => '',
            'content'   => '',
            'data'      => '',
            'xdata'     => array(),
            'timestamp' => null,
            'skew'      => null,
            'events'    => array(),
            'key'       => null,
        );

        $pattern = '/^([0-9A-Fa-f]{4})([0-9A-Fa-f]{4})("(\*?)([A-Z0-9-]+)"(\d{4})(R[0-9A-Fa-f]{1,6})?(L[0-9A-Fa-f]{1,6})(?:#([0-9A-Fa-f]{1,16}))?\[(.*))$/s';
        if (!preg_match($pattern, $_frame, $m)) {
            $msg['status'] = 'format';
            $msg['error'] = 'trame non conforme à SIA DC-09';
            return $msg;
        }
        $body = $m[3];
        $msg['encrypted'] = ($m[4] === '*');
        $msg['type']      = $m[5];
        $msg['seq']       = $m[6];
        $msg['receiver']  = $m[7];
        $msg['line']      = $m[8];
        /* La recherche du hub se fait en majuscules, mais l'accusé reprend le
         * compte tel que reçu : un émetteur peut le comparer à l'identique. */
        $msg['account_raw'] = $m[9];
        $msg['account']   = strtoupper($m[9]);
        $rest             = $m[10];

        $msg['crc_ok'] = (strtoupper($m[1]) === self::crc($body));
        if (!$msg['crc_ok']) {
            $msg['status'] = 'crc';
            $msg['error'] = 'CRC faux (reçu ' . strtoupper($m[1]) . ', calculé ' . self::crc($body) . ')';
            return $msg;
        }
        /* Longueur fausse mais CRC juste : la trame est intègre. Certains
         * émetteurs écrivent la longueur en décimal ; la refuser les rendrait
         * muets pour de bon. On l'accepte en le signalant. */
        if (hexdec($m[2]) !== strlen($body)) {
            $msg['warning'] = 'longueur annoncée ' . hexdec($m[2]) . ', reçue ' . strlen($body);
        }

        if ($msg['encrypted']) {
            $keys = is_callable($_keys) ? call_user_func($_keys, $msg['account']) : array();
            $plain = null;
            foreach ((array) $keys as $key) {
                $plain = self::decrypt($rest, $key);
                if ($plain !== null) {
                    $msg['key'] = $key;
                    break;
                }
            }
            if ($plain === null) {
                $msg['status'] = 'decrypt';
                $msg['error'] = empty($keys) ? 'trame chiffrée et aucune clé configurée'
                                             : 'déchiffrement impossible : clé fausse';
                return $msg;
            }
            /* Le début des données se repère d'abord sur « #compte| », que le
             * hub répète en tête : c'est sûr même si un bourrage binaire
             * contient « | » ou « ] ». À défaut, la norme exclut ces caractères
             * du bourrage : tout ce qui précède le premier est jeté. */
            $marker = ($msg['account_raw'] !== '') ? strpos($plain, '#' . $msg['account_raw'] . '|') : false;
            $rest = ($marker !== false) ? substr($plain, $marker) : preg_replace('/^[^|\[\]]*\|?/', '', $plain);
        }
        $msg['content'] = $rest;

        /* données ] [extension] ... _horodatage */
        $close = strpos($rest, ']');
        if ($close === false) {
            $msg['status'] = 'format';
            $msg['error'] = 'bloc de données non fermé';
            return $msg;
        }
        $data = substr($rest, 0, $close);
        $tail = substr($rest, $close + 1);
        while (preg_match('/^\[([^\]]*)\]/', $tail, $x)) {
            if ($x[1] !== '') {
                $msg['xdata'][] = $x[1];
            }
            $tail = substr($tail, strlen($x[0]));
        }
        if (preg_match('/_(\d{2}):(\d{2}):(\d{2}),(\d{2})-(\d{2})-(\d{4})/', $tail, $t)) {
            $ts = gmmktime((int) $t[1], (int) $t[2], (int) $t[3], (int) $t[4], (int) $t[5], (int) $t[6]);
            if ($ts !== false) {
                $msg['timestamp'] = $ts;
                $msg['skew'] = $ts - $now;
            }
        }

        /* Le compte est souvent répété en tête des données : « #1234|Nri1/CL5 ». */
        $data = preg_replace('/^#[0-9A-Fa-f]{1,16}\|?/', '', $data);
        $data = ltrim($data, '|');
        $msg['data'] = self::clean($data);
        $msg['content'] = self::clean($msg['content']);

        if ($msg['encrypted']) {
            if ($msg['timestamp'] === null) {
                $msg['status'] = 'timestamp';
                $msg['error'] = 'trame chiffrée sans horodatage';
                return $msg;
            }
        }

        if ($msg['type'] === 'SIA-DCS') {
            $msg['events'] = self::parseSiaData($msg['data']);
        } elseif ($msg['type'] === 'ADM-CID') {
            $msg['events'] = self::parseContactId($msg['data']);
        }
        /* Accusé mais vide : sans ce signalement, un événement dont on n'a pas
         * su lire les données disparaîtrait sans laisser de trace. */
        if (empty($msg['events']) && in_array($msg['type'], array('SIA-DCS', 'ADM-CID'))) {
            $msg['warning'] = trim($msg['warning'] . ' ; aucun événement lisible dans les données', ' ;');
        }
        return $msg;
    }

    /*
     * Texte en UTF-8 valide et sans caractère de contrôle : il finit dans un
     * JSON (journal, envoi à Jeedom) et dans des lignes de log, où un octet
     * invalide ferait tout échouer et un saut de ligne fabriquerait une ligne.
     */
    public static function clean($_text) {
        $text = mb_scrub((string) $_text, 'UTF-8');
        return preg_replace('/[\x00-\x1F\x7F]/u', '?', $text);
    }

    /* Vrai si l'horodatage d'une trame chiffrée sort de la fenêtre admise. */
    public static function outsideWindow($_msg, $_past = self::WINDOW_PAST, $_future = self::WINDOW_FUTURE) {
        if (!$_msg['encrypted'] || $_msg['skew'] === null) {
            return false;
        }
        return ($_msg['skew'] < -$_past || $_msg['skew'] > $_future);
    }

    /*
     * Données SIA-DCS : « Nri1/CL5 », « Nri1BA012 », « Nid3/ri2/OP3^texte^ »…
     *
     * Un bloc est une suite de modificateurs (deux minuscules et une valeur :
     * ri groupe, id utilisateur, pi périphérique, ti heure) suivie d'un code (deux
     * majuscules) et de son adresse. Les blocs sont séparés par « / » et les
     * modificateurs restent acquis d'un bloc au suivant. Un « N » (nouvel
     * événement) ou « O » (ancien) peut précéder le tout.
     */
    public static function parseSiaData($_data) {
        $data = trim($_data);
        if ($data === '') {
            return array();
        }
        $events = array();
        if (preg_match('/^[NO](?=[a-z]{2}|[A-Z]{2})/', $data)) {
            $events = self::parseSiaBlocks(substr($data, 1));
        }
        if (empty($events)) {
            $events = self::parseSiaBlocks($data);
        }
        return $events;
    }

    private static function parseSiaBlocks($_data) {
        $events = array();
        $modifiers = array();
        foreach (explode('/', $_data) as $block) {
            $position = 0;
            $length = strlen($block);
            while ($position < $length) {
                /* Valeur décimale seulement : « ri1BA012 » doit se lire ri=1 puis
                 * BA012, et non ri=1BA012 comme le lirait une valeur hexadécimale. */
                if (preg_match('/\G([a-z]{2})([0-9:.\-]*)/', $block, $mm, 0, $position)) {
                    $modifiers[$mm[1]] = $mm[2];
                    $position += strlen($mm[0]);
                    continue;
                }
                if (preg_match('/\G([A-Z]{2})([^\^\/A-Z]*)(?:\^([^\^]*)\^?)?/', $block, $cm, 0, $position)) {
                    $events[] = array(
                        'code' => $cm[1],
                        'addr' => trim($cm[2]),
                        'ri'   => isset($modifiers['ri']) ? $modifiers['ri'] : '',
                        'id'   => isset($modifiers['id']) ? $modifiers['id'] : '',
                        'pi'   => isset($modifiers['pi']) ? $modifiers['pi'] : '',
                        'ti'   => isset($modifiers['ti']) ? $modifiers['ti'] : '',
                        'text' => isset($cm[3]) ? $cm[3] : '',
                    );
                    $position += strlen($cm[0]);
                    continue;
                }
                break;                      // reste illisible : on garde ce qui précède
            }
        }
        return $events;
    }

    /*
     * Données Contact ID : « 1401 01 005 » — qualificatif (1 nouvel événement ou
     * ouverture, 3 rétablissement ou fermeture, 6 état antérieur), code à trois
     * chiffres, groupe, puis zone ou utilisateur. Le code est traduit en code SIA
     * par la table 'cid' du dictionnaire, pour que la suite du traitement n'ait
     * qu'un seul vocabulaire.
     */
    public static function parseContactId($_data) {
        if (!preg_match('/(\d)(\d{3})\s*(\d{2})\s*(\d{3})/', $_data, $m)) {
            return array();
        }
        $cid = self::dictionary('cid');
        $qualifier = ($m[1] === '6') ? '1' : $m[1];
        /* Code absent de la table : '' ; le plugin publie alors l'événement
         * sous son numéro Contact ID plutôt que de le taire. */
        $code = isset($cid[$m[2]][$qualifier]) ? $cid[$m[2]][$qualifier] : '';
        return array(array(
            'code' => $code,
            'addr' => $m[4],
            'ri'   => $m[3],
            'id'   => '',
            'pi'   => '',
            'ti'   => '',
            'text' => '',
            'cid'  => $m[1] . $m[2],
        ));
    }

    /* ----------------------------------------------------- dictionnaire */

    private static $dictionary = null;

    /* core/config/sia_codes.json, partagé avec le plugin. */
    public static function dictionary($_section = 'codes') {
        if (self::$dictionary === null) {
            $raw = @file_get_contents(__DIR__ . '/../../core/config/sia_codes.json');
            $json = ($raw === false) ? null : json_decode($raw, true);
            self::$dictionary = is_array($json) ? $json : array('codes' => array(), 'cid' => array(), 'categories' => array());
        }
        return isset(self::$dictionary[$_section]) ? self::$dictionary[$_section] : array();
    }

    /* Libellé, catégorie et nature d'adresse d'un code ; un code inconnu reste lisible. */
    public static function describe($_code) {
        $codes = self::dictionary('codes');
        if (isset($codes[$_code])) {
            return $codes[$_code];
        }
        return array('l' => 'Code inconnu ' . $_code, 'c' => 'info', 'a' => '');
    }

    /* ------------------------------------------------------- chiffrement */

    private static function cipher($_key) {
        switch (strlen($_key)) {
            case 16: return 'aes-128-cbc';
            case 24: return 'aes-192-cbc';
            case 32: return 'aes-256-cbc';
        }
        return null;
    }

    /*
     * Déchiffre un contenu hexadécimal. Rend null si la clé est fausse.
     *
     * Le critère est la fin du texte : « ] », extensions éventuelles, puis
     * l'horodatage, obligatoire dans une trame chiffrée. Avec une mauvaise clé,
     * c'est du bruit binaire qui ne s'y conforme jamais. Le reste n'est pas
     * examiné : le bourrage peut être binaire et un texte « ^…^ » accentué.
     */
    public static function decrypt($_hex, $_key) {
        $cipher = self::cipher($_key);
        $hex = trim($_hex);
        if ($cipher === null || $hex === '' || strlen($hex) % 32 !== 0 || !ctype_xdigit($hex)) {
            return null;
        }
        $plain = openssl_decrypt(hex2bin($hex), $cipher, $_key,
                                 OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));
        if ($plain === false || !preg_match('/\][^\]]*(\[[^\]]*\])*_\d{2}:\d{2}:\d{2},\d{2}-\d{2}-\d{4}[\x00\s]*$/', $plain)) {
            return null;
        }
        return $plain;
    }

    /*
     * Chiffre un texte après l'avoir bourré en tête jusqu'au multiple de 16
     * suivant — un bloc entier de plus quand il y tombe déjà, comme le font les
     * émetteurs. Le bourrage est fait de caractères quelconques hors « | [ ] »,
     * suivis d'un « | » quand $_separator est vrai.
     */
    public static function encrypt($_plain, $_key, $_separator = false, $_padChar = null) {
        $cipher = self::cipher($_key);
        if ($cipher === null) {
            return null;
        }
        $text = ($_separator ? '|' : '') . $_plain;
        $fill = 16 - (strlen($text) % 16);
        $pad = '';
        for ($i = 0; $i < $fill; $i++) {
            $pad .= ($_padChar !== null) ? $_padChar : chr(random_int(0x30, 0x39));
        }
        $raw = openssl_encrypt($pad . $text, $cipher, $_key,
                               OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));
        return ($raw === false) ? null : strtoupper(bin2hex($raw));
    }

    /* ---------------------------------------------------------- réponses */

    public static function timestamp($_time = null) {
        return gmdate('_H:i:s,m-d-Y', ($_time === null) ? time() : $_time);
    }

    /*
     * Accusé de réception : il reprend le numéro de séquence, le récepteur, la
     * ligne et le compte du message. Sans lui, le hub renvoie le message en
     * boucle et finit par déclarer le centre de télésurveillance injoignable.
     *
     * Chiffré si le message l'était, avec la même clé : « ] » suivi de
     * l'horodatage, bourrés de zéros en tête. C'est la forme qu'attendent les
     * hubs Ajax.
     */
    public static function ack($_msg, $_time = null) {
        $account = ($_msg['account_raw'] !== '') ? '#' . $_msg['account_raw'] : '';
        $head = '"' . ($_msg['encrypted'] ? '*' : '') . 'ACK"' . $_msg['seq'] . $_msg['receiver']
              . ($_msg['line'] !== '' ? $_msg['line'] : 'L0') . $account . '[';
        if ($_msg['encrypted'] && $_msg['key'] !== null) {
            $body = $head . self::encrypt(']' . self::timestamp($_time), $_msg['key'], false, '0');
        } else {
            $body = $head . ']';
        }
        return self::wrap($body);
    }

    /*
     * Refus. La norme l'impose pour un horodatage hors fenêtre et y joint
     * l'heure du récepteur : l'émetteur recale son horloge sur elle et renvoie
     * le message. Il n'est jamais chiffré.
     */
    public static function nak($_msg = null, $_time = null) {
        $receiver = ($_msg !== null && $_msg['receiver'] !== '') ? $_msg['receiver'] : 'R0';
        $line     = ($_msg !== null && $_msg['line'] !== '') ? $_msg['line'] : 'L0';
        return self::wrap('"NAK"0000' . $receiver . $line . 'A0[]' . self::timestamp($_time));
    }

    /* ---------------------------------------------- émission (simulateur) */

    /*
     * Construit une trame telle qu'un hub l'émettrait. Sert au simulateur et
     * aux essais : c'est aussi la preuve que parse() relit ce qu'on écrit.
     */
    public static function build($_type, $_seq, $_account, $_data, $_key = null, $_time = null, $_line = 'L0', $_receiver = 'R0') {
        $content = ($_type === 'NULL' || $_data === '') ? ']' : '#' . $_account . '|' . $_data . ']';
        $content .= self::timestamp($_time);
        $head = '"' . ($_key !== null ? '*' : '') . $_type . '"' . sprintf('%04d', $_seq)
              . $_receiver . $_line . '#' . $_account . '[';
        if ($_key !== null) {
            return self::wrap($head . self::encrypt($content, $_key, true));
        }
        return self::wrap($head . $content);
    }
}
