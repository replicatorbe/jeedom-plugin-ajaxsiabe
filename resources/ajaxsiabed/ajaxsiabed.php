#!/usr/bin/env php
<?php
/* Démon récepteur SIA DC-09 pour Jeedom.
 *
 * Il tient le rôle d'un centre de télésurveillance : le hub Ajax lui envoie ses
 * événements en TCP (ou UDP), il les accuse aussitôt, les consigne dans le
 * journal puis les remet à Jeedom.
 *
 * L'accusé de réception ne dépend jamais de Jeedom : il part dès que la
 * trame est lue et consignée au journal, la remise à Jeedom se faisant dans un
 * processus fils. Le hub attend quelques secondes puis réémet, et un Jeedom
 * lent ne doit jamais lui faire croire que le centre est tombé. Le journal est
 * écrit par le démon lui-même : un événement reçu y figure même si Jeedom ne
 * répond pas, et même s'il a été refusé.
 *
 * Processus autonome : il ne charge PAS le coeur de Jeedom. Sa configuration
 * est lue sur le callback HTTP du plugin, et les événements y sont repoussés.
 *
 * Licence AGPL v3, comme le reste du plugin.
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

    /* Remise à Jeedom. Délai large : le premier message d'un hub crée des
     * équipements, ce qui prend du temps sur un petit matériel. Une seule
     * nouvelle tentative, et seulement si Jeedom n'a pas été joint du tout :
     * relancer un envoi qui a expiré le ferait traiter deux fois. */
    const PUSH_TIMEOUT   = 20;
    const PUSH_QUEUE_MAX = 500;

    /* La configuration est lue dans la boucle : un délai court, pour ne pas
     * laisser un hub sans accusé pendant qu'on attend Jeedom. */
    const CONFIG_TIMEOUT = 2;

    /*
     * Connexions TCP. Un hub garde parfois la sienne ouverte entre deux
     * messages : elle vit tant qu'elle sert, mais une connexion qui n'a jamais
     * produit de trame valide est fermée vite, et quand toutes les places sont
     * prises c'est la plus ancienne inactive qui cède. Sans cela, quelques
     * connexions muettes suffiraient à rendre le récepteur sourd.
     */
    const CLIENT_IDLE    = 600;
    const CLIENT_PROBING = 30;
    const MAX_CLIENTS    = 32;
    const MAX_PER_PEER   = 8;
    const MAX_INVALID    = 5;        // trames illisibles avant fermeture

    /* Un hub réémet un message dont il n'a pas eu l'accusé, avec le même numéro
     * de séquence. Le journal le garde, Jeedom ne le traite qu'une fois. */
    const DEDUP_TTL = 300;

    const RAW_MAX = 1024;            // taille gardée d'une trame dans le journal

    /* Le journal ne doit pas pouvoir remplir le disque : au-delà de cette
     * taille, seuls les messages acceptés porteurs d'événements y entrent
     * encore, et les refus répétés d'un même émetteur sont résumés. */
    const JOURNAL_DAY_MAX = 52428800;   // 50 Mo
    const NOISE_WINDOW    = 60;

    const MAX_PEERS = 64;            // émetteurs gardés dans les statistiques

    private $opt;
    private $config = array();
    private $tcp = null;
    private $udp = null;
    private $tcpPort = 0;            // port réellement ouvert, 0 sinon
    private $udpPort = 0;
    private $listenError = '';
    private $lastListenTry = 0;
    private $control = null;
    private $clients = array();      // (int) ressource => client TCP
    private $orders = array();       // (int) ressource => connexion d'ordres
    private $pushQueue = array();
    private $pushPid = 0;
    private $dedup = array();
    private $noise = array();        // émetteur|motif => [début, nombre]
    private $reloadPending = false;
    private $running = true;
    private $lastPurge = 0;
    private $uid = 0;
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

    private function callback($_query = '', $_body = null, $_timeout = self::PUSH_TIMEOUT, $_attempts = 2) {
        $url = $this->opt['callback'] . '?apikey=' . urlencode($this->opt['apikey']) . $_query;

        for ($i = 0; $i < $_attempts; $i++) {
            $ch = curl_init($url);
            $options = array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT        => $_timeout,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            );
            if ($_body !== null) {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = json_encode($_body, JSON_INVALID_UTF8_SUBSTITUTE);
                $options[CURLOPT_HTTPHEADER] = array('Content-Type: application/json');
            }
            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($code == 200) {
                /* Seul « OK » atteste qu'un lot a été traité : le callback répond
                 * aussi 200 quand il rejette un corps vide ou illisible. */
                if ($_body === null || trim((string) $response) === 'OK') {
                    return $response;
                }
                SiaLog::error('lot refusé par Jeedom : ' . AjaxSiaCodec::clean(substr(trim((string) $response), 0, 200)));
                return false;
            }
            SiaLog::error('callback en échec (HTTP ' . $code . ($error != '' ? ' / ' . $error : '') . ')');
            /* Nouvelle tentative seulement si Jeedom n'a jamais reçu la requête. */
            if ($errno !== CURLE_COULDNT_CONNECT || $i + 1 >= $_attempts) {
                return false;
            }
            sleep(1);
        }
        return false;
    }

    private function loadConfig() {
        /* Une seule tentative, délai court : pendant ce temps la boucle ne
         * répond plus aux hubs. En cas d'échec l'ancienne configuration reste. */
        $raw = $this->callback('&action=config', null, self::CONFIG_TIMEOUT, $this->stats['started'] > 0 ? 1 : 2);
        if ($raw === false) {
            SiaLog::error('configuration illisible depuis Jeedom');
            return false;
        }
        $config = json_decode($raw, true);
        if (!is_array($config) || !isset($config['port'])) {
            /* Une configuration contient des clés de chiffrement : on n'en
             * recopie jamais rien, seulement de quoi reconnaître une page
             * d'erreur HTML. */
            $head = ltrim((string) $raw);
            SiaLog::error('configuration invalide (' . strlen($raw) . ' octets'
                        . (($head !== '' && $head[0] !== '{') ? ', début : ' . AjaxSiaCodec::clean(substr($head, 0, 60)) : '') . ')');
            return false;
        }
        $config['port']         = (int) $config['port'];
        if ($config['port'] < 1 || $config['port'] > 65535) {
            SiaLog::error('configuration invalide : port ' . $config['port']);
            return false;
        }
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
        SiaLog::info(count($config['hubs']) . ' hub(s) connu(s), réception sur le port ' . $config['port']
                   . ($config['udp'] ? ' (TCP et UDP)' : ' (TCP)'));
        return true;
    }

    /* Clé à essayer pour un compte : la sienne s'il en a une, sinon la clé
     * générale. Un hub qui a sa propre clé n'accepte que celle-là. */
    public function keysFor($_account) {
        if (isset($this->config['hubs'][$_account]['key']) && $this->config['hubs'][$_account]['key'] !== '') {
            return array($this->config['hubs'][$_account]['key']);
        }
        return ($this->config['key'] !== '') ? array($this->config['key']) : array();
    }

    /* ------------------------------------------------------------ écoute */

    /*
     * Met les sockets de réception en accord avec la configuration. Le nouveau
     * port est ouvert AVANT de fermer l'ancien : si l'ouverture échoue (port
     * pris, refusé), le démon continue de recevoir sur l'ancien au lieu de
     * devenir sourd, et réessaie à chaque demi-minute.
     */
    private function syncListeners($_force = false) {
        $port = $this->config['port'];
        $wantUdp = $this->config['udp'];
        if ($this->tcpPort === $port && ($wantUdp ? $this->udpPort === $port : $this->udpPort === 0)) {
            $this->listenError = '';
            return true;
        }
        if (!$_force && time() - $this->lastListenTry < 30) {
            return false;
        }
        $this->lastListenTry = time();
        $errors = array();

        /* Les nouvelles sockets sont toutes ouvertes avant qu'une seule ne
         * remplace l'ancienne : TCP et UDP basculent ensemble ou pas du tout. */
        $newTcp = null;
        $newUdp = null;
        if ($this->tcpPort !== $port) {
            $errno = 0; $errstr = '';
            $newTcp = @stream_socket_server('tcp://0.0.0.0:' . $port, $errno, $errstr);
            if ($newTcp === false) {
                $errors[] = 'TCP ' . $port . ' : ' . $errstr;
            }
        }
        if ($wantUdp && $this->udpPort !== $port) {
            $errno = 0; $errstr = '';
            $newUdp = @stream_socket_server('udp://0.0.0.0:' . $port, $errno, $errstr, STREAM_SERVER_BIND);
            if ($newUdp === false) {
                $errors[] = 'UDP ' . $port . ' : ' . $errstr;
            }
        }
        if (!empty($errors)) {
            foreach (array($newTcp, $newUdp) as $socket) {
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }
        } else {
            if (is_resource($newTcp)) {
                stream_set_blocking($newTcp, false);
                if (is_resource($this->tcp)) {
                    fclose($this->tcp);
                    SiaLog::info('écoute TCP déplacée du port ' . $this->tcpPort . ' au port ' . $port);
                }
                $this->tcp = $newTcp;
                $this->tcpPort = $port;
            }
            if (is_resource($newUdp)) {
                stream_set_blocking($newUdp, false);
                if (is_resource($this->udp)) {
                    fclose($this->udp);
                }
                $this->udp = $newUdp;
                $this->udpPort = $port;
            }
            if (!$wantUdp && is_resource($this->udp)) {
                fclose($this->udp);
                $this->udp = null;
                $this->udpPort = 0;
            }
        }

        if (!empty($errors)) {
            $this->listenError = 'écoute impossible (' . implode(', ', $errors) . ')';
            SiaLog::error($this->listenError . ($this->tcpPort > 0 && $this->tcpPort !== $port
                ? ' — réception maintenue sur l\'ancien port ' . $this->tcpPort : '') . ', nouvel essai dans 30 s');
            return false;
        }
        $this->listenError = '';
        SiaLog::info('réception SIA ouverte sur le port ' . $port . ($wantUdp ? ' (TCP et UDP)' : ' (TCP)'));
        return true;
    }

    private function closeListeners() {
        foreach ($this->clients as $client) {
            @fclose($client['stream']);
        }
        $this->clients = array();
        foreach (array($this->tcp, $this->udp) as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->tcp = null;
        $this->udp = null;
        $this->tcpPort = 0;
        $this->udpPort = 0;
    }

    /* --------------------------------------------------------------- démarrage */

    public function run() {
        if (trim((string) $this->callback('&test=1', null, self::CONFIG_TIMEOUT)) !== 'OK') {
            SiaLog::error('callback injoignable : ' . $this->opt['callback']);
            return 1;
        }
        if (!$this->loadConfig()) {
            return 1;
        }
        /* Au démarrage, pas de port de repli : sans écoute TCP, inutile de tourner. */
        $this->syncListeners(true);
        if ($this->tcpPort === 0) {
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
        $this->flushNoise(true);
        $this->closeListeners();
        foreach ($this->orders as $order) {
            @fclose($order['stream']);
        }
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
                    $this->syncListeners(true);
                }
            } else {
                $this->syncListeners();
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
            foreach ($this->orders as $order) {
                $read[] = $order['stream'];
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
                    } elseif (isset($this->orders[(int) $stream])) {
                        $this->readOrder($stream);
                    } else {
                        $this->readClient($stream);
                    }
                }
            }

            $this->expireClients();
            $this->expireOrders();
            $this->flushNoise();
            $this->purgeJournal();
        }
    }

    /* ------------------------------------------------------------ clients TCP */

    private static function peerIp($_peer) {
        return trim(preg_replace('/:\d+$/', '', (string) $_peer), '[]');
    }

    private function acceptClients() {
        for ($i = 0; $i < 8; $i++) {
            $peer = '';
            $stream = @stream_socket_accept($this->tcp, 0, $peer);
            if ($stream === false) {
                return;
            }
            $ip = self::peerIp($peer);
            if (!$this->allowed($ip)) {
                $this->journalNoise($peer, 'tcp', 'refused', 'adresse non autorisée');
                @fclose($stream);
                continue;
            }
            $samePeer = array_filter($this->clients, function ($_c) use ($ip) { return $_c['ip'] === $ip; });
            if (count($samePeer) >= self::MAX_PER_PEER) {
                $this->evictClient($this->oldestIdle($samePeer), 'trop de connexions de ' . $ip);
            } elseif (count($this->clients) >= self::MAX_CLIENTS) {
                $this->evictClient($this->oldestIdle($this->clients), 'plus de place');
            }
            stream_set_blocking($stream, false);
            $this->clients[(int) $stream] = array(
                'stream'  => $stream,
                'peer'    => $peer,
                'ip'      => $ip,
                'buffer'  => '',
                'opened'  => time(),
                'last'    => time(),
                'valid'   => false,       // a déjà produit une trame valide
                'invalid' => 0,
            );
            SiaLog::debug('connexion de ' . $peer);
        }
    }

    /* La connexion inactive depuis le plus longtemps, celles qui n'ont jamais
     * rien produit de valide passant en premier. */
    private function oldestIdle($_clients) {
        $oldest = null;
        foreach ($_clients as $id => $client) {
            $score = $client['last'] - ($client['valid'] ? 0 : 100000);
            if ($oldest === null || $score < $oldest[1]) {
                $oldest = array($id, $score);
            }
        }
        return ($oldest === null) ? null : $oldest[0];
    }

    /* Avant de fermer une connexion pour faire de la place, on lit ce qu'elle
     * a déjà envoyé : une trame arrivée ne doit pas se perdre sans accusé. */
    private function evictClient($_id, $_reason) {
        if ($_id === null || !isset($this->clients[$_id])) {
            return;
        }
        $this->readClient($this->clients[$_id]['stream']);
        $this->closeClient($_id, $_reason);
    }

    private function closeClient($_id, $_reason) {
        if ($_id === null || !isset($this->clients[$_id])) {
            return;
        }
        SiaLog::debug('connexion fermée (' . $_reason . ') : ' . $this->clients[$_id]['peer']);
        @fclose($this->clients[$_id]['stream']);
        unset($this->clients[$_id]);
    }

    private function readClient($_stream) {
        $id = (int) $_stream;
        if (!isset($this->clients[$id])) {
            return;
        }
        $chunk = @fread($_stream, 8192);
        if ($chunk === false || ($chunk === '' && feof($_stream))) {
            $this->closeClient($id, 'fermée par l\'émetteur');
            return;
        }
        $this->clients[$id]['last'] = time();
        $this->clients[$id]['buffer'] .= $chunk;
        foreach (AjaxSiaCodec::extractFrames($this->clients[$id]['buffer']) as $frame) {
            $result = $this->handleFrame($frame, $this->clients[$id]['peer'], 'tcp');
            if ($result['reply'] !== null) {
                @fwrite($_stream, $result['reply']);
            }
            if ($result['valid']) {
                $this->clients[$id]['valid'] = true;
                $this->clients[$id]['invalid'] = 0;
            } elseif (++$this->clients[$id]['invalid'] >= self::MAX_INVALID) {
                $this->closeClient($id, self::MAX_INVALID . ' trames illisibles');
                return;
            }
        }
    }

    private function readDatagrams() {
        for ($i = 0; $i < 16; $i++) {
            $peer = '';
            $data = @stream_socket_recvfrom($this->udp, 8192, 0, $peer);
            if ($data === false || $data === '') {
                return;
            }
            if (!$this->allowed(self::peerIp($peer))) {
                $this->journalNoise($peer, 'udp', 'refused', 'adresse non autorisée');
                continue;
            }
            /* Un datagramme est une trame : le CR final peut manquer. */
            $buffer = (substr($data, -1) === "\r") ? $data : $data . "\r";
            foreach (AjaxSiaCodec::extractFrames($buffer) as $frame) {
                $result = $this->handleFrame($frame, $peer, 'udp');
                if ($result['reply'] !== null) {
                    @stream_socket_sendto($this->udp, $result['reply'], 0, $peer);
                }
            }
        }
    }

    private function expireClients() {
        $now = time();
        foreach ($this->clients as $id => $client) {
            if (!$client['valid'] && $now - $client['opened'] > self::CLIENT_PROBING) {
                $this->closeClient($id, 'aucune trame valide en ' . self::CLIENT_PROBING . ' s');
            } elseif ($now - $client['last'] > self::CLIENT_IDLE) {
                $this->closeClient($id, 'inactive');
            }
        }
    }

    /* Liste blanche : vide, elle laisse tout passer. */
    private function allowed($_ip) {
        return empty($this->config['allowed']) || in_array($_ip, $this->config['allowed'], true);
    }

    /* --------------------------------------------------------------- trames */

    /*
     * Traite une trame. Rend la réponse à renvoyer (ou null) et si la trame
     * était valide, ce qui garde la connexion TCP en vie.
     *
     * Trame illisible, CRC faux, clé inconnue ou message en clair pour un
     * compte chiffré : aucune réponse. Le hub réémettra, et le journal dit
     * pourquoi.
     */
    private function handleFrame($_frame, $_peer, $_proto) {
        $now = microtime(true);
        $msg = AjaxSiaCodec::parse($_frame, array($this, 'keysFor'), (int) $now);
        $this->stats['frames']++;
        $this->stats['lastFrame'] = (int) $now;
        $this->stats['peers'][self::peerIp($_peer)] = (int) $now;
        if (count($this->stats['peers']) > self::MAX_PEERS) {
            arsort($this->stats['peers']);
            $this->stats['peers'] = array_slice($this->stats['peers'], 0, self::MAX_PEERS, true);
        }

        $reply = null;
        $replyName = '';
        $status = $msg['status'];
        $error = $msg['error'];
        $duplicate = false;

        /*
         * Dès qu'une clé est connue pour ce compte (la sienne ou la clé
         * générale), le clair est refusé : sinon n'importe quelle machine du
         * réseau pourrait envoyer un faux désarmement en recopiant le numéro de
         * compte, qui circule en clair même dans les trames chiffrées.
         */
        if ($status === 'ok' && !$msg['encrypted'] && !empty($this->keysFor($msg['account']))) {
            $status = 'plain';
            $error = 'message en clair pour un compte chiffré';
            /* Un test de liaison en clair ne porte aucun événement : il est
             * accusé, pour que le hub ne croie pas le centre tombé, mais ni remis
             * à Jeedom ni compté comme contact — un faux test ne doit pas
             * masquer un hub coupé. */
            if ($msg['type'] === 'NULL') {
                $error = 'test de liaison en clair pour un compte chiffré : accusé, ignoré';
                $reply = AjaxSiaCodec::ack($msg);
                $replyName = 'ACK';
            }
        }
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
        $valid = ($msg['status'] === 'ok');

        $events = array();
        foreach ($msg['events'] as $event) {
            $info = AjaxSiaCodec::describe($event['code']);
            $event['text'] = AjaxSiaCodec::clean($event['text']);
            $events[] = $event + array('label' => $info['l'], 'cat' => $info['c']);
        }

        $what = $msg['type'] . ' #' . $msg['account'] . ' seq ' . $msg['seq']
              . ($msg['data'] !== '' ? ' [' . $msg['data'] . ']' : '');
        if (in_array($status, array('format', 'crc'))) {
            /* Bruit : résumé par émetteur, pour que le journal ne puisse pas
             * servir à remplir le disque. */
            $this->stats['rejected']++;
            $this->journalNoise($_peer, $_proto, $status, $error, $msg['raw']);
            return array('reply' => null, 'valid' => false);
        }

        $entry = array(
            't'       => round($now, 3),
            'peer'    => $_peer,
            'proto'   => $_proto,
            'status'  => $duplicate ? 'duplicate' : $status,
            'error'   => $error,
            'warning' => $msg['warning'],
            'reply'   => $replyName,
            'type'    => $msg['type'],
            'enc'     => $msg['encrypted'],
            'account' => $msg['account'],
            'seq'     => $msg['seq'],
            'data'    => $msg['data'],
            'xdata'   => array_map(array('AjaxSiaCodec', 'clean'), $msg['xdata']),
            'skew'    => $msg['skew'],
            'events'  => $events,
            'raw'     => AjaxSiaCodec::clean(substr($msg['raw'], 0, self::RAW_MAX)),
        );
        if ($msg['encrypted'] && $msg['content'] !== '') {
            $entry['content'] = $msg['content'];
        }
        $this->journal($entry, $status === 'ok' && !$duplicate && !empty($events));

        if ($status === 'ok') {
            $this->stats['accepted']++;
            SiaLog::info(($duplicate ? 'doublon ' : 'reçu ') . $what . ' de ' . $_peer . ' → ACK'
                       . ($msg['warning'] !== '' ? ' (' . $msg['warning'] . ')' : ''));
        } else {
            $this->stats['rejected']++;
            SiaLog::warning('refusé (' . $error . ') ' . $what . ' de ' . $_peer
                          . ($replyName !== '' ? ' → ' . $replyName : ''));
        }
        SiaLog::debug('trame : ' . AjaxSiaCodec::clean($msg['raw']));

        if ($status === 'ok' && !$duplicate) {
            $this->push(array(
                'uid'     => getmypid() . '-' . $this->stats['started'] . '-' . (++$this->uid),
                't'       => $entry['t'],
                'peer'    => $_peer,
                'type'    => $msg['type'],
                'enc'     => $msg['encrypted'] ? 1 : 0,
                'account' => $msg['account'],
                'seq'     => $msg['seq'],
                'events'  => $events,
            ));
        }
        return array('reply' => $reply, 'valid' => $valid);
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

    /*
     * Une ligne JSON par trame, un fichier par jour. Au-delà de la taille
     * maximale du jour, seuls les messages porteurs d'événements y entrent
     * encore : c'est ce qu'on voudra relire.
     */
    private function journal($_entry, $_important = false) {
        $dir = $this->journalDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            SiaLog::error('dossier du journal impossible à créer : ' . $dir);
            return;
        }
        $_entry += array('t' => round(microtime(true), 3));
        $file = $dir . '/' . date('Y-m-d', (int) $_entry['t']) . '.jsonl';
        clearstatcache(true, $file);
        if (!$_important && @filesize($file) > self::JOURNAL_DAY_MAX) {
            return;
        }
        $line = json_encode($_entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            SiaLog::error('écriture du journal impossible : ' . $file);
        }
    }

    /*
     * Refus répétés (bruit, émetteur non autorisé) : la première occurrence
     * est consignée, les suivantes sont comptées, et le total est consigné une
     * fois par minute et par émetteur.
     */
    private function journalNoise($_peer, $_proto, $_status, $_error, $_raw = '') {
        $key = self::peerIp($_peer) . '|' . $_status;
        $this->flushNoise();
        if (isset($this->noise[$key])) {
            $this->noise[$key][1]++;
            return;
        }
        $this->noise[$key] = array(time(), 0, $_peer, $_proto, $_status);
        $this->journal(array('peer' => $_peer, 'proto' => $_proto, 'status' => $_status, 'error' => $_error,
                             'raw' => AjaxSiaCodec::clean(substr((string) $_raw, 0, self::RAW_MAX))));
        SiaLog::warning('refusé (' . $_error . ') de ' . $_peer);
    }

    /*
     * Écrit le résumé des fenêtres de bruit écoulées (toutes si $_all, à
     * l'arrêt). Appelé à chaque tour de boucle : le résumé ne doit pas
     * attendre le bruit suivant. Daté du début de la fenêtre.
     */
    private function flushNoise($_all = false) {
        $now = time();
        foreach ($this->noise as $k => $n) {
            if ($_all || $now - $n[0] >= self::NOISE_WINDOW) {
                if ($n[1] > 0) {
                    $this->journal(array('t' => $n[0], 'peer' => $n[2], 'proto' => $n[3], 'status' => $n[4],
                                         'error' => $n[1] . ' autre(s) refus du même type en ' . self::NOISE_WINDOW . ' s'));
                }
                unset($this->noise[$k]);
            }
        }
    }

    /* Une fois par heure, efface les journaux plus anciens que la rétention. */
    private function purgeJournal() {
        if (time() - $this->lastPurge < 3600) {
            return;
        }
        $this->lastPurge = time();
        /* N jours conservés, aujourd'hui compris. */
        $limit = date('Y-m-d', time() - 86400 * ($this->config['journal_days'] - 1));
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
            SiaLog::warning('file d\'envoi saturée, le plus ancien événement est abandonné (il reste au journal)');
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
            SiaLog::error('fork impossible pour l\'envoi, lot abandonné (il reste au journal)');
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
        foreach ($this->orders as $order) {
            @fclose($order['stream']);
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

    /*
     * Les connexions d'ordres passent par la boucle principale, comme les
     * autres : une connexion locale muette ne peut plus retenir la réception.
     */
    private function acceptOrders() {
        for ($i = 0; $i < 8; $i++) {
            $conn = @stream_socket_accept($this->control, 0);
            if ($conn === false) {
                return;
            }
            if (count($this->orders) >= 16) {
                @fclose($conn);
                continue;
            }
            stream_set_blocking($conn, false);
            $this->orders[(int) $conn] = array('stream' => $conn, 'buffer' => '', 'opened' => microtime(true));
        }
    }

    private function readOrder($_stream) {
        $id = (int) $_stream;
        $chunk = @fread($_stream, 65535);
        if ($chunk === false || ($chunk === '' && feof($_stream))) {
            @fclose($_stream);
            unset($this->orders[$id]);
            return;
        }
        $this->orders[$id]['buffer'] .= $chunk;
        if (strpos($this->orders[$id]['buffer'], "\n") === false) {
            if (strlen($this->orders[$id]['buffer']) > 65535) {
                @fclose($_stream);
                unset($this->orders[$id]);
            }
            return;
        }
        $result = $this->handleOrder(trim($this->orders[$id]['buffer']));
        @fwrite($_stream, json_encode($result) . "\n");
        @fclose($_stream);
        unset($this->orders[$id]);
    }

    private function expireOrders() {
        foreach ($this->orders as $id => $order) {
            if (microtime(true) - $order['opened'] > 1) {
                @fclose($order['stream']);
                unset($this->orders[$id]);
            }
        }
    }

    private function handleOrder($_line) {
        $order = json_decode($_line, true);
        if (!is_array($order) || !isset($order['apikey']) || !hash_equals($this->opt['apikey'], (string) $order['apikey'])) {
            return array('state' => 'error', 'result' => 'clé API invalide');
        }
        switch (isset($order['cmd']) ? $order['cmd'] : '') {
            case 'reload':
                $this->reloadPending = true;
                return array('state' => 'ok');
            case 'status':
                return array('state' => 'ok', 'result' => $this->stats + array(
                    'port'        => $this->config['port'],
                    'tcp'         => $this->tcpPort,
                    'udp'         => $this->udpPort,
                    'wantUdp'     => $this->config['udp'],
                    'listenError' => $this->listenError,
                    'clients'     => count($this->clients),
                ));
        }
        return array('state' => 'error', 'result' => 'commande inconnue');
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
