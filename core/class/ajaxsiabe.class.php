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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
/* Le codec est partagé avec le démon : dictionnaire des codes et libellés. */
require_once __DIR__ . '/../../resources/ajaxsiabed/AjaxSiaCodec.php';

class ajaxsiabe extends eqLogic {

    const TYPE_HUB  = 'hub';
    const TYPE_ZONE = 'zone';

    /* Un seul rechargement du démon par requête, même si dix équipements sont enregistrés. */
    private static $_reloadScheduled = false;

    /* La clé générale de chiffrement est stockée chiffrée par le coeur. */
    public static $_encryptConfigKey = array('key');

    /* Libellés de la commande « Mode ». Ce sont les valeurs que lit un scénario :
     * ne pas les changer sans migration. */
    public static $_armingLabels = array(
        'disarmed' => 'Désarmé',
        'armed'    => 'Armé',
        'night'    => 'Mode nuit',
        'partial'  => 'Armé partiel',
    );

    /*
     * Commandes d'un hub. 'invert' pour les états dont 1 signale un problème :
     * au repos le widget montre une coche verte, une croix rouge seulement quand
     * il y a lieu de s'inquiéter.
     */
    public static $_hubCommands = array(
        array('logicalId' => 'arming',       'name' => 'Mode',                   'type' => 'info',   'subType' => 'string', 'isHistorized' => 1, 'generic_type' => 'ALARM_MODE'),
        array('logicalId' => 'armed',        'name' => 'Armée',                  'type' => 'info',   'subType' => 'binary', 'isHistorized' => 1, 'generic_type' => 'ALARM_ENABLE_STATE'),
        array('logicalId' => 'arming_by',    'name' => 'Mode changé par',        'type' => 'info',   'subType' => 'string'),
        array('logicalId' => 'alarm',        'name' => 'Alarme',                 'type' => 'info',   'subType' => 'binary', 'isHistorized' => 1, 'generic_type' => 'ALARM_STATE', 'invert' => 1, 'initial' => 0),
        array('logicalId' => 'alarm_type',   'name' => 'Type d\'alarme',         'type' => 'info',   'subType' => 'string'),
        array('logicalId' => 'alarm_zone',   'name' => 'Origine de l\'alarme',   'type' => 'info',   'subType' => 'string'),
        array('logicalId' => 'reset_alarm',  'name' => 'Acquitter l\'alarme',    'type' => 'action', 'subType' => 'other'),
        array('logicalId' => 'tamper',       'name' => 'Sabotage',               'type' => 'info',   'subType' => 'binary', 'isHistorized' => 1, 'generic_type' => 'SABOTAGE', 'invert' => 1, 'initial' => 0),
        array('logicalId' => 'power',        'name' => 'Secteur',                'type' => 'info',   'subType' => 'binary', 'isHistorized' => 1, 'initial' => 1),
        array('logicalId' => 'battery_low',  'name' => 'Batterie faible',        'type' => 'info',   'subType' => 'binary', 'invert' => 1, 'initial' => 0),
        array('logicalId' => 'jamming',      'name' => 'Brouillage',             'type' => 'info',   'subType' => 'binary', 'isHistorized' => 1, 'invert' => 1, 'initial' => 0),
        array('logicalId' => 'link',         'name' => 'Liaison',                'type' => 'info',   'subType' => 'binary', 'isHistorized' => 1),
        array('logicalId' => 'last_contact', 'name' => 'Dernier contact',        'type' => 'info',   'subType' => 'string'),
        array('logicalId' => 'last_event',   'name' => 'Dernier événement',      'type' => 'info',   'subType' => 'string', 'repeat' => 1),
        array('logicalId' => 'last_code',    'name' => 'Dernier code SIA',       'type' => 'info',   'subType' => 'string', 'repeat' => 1, 'isVisible' => 0),
    );

    public static $_zoneCommands = array(
        array('logicalId' => 'alarm',       'name' => 'Alarme',            'type' => 'info', 'subType' => 'binary', 'isHistorized' => 1, 'invert' => 1, 'initial' => 0),
        array('logicalId' => 'tamper',      'name' => 'Sabotage',          'type' => 'info', 'subType' => 'binary', 'isHistorized' => 1, 'generic_type' => 'SABOTAGE', 'invert' => 1, 'initial' => 0),
        array('logicalId' => 'battery_low', 'name' => 'Batterie faible',   'type' => 'info', 'subType' => 'binary', 'invert' => 1, 'initial' => 0),
        array('logicalId' => 'link',        'name' => 'Liaison',           'type' => 'info', 'subType' => 'binary', 'isHistorized' => 1, 'initial' => 1),
        array('logicalId' => 'last_event',  'name' => 'Dernier événement', 'type' => 'info', 'subType' => 'string', 'repeat' => 1),
    );

