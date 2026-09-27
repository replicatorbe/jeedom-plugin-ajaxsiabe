#!/usr/bin/env php
<?php
/* Démon récepteur SIA DC-09 pour Jeedom.
 *
 * Il tient le rôle d'un centre de télésurveillance : le hub Ajax lui envoie ses
 * événements en TCP (ou UDP), il les accuse aussitôt, les consigne dans le
 * journal puis les remet à Jeedom.
 *
 * L'ordre compte. L'accusé de réception part AVANT toute écriture et tout appel
 * à Jeedom : le hub attend quelques secondes puis réémet, et un Jeedom lent ne
 * doit jamais lui faire croire que le centre est tombé. Le journal est écrit
 * ensuite, par le démon lui-même : un événement reçu y figure même si Jeedom
 * ne répond pas, et même s'il a été refusé.
 *
 * Processus autonome : il ne charge PAS le coeur de Jeedom. Sa configuration
 * est lue sur le callback HTTP du plugin, et les événements y sont repoussés.
 *
 * This file is part of Jeedom. Licensed under GNU GPL v3 or later.
 */

/* ---------------------------------------------------------------- garde CLI ---
 * Les .htaccess ne protègent que si Apache les lit : cette garde est la seule
 * protection réelle contre un lancement depuis le web.
 */
if (php_sapi_name() != 'cli' || isset($_SERVER['REQUEST_METHOD']) || !isset($_SERVER['argc'])) {
    header('HTTP/1.0 404 Not Found');
    echo '<h1>404 Not Found</h1>';
    exit(1);
}

pcntl_async_signals(true);
require_once __DIR__ . '/AjaxSiaCodec.php';

/* ------------------------------------------------------------------- options */
$opt = getopt('', array('callback:', 'apikey:', 'pid:', 'socketport:', 'loglevel:'));
foreach (array('callback', 'pid', 'socketport') as $required) {
    if (!isset($opt[$required])) {
        fwrite(STDERR, "Argument manquant : --$required\n");
        exit(1);
    }
}
/* La clé API arrive sur STDIN : une ligne de commande se lit avec `ps`. */
if (!isset($opt['apikey'])) {
    $line = fgets(STDIN);
    $opt['apikey'] = ($line === false) ? '' : trim($line);
}
if ($opt['apikey'] === '') {
    fwrite(STDERR, "Clé API absente (--apikey ou première ligne de STDIN)\n");
    exit(1);
}

/* ------------------------------------------------------------------- logging */
class SiaLog {
    const LEVELS = array('debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'none' => 99);
    private static $min = 3;

    public static function setLevel($_level) {
        self::$min = isset(self::LEVELS[$_level]) ? self::LEVELS[$_level] : 3;
    }
    public static function write($_level, $_msg) {
        if ((isset(self::LEVELS[$_level]) ? self::LEVELS[$_level] : 3) < self::$min) {
            return;
        }
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '][' . strtoupper($_level) . '] ' . $_msg . PHP_EOL);
    }
    public static function debug($m)   { self::write('debug', $m); }
    public static function info($m)    { self::write('info', $m); }
    public static function warning($m) { self::write('warning', $m); }
    public static function error($m)   { self::write('error', $m); }
}
SiaLog::setLevel(isset($opt['loglevel']) ? $opt['loglevel'] : 'error');

/* =============================================================================
 * Le démon.
 * ========================================================================== */
class AjaxSiaDaemon {

    const PUSH_TIMEOUT   = 4;        // un Jeedom lent ne doit jamais geler la boucle
    const PUSH_ATTEMPTS  = 2;
    const PUSH_QUEUE_MAX = 500;

    /* Un hub garde parfois sa connexion ouverte entre deux messages : on la
     * laisse vivre, mais pas indéfiniment, et pas à n'importe qui en nombre. */
    const CLIENT_IDLE  = 600;
    const MAX_CLIENTS  = 32;

    /* Un hub réémet un message dont il n'a pas eu l'accusé, avec le même numéro
     * de séquence. Le journal le garde, Jeedom ne le traite qu'une fois. */
    const DEDUP_TTL = 300;

    const RAW_MAX = 1024;            // taille gardée d'une trame dans le journal

