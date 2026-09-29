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

    /* Numéros d'appareil admis : au-delà, ce n'est pas un appareil Ajax mais
     * une trame fabriquée, qui ferait naître des équipements à volonté. */
    const ZONE_MAX = 999;

    /* Hubs créés automatiquement, au plus : un émetteur qui inventerait des
     * numéros de compte ne doit pas pouvoir remplir Jeedom d'équipements. */
    const AUTO_HUBS_MAX = 5;

    /* Un seul rechargement du démon par requête, même si dix équipements sont enregistrés. */
    private static $_reloadScheduled = false;

    /* Zones de chaque hub, lues une fois par requête. */
    private static $_zoneCache = array();

    /* La clé générale de chiffrement est stockée chiffrée par le coeur. Celle
     * de chaque hub l'est par preSave(), le coeur ne chiffrant pas la
     * configuration des équipements. */
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
     * il y a lieu de s'inquiéter. 'repeat' : chaque nouvel événement déclenche
     * les scénarios, même si la valeur ne change pas.
     */
    public static $_hubCommands = array(
        array('logicalId' => 'arming',       'name' => 'Mode',                   'type' => 'info',   'subType' => 'string', 'isHistorized' => 1, 'generic_type' => 'ALARM_MODE'),
        array('logicalId' => 'armed',        'name' => 'Armée',                  'type' => 'info',   'subType' => 'binary', 'isHistorized' => 1, 'generic_type' => 'ALARM_ENABLE_STATE'),
        array('logicalId' => 'arming_by',    'name' => 'Mode changé par',        'type' => 'info',   'subType' => 'string'),
        array('logicalId' => 'alarm',        'name' => 'Alarme',                 'type' => 'info',   'subType' => 'binary', 'isHistorized' => 1, 'generic_type' => 'ALARM_STATE', 'invert' => 1, 'initial' => 0, 'repeat' => 1),
        array('logicalId' => 'alarm_type',   'name' => 'Type d’alarme',          'type' => 'info',   'subType' => 'string'),
        array('logicalId' => 'alarm_zone',   'name' => 'Origine de l’alarme',    'type' => 'info',   'subType' => 'string'),
        array('logicalId' => 'alarm_intrusion', 'name' => 'Alarme intrusion',    'type' => 'info',   'subType' => 'binary', 'isHistorized' => 1, 'invert' => 1, 'initial' => 0, 'isVisible' => 0),
        array('logicalId' => 'alarm_fire',   'name' => 'Alarme incendie',        'type' => 'info',   'subType' => 'binary', 'isHistorized' => 1, 'generic_type' => 'SMOKE', 'invert' => 1, 'initial' => 0, 'isVisible' => 0),
        array('logicalId' => 'alarm_water',  'name' => 'Alarme inondation',      'type' => 'info',   'subType' => 'binary', 'isHistorized' => 1, 'generic_type' => 'FLOOD', 'invert' => 1, 'initial' => 0, 'isVisible' => 0),
        array('logicalId' => 'alarm_gas',    'name' => 'Alarme gaz',             'type' => 'info',   'subType' => 'binary', 'isHistorized' => 1, 'invert' => 1, 'initial' => 0, 'isVisible' => 0),
        array('logicalId' => 'alarm_panic',  'name' => 'Alarme panique',         'type' => 'info',   'subType' => 'binary', 'isHistorized' => 1, 'invert' => 1, 'initial' => 0, 'isVisible' => 0),
        array('logicalId' => 'reset_alarm',  'name' => 'Acquitter l’alarme',     'type' => 'action', 'subType' => 'other'),
        array('logicalId' => 'tamper',       'name' => 'Sabotage',               'type' => 'info',   'subType' => 'binary', 'isHistorized' => 1, 'generic_type' => 'SABOTAGE', 'invert' => 1, 'initial' => 0),
        array('logicalId' => 'power',        'name' => 'Secteur',                'type' => 'info',   'subType' => 'binary', 'isHistorized' => 1, 'initial' => 1),
        array('logicalId' => 'battery_low',  'name' => 'Batterie faible',        'type' => 'info',   'subType' => 'binary', 'invert' => 1, 'initial' => 0),
        array('logicalId' => 'jamming',      'name' => 'Brouillage',             'type' => 'info',   'subType' => 'binary', 'isHistorized' => 1, 'invert' => 1, 'initial' => 0),
        array('logicalId' => 'link',         'name' => 'Liaison',                'type' => 'info',   'subType' => 'binary', 'isHistorized' => 1),
        array('logicalId' => 'last_contact', 'name' => 'Dernier contact',        'type' => 'info',   'subType' => 'string'),
        array('logicalId' => 'last_user',    'name' => 'Dernier utilisateur',    'type' => 'info',   'subType' => 'string', 'isVisible' => 0),
        array('logicalId' => 'last_zone',    'name' => 'Dernière zone',          'type' => 'info',   'subType' => 'string', 'isVisible' => 0),
        array('logicalId' => 'last_category', 'name' => 'Catégorie du dernier événement', 'type' => 'info', 'subType' => 'string', 'repeat' => 1, 'isVisible' => 0),
        array('logicalId' => 'last_event',   'name' => 'Dernier événement',      'type' => 'info',   'subType' => 'string', 'repeat' => 1),
        array('logicalId' => 'last_code',    'name' => 'Dernier code SIA',       'type' => 'info',   'subType' => 'string', 'repeat' => 1, 'isVisible' => 0),
    );

    /*
     * Une commande binaire par famille d'alarme : un scénario « incendie →
     * couper la VMC » se déclenche sur elle, sans comparer de texte. Elles
     * suivent les alarmes en cours, mémoire d'intrusion comprise.
     */
    public static $_alarmFamilies = array(
        'alarm_intrusion' => array('Intrusion'),
        'alarm_fire'      => array('Incendie', 'Chaleur', 'Sprinkler'),
        'alarm_water'     => array('Inondation', 'Gel'),
        'alarm_gas'       => array('Gaz'),
        'alarm_panic'     => array('Panique', 'Agression', 'Contrainte', 'Médicale', 'Urgence'),
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

        $cmd  = 'php ' . escapeshellarg($daemon);
        $cmd .= ' --callback '   . escapeshellarg(self::getCallbackUrl());
        $cmd .= ' --pid '        . escapeshellarg(jeedom::getTmpFolder(__CLASS__) . '/deamon.pid');
        $cmd .= ' --socketport ' . escapeshellarg(config::byKey('socketport', __CLASS__, 55065));
        $cmd .= ' --loglevel '   . escapeshellarg(log::convertLogLevel(log::getLogLevel(__CLASS__)));
        log::add(__CLASS__, 'info', __('Lancement du démon :', __FILE__) . ' ' . $cmd);

        /*
         * La clé API est écrite sur l'entrée standard du démon, par un tube : elle
         * n'apparaît dans aucune ligne de commande, pas même celle du shell qui
         * le lance, que tout utilisateur local lirait avec `ps`. Le démon part en
         * arrière-plan en gardant le tube ; le shell rend la main aussitôt.
         *
         * Le détour par le descripteur 3 est indispensable : un shell non
         * interactif (dash) branche l'entrée d'une commande lancée avec « & »
         * sur /dev/null, et le démon ne recevrait jamais la clé.
         */
        $process = proc_open(array('sh', '-c', 'exec 3<&0; ' . $cmd . ' <&3 3<&- >> '
                                   . escapeshellarg(log::getPathToLog(__CLASS__ . 'd')) . ' 2>&1 &'),
                             array(0 => array('pipe', 'r')), $pipes);
        if (!is_resource($process)) {
            throw new Exception(__('Lancement du démon impossible', __FILE__));
        }
        fwrite($pipes[0], jeedom::getApiKey(__CLASS__) . "\n");
        fclose($pipes[0]);
        proc_close($process);
        cache::set('ajaxsiabe::daemonStart', time());

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

    /* Le journal SIA peut peser des dizaines de Mo : il n'a pas sa place dans
     * chaque sauvegarde de Jeedom. */
    public static function backupExclude() {
        return array('data/journal');
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
                'key' => $hub->hubKey(),
            );
        }
        return array(
            'timezone'     => config::byKey('timezone', 'core', 'Europe/Brussels'),
            'port'         => (int) config::byKey('port', __CLASS__, 7777),
            'udp'          => (int) config::byKey('udp', __CLASS__, 0),
            'key'          => self::generalKey(),
            'strict_time'  => (int) config::byKey('strict_time', __CLASS__, 1),
            'journal_days' => (int) config::byKey('journal_days', __CLASS__, 90),
            'allowed'      => self::allowedAddresses(),
            'hubs'         => $hubs,
        );
    }

    public static function generalKey() {
        return trim((string) config::byKey('key', __CLASS__, ''));
    }

    /* Clé propre au hub, déchiffrée. */
    public function hubKey() {
        $key = (string) $this->getConfiguration('key');
        return ($key === '') ? '' : trim((string) utils::decrypt($key));
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
     * et déplace son écoute si le port a changé. */
    public static function preConfig_port($_value) {
        $port = (int) $_value;
        if ($port < 1024 || $port > 65535) {
            throw new Exception(__('Port de réception invalide : de 1024 à 65535', __FILE__));
        }
        return $port;
    }

    public static function preConfig_key($_value) {
        $key = trim((string) $_value);
        if ($key !== '' && strpos($key, 'crypt:') === false && !in_array(strlen($key), array(16, 24, 32))) {
            throw new Exception(__('La clé de chiffrement doit compter 16, 24 ou 32 caractères', __FILE__));
        }
        return $key;
    }

    public static function postConfig_port($_value)         { self::reloadDaemonConfig(); }
    public static function postConfig_udp($_value)          { self::reloadDaemonConfig(); }
    public static function postConfig_key($_value)          { self::reloadDaemonConfig(); }
    public static function postConfig_strict_time($_value)  { self::reloadDaemonConfig(); }
    public static function postConfig_allowed($_value)      { self::reloadDaemonConfig(); }
    public static function postConfig_journal_days($_value) { self::reloadDaemonConfig(); }

    /* État du démon pour la page : ports réellement ouverts, trames reçues. */
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
        /*
         * Garde-fou : chaque message porte un identifiant unique, et un message
         * déjà vu est ignoré, sans quoi une alarme déclencherait deux fois les
         * scénarios. Le démon ne renvoie un lot que si Jeedom n'a pas pu être
         * joint du tout, mais un mandataire ou une évolution du démon pourrait
         * le rejouer.
         */
        $seen = cache::byKey('ajaxsiabe::seen')->getValue(array());
        $seen = is_array($seen) ? $seen : array();
        foreach ($_messages as $message) {
            $uid = isset($message['uid']) ? (string) $message['uid'] : '';
            if ($uid !== '' && isset($seen[$uid])) {
                log::add(__CLASS__, 'debug', __('Message déjà traité, ignoré :', __FILE__) . ' ' . $uid);
                continue;
            }
            if ($uid !== '') {
                $seen[$uid] = time();
            }
            try {
                self::handleMessage($message);
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', __('Traitement du message en échec :', __FILE__) . ' '
                       . $e->getMessage() . ' — ' . json_encode($message));
            }
        }
        if (count($seen) > 500) {
            arsort($seen);
            $seen = array_slice($seen, 0, 500, true);
        }
        cache::set('ajaxsiabe::seen', $seen);
    }

    private static function handleMessage($_message) {
        $account = strtoupper(trim((string) (isset($_message['account']) ? $_message['account'] : '')));
        if ($account === '') {
            log::add(__CLASS__, 'debug', __('Message sans numéro de compte ignoré', __FILE__));
            return;
        }
        $encrypted = !empty($_message['enc']);
        $hub = self::hubByAccount($account);

        /* Le démon refuse déjà le clair quand une clé est connue ; on le vérifie
         * encore ici, parce que c'est ici qu'un désarmement prend effet. */
        if (!$encrypted && (self::generalKey() !== '' || (is_object($hub) && $hub->hubKey() !== ''))) {
            log::add(__CLASS__, 'warning', __('Message en clair ignoré pour un compte chiffré :', __FILE__) . ' ' . $account);
            return;
        }

        if (!is_object($hub)) {
            if (config::byKey('autocreate', __CLASS__, 1) != 1) {
                log::add(__CLASS__, 'info', __('Message d\'un compte inconnu, non créé (création automatique désactivée) :', __FILE__) . ' ' . $account);
                return;
            }
            $auto = count(self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_HUB, 'autocreated' => 1)));
            if ($auto >= self::AUTO_HUBS_MAX) {
                log::add(__CLASS__, 'warning', __('Compte inconnu non créé : déjà', __FILE__) . ' ' . self::AUTO_HUBS_MAX . ' '
                       . __('hubs créés automatiquement. Créez-le à la main s\'il est légitime :', __FILE__) . ' ' . $account);
                return;
            }
            $hub = self::createHub($account);
        }
        if ($hub->getIsEnable() != 1) {
            log::add(__CLASS__, 'debug', $hub->getHumanName() . ' ' . __('désactivé : message ignoré', __FILE__));
            return;
        }
        $time = isset($_message['t']) ? (int) $_message['t'] : time();
        $type = isset($_message['type']) ? $_message['type'] : '';
        $events = (isset($_message['events']) && is_array($_message['events'])) ? $_message['events'] : array();
        $hub->touchContact($time, $type === 'NULL' || self::onlyTests($events));

        /* Un événement en échec ne doit pas emporter les suivants du même
         * message : ce pourrait être l'alarme. */
        foreach ($events as $event) {
            try {
                $hub->applyEvent($event);
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $hub->getHumanName() . ' ' . __('événement non appliqué :', __FILE__) . ' '
                       . $e->getMessage() . ' — ' . json_encode($event));
            }
        }
    }

    /* Codes des tests automatiques, qui reviennent à intervalle fixe. Le test
     * manuel (RX) n'en fait pas partie : c'est un geste de l'installateur. */
    private static $_periodicCodes = array('RP', 'TX', 'YY');

    /* Vrai si tous les événements sont des tests automatiques. */
    private static function onlyTests($_events) {
        if (empty($_events)) {
            return false;
        }
        foreach ($_events as $event) {
            if (!isset($event['code']) || !in_array($event['code'], self::$_periodicCodes)) {
                return false;
            }
        }
        return true;
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
        $hub->setConfiguration('autocreated', 1);
        $hub->setIsEnable(1);
        $hub->setIsVisible(1);
        $hub->save();
        log::add(__CLASS__, 'info', __('Nouveau hub découvert, équipement créé :', __FILE__) . ' ' . $hub->getHumanName());
        return $hub;
    }

    /*
     * Un nom libre sous un objet donné. L'unicité (nom, objet) vaut pour tous
     * les équipements de Jeedom, pas seulement ceux du plugin : une « Zone 5 »
     * d'un autre plugin ferait échouer l'enregistrement.
     */
    private static function freeName($_name, $_objectId) {
        $objectId = ($_objectId === '' || $_objectId === null) ? null : $_objectId;
        $taken = array();
        foreach (eqLogic::byObjectId($objectId, false) as $eqLogic) {
            $taken[mb_strtolower($eqLogic->getName())] = true;
        }
        $name = $_name;
        for ($i = 2; isset($taken[mb_strtolower($name)]); $i++) {
            $name = $_name . ' (' . $i . ')';
        }
        return $name;
    }

    /* ---------------------------------------------------------- zones */

    public function zones() {
        $id = (int) $this->getId();
        if (!isset(self::$_zoneCache[$id])) {
            $zones = array();
            foreach (self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_ZONE)) as $zone) {
                if ((int) $zone->getConfiguration('hub_id') == $id) {
                    $zones[(int) $zone->getConfiguration('zone')] = $zone;
                }
            }
            self::$_zoneCache[$id] = $zones;
        }
        return self::$_zoneCache[$id];
    }

    public function zone($_number, $_create = false) {
        $zones = $this->zones();
        if (isset($zones[$_number])) {
            return $zones[$_number];
        }
        if (!$_create || $this->getConfiguration('autozone', 1) != 1 || $_number < 1 || $_number > self::ZONE_MAX) {
            return null;
        }
        /* Une zone qui ne peut pas être créée ne doit pas empêcher d'appliquer
         * l'événement : l'alarme du hub compte plus que l'équipement de zone. */
        try {
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
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $this->getHumanName() . ' ' . __('zone', __FILE__) . ' ' . $_number . ' '
                   . __('non créée :', __FILE__) . ' ' . $e->getMessage());
            return null;
        }
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
        if (isset($_event['id']) && $_event['id'] !== '' && ctype_digit((string) $_event['id'])) {
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
        if ($code === '') {
            return __('Événement Contact ID', __FILE__) . ' ' . (isset($_event['cid']) ? $_event['cid'] : '?')
                 . ' (' . __('non répertorié', __FILE__) . ')';
        }
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
     * Les conditions en cours : alarmes, sabotages, batteries faibles et
     * liaisons perdues par zone, mode par groupe. Le hub n'en montre que la
     * synthèse, qu'on ne peut pas reconstruire à partir de la seule dernière
     * commande : un sabotage rétabli sur une zone ne dit rien des autres.
     */
    private function loadState() {
        $state = cache::byKey('ajaxsiabe::state::' . $this->getId())->getValue(null);
        $state = is_array($state) ? $state : array();
        foreach (array('alarms', 'tamper', 'battery', 'link', 'groups') as $key) {
            if (!isset($state[$key]) || !is_array($state[$key])) {
                $state[$key] = array();
            }
        }
        return $state;
    }

    private function saveState($_state) {
        cache::set('ajaxsiabe::state::' . $this->getId(), $_state);
    }

    /* Publie une valeur seulement si elle change : le coeur traite toujours une
     * valeur vide comme un changement, et relancerait les scénarios. */
    private function updateIfChanged($_logicalId, $_value) {
        $cmd = $this->getCmd('info', $_logicalId);
        if (is_object($cmd) && (string) $cmd->execCmd() === (string) $_value) {
            return;
        }
        $this->checkAndUpdateCmd($_logicalId, $_value);
    }

    /* ------------------------------------------------ application */

    /* Tout message, test de liaison compris, prouve que le hub est vivant. */
    public function touchContact($_time, $_isTest = false) {
        $id = $this->getId();
        $previous = (int) cache::byKey('ajaxsiabe::contact::' . $id)->getValue(0);
        cache::set('ajaxsiabe::contact::' . $id, $_time);
        if ($_isTest) {
            /* Intervalles observés entre deux tests : ils règlent la supervision
             * automatique. On en garde plusieurs et on retient la médiane ; un
             * écart de moins de 30 s (test doublé sur deux canaux) est ignoré. */
            $lastTest = (int) cache::byKey('ajaxsiabe::lasttest::' . $id)->getValue(0);
            if ($lastTest > 0 && $_time - $lastTest >= 30) {
                $intervals = cache::byKey('ajaxsiabe::intervals::' . $id)->getValue(array());
                $intervals = is_array($intervals) ? $intervals : array();
                $intervals[] = $_time - $lastTest;
                cache::set('ajaxsiabe::intervals::' . $id, array_slice($intervals, -5));
            }
            if ($lastTest == 0 || $_time - $lastTest >= 30) {
                cache::set('ajaxsiabe::lasttest::' . $id, $_time);
            }
        }
        $this->checkAndUpdateCmd('last_contact', date('Y-m-d H:i:s', $_time));
        $link = $this->getCmd('info', 'link');
        if (is_object($link) && $link->execCmd() !== '' && (int) $link->execCmd() === 0 && $previous > 0) {
            log::add(__CLASS__, 'info', $this->getHumanName() . ' ' . __('liaison rétablie', __FILE__));
            message::removeAll(__CLASS__, 'linkLost' . $id);
        }
        $this->updateIfChanged('link', 1);
    }

    public function applyEvent($_event) {
        $code = isset($_event['code']) ? (string) $_event['code'] : '';
        if ($code === '') {
            $label = $this->describeEvent($_event);
            /* Code Contact ID absent de la table : publié tel quel plutôt que tu. */
            log::add(__CLASS__, 'info', $this->getHumanName() . ' ' . $label);
            $this->checkAndUpdateCmd('last_code', 'CID' . (isset($_event['cid']) ? $_event['cid'] : ''));
            $this->checkAndUpdateCmd('last_event', $label);
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
            $this->applyArming($effects['arm'], $_event, $subject, $state);
        }

        if (isset($effects['alarm'])) {
            $type = __($effects['alarm'], __FILE__);
            /* Une alarme déjà mémorisée pour cette zone garde sa mémoire. */
            $latch = !empty($effects['latch']) || !empty($state['alarms'][$zoneNumber]['latch']);
            unset($state['alarms'][$zoneNumber]);
            $state['alarms'][$zoneNumber] = array('type' => $type, 'latch' => $latch ? 1 : 0);
            $this->checkAndUpdateCmd('alarm_type', $type);
            $this->checkAndUpdateCmd('alarm_zone', ($zoneNumber > 0) ? $this->zoneName($zoneNumber) : $this->getName());
            $this->publishAlarms($state);
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
                $this->publishAlarms($state);
            }
        }

        if (!empty($effects['cancel'])) {
            $this->clearAlarms($state, $effects['cancel']);
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
            $this->updateIfChanged($logicalId, empty($state[$effect]) ? 0 : 1);
        }

        if ($zoneNumber > 0) {
            /* Un appareil qui parle est un appareil joignable : tout événement de
             * la zone rétablit sa liaison, sauf celui qui annonce sa perte. */
            $linkUp = !isset($effects['link']) || $effects['link'];
            if ($linkUp) {
                unset($state['link'][$zoneNumber]);
            } else {
                $state['link'][$zoneNumber] = 1;
            }
            if (is_object($zone)) {
                $zone->updateIfChanged('link', $linkUp ? 1 : 0);
            }
        }
        if (isset($effects['power'])) {
            $this->updateIfChanged('power', $effects['power'] ? 1 : 0);
        }
        if (isset($effects['jamming'])) {
            $this->updateIfChanged('jamming', $effects['jamming'] ? 1 : 0);
        }

        $this->saveState($state);
        /* Le contexte d'abord, l'événement ensuite : un scénario déclenché par
         * « Dernier événement » doit lire l'utilisateur et la zone de celui-ci,
         * pas ceux du précédent. */
        $this->checkAndUpdateCmd('last_user', ($subject['user'] !== null) ? $subject['who'] : '');
        $this->checkAndUpdateCmd('last_zone', ($zoneNumber > 0) ? $this->zoneName($zoneNumber) : '');
        $categories = AjaxSiaCodec::dictionary('categories');
        $this->checkAndUpdateCmd('last_category', isset($categories[$info['c']]) ? __($categories[$info['c']], __FILE__) : $info['c']);
        $this->checkAndUpdateCmd('last_code', $code);
        $this->checkAndUpdateCmd('last_event', $label);
        if (is_object($zone)) {
            $zone->checkAndUpdateCmd('last_event', $label);
        }
    }

    /*
     * Mode d'armement.
     *
     * Sans groupes déclarés sur le hub, le numéro de groupe transmis (« ri »)
     * est ignoré : tout événement vaut pour le système entier. Un hub Ajax sans
     * mode groupes envoie un ri quelconque, qui ne doit rien changer.
     *
     * Avec des groupes déclarés, chacun a son mode et celui du hub en est la
     * synthèse : tous armés → Armé, tous désarmés → Désarmé, un mélange →
     * Armé partiel. Un groupe déclaré mais jamais signalé prend le dernier
     * mode du système entier. Désarmer le garage ne désarme pas la maison, ni
     * n'efface une intrusion en cours dans la maison.
     */
    private function applyArming($_mode, $_event, $_subject, &$_state) {
        $declared = array_keys($this->numberedNames('groups'));
        $group = 0;
        if (!empty($declared)) {
            if (isset($_event['ri']) && $_event['ri'] !== '' && (int) $_event['ri'] > 0) {
                $group = (int) $_event['ri'];
            } else {
                /* Certains codes de groupe (CG, OG, CA…) portent le numéro de
                 * groupe dans leur adresse plutôt que dans « ri ». */
                $info = AjaxSiaCodec::describe(isset($_event['code']) ? $_event['code'] : '');
                $addr = isset($_event['addr']) ? trim($_event['addr']) : '';
                if ($info['a'] == 'g' && $addr !== '' && ctype_digit($addr) && (int) $addr > 0) {
                    $group = (int) $addr;
                }
            }
        }
        if ($group == 0) {
            $_state['groups'] = array('0' => $_mode);
        } else {
            $_state['groups'][(string) $group] = $_mode;
        }
        if (empty($declared)) {
            $global = $_mode;
        } else {
            $whole = isset($_state['groups']['0']) ? $_state['groups']['0'] : null;
            $modes = array();
            foreach ($declared as $number) {
                if (isset($_state['groups'][(string) $number])) {
                    $modes[] = $_state['groups'][(string) $number];
                } elseif ($whole !== null) {
                    $modes[] = $whole;
                }
            }
            if ($group != 0 && !in_array($group, $declared)) {
                $modes[] = $_mode;            // groupe non déclaré : compté quand même
            }
            $modes = array_values(array_unique($modes));
            $global = (count($modes) == 1) ? $modes[0] : 'partial';
        }
        $this->checkAndUpdateCmd('arming', __(self::$_armingLabels[$global], __FILE__));
        $this->checkAndUpdateCmd('armed', ($global == 'disarmed') ? 0 : 1);

        $who = $_subject['who'];
        if ($who === '' && $group > 0) {
            $who = $this->groupName($group);
        }
        if ($who === '') {
            $who = __('le système', __FILE__);
        }
        $this->checkAndUpdateCmd('arming_by', $who);

        /* Le désarmement complet efface les alarmes mémorisées (intrusion,
         * panique…). Les alarmes techniques restent tant que leur détecteur
         * n'est pas revenu au repos : désarmer n'éteint pas un incendie. */
        if ($global == 'disarmed') {
            $this->clearAlarms($_state, 'latched');
        }
    }

    /*
     * Retire des alarmes en cours : 'latched' les alarmes mémorisées, un type
     * (« Intrusion », « Incendie ») celles de ce type, 'all' toutes.
     */
    private function clearAlarms(&$_state, $_filter = 'all') {
        $zones = $this->zones();
        foreach ($_state['alarms'] as $number => $alarm) {
            $match = ($_filter == 'all')
                  || ($_filter == 'latched' && !empty($alarm['latch']))
                  || ($alarm['type'] === __($_filter, __FILE__));
            if (!$match) {
                continue;
            }
            unset($_state['alarms'][$number]);
            if (isset($zones[$number])) {
                $zones[$number]->updateIfChanged('alarm', 0);
            }
        }
        $this->publishAlarms($_state);
    }

    /* Commandes d'alarme du hub d'après ce qui reste en cours : la dernière
     * alarme encore active donne le type et l'origine. */
    private function publishAlarms($_state) {
        $active = array();
        foreach ($_state['alarms'] as $alarm) {
            $active[$alarm['type']] = true;
        }
        foreach (self::$_alarmFamilies as $logicalId => $types) {
            $on = 0;
            foreach ($types as $type) {
                if (isset($active[__($type, __FILE__)])) {
                    $on = 1;
                }
            }
            $this->updateIfChanged($logicalId, $on);
        }
        if (empty($_state['alarms'])) {
            $this->updateIfChanged('alarm', 0);
            $this->updateIfChanged('alarm_type', '');
            $this->updateIfChanged('alarm_zone', '');
            return;
        }
        $numbers = array_keys($_state['alarms']);
        $last = end($numbers);
        $this->updateIfChanged('alarm_type', $_state['alarms'][$last]['type']);
        $this->updateIfChanged('alarm_zone', ($last > 0) ? $this->zoneName($last) : $this->getName());
    }

    public function resetAlarm() {
        $state = $this->loadState();
        $this->clearAlarms($state, 'all');
        $this->saveState($state);
        log::add(__CLASS__, 'info', $this->getHumanName() . ' ' . __('alarme acquittée depuis Jeedom', __FILE__));
    }

    /* Une zone supprimée n'enverra plus de rétablissement : ses conditions en
     * cours sont retirées de la synthèse du hub. */
    private function forgetZone($_number) {
        $state = $this->loadState();
        foreach (array('tamper' => 'tamper', 'battery' => 'battery_low') as $key => $logicalId) {
            unset($state[$key][$_number]);
            $this->updateIfChanged($logicalId, empty($state[$key]) ? 0 : 1);
        }
        unset($state['link'][$_number]);
        if (isset($state['alarms'][$_number])) {
            unset($state['alarms'][$_number]);
            $this->publishAlarms($state);
        }
        $this->saveState($state);
    }

    /* ------------------------------------------------------ supervision */

    /*
     * Délai au-delà duquel un hub muet est déclaré perdu, en secondes. Réglé à
     * la main sur le hub, sinon déduit de la médiane des intervalles observés
     * entre deux tests. 0 : pas encore assez de mesures, pas de supervision.
     *
     * Trois intervalles au moins : sur le vrai hub, le premier test est parti
     * juste après l'enregistrement des réglages dans Ajax PRO, hors de son
     * calendrier. Un seul intervalle, trop court, donnerait un délai trop
     * court et une fausse perte de liaison au test suivant.
     */
    public function supervisionDelay() {
        $manual = (int) $this->getConfiguration('supervision', 0);
        if ($manual > 0) {
            return $manual * 60;
        }
        $intervals = cache::byKey('ajaxsiabe::intervals::' . $this->getId())->getValue(array());
        if (!is_array($intervals) || count($intervals) < 3) {
            return 0;
        }
        sort($intervals);
        $median = $intervals[(int) floor(count($intervals) / 2)];
        /* Deux tests et demi manqués, et jamais moins de trois minutes : un
         * message peut arriver en retard sans que la liaison soit en cause. */
        return max(180, (int) round($median * 2.5) + 30);
    }

    public static function cron() {
        /* Démon arrêté : c'est le récepteur qui est sourd, pas le hub qui s'est
         * tu. Accuser le hub déclencherait de fausses alertes. Le coeur relance
         * lui-même un démon arrêté quand la gestion automatique est active. */
        if (self::deamon_info()['state'] != 'ok') {
            return;
        }
        self::checkListening();
        $daemonStart = (int) cache::byKey('ajaxsiabe::daemonStart')->getValue(0);
        foreach (self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_HUB), true) as $hub) {
            try {
                $delay = $hub->supervisionDelay();
                if ($delay <= 0) {
                    continue;
                }
                /* Référence : le dernier message, mais jamais avant le démarrage
                 * du démon. Après un arrêt de Jeedom, le hub a le temps de se
                 * manifester ; un hub jamais entendu depuis finit signalé. */
                $last = max((int) cache::byKey('ajaxsiabe::contact::' . $hub->getId())->getValue(0), $daemonStart);
                if ($last <= 0 || time() - $last <= $delay) {
                    continue;
                }
                $link = $hub->getCmd('info', 'link');
                if (is_object($link) && (string) $link->execCmd() !== '0') {
                    log::add(__CLASS__, 'warning', $hub->getHumanName() . ' ' . __('muet depuis', __FILE__) . ' '
                           . round((time() - $last) / 60) . ' min : ' . __('liaison déclarée perdue', __FILE__));
                    $hub->checkAndUpdateCmd('link', 0);
                    /* Une alerte visible sans scénario : un hub muet, c'est une
                     * alarme qui ne préviendrait plus personne. */
                    message::add(__CLASS__, $hub->getHumanName() . ' ' . __('ne donne plus de nouvelles depuis', __FILE__) . ' '
                               . round((time() - $last) / 60) . ' min. '
                               . __('Vérifiez son alimentation et sa connexion réseau.', __FILE__), '', 'linkLost' . $hub->getId());
                }
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', __('Supervision en échec :', __FILE__) . ' ' . $e->getMessage());
            }
        }
    }

    /*
     * Démon vivant mais sourd (port pris, refusé) : aucun message n'arrive, et
     * rien d'autre ne le signalerait. Un message tant que dure le problème.
     */
    private static function checkListening() {
        $status = self::daemonStatus();
        if (!is_array($status)) {
            return;
        }
        if (empty($status['tcp']) || (int) $status['tcp'] !== (int) $status['port']) {
            if (!cache::byKey('ajaxsiabe::deafNotified')->getValue(0)) {
                message::add(__CLASS__, __('Le récepteur SIA n\'écoute pas sur le port', __FILE__) . ' ' . $status['port'] . ' : '
                           . (!empty($status['listenError']) ? $status['listenError'] : __('raison inconnue', __FILE__))
                           . '. ' . __('Les messages du hub ne peuvent pas arriver.', __FILE__), '', 'deaf');
                cache::set('ajaxsiabe::deafNotified', 1);
            }
        } elseif (cache::byKey('ajaxsiabe::deafNotified')->getValue(0)) {
            message::removeAll(__CLASS__, 'deaf');
            cache::set('ajaxsiabe::deafNotified', 0);
        }
    }

    /* ============================================================ SANTÉ */

    /* Page Santé de Jeedom : réception, chiffrement, et chaque hub. */
    public static function health() {
        $return = array();
        $daemon = self::deamon_info();
        $status = ($daemon['state'] == 'ok') ? self::daemonStatus() : null;
        $port = (int) config::byKey('port', __CLASS__, 7777);

        $listening = is_array($status) && !empty($status['tcp']) && (int) $status['tcp'] === $port;
        $return[] = array(
            'test'   => __('Réception SIA', __FILE__),
            'result' => $listening
                ? __('à l\'écoute sur le port', __FILE__) . ' ' . $port . ' (' . (!empty($status['udp']) ? 'TCP + UDP' : 'TCP') . ')'
                : (($daemon['state'] != 'ok') ? __('démon arrêté', __FILE__)
                    : __('port', __FILE__) . ' ' . $port . ' ' . __('non ouvert', __FILE__)
                      . (is_array($status) && !empty($status['listenError']) ? ' : ' . $status['listenError'] : '')),
            'advice' => __('Le hub envoie ses messages sur ce port : sans lui, rien n’arrive.', __FILE__),
            'state'  => $listening,
        );
        if (is_array($status)) {
            $return[] = array(
                'test'   => __('Trames reçues', __FILE__),
                'result' => $status['frames'] . ' ' . __('depuis le démarrage du démon, dont', __FILE__) . ' ' . $status['rejected'] . ' ' . __('refusée(s)', __FILE__),
                'advice' => __('Le journal SIA donne le motif de chaque refus.', __FILE__),
                'state'  => true,
            );
        }

        $hubs = self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_HUB), true);
        $unencrypted = array();
        foreach ($hubs as $hub) {
            if ($hub->hubKey() === '' && self::generalKey() === '') {
                $unencrypted[] = $hub->getName();
            }
        }
        $return[] = array(
            'test'   => __('Chiffrement', __FILE__),
            'result' => empty($unencrypted) ? ((self::generalKey() !== '' || count($hubs) > 0) ? __('actif', __FILE__) : __('aucun hub', __FILE__))
                                            : __('absent pour', __FILE__) . ' ' . implode(', ', $unencrypted),
            'advice' => __('Sans clé, n’importe quel appareil du réseau peut envoyer un faux désarmement.', __FILE__),
            'state'  => empty($unencrypted),
        );

        foreach ($hubs as $hub) {
            $last = (int) cache::byKey('ajaxsiabe::contact::' . $hub->getId())->getValue(0);
            $delay = $hub->supervisionDelay();
            $link = $hub->getCmd('info', 'link');
            $ok = $last > 0 && (!is_object($link) || (string) $link->execCmd() !== '0');
            $result = ($last > 0) ? __('dernier message il y a', __FILE__) . ' ' . self::duration(time() - $last) : __('aucun message reçu', __FILE__);
            $result .= ' — ' . __('supervision', __FILE__) . ' : ' . (($delay > 0) ? self::duration($delay) : __('pas encore mesurée', __FILE__));
            $return[] = array(
                'test'   => $hub->getName(),
                'result' => $result,
                'advice' => __('Le hub doit envoyer des tests de liaison réguliers (intervalle de test dans Ajax PRO).', __FILE__),
                'state'  => $ok,
            );
        }
        return $return;
    }

    /* « 1 h 30 min », « 4 min », « 12 s ». */
    public static function duration($_seconds) {
        $s = max(0, (int) $_seconds);
        if ($s < 60) {
            return $s . ' s';
        }
        if ($s < 3600) {
            return floor($s / 60) . ' min';
        }
        if ($s < 86400) {
            $minutes = floor(($s % 3600) / 60);
            return floor($s / 3600) . ' h' . ($minutes > 0 ? ' ' . $minutes . ' min' : '');
        }
        return floor($s / 86400) . ' j';
    }

    /* ==================================================== RACCORDEMENT */

    /* Clé aléatoire de 16 caractères hexadécimaux, la forme qu'acceptent les
     * applications Ajax (AES-128). */
    public static function generateKey() {
        return strtoupper(bin2hex(random_bytes(8)));
    }

    /* Un numéro de compte libre à proposer pour un nouveau hub. */
    public static function proposeAccount() {
        $taken = array();
        foreach (self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_HUB)) as $hub) {
            $taken[strtoupper((string) $hub->getConfiguration('account'))] = true;
        }
        do {
            $account = strtoupper(bin2hex(random_bytes(2)));
        } while (isset($taken[$account]));
        return $account;
    }

    /* Nomme l'appareil N d'un hub, en le créant s'il n'existe pas encore. */
    public function nameZone($_number, $_name) {
        $number = (int) $_number;
        $name = trim((string) $_name);
        if ($number < 1 || $number > self::ZONE_MAX) {
            throw new Exception(__('Numéro de zone invalide', __FILE__));
        }
        if ($name === '') {
            throw new Exception(__('Le nom ne peut pas être vide', __FILE__));
        }
        $zone = $this->zone($number);
        if (!is_object($zone)) {
            $zone = new ajaxsiabe();
            $zone->setEqType_name(__CLASS__);
            $zone->setObject_id($this->getObject_id());
            $zone->setConfiguration('type', self::TYPE_ZONE);
            $zone->setConfiguration('hub_id', $this->getId());
            $zone->setConfiguration('zone', $number);
            $zone->setIsEnable(1);
            $zone->setIsVisible(1);
        }
        foreach (eqLogic::byObjectId(($zone->getObject_id() === '' || $zone->getObject_id() === null) ? null : $zone->getObject_id(), false) as $other) {
            if ($other->getId() != $zone->getId() && mb_strtolower($other->getName()) === mb_strtolower($name)) {
                throw new Exception(__('Un équipement porte déjà ce nom sous le même objet :', __FILE__) . ' ' . $other->getHumanName());
            }
        }
        $zone->setName($name);
        $zone->save();
        return $zone;
    }

    /* Nomme l'utilisateur N (ou le groupe N) dans la fiche du hub. */
    public function nameNumber($_key, $_number, $_name) {
        if (!in_array($_key, array('users', 'groups'))) {
            throw new Exception(__('Liste inconnue', __FILE__));
        }
        $number = (int) $_number;
        $name = trim(str_replace(array("\r", "\n", '='), ' ', (string) $_name));
        if ($number < 0 || $name === '') {
            throw new Exception(__('Numéro ou nom invalide', __FILE__));
        }
        $lines = array();
        $done = false;
        foreach (preg_split('/\r?\n/', (string) $this->getConfiguration($_key)) as $line) {
            if (preg_match('/^\s*0*(\d+)\s*[=:]/', $line, $m) && (int) $m[1] === $number) {
                if (!$done) {
                    $lines[] = $number . '=' . $name;
                    $done = true;
                }
                continue;
            }
            if (trim($line) !== '') {
                $lines[] = $line;
            }
        }
        if (!$done) {
            $lines[] = $number . '=' . $name;
        }
        $this->setConfiguration($_key, implode("\n", $lines));
        $this->save();
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
     * les événements passés. Date vide : aujourd'hui, selon l'horloge de Jeedom.
     *
     * Filtres : 'account', 'tests' (0 masque les tests de liaison et les tests
     * périodiques, qui sont comptés à part), 'problems' (1 ne garde que les
     * refus), 'search', 'limit'.
     */
    public static function readJournal($_date, $_filters = array()) {
        $date = ($_date === '' || $_date === null) ? date('Y-m-d') : $_date;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new Exception(__('Date invalide', __FILE__));
        }
        $file = self::journalDir() . '/' . $date . '.jsonl';
        $result = array('date' => $date, 'entries' => array(), 'total' => 0, 'hiddenTests' => 0, 'truncated' => false);
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
            $hubAccount = strtoupper((string) $hub->getConfiguration('account'));
            if ($hubAccount !== '') {
                $hubs[$hubAccount] = $hub;
            }
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $entry = json_decode($lines[$i], true);
            if (!is_array($entry)) {
                continue;
            }
            $entry += array('status' => '', 'type' => '', 'account' => '', 'events' => array(), 'error' => '', 'peer' => '', 'warning' => '');
            if ($account !== '' && $entry['account'] !== $account) {
                continue;
            }
            if ($problems && in_array($entry['status'], array('ok', 'duplicate'))) {
                continue;
            }
            if (!$tests && in_array($entry['status'], array('ok', 'duplicate')) && self::isPeriodicTest($entry)) {
                $result['hiddenTests']++;
                continue;
            }
            $hub = ($entry['account'] !== '' && isset($hubs[$entry['account']])) ? $hubs[$entry['account']] : null;
            $entry['hub'] = is_object($hub) ? $hub->getName() : '';
            $texts = array();
            foreach ($entry['events'] as $index => $event) {
                $texts[] = is_object($hub) ? $hub->describeEvent($event) : (isset($event['label']) ? $event['label'] : $event['code']);
                /* Ce que l'événement désigne, pour les boutons « Nommer » du détail. */
                if (is_object($hub)) {
                    $subject = $hub->eventSubject($event);
                    $entry['events'][$index]['zoneNumber'] = $subject['zone'];
                    $entry['events'][$index]['zoneName'] = ($subject['zone'] > 0 && is_object($hub->zone($subject['zone']))) ? $hub->zone($subject['zone'])->getName() : '';
                    $entry['events'][$index]['userNumber'] = $subject['user'];
                    $entry['events'][$index]['userName'] = ($subject['user'] !== null) ? $subject['who'] : '';
                }
            }
            $entry['hubId'] = is_object($hub) ? (int) $hub->getId() : 0;
            if ($entry['type'] == 'NULL') {
                $texts[] = __('Test de liaison', __FILE__);
            }
            $entry['text'] = implode(' · ', $texts);
            $entry['time'] = isset($entry['t']) ? date('H:i:s', (int) $entry['t']) : '';
            if ($search !== '') {
                $haystack = mb_strtolower($entry['text'] . ' ' . (isset($entry['data']) ? $entry['data'] : '') . ' ' . $entry['hub'] . ' '
                          . $entry['account'] . ' ' . $entry['error'] . ' ' . $entry['warning'] . ' ' . $entry['peer']);
                if (mb_strpos($haystack, $search) === false) {
                    continue;
                }
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

    /* Test de liaison (NULL) ou test périodique automatique (RP) : ce qui
     * revient toutes les minutes. Un test manuel (RX) reste visible, c'est un
     * geste de l'installateur. */
    private static function isPeriodicTest($_entry) {
        if ($_entry['type'] == 'NULL') {
            return true;
        }
        return self::onlyTests($_entry['events']);
    }

    /* ================================================== CYCLE DE VIE */

    public function preSave() {
        if (!in_array($this->getConfiguration('type'), array(self::TYPE_HUB, self::TYPE_ZONE))) {
            $this->setConfiguration('type', self::TYPE_HUB);
        }
        /* Un hub qui a des appareils ne peut pas devenir un appareil : ses zones
         * perdraient leur hub et le compte serait recréé au message suivant. */
        if ($this->getId() != '' && $this->getConfiguration('type') == self::TYPE_ZONE) {
            $stored = self::byId($this->getId());
            if (is_object($stored) && $stored->getConfiguration('type') == self::TYPE_HUB && count($stored->zones()) > 0) {
                throw new Exception(__('Ce hub a des appareils : il ne peut pas devenir un appareil.', __FILE__));
            }
        }
        if ($this->getConfiguration('type') == self::TYPE_HUB) {
            $account = strtoupper(trim((string) $this->getConfiguration('account')));
            if ($account !== '' && !preg_match('/^[0-9A-F]{1,16}$/', $account)) {
                throw new Exception(__('Numéro de compte invalide : 1 à 16 caractères hexadécimaux (0-9, A-F)', __FILE__));
            }
            $this->setConfiguration('account', $account);
            $key = trim((string) $this->getConfiguration('key'));
            if ($key !== '' && strpos($key, 'crypt:') === false) {
                if (!in_array(strlen($key), array(16, 24, 32))) {
                    throw new Exception(__('La clé de chiffrement doit compter 16, 24 ou 32 caractères', __FILE__));
                }
                $key = utils::encrypt($key);
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
            $hubId = (int) $this->getConfiguration('hub_id');
            $number = (int) $this->getConfiguration('zone');
            if ($this->getId() != '' && $hubId > 0 && $number > 0) {
                foreach (self::byTypeAndSearchConfiguration(__CLASS__, array('type' => self::TYPE_ZONE)) as $other) {
                    if ($other->getId() != $this->getId() && (int) $other->getConfiguration('hub_id') == $hubId
                     && (int) $other->getConfiguration('zone') == $number) {
                        throw new Exception(__('Ce numéro de zone est déjà celui de', __FILE__) . ' ' . $other->getHumanName());
                    }
                }
            }
            $this->setLogicalId('zone::' . $hubId . '::' . $number);
        }
    }

    public function postSave() {
        self::$_zoneCache = array();
        $definitions = ($this->getConfiguration('type') == self::TYPE_ZONE) ? self::$_zoneCommands : self::$_hubCommands;
        $wanted = array();
        $order = 0;
        foreach ($definitions as $definition) {
            $wanted[$definition['logicalId']] = true;
            $cmd = $this->addCmdIfMissing($definition, $order++);
            /* Valeur de départ des états. Posée à chaque enregistrement tant que la
             * commande est vide : un hub créé à la main l'est d'abord désactivé, et
             * le coeur ignore toute mise à jour d'un équipement désactivé. */
            if (isset($definition['initial']) && $this->getIsEnable() == 1 && $cmd->execCmd() === '') {
                $this->checkAndUpdateCmd($cmd, $definition['initial']);
            }
        }
        /* Commandes de l'autre type, laissées par un changement de type. */
        foreach ($this->getCmd() as $cmd) {
            if (!isset($wanted[$cmd->getLogicalId()])) {
                $cmd->remove();
            }
        }
        /* Toujours, et pas seulement pour un hub : un hub devenu zone doit
         * sortir de la configuration du démon, avec sa clé. */
        self::reloadDaemonConfig();
    }

    /* Hub en cours de suppression : ses zones partent avec lui sans rien
     * republier sur lui, ce qui déclencherait des scénarios pour rien. */
    private static $_removingHub = 0;

    /* Les appareils n'existent que par leur hub : ils partent avec lui. */
    public function preRemove() {
        if ($this->getConfiguration('type') == self::TYPE_HUB) {
            self::$_removingHub = (int) $this->getId();
            foreach ($this->zones() as $zone) {
                $zone->remove();
            }
        }
    }

    public function postRemove() {
        self::$_zoneCache = array();
        if ($this->getConfiguration('type') == self::TYPE_ZONE) {
            $hubId = (int) $this->getConfiguration('hub_id');
            $hub = ($hubId == self::$_removingHub) ? null : self::byId($hubId);
            if (is_object($hub) && $hub->getEqType_name() == __CLASS__) {
                $hub->forgetZone((int) $this->getConfiguration('zone'));
            }
            return;
        }
        foreach (array('state', 'contact', 'lasttest', 'intervals') as $key) {
            cache::delete('ajaxsiabe::' . $key . '::' . $this->getId());
        }
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
        if (!empty($_definition['repeat'])) {
            $cmd->setConfiguration('repeatEventManagement', 'always');
        }
        $cmd->save();
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