    /* ====================================================================== DÉMON */

    public static function deamon_info() {
        $return = array(
            'log'        => __CLASS__ . 'd',        // le démon écrit dans log/ajaxsiabed
            'state'      => 'nok',
            /* Toujours lançable : c'est le démon qui découvre les hubs. Il doit
             * écouter avant que le moindre équipement existe. */
            'launchable' => 'ok',
        );
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            if ($pid != '' && @posix_getsid((int) $pid)) {
                $return['state'] = 'ok';
            } else {
                // PID mort : sans ce nettoyage le watchdog ne relancerait jamais le démon.
                @unlink($pid_file);
            }
        }
        $port = (int) config::byKey('port', __CLASS__, 7777);
        if ($port < 1024 || $port > 65535) {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Port de réception invalide (1024 à 65535)', __FILE__);
        }
        return $return;
    }

    public static function deamon_start() {
        self::deamon_stop();

        $deamon_info = self::deamon_info();
        if ($deamon_info['launchable'] != 'ok') {
            throw new Exception(__('Le démon ne peut pas être lancé :', __FILE__) . ' ' . $deamon_info['launchable_message']);
        }
        $daemon = realpath(__DIR__ . '/../../resources/ajaxsiabed/ajaxsiabed.php');
        if ($daemon === false) {
            throw new Exception(__('Démon introuvable dans resources/ajaxsiabed/', __FILE__));
        }

        /* La clé API passe par l'entrée standard, pas par la ligne de commande
         * que n'importe quel utilisateur local lit avec `ps`. */
        $cmd  = 'php ' . escapeshellarg($daemon);
        $cmd .= ' --callback '   . escapeshellarg(self::getCallbackUrl());
        $cmd .= ' --pid '        . escapeshellarg(jeedom::getTmpFolder(__CLASS__) . '/deamon.pid');
        $cmd .= ' --socketport ' . escapeshellarg(config::byKey('socketport', __CLASS__, 55065));
        $cmd .= ' --loglevel '   . escapeshellarg(log::convertLogLevel(log::getLogLevel(__CLASS__)));

        log::add(__CLASS__, 'info', __('Lancement du démon :', __FILE__) . ' ' . $cmd);
        $full = 'echo ' . escapeshellarg(jeedom::getApiKey(__CLASS__)) . ' | ' . $cmd
              . ' >> ' . log::getPathToLog(__CLASS__ . 'd') . ' 2>&1 &';
        exec($full);

        for ($i = 0; $i < 30; $i++) {
            if (self::deamon_info()['state'] == 'ok') {
                message::removeAll(__CLASS__, 'unableStartDeamon');
                return true;
            }
            sleep(1);
        }
        log::add(__CLASS__, 'error', __('Impossible de lancer le démon, consultez le log ajaxsiabed', __FILE__), 'unableStartDeamon');
        message::add(__CLASS__, __('Impossible de lancer le démon, consultez le log ajaxsiabed', __FILE__), '', 'unableStartDeamon');
        return false;
    }

    public static function deamon_stop() {
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = intval(trim(file_get_contents($pid_file)));
            if ($pid > 0) {
                system::kill($pid);
            }
            @unlink($pid_file);
        }
        // Filet de sécurité si le fichier PID a disparu alors que le process tourne.
        system::kill('resources/ajaxsiabed/ajaxsiabed.php');
        system::fuserk(config::byKey('socketport', __CLASS__, 55065));
    }

    public static function getCallbackUrl() {
        return network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp')
             . '/plugins/ajaxsiabe/core/php/jeeAjaxsiabe.php';
    }

    /* Configuration lue par le démon sur son callback (action=config). */
    public static function getDaemonConfig() {
        $hubs = array();
        foreach (self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_HUB)) as $hub) {
            $account = strtoupper(trim((string) $hub->getConfiguration('account')));
            if ($account === '') {
                continue;
            }
            $hubs[$account] = array(
                'id'  => (int) $hub->getId(),
                'key' => trim((string) $hub->getConfiguration('key')),
            );
        }
        return array(
            'timezone'     => config::byKey('timezone', 'core', 'Europe/Brussels'),
            'port'         => (int) config::byKey('port', __CLASS__, 7777),
            'udp'          => (int) config::byKey('udp', __CLASS__, 1),
            'key'          => trim((string) config::byKey('key', __CLASS__, '')),
            'strict_time'  => (int) config::byKey('strict_time', __CLASS__, 1),
            'journal_days' => (int) config::byKey('journal_days', __CLASS__, 90),
            'allowed'      => self::allowedAddresses(),
            'hubs'         => $hubs,
        );
    }

    /* Liste blanche saisie librement : virgules, espaces ou retours à la ligne. */
    public static function allowedAddresses() {
        $raw = (string) config::byKey('allowed', __CLASS__, '');
        $list = array();
        foreach (preg_split('/[\s,;]+/', $raw) as $ip) {
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                $list[] = $ip;
            }
        }
        return $list;
    }

    /* Demande au démon de relire sa configuration, une fois en fin de requête. */
    public static function reloadDaemonConfig() {
        if (self::$_reloadScheduled) {
            return;
        }
        self::$_reloadScheduled = true;
        register_shutdown_function(function () {
            try {
                ajaxsiabe::sendToDaemon(array('cmd' => 'reload'), false);
            } catch (Throwable $e) {
                log::add('ajaxsiabe', 'debug', 'reload démon : ' . $e->getMessage());
            }
        });
    }

    /* Réglages de la page de configuration : le démon les relit sans redémarrer,
     * et rouvre son écoute si le port a changé. */
    public static function postConfig_port($_value)         { self::reloadDaemonConfig(); }
    public static function postConfig_udp($_value)          { self::reloadDaemonConfig(); }
    public static function postConfig_key($_value)          { self::reloadDaemonConfig(); }
    public static function postConfig_strict_time($_value)  { self::reloadDaemonConfig(); }
    public static function postConfig_allowed($_value)      { self::reloadDaemonConfig(); }
    public static function postConfig_journal_days($_value) { self::reloadDaemonConfig(); }

    /* État du démon pour la page : port ouvert, trames reçues, derniers émetteurs. */
    public static function daemonStatus() {
        $answer = self::sendToDaemon(array('cmd' => 'status'));
        if (!is_array($answer) || $answer['state'] != 'ok') {
            return null;
        }
        return $answer['result'];
    }

    public static function sendToDaemon($_payload, $_waitAnswer = true) {
        if (self::deamon_info()['state'] != 'ok') {
            return false;
        }
        $_payload['apikey'] = jeedom::getApiKey(__CLASS__);
        $socket = @stream_socket_client('tcp://127.0.0.1:' . config::byKey('socketport', __CLASS__, 55065), $errno, $errstr, 3);
        if ($socket === false) {
            log::add(__CLASS__, 'error', __('Connexion au démon impossible :', __FILE__) . ' ' . $errstr);
            return false;
        }
        fwrite($socket, json_encode($_payload) . "\n");
        if (!$_waitAnswer) {
            fclose($socket);
            return true;
        }
        stream_set_timeout($socket, 5);
        $response = trim((string) fgets($socket));
        fclose($socket);
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : array('state' => 'error', 'result' => $response);
    }

    /* ========================================================= ÉVÉNEMENTS */

    /*
     * Point d'entrée du callback : un lot de messages reçus par le démon, déjà
     * accusés au hub et consignés au journal. Il ne reste qu'à en tirer les états.
     */
    public static function handleMessages($_messages) {
        foreach ($_messages as $message) {
            try {
                self::handleMessage($message);
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', __('Traitement du message en échec :', __FILE__) . ' '
                       . $e->getMessage() . ' — ' . json_encode($message));
            }
        }
    }

    private static function handleMessage($_message) {
        $account = strtoupper(trim((string) (isset($_message['account']) ? $_message['account'] : '')));
        if ($account === '') {
            log::add(__CLASS__, 'debug', __('Message sans numéro de compte ignoré', __FILE__));
            return;
        }
        $hub = self::hubByAccount($account);
        if (!is_object($hub)) {
            if (config::byKey('autocreate', __CLASS__, 1) != 1) {
                log::add(__CLASS__, 'info', __('Message d\'un compte inconnu, non créé (création automatique désactivée) :', __FILE__) . ' ' . $account);
                return;
            }
            $hub = self::createHub($account);
        }
        if ($hub->getIsEnable() != 1) {
            return;
        }
        $time = isset($_message['t']) ? (int) $_message['t'] : time();
        $hub->touchContact($time, (isset($_message['type']) ? $_message['type'] : '') === 'NULL');

        foreach ((isset($_message['events']) && is_array($_message['events'])) ? $_message['events'] : array() as $event) {
            $hub->applyEvent($event);
        }
    }

    public static function hubByAccount($_account) {
        foreach (self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_HUB)) as $hub) {
            if (strtoupper(trim((string) $hub->getConfiguration('account'))) === $_account) {
                return $hub;
            }
        }
        return null;
    }

    private static function createHub($_account) {
        $hub = new ajaxsiabe();
        $hub->setEqType_name(__CLASS__);
        $hub->setName(self::freeName('Hub Ajax ' . $_account, null));
        $hub->setConfiguration('type', self::TYPE_HUB);
        $hub->setConfiguration('account', $_account);
        $hub->setConfiguration('autozone', 1);
        $hub->setIsEnable(1);
        $hub->setIsVisible(1);
        $hub->save();
        log::add(__CLASS__, 'info', __('Nouveau hub découvert, équipement créé :', __FILE__) . ' ' . $hub->getHumanName());
        return $hub;
    }

    /*
     * Un nom libre pour un objet donné : le coeur refuse deux équipements de
     * même nom sous le même objet, et l'enregistrement échouerait.
     */
    private static function freeName($_name, $_objectId) {
        $taken = array();
        foreach (eqLogic::byType(__CLASS__) as $eqLogic) {
            if ($eqLogic->getObject_id() == $_objectId) {
                $taken[mb_strtolower($eqLogic->getName())] = true;
            }
        }
        $name = $_name;
        for ($i = 2; isset($taken[mb_strtolower($name)]); $i++) {
            $name = $_name . ' (' . $i . ')';
        }
        return $name;
    }

    /* ---------------------------------------------------------- zones */

    public function zones() {
        $zones = array();
        foreach (self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_ZONE)) as $zone) {
            if ((int) $zone->getConfiguration('hub_id') == (int) $this->getId()) {
                $zones[(int) $zone->getConfiguration('zone')] = $zone;
            }
        }
        return $zones;
    }

    public function zone($_number, $_create = false) {
        $zones = $this->zones();
        if (isset($zones[$_number])) {
            return $zones[$_number];
        }
        if (!$_create || $this->getConfiguration('autozone', 1) != 1) {
            return null;
        }
        $zone = new ajaxsiabe();
        $zone->setEqType_name(__CLASS__);
        $zone->setObject_id($this->getObject_id());
        $zone->setName(self::freeName(__('Zone', __FILE__) . ' ' . $_number, $this->getObject_id()));
        $zone->setConfiguration('type', self::TYPE_ZONE);
        $zone->setConfiguration('hub_id', $this->getId());
        $zone->setConfiguration('zone', $_number);
        $zone->setIsEnable(1);
        $zone->setIsVisible(1);
        $zone->save();
        log::add(__CLASS__, 'info', __('Nouvel appareil vu, équipement créé :', __FILE__) . ' ' . $zone->getHumanName()
               . ' — ' . __('renommez-le d\'après l\'appareil Ajax portant ce numéro', __FILE__));
        return $zone;
    }

    /* ------------------------------------------------- noms lisibles */

    /* « 5=Jérôme », une ligne par utilisateur (ou par groupe). */
    private function numberedNames($_key) {
        $names = array();
        foreach (preg_split('/\r?\n/', (string) $this->getConfiguration($_key)) as $line) {
            if (preg_match('/^\s*0*(\d+)\s*[=:]\s*(.+?)\s*$/', $line, $m)) {
                $names[(int) $m[1]] = $m[2];
            }
        }
        return $names;
    }

    public function userName($_number) {
        $number = (int) $_number;
        $users = $this->numberedNames('users');
        if (isset($users[$number])) {
            return $users[$number];
        }
        return ($number == 0) ? __('le système', __FILE__) : __('utilisateur', __FILE__) . ' ' . $number;
    }

    public function zoneName($_number) {
        $zone = $this->zone((int) $_number);
        return is_object($zone) ? $zone->getName() : __('zone', __FILE__) . ' ' . (int) $_number;
    }

    public function groupName($_ri) {
        $groups = $this->numberedNames('groups');
        return isset($groups[(int) $_ri]) ? $groups[(int) $_ri] : '';
    }

    /*
     * Ce que le numéro d'un événement désigne, selon la nature du code : zone
     * (l'appareil), utilisateur ou groupe. Le modificateur « id » l'emporte
     * quand il est présent : il nomme l'utilisateur sans ambiguïté.
     */
    public function eventSubject($_event) {
        $info = AjaxSiaCodec::describe(isset($_event['code']) ? $_event['code'] : '');
        $addr = isset($_event['addr']) ? trim($_event['addr']) : '';
        $subject = array('zone' => 0, 'user' => null, 'who' => '');
        if (isset($_event['id']) && $_event['id'] !== '') {
            $subject['user'] = (int) $_event['id'];
        } elseif ($info['a'] == 'u' && $addr !== '' && ctype_digit($addr)) {
            $subject['user'] = (int) $addr;
        }
        if ($info['a'] == 'z' && $addr !== '' && ctype_digit($addr)) {
            $subject['zone'] = (int) $addr;
        }
        if ($subject['user'] !== null) {
            $subject['who'] = $this->userName($subject['user']);
        } elseif ($subject['zone'] > 0) {
            $subject['who'] = $this->zoneName($subject['zone']);
        }
        return $subject;
    }

    /* Phrase affichée : « Armement par Jérôme », « Alarme intrusion — Porte d'entrée ». */
    public function describeEvent($_event) {
        $code = isset($_event['code']) ? $_event['code'] : '';
        $info = AjaxSiaCodec::describe($code);
        $subject = $this->eventSubject($_event);
        $text = __($info['l'], __FILE__);
        if ($subject['user'] !== null) {
            $text .= ' ' . __('par', __FILE__) . ' ' . $subject['who'];
        } elseif ($subject['who'] !== '') {
            $text .= ' — ' . $subject['who'];
        }
        $group = $this->groupName(isset($_event['ri']) ? $_event['ri'] : '');
        if ($group !== '') {
            $text .= ' (' . $group . ')';
        }
        if (isset($_event['text']) && trim($_event['text']) !== '') {
            $text .= ' « ' . trim($_event['text']) . ' »';
        }
        return $text;
    }

    /* ------------------------------------------------- états persistants */

    /*
     * Les conditions en cours, par zone : alarmes, sabotages, batteries faibles,
     * liaisons perdues. Le hub n'en montre que la synthèse (« au moins une »),
     * qu'on ne peut pas reconstruire à partir de la seule dernière commande :
     * un sabotage rétabli sur une zone ne dit rien des autres.
     */
    private function loadState() {
        $state = cache::byKey('ajaxsiabe::state::' . $this->getId())->getValue(null);
        $state = is_array($state) ? $state : array();
        foreach (array('alarms', 'tamper', 'battery', 'link') as $key) {
            if (!isset($state[$key]) || !is_array($state[$key])) {
                $state[$key] = array();
            }
        }
        return $state;
    }

    private function saveState($_state) {
        cache::set('ajaxsiabe::state::' . $this->getId(), $_state);
    }

    /* ------------------------------------------------ application */

    /* Tout message, test de liaison compris, prouve que le hub est vivant. */
    public function touchContact($_time, $_isNull = false) {
        $previous = (int) cache::byKey('ajaxsiabe::contact::' . $this->getId())->getValue(0);
        cache::set('ajaxsiabe::contact::' . $this->getId(), $_time);
        if ($_isNull) {
            /* Intervalle observé entre deux tests de liaison : c'est lui qui règle
             * la supervision automatique, sans rien demander à l'utilisateur. */
            $lastNull = (int) cache::byKey('ajaxsiabe::lastnull::' . $this->getId())->getValue(0);
            if ($lastNull > 0 && $_time > $lastNull) {
                cache::set('ajaxsiabe::nullinterval::' . $this->getId(), $_time - $lastNull);
            }
            cache::set('ajaxsiabe::lastnull::' . $this->getId(), $_time);
        }
        $this->checkAndUpdateCmd('last_contact', date('Y-m-d H:i:s', $_time));
        $link = $this->getCmd('info', 'link');
        if (is_object($link) && $link->execCmd() !== '' && (int) $link->execCmd() === 0 && $previous > 0) {
            log::add(__CLASS__, 'info', $this->getHumanName() . ' ' . __('liaison rétablie', __FILE__));
        }
        $this->checkAndUpdateCmd('link', 1);
    }

    public function applyEvent($_event) {
        $code = isset($_event['code']) ? (string) $_event['code'] : '';
        if ($code === '') {
            return;
        }
        $info = AjaxSiaCodec::describe($code);
        $effects = isset($info['e']) ? $info['e'] : array();
        $subject = $this->eventSubject($_event);
        $zoneNumber = $subject['zone'];
        /* Seul un événement rattaché à un appareil crée sa zone : un armement par
         * l'utilisateur 5 ne doit pas faire apparaître une « Zone 5 ». */
        $zone = ($zoneNumber > 0) ? $this->zone($zoneNumber, true) : null;
        $label = $this->describeEvent($_event);
        $state = $this->loadState();

        log::add(__CLASS__, 'info', $this->getHumanName() . ' ' . $code . ' : ' . $label);

        if (isset($effects['arm'])) {
            $mode = $effects['arm'];
            $this->checkAndUpdateCmd('arming', __(self::$_armingLabels[$mode], __FILE__));
            $this->checkAndUpdateCmd('armed', ($mode == 'disarmed') ? 0 : 1);
            if ($subject['who'] !== '') {
                $this->checkAndUpdateCmd('arming_by', $subject['who']);
            }
            if ($mode == 'disarmed') {
                $this->clearAlarms($state);
            }
        }

        if (isset($effects['alarm'])) {
            $type = __($effects['alarm'], __FILE__);
            $state['alarms'][$zoneNumber] = array('type' => $type, 'latch' => !empty($effects['latch']) ? 1 : 0);
            $this->checkAndUpdateCmd('alarm_type', $type);
            $this->checkAndUpdateCmd('alarm_zone', ($zoneNumber > 0) ? $this->zoneName($zoneNumber) : $this->getName());
            $this->checkAndUpdateCmd('alarm', 1);
            if (is_object($zone)) {
                $zone->checkAndUpdateCmd('alarm', 1);
            }
        }

        if (!empty($effects['restore'])) {
            if (is_object($zone)) {
                $zone->checkAndUpdateCmd('alarm', 0);
            }
            /* Une alarme d'intrusion reste en mémoire : que la porte se referme ne
             * dit pas que l'intrus est reparti. Les alarmes techniques (eau, feu,
             * gaz…) retombent avec leur détecteur. */
            if (isset($state['alarms'][$zoneNumber]) && empty($state['alarms'][$zoneNumber]['latch'])) {
                unset($state['alarms'][$zoneNumber]);
                if (empty($state['alarms'])) {
                    $this->clearAlarms($state);
                }
            }
        }

        if (!empty($effects['cancel'])) {
            $this->clearAlarms($state);
        }

        foreach (array('tamper' => 'tamper', 'battery' => 'battery_low') as $effect => $logicalId) {
            if (!isset($effects[$effect])) {
                continue;
            }
            if ($effects[$effect]) {
                $state[$effect][$zoneNumber] = 1;
            } else {
                unset($state[$effect][$zoneNumber]);
            }
            if (is_object($zone)) {
                $zone->checkAndUpdateCmd($logicalId, $effects[$effect] ? 1 : 0);
            }
            $this->checkAndUpdateCmd($logicalId, empty($state[$effect]) ? 0 : 1);
        }

        if (isset($effects['link']) && $zoneNumber > 0) {
            if ($effects['link']) {
                unset($state['link'][$zoneNumber]);
            } else {
                $state['link'][$zoneNumber] = 1;
            }
            if (is_object($zone)) {
                $zone->checkAndUpdateCmd('link', $effects['link'] ? 1 : 0);
            }
        }
        if (isset($effects['power'])) {
            $this->checkAndUpdateCmd('power', $effects['power'] ? 1 : 0);
        }
        if (isset($effects['jamming'])) {
            $this->checkAndUpdateCmd('jamming', $effects['jamming'] ? 1 : 0);
        }

        $this->saveState($state);
        $this->checkAndUpdateCmd('last_code', $code);
        $this->checkAndUpdateCmd('last_event', $label);
        if (is_object($zone)) {
            $zone->checkAndUpdateCmd('last_event', $label);
        }
    }

    /* Fin de toutes les alarmes : désarmement, annulation ou acquittement. */
    private function clearAlarms(&$_state) {
        $zones = $this->zones();
        foreach (array_keys($_state['alarms']) as $number) {
            if (isset($zones[$number])) {
                $zones[$number]->checkAndUpdateCmd('alarm', 0);
            }
        }
        $_state['alarms'] = array();
        $this->checkAndUpdateCmd('alarm', 0);
        $this->checkAndUpdateCmd('alarm_type', '');
        $this->checkAndUpdateCmd('alarm_zone', '');
    }

    public function resetAlarm() {
        $state = $this->loadState();
        $this->clearAlarms($state);
        $this->saveState($state);
        log::add(__CLASS__, 'info', $this->getHumanName() . ' ' . __('alarme acquittée depuis Jeedom', __FILE__));
    }

    /* ------------------------------------------------------ supervision */

    /*
     * Délai au-delà duquel un hub muet est déclaré perdu, en secondes. Réglé à
     * la main sur le hub, sinon déduit de l'intervalle observé entre deux tests
     * de liaison. 0 : pas encore assez d'observations, pas de supervision.
     */
    public function supervisionDelay() {
        $manual = (int) $this->getConfiguration('supervision', 0);
        if ($manual > 0) {
            return $manual * 60;
        }
        $interval = (int) cache::byKey('ajaxsiabe::nullinterval::' . $this->getId())->getValue(0);
        if ($interval <= 0) {
            return 0;
        }
        /* Deux tests et demi manqués, et jamais moins de trois minutes : un
         * message peut arriver en retard sans que la liaison soit en cause. */
        return max(180, (int) round($interval * 2.5) + 30);
    }

    public static function cron() {
        foreach (self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_HUB), true) as $hub) {
            try {
                $delay = $hub->supervisionDelay();
                $last = (int) cache::byKey('ajaxsiabe::contact::' . $hub->getId())->getValue(0);
                if ($delay <= 0 || $last <= 0 || time() - $last <= $delay) {
                    continue;
                }
                $link = $hub->getCmd('info', 'link');
                if (is_object($link) && (int) $link->execCmd() === 1) {
                    log::add(__CLASS__, 'warning', $hub->getHumanName() . ' ' . __('muet depuis', __FILE__) . ' '
                           . round((time() - $last) / 60) . ' min : ' . __('liaison déclarée perdue', __FILE__));
                    $hub->checkAndUpdateCmd('link', 0);
                }
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', __('Supervision en échec :', __FILE__) . ' ' . $e->getMessage());
            }
        }
    }

    /* =========================================================== JOURNAL */

    public static function journalDir() {
        return __DIR__ . '/../../data/journal';
    }

    /* Jours disponibles, du plus récent au plus ancien. */
    public static function journalDates() {
        $dates = array();
        foreach ((array) glob(self::journalDir() . '/*.jsonl') as $file) {
            $date = basename($file, '.jsonl');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $dates[] = $date;
            }
        }
        rsort($dates);
        return $dates;
    }

    /*
     * Les trames d'une journée, de la plus récente à la plus ancienne, avec les
     * noms résolus au moment de la lecture : renommer une zone éclaire aussi
     * les événements passés.
     *
     * Filtres : 'account', 'tests' (0 masque tests de liaison et tests
     * périodiques), 'problems' (1 ne garde que les refus), 'search', 'limit'.
     */
    public static function readJournal($_date, $_filters = array()) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $_date)) {
            throw new Exception(__('Date invalide', __FILE__));
        }
        $file = self::journalDir() . '/' . $_date . '.jsonl';
        $result = array('date' => $_date, 'entries' => array(), 'total' => 0, 'truncated' => false);
        if (!is_readable($file)) {
            return $result;
        }
        $limit = isset($_filters['limit']) ? max(1, min(5000, (int) $_filters['limit'])) : 1000;
        $account = isset($_filters['account']) ? strtoupper(trim($_filters['account'])) : '';
        $tests = !isset($_filters['tests']) || $_filters['tests'];
        $problems = !empty($_filters['problems']);
        $search = isset($_filters['search']) ? mb_strtolower(trim($_filters['search'])) : '';

        $hubs = array();
        foreach (self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_HUB)) as $hub) {
            $hubs[strtoupper((string) $hub->getConfiguration('account'))] = $hub;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $entry = json_decode($lines[$i], true);
            if (!is_array($entry)) {
                continue;
            }
            $entry += array('status' => '', 'type' => '', 'account' => '', 'events' => array(), 'error' => '');
            if ($account !== '' && $entry['account'] !== $account) {
                continue;
            }
            if ($problems && in_array($entry['status'], array('ok', 'duplicate'))) {
                continue;
            }
            if (!$tests && $entry['status'] == 'ok' && self::isTestOnly($entry)) {
                continue;
            }
            $hub = isset($hubs[$entry['account']]) ? $hubs[$entry['account']] : null;
            $entry['hub'] = is_object($hub) ? $hub->getName() : '';
            $texts = array();
            foreach ($entry['events'] as $event) {
                $texts[] = is_object($hub) ? $hub->describeEvent($event) : (isset($event['label']) ? $event['label'] : $event['code']);
            }
            if ($entry['type'] == 'NULL') {
                $texts[] = __('Test de liaison', __FILE__);
            }
            $entry['text'] = implode(' · ', $texts);
            if ($search !== '' && mb_strpos(mb_strtolower($entry['text'] . ' ' . (isset($entry['data']) ? $entry['data'] : '') . ' ' . $entry['hub'] . ' ' . $entry['account']), $search) === false) {
                continue;
            }
            $result['total']++;
            if (count($result['entries']) < $limit) {
                $result['entries'][] = $entry;
            } else {
                $result['truncated'] = true;
            }
        }
        return $result;
    }

    /* Message qui ne porte qu'un test de liaison ou un test périodique. */
    private static function isTestOnly($_entry) {
        if ($_entry['type'] == 'NULL') {
            return true;
        }
        if (empty($_entry['events'])) {
            return false;
        }
        foreach ($_entry['events'] as $event) {
            $info = AjaxSiaCodec::describe(isset($event['code']) ? $event['code'] : '');
            if (empty($info['e']['test'])) {
                return false;
            }
        }
        return true;
    }

    /* ================================================== CYCLE DE VIE */

    public function preSave() {
        if (!in_array($this->getConfiguration('type'), array(self::TYPE_HUB, self::TYPE_ZONE))) {
            $this->setConfiguration('type', self::TYPE_HUB);
        }
        if ($this->getConfiguration('type') == self::TYPE_HUB) {
            $account = strtoupper(trim((string) $this->getConfiguration('account')));
            if ($account !== '' && !preg_match('/^[0-9A-F]{1,16}$/', $account)) {
                throw new Exception(__('Numéro de compte invalide : 1 à 16 caractères hexadécimaux (0-9, A-F)', __FILE__));
            }
            $this->setConfiguration('account', $account);
            $key = trim((string) $this->getConfiguration('key'));
            if ($key !== '' && !in_array(strlen($key), array(16, 24, 32))) {
                throw new Exception(__('La clé de chiffrement doit compter 16, 24 ou 32 caractères', __FILE__));
            }
            $this->setConfiguration('key', $key);
            if ($account !== '') {
                $other = self::hubByAccount($account);
                if (is_object($other) && $other->getId() != $this->getId()) {
                    throw new Exception(__('Un autre hub utilise déjà ce numéro de compte :', __FILE__) . ' ' . $other->getHumanName());
                }
            }
            $this->setLogicalId($account);
        } else {
            $this->setLogicalId('zone::' . (int) $this->getConfiguration('hub_id') . '::' . (int) $this->getConfiguration('zone'));
        }
    }

    public function postSave() {
        $definitions = ($this->getConfiguration('type') == self::TYPE_ZONE) ? self::$_zoneCommands : self::$_hubCommands;
        $order = 0;
        foreach ($definitions as $definition) {
            $this->addCmdIfMissing($definition, $order++);
        }
        if ($this->getConfiguration('type') == self::TYPE_HUB) {
            self::reloadDaemonConfig();
        }
    }

    /* Les appareils n'existent que par leur hub : ils partent avec lui. */
    public function preRemove() {
        if ($this->getConfiguration('type') == self::TYPE_HUB) {
            foreach ($this->zones() as $zone) {
                $zone->remove();
            }
        }
    }

    public function postRemove() {
        cache::delete('ajaxsiabe::state::' . $this->getId());
        cache::delete('ajaxsiabe::contact::' . $this->getId());
        cache::delete('ajaxsiabe::lastnull::' . $this->getId());
        cache::delete('ajaxsiabe::nullinterval::' . $this->getId());
        self::reloadDaemonConfig();
    }

    /* Crée une commande manquante sans jamais écraser la personnalisation. */
    private function addCmdIfMissing($_definition, $_order) {
        $cmd = $this->getCmd(null, $_definition['logicalId']);
        if (is_object($cmd)) {
            return $cmd;
        }
        $cmd = new ajaxsiabeCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($_definition['logicalId']);
        /* Le couple (équipement, nom) est unique en base : un nom déjà pris par
         * une commande de l'utilisateur ferait échouer tout l'enregistrement. */
        $name = __($_definition['name'], __FILE__);
        if (is_object(cmd::byEqLogicIdCmdName($this->getId(), $name))) {
            $name .= ' (' . $_definition['logicalId'] . ')';
        }
        $cmd->setName($name);
        $cmd->setType($_definition['type']);
        $cmd->setSubType($_definition['subType']);
        $cmd->setOrder($_order);
        $cmd->setIsVisible(isset($_definition['isVisible']) ? $_definition['isVisible'] : 1);
        $cmd->setIsHistorized(isset($_definition['isHistorized']) ? $_definition['isHistorized'] : 0);
        if (isset($_definition['generic_type'])) {
            $cmd->setGeneric_type($_definition['generic_type']);
        }
        if (!empty($_definition['invert'])) {
            $cmd->setDisplay('invertBinary', 1);
        }
        /* Deux armements de suite par la même personne sont deux événements : un
         * scénario déclenché sur cette commande doit voir le second. */
        if (!empty($_definition['repeat'])) {
            $cmd->setConfiguration('repeatEventManagement', 'always');
        }
        $cmd->save();
        /* Valeur de départ des états : sans elle, un hub tout juste découvert
         * afficherait « sabotage » ou « secteur coupé » faute de valeur. */
        if (isset($_definition['initial'])) {
            $this->checkAndUpdateCmd($cmd, $_definition['initial']);
        }
        return $cmd;
    }
}

class ajaxsiabeCmd extends cmd {

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) {
            return;
        }
        if ($this->getLogicalId() == 'reset_alarm') {
            $eqLogic->resetAlarm();
        }
    }
}