    private $opt;
    private $config = array();
    private $tcp = null;
    private $udp = null;
    private $control = null;
    private $listening = '';         // « port/udp » effectivement ouverts
    private $clients = array();      // (int) ressource => client
    private $pushQueue = array();
    private $pushPid = 0;
    private $dedup = array();
    private $reloadPending = false;
    private $running = true;
    private $lastPurge = 0;
    private $stats = array(
        'started'   => 0,
        'frames'    => 0,
        'accepted'  => 0,
        'rejected'  => 0,
        'lastFrame' => 0,
        'peers'     => array(),
    );

    public function __construct($_opt) {
        $this->opt = $_opt;
    }

    public function stop() {
        $this->running = false;
    }

    /* ------------------------------------------------------------- callback */

    private function callback($_query = '', $_body = null) {
        $url = $this->opt['callback'] . '?apikey=' . urlencode($this->opt['apikey']) . $_query;

        for ($i = 0; $i < self::PUSH_ATTEMPTS; $i++) {
            $ch = curl_init($url);
            $options = array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT        => self::PUSH_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            );
            if ($_body !== null) {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = json_encode($_body);
                $options[CURLOPT_HTTPHEADER] = array('Content-Type: application/json');
            }
            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($code == 200) {
                /* Seul « OK » atteste qu'un lot a été traité : le callback répond
                 * aussi 200 quand il rejette un corps vide ou illisible. */
                if ($_body === null || trim((string) $response) === 'OK') {
                    return $response;
                }
                SiaLog::error('lot refusé par Jeedom : ' . substr(trim((string) $response), 0, 200)
                            . ' — tentative ' . ($i + 1) . '/' . self::PUSH_ATTEMPTS);
            } else {
                SiaLog::error('callback en échec (HTTP ' . $code . ($error != '' ? ' / ' . $error : '')
                            . ') tentative ' . ($i + 1) . '/' . self::PUSH_ATTEMPTS);
            }
        }
        return false;
    }

    private function loadConfig() {
        $raw = $this->callback('&action=config');
        if ($raw === false) {
            SiaLog::error('configuration illisible depuis Jeedom');
            return false;
        }
        $config = json_decode($raw, true);
        if (!is_array($config) || !isset($config['port'])) {
            /* 80 caractères : de quoi reconnaître une page d'erreur, pas assez
             * pour recopier une clé de chiffrement dans le journal. */
            SiaLog::error('configuration invalide : ' . substr((string) $raw, 0, 80));
            return false;
        }
        $config['port']         = (int) $config['port'];
        $config['udp']          = !empty($config['udp']);
        $config['strict_time']  = !isset($config['strict_time']) || !empty($config['strict_time']);
        $config['journal_days'] = isset($config['journal_days']) ? max(1, (int) $config['journal_days']) : 90;
        $config['allowed']      = isset($config['allowed']) && is_array($config['allowed']) ? $config['allowed'] : array();
        $config['hubs']         = isset($config['hubs']) && is_array($config['hubs']) ? $config['hubs'] : array();
        $config['key']          = isset($config['key']) ? (string) $config['key'] : '';
        $this->config = $config;

        /* Le démon ne charge pas le coeur, donc jamais son fuseau : sans cela il
         * daterait son journal en UTC. */
        if (!empty($config['timezone']) && in_array($config['timezone'], timezone_identifiers_list(), true)) {
            date_default_timezone_set($config['timezone']);
        }
        SiaLog::info(count($config['hubs']) . ' hub(s) connu(s), écoute sur le port ' . $config['port']
                   . ($config['udp'] ? ' (TCP et UDP)' : ' (TCP)'));
        return true;
    }

    /* Clés à essayer pour un compte : la sienne, puis la clé générale. */
    public function keysFor($_account) {
        $keys = array();
        if (isset($this->config['hubs'][$_account]['key']) && $this->config['hubs'][$_account]['key'] !== '') {
            $keys[] = $this->config['hubs'][$_account]['key'];
        }
        if ($this->config['key'] !== '' && !in_array($this->config['key'], $keys, true)) {
            $keys[] = $this->config['key'];
        }
        return $keys;
    }

    /* ------------------------------------------------------------ écoute */

    /* Ouvre (ou rouvre, si le port a changé) les sockets de réception. */
    private function openListeners() {
        $wanted = $this->config['port'] . '/' . ($this->config['udp'] ? 'udp' : '');
        if ($wanted === $this->listening) {
            return true;
        }
        $this->closeListeners();
        $port = $this->config['port'];
        $errno = 0; $errstr = '';
        $tcp = @stream_socket_server('tcp://0.0.0.0:' . $port, $errno, $errstr);
        if ($tcp === false) {
            SiaLog::error('écoute TCP impossible sur le port ' . $port . ' : ' . $errstr);
            return false;
        }
        stream_set_blocking($tcp, false);
        $this->tcp = $tcp;
        if ($this->config['udp']) {
            $udp = @stream_socket_server('udp://0.0.0.0:' . $port, $errno, $errstr, STREAM_SERVER_BIND);
            if ($udp === false) {
                SiaLog::error('écoute UDP impossible sur le port ' . $port . ' : ' . $errstr);
            } else {
                stream_set_blocking($udp, false);
                $this->udp = $udp;
            }
        }
        $this->listening = $wanted;
        SiaLog::info('réception SIA ouverte sur le port ' . $port);
        return true;
    }

    private function closeListeners() {
        foreach ($this->clients as $key => $client) {
            @fclose($client['stream']);
        }
        $this->clients = array();
        if (is_resource($this->tcp)) {
            fclose($this->tcp);
        }
        if (is_resource($this->udp)) {
            fclose($this->udp);
        }
        $this->tcp = null;
        $this->udp = null;
        $this->listening = '';
    }

    /* --------------------------------------------------------------- démarrage */

    public function run() {
        if (trim((string) $this->callback('&test=1')) !== 'OK') {
            SiaLog::error('callback injoignable : ' . $this->opt['callback']);
            return 1;
        }
        if (!$this->loadConfig()) {
            return 1;
        }
        if (!$this->openListeners()) {
            return 1;
        }

        $port = (int) $this->opt['socketport'];
        $errno = 0; $errstr = '';
        $this->control = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
        if ($this->control === false) {
            SiaLog::error('écoute des ordres impossible sur le port ' . $port . ' : ' . $errstr);
            return 1;
        }
        stream_set_blocking($this->control, false);

        $this->stats['started'] = time();
        file_put_contents($this->opt['pid'], getmypid() . "\n");
        SiaLog::info('démon démarré (pid ' . getmypid() . ', ordres sur 127.0.0.1:' . $port . ')');

        $this->loop();

        SiaLog::info('arrêt du démon');
        $this->closeListeners();
        if (is_resource($this->control)) {
            fclose($this->control);
        }
        @unlink($this->opt['pid']);
        return 0;
    }

    private function loop() {
        while ($this->running) {
            $this->reapChildren();
            $this->drainPushQueue();

            if ($this->reloadPending) {
                $this->reloadPending = false;
                if ($this->loadConfig()) {
                    $this->openListeners();
                }
            }

            $read = array($this->control);
            if (is_resource($this->tcp)) {
                $read[] = $this->tcp;
            }
            if (is_resource($this->udp)) {
                $read[] = $this->udp;
            }
            foreach ($this->clients as $client) {
                $read[] = $client['stream'];
            }
            $write = null; $except = null;
            $ready = @stream_select($read, $write, $except, 1);

            if ($ready > 0) {
                foreach ($read as $stream) {
                    if ($stream === $this->control) {
                        $this->acceptOrders();
                    } elseif ($stream === $this->tcp) {
                        $this->acceptClients();
                    } elseif ($stream === $this->udp) {
                        $this->readDatagrams();
                    } else {
                        $this->readClient($stream);
                    }
                }
            }

            $this->expireClients();
            $this->purgeJournal();
        }
    }

    /* ------------------------------------------------------------ clients TCP */

    private function acceptClients() {
        for ($i = 0; $i < 8; $i++) {
            $peer = '';
            $stream = @stream_socket_accept($this->tcp, 0, $peer);
            if ($stream === false) {
                return;
            }
            if (!$this->allowed($peer)) {
                $this->journal(array('peer' => $peer, 'proto' => 'tcp', 'status' => 'refused',
                                     'error' => 'adresse non autorisée'));
                SiaLog::warning('connexion refusée depuis ' . $peer . ' (adresse non autorisée)');
                @fclose($stream);
                continue;
            }
            if (count($this->clients) >= self::MAX_CLIENTS) {
                SiaLog::warning('trop de connexions ouvertes, ' . $peer . ' refusé');
                @fclose($stream);
                continue;
            }
            stream_set_blocking($stream, false);
            $this->clients[(int) $stream] = array(
                'stream' => $stream,
                'peer'   => $peer,
                'buffer' => '',
                'last'   => time(),
            );
            SiaLog::debug('connexion de ' . $peer);
        }
    }

    private function readClient($_stream) {
        $id = (int) $_stream;
        if (!isset($this->clients[$id])) {
            return;
        }
        $chunk = @fread($_stream, 8192);
        if ($chunk === false || ($chunk === '' && feof($_stream))) {
            SiaLog::debug('déconnexion de ' . $this->clients[$id]['peer']);
            @fclose($_stream);
            unset($this->clients[$id]);
            return;
        }
        $this->clients[$id]['last'] = time();
        $this->clients[$id]['buffer'] .= $chunk;
        foreach (AjaxSiaCodec::extractFrames($this->clients[$id]['buffer']) as $frame) {
            $reply = $this->handleFrame($frame, $this->clients[$id]['peer'], 'tcp');
            if ($reply !== null) {
                @fwrite($_stream, $reply);
            }
        }
    }

    private function readDatagrams() {
        for ($i = 0; $i < 16; $i++) {
            $peer = '';
            $data = @stream_socket_recvfrom($this->udp, 4096, 0, $peer);
            if ($data === false || $data === '') {
                return;
            }
            if (!$this->allowed($peer)) {
                SiaLog::debug('datagramme ignoré depuis ' . $peer . ' (adresse non autorisée)');
                continue;
            }
            /* Un datagramme est une trame : le CR final peut manquer. */
            $buffer = (substr($data, -1) === "\r") ? $data : $data . "\r";
            foreach (AjaxSiaCodec::extractFrames($buffer) as $frame) {
                $reply = $this->handleFrame($frame, $peer, 'udp');
                if ($reply !== null) {
                    @stream_socket_sendto($this->udp, $reply, 0, $peer);
                }
            }
        }
    }

    private function expireClients() {
        $now = time();
        foreach ($this->clients as $id => $client) {
            if ($now - $client['last'] > self::CLIENT_IDLE) {
                SiaLog::debug('connexion inactive fermée : ' . $client['peer']);
                @fclose($client['stream']);
                unset($this->clients[$id]);
            }
        }
    }

    /* Liste blanche : vide, elle laisse tout passer. */
    private function allowed($_peer) {
        if (empty($this->config['allowed'])) {
            return true;
        }
        $ip = preg_replace('/:\d+$/', '', $_peer);
        $ip = trim($ip, '[]');
        return in_array($ip, $this->config['allowed'], true);
    }

    /* --------------------------------------------------------------- trames */

    /*
     * Traite une trame et rend la réponse à renvoyer (ou null).
     *
     * Trame illisible, CRC faux ou clé inconnue : aucune réponse. C'est ce que
     * prévoit la norme ; le hub réémettra, et le journal montre pourquoi.
     */
    private function handleFrame($_frame, $_peer, $_proto) {
        $now = microtime(true);
        $msg = AjaxSiaCodec::parse($_frame, array($this, 'keysFor'), (int) $now);
        $this->stats['frames']++;
        $this->stats['lastFrame'] = (int) $now;
        $this->stats['peers'][preg_replace('/:\d+$/', '', $_peer)] = (int) $now;

        $reply = null;
        $replyName = '';
        $status = $msg['status'];
        $error = $msg['error'];
        $duplicate = false;

        if ($status === 'ok' && AjaxSiaCodec::outsideWindow($msg) && $this->config['strict_time']) {
            $status = 'window';
            $error = 'horodatage hors fenêtre (écart ' . $msg['skew'] . ' s)';
        }
        if ($status === 'ok') {
            $reply = AjaxSiaCodec::ack($msg);
            $replyName = 'ACK';
            $duplicate = $this->isDuplicate($msg, (int) $now);
        } elseif ($status === 'window' || $status === 'timestamp') {
            $reply = AjaxSiaCodec::nak($msg);
            $replyName = 'NAK';
        }

        $events = array();
        foreach ($msg['events'] as $event) {
            $info = AjaxSiaCodec::describe($event['code']);
            $events[] = $event + array('label' => $info['l'], 'cat' => $info['c']);
        }

        $entry = array(
            't'       => round($now, 3),
            'peer'    => $_peer,
            'proto'   => $_proto,
            'status'  => $duplicate ? 'duplicate' : $status,
            'error'   => $error,
            'reply'   => $replyName,
            'type'    => $msg['type'],
            'enc'     => $msg['encrypted'],
            'account' => $msg['account'],
            'seq'     => $msg['seq'],
            'data'    => $msg['data'],
            'xdata'   => $msg['xdata'],
            'skew'    => $msg['skew'],
            'events'  => $events,
            'raw'     => substr($msg['raw'], 0, self::RAW_MAX),
        );
        if ($msg['encrypted'] && $msg['content'] !== '') {
            $entry['content'] = $msg['content'];
        }
        $this->journal($entry);

        $what = $msg['type'] . ' #' . $msg['account'] . ' seq ' . $msg['seq']
              . ($msg['data'] !== '' ? ' [' . $msg['data'] . ']' : '');
        if ($status === 'ok') {
            $this->stats['accepted']++;
            SiaLog::info(($duplicate ? 'doublon ' : 'reçu ') . $what . ' de ' . $_peer . ' → ACK');
        } else {
            $this->stats['rejected']++;
            SiaLog::warning('refusé (' . $error . ') ' . $what . ' de ' . $_peer
                          . ($replyName !== '' ? ' → ' . $replyName : ''));
        }
        SiaLog::debug('trame : ' . $msg['raw']);

        if ($status === 'ok' && !$duplicate) {
            $this->push(array(
                't'       => $entry['t'],
                'peer'    => $_peer,
                'type'    => $msg['type'],
                'enc'     => $msg['encrypted'] ? 1 : 0,
                'account' => $msg['account'],
                'seq'     => $msg['seq'],
                'events'  => $events,
            ));
        }
        return $reply;
    }

    private function isDuplicate($_msg, $_now) {
        foreach ($this->dedup as $key => $time) {
            if ($_now - $time > self::DEDUP_TTL) {
                unset($this->dedup[$key]);
            }
        }
        /* Les tests de liaison portent tous la séquence 0000 : ce ne sont
         * jamais des doublons. */
        if ($_msg['type'] === 'NULL') {
            return false;
        }
        $key = $_msg['account'] . '|' . $_msg['seq'] . '|' . $_msg['type'] . '|' . $_msg['data'];
        if (isset($this->dedup[$key])) {
            return true;
        }
        $this->dedup[$key] = $_now;
        return false;
    }

    /* -------------------------------------------------------------- journal */

    private function journalDir() {
        return __DIR__ . '/../../data/journal';
    }

    /* Une ligne JSON par trame, un fichier par jour. */
    private function journal($_entry) {
        $dir = $this->journalDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            SiaLog::error('dossier du journal impossible à créer : ' . $dir);
            return;
        }
        $_entry += array('t' => round(microtime(true), 3));
        $file = $dir . '/' . date('Y-m-d', (int) $_entry['t']) . '.jsonl';
        $line = json_encode($_entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            SiaLog::error('écriture du journal impossible : ' . $file);
        }
    }

    /* Une fois par heure, efface les journaux plus anciens que la rétention. */
    private function purgeJournal() {
        if (time() - $this->lastPurge < 3600) {
            return;
        }
        $this->lastPurge = time();
        $limit = date('Y-m-d', time() - 86400 * $this->config['journal_days']);
        foreach ((array) glob($this->journalDir() . '/*.jsonl') as $file) {
            if (basename($file, '.jsonl') < $limit) {
                @unlink($file);
                SiaLog::info('journal purgé : ' . basename($file));
            }
        }
    }

    /* ------------------------------------------------------- remise à Jeedom */

    private function push($_event) {
        $this->pushQueue[] = $_event;
        if (count($this->pushQueue) > self::PUSH_QUEUE_MAX) {
            array_shift($this->pushQueue);
            SiaLog::warning('file d\'envoi saturée, le plus ancien événement est abandonné');
        }
        $this->drainPushQueue();
    }

    /*
     * L'envoi se fait dans un processus fils : la boucle doit rester libre
     * d'accuser réception au hub pendant que Jeedom traite. Un seul envoi à la
     * fois, pour que Jeedom reçoive les événements dans l'ordre.
     */
    private function drainPushQueue() {
        if ($this->pushPid > 0) {
            /* 0 : le fils tourne encore. Toute autre valeur, -1 compris (fils
             * déjà récolté par reapChildren), veut dire qu'il est terminé. */
            if (pcntl_waitpid($this->pushPid, $status, WNOHANG) === 0) {
                return;
            }
            $this->pushPid = 0;
        }
        if (empty($this->pushQueue)) {
            return;
        }
        $batch = $this->pushQueue;
        $this->pushQueue = array();

        $pid = pcntl_fork();
        if ($pid == -1) {
            SiaLog::error('fork impossible pour l\'envoi, lot abandonné');
            return;
        }
        if ($pid > 0) {
            $this->pushPid = $pid;
            return;
        }
        $this->closeInheritedSockets();
        if ($this->callback('', array('events' => $batch)) === false) {
            SiaLog::error(count($batch) . ' événement(s) non remis à Jeedom (ils restent au journal)');
        }
        exit(0);
    }

    /* Un fils qui garderait les sockets d'écoute empêcherait tout redémarrage
     * du démon (« Address already in use ») tant qu'il vit. */
    private function closeInheritedSockets() {
        foreach ($this->clients as $client) {
            @fclose($client['stream']);
        }
        foreach (array($this->tcp, $this->udp, $this->control) as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    private function reapChildren() {
        while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
            // vide la table des processus
        }
    }

    /* ------------------------------------------------------ ordres Jeedom */

    private function acceptOrders() {
        for ($i = 0; $i < 8; $i++) {
            $conn = @stream_socket_accept($this->control, 0);
            if ($conn === false) {
                return;
            }
            $this->handleOrder($conn);
        }
    }

    /* Lecture bornée à 1 s : un client muet ne doit pas immobiliser la boucle. */
    private function handleOrder($_conn) {
        stream_set_blocking($_conn, false);
        $line = '';
        $deadline = microtime(true) + 1.0;
        while (microtime(true) < $deadline && strpos($line, "\n") === false) {
            $read = array($_conn); $write = null; $except = null;
            if (@stream_select($read, $write, $except, 0, 100000) < 1) {
                continue;
            }
            $chunk = @fread($_conn, 65535);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $line .= $chunk;
        }
        $order = json_decode(trim($line), true);
        $result = array('state' => 'ok');
        if (!is_array($order) || !isset($order['apikey']) || !hash_equals($this->opt['apikey'], (string) $order['apikey'])) {
            $result = array('state' => 'error', 'result' => 'clé API invalide');
        } else {
            switch (isset($order['cmd']) ? $order['cmd'] : '') {
                case 'reload':
                    $this->reloadPending = true;
                    break;
                case 'status':
                    $result['result'] = $this->stats + array(
                        'port'    => $this->config['port'],
                        'udp'     => is_resource($this->udp),
                        'clients' => array_values(array_map(function ($_c) {
                            return array('peer' => $_c['peer'], 'last' => $_c['last']);
                        }, $this->clients)),
                    );
                    break;
                default:
                    $result = array('state' => 'error', 'result' => 'commande inconnue');
            }
        }
        @fwrite($_conn, json_encode($result) . "\n");
        @fclose($_conn);
    }
}

/* ------------------------------------------------------------------ signaux */
$daemon = new AjaxSiaDaemon($opt);
$shutdown = function ($signo) use ($daemon) {
    SiaLog::info('signal ' . $signo . ' reçu, arrêt en cours');
    $daemon->stop();
};
pcntl_signal(SIGTERM, $shutdown);
pcntl_signal(SIGINT,  $shutdown);
pcntl_signal(SIGHUP,  $shutdown);

exit($daemon->run());
