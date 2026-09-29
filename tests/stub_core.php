<?php
/* Doublure du coeur de Jeedom pour tests/test_pilot.php.
 *
 * Copiée par l'essai sous <dossier temporaire>/core/php/core.inc.php, là où
 * core/class/ajaxsiabe.class.php va chercher le vrai coeur : la classe du
 * plugin tourne alors telle quelle, sans base, sans cache partagé, sans démon.
 * Rien ici n'écrit hors du dossier temporaire, et aucune commande n'est
 * jamais exécutée : scenarioExpression::createAndExec() ne fait que noter ce
 * qu'on lui demande. C'est ce qui permet de vérifier le pilotage sans
 * jamais approcher de la vraie centrale.
 *
 * Seul ce dont la classe a besoin sur les chemins essayés est doublé. */

function __($_text, $_file = '') {
    return $_text;
}

class StubWorld {
    public static $cache = array();
    public static $config = array();
    public static $logs = array();
    public static $messages = array();
    public static $execs = array();
    public static $eqLogics = array();
    public static $cmds = array();
    public static $nextId = 1000;
    public static $tmp = '';

    public static function reset() {
        self::$cache = self::$config = self::$logs = self::$messages = self::$execs = array();
        self::$eqLogics = self::$cmds = array();
    }
}

class cacheItem {
    private $_value;
    public function __construct($_value) { $this->_value = $_value; }
    public function getValue($_default = '') { return ($this->_value === null) ? $_default : $this->_value; }
}

class cache {
    public static function byKey($_key) {
        return new cacheItem(array_key_exists($_key, StubWorld::$cache) ? StubWorld::$cache[$_key] : null);
    }
    public static function set($_key, $_value, $_lifetime = 0) { StubWorld::$cache[$_key] = $_value; }
    public static function delete($_key) { unset(StubWorld::$cache[$_key]); }
}

class config {
    /* Comme le coeur : une valeur JSON est rendue décodée (is_json()), une
     * valeur tableau est enregistrée encodée. */
    public static function byKey($_key, $_plugin = 'core', $_default = '') {
        $k = $_plugin . '::' . $_key;
        if (!array_key_exists($k, StubWorld::$config) || StubWorld::$config[$k] === '') {
            return $_default;
        }
        $decoded = json_decode(StubWorld::$config[$k], true);
        return is_array($decoded) ? $decoded : StubWorld::$config[$k];
    }
    public static function save($_key, $_value, $_plugin = 'core') {
        StubWorld::$config[$_plugin . '::' . $_key] = is_array($_value) ? json_encode($_value) : (string) $_value;
    }
    public static function remove($_key, $_plugin = 'core') { unset(StubWorld::$config[$_plugin . '::' . $_key]); }
}

class log {
    public static function add($_plugin, $_level, $_message, $_logicalId = '') { StubWorld::$logs[] = array($_level, $_message); }
}

class message {
    public static function add($_plugin, $_message, $_action = '', $_logicalId = '') { StubWorld::$messages[$_logicalId] = $_message; }
    public static function removeAll($_plugin = '', $_logicalId = '') { unset(StubWorld::$messages[$_logicalId]); }
}

class jeedom {
    public static function getTmpFolder($_plugin = '') { return StubWorld::$tmp; }
    public static function getApiKey($_plugin = '') { return 'CLE'; }
}

class system {
    public static function kill($_pid) {}
    public static function fuserk($_port) {}
}

class listener {
    public static function byClassAndFunction($_class, $_function, $_option = '') { return null; }
}

class scenarioExpression {
    /* Note l'ordre au lieu de l'exécuter : c'est tout l'objet de la doublure. */
    public static function createAndExec($_type, $_cmd, $_options = null) {
        StubWorld::$execs[] = array('cmd' => $_cmd, 'options' => $_options);
    }
    public static function setTags($_value, &$_scenario = null) { return $_value; }
}

class jeeObject {
    public function getName() { return ''; }
}

class eqLogic {
    protected $id = '';
    protected $name = '';
    protected $eqType_name = '';
    protected $logicalId = '';
    protected $object_id = null;
    protected $isEnable = 1;
    protected $configuration = array();
    protected $status = array();

    public function getId() { return $this->id; }
    public function setId($_id) { $this->id = $_id; return $this; }
    public function getName() { return $this->name; }
    public function setName($_name) { $this->name = $_name; return $this; }
    public function getHumanName($_tag = false, $_prettify = false) { return '[' . $this->name . ']'; }
    public function getEqType_name() { return $this->eqType_name; }
    public function setEqType_name($_type) { $this->eqType_name = $_type; return $this; }
    public function getLogicalId() { return $this->logicalId; }
    public function setLogicalId($_id) { $this->logicalId = $_id; return $this; }
    public function getObject_id() { return $this->object_id; }
    public function setObject_id($_id) { $this->object_id = $_id; return $this; }
    public function getObject() { return null; }
    public function getIsEnable() { return $this->isEnable; }
    public function setIsEnable($_v) { $this->isEnable = $_v; return $this; }
    public function setIsVisible($_v) { return $this; }
    public function getStatus($_key = '', $_default = '') { return isset($this->status[$_key]) ? $this->status[$_key] : $_default; }
    public function setStatus($_key, $_value) { $this->status[$_key] = $_value; return $this; }
    public function getConfiguration($_key = '', $_default = '') {
        if ($_key === '') {
            return $this->configuration;
        }
        return (isset($this->configuration[$_key]) && $this->configuration[$_key] !== '') ? $this->configuration[$_key] : $_default;
    }
    public function setConfiguration($_key, $_value) { $this->configuration[$_key] = $_value; return $this; }

    /* Enregistre l'équipement dans le monde doublé, sans pre/postSave : les
     * commandes sont créées par l'essai lui-même. */
    public function save($_direct = false) {
        if ($this->id === '') {
            $this->id = StubWorld::$nextId++;
        }
        StubWorld::$eqLogics[$this->id] = $this;
        return $this;
    }

    public function getCmd($_type = null, $_logicalId = null) {
        $found = array();
        foreach (StubWorld::$cmds as $cmd) {
            if ($cmd->getEqLogic_id() == $this->id && ($_type === null || $cmd->getType() == $_type)
                && ($_logicalId === null || $cmd->getLogicalId() == $_logicalId)) {
                if ($_logicalId !== null) {
                    return $cmd;
                }
                $found[] = $cmd;
            }
        }
        return ($_logicalId !== null) ? null : $found;
    }

    public function checkAndUpdateCmd($_logicalId, $_value, $_updateTime = null) {
        $cmd = is_object($_logicalId) ? $_logicalId : $this->getCmd('info', $_logicalId);
        if (!is_object($cmd)) {
            return false;
        }
        $cmd->setValue($_value);
        return true;
    }

    public static function byId($_id) {
        return isset(StubWorld::$eqLogics[$_id]) ? StubWorld::$eqLogics[$_id] : null;
    }

    public static function byType($_type, $_onlyEnable = false) {
        return array_values(array_filter(StubWorld::$eqLogics, function ($_e) use ($_type, $_onlyEnable) {
            return $_e->getEqType_name() == $_type && (!$_onlyEnable || $_e->getIsEnable() == 1);
        }));
    }

    public static function byTypeAndSearchConfiguration($_type, $_search, $_onlyEnable = false) {
        return array_values(array_filter(self::byType($_type, $_onlyEnable), function ($_e) use ($_search) {
            foreach ((array) $_search as $key => $value) {
                if ((string) $_e->getConfiguration($key) !== (string) $value) {
                    return false;
                }
            }
            return true;
        }));
    }

    public static function byObjectId($_objectId, $_onlyEnable = true) {
        return array_values(array_filter(StubWorld::$eqLogics, function ($_e) use ($_objectId) {
            return $_e->getObject_id() == $_objectId;
        }));
    }
}

class cmd {
    protected $id = '';
    protected $eqLogic_id = '';
    protected $eqType = '';
    protected $logicalId = '';
    protected $name = '';
    protected $type = 'info';
    protected $subType = 'string';
    protected $_value = '';

    public static function make($_eqLogic, $_logicalId, $_type, $_name = '', $_value = '') {
        $class = get_called_class();
        $cmd = new $class();
        $cmd->id = StubWorld::$nextId++;
        $cmd->eqLogic_id = $_eqLogic->getId();
        $cmd->eqType = $_eqLogic->getEqType_name();
        $cmd->logicalId = $_logicalId;
        $cmd->type = $_type;
        $cmd->name = ($_name === '') ? $_logicalId : $_name;
        $cmd->_value = $_value;
        StubWorld::$cmds[$cmd->id] = $cmd;
        return $cmd;
    }

    public function getId() { return $this->id; }
    public function getEqLogic_id() { return $this->eqLogic_id; }
    public function getEqType() { return $this->eqType; }
    public function getLogicalId() { return $this->logicalId; }
    public function getType() { return $this->type; }
    public function getName() { return $this->name; }
    public function getHumanName($_tag = false, $_prettify = false) { return '#' . $this->name . '#'; }
    public function getEqLogic() { return eqLogic::byId($this->eqLogic_id); }
    public function execCmd($_options = null) {
        if ($this->type == 'action') {
            /* Une action directe (liste d'actions d'alerte) : notée, jamais jouée. */
            StubWorld::$execs[] = array('cmd' => '#' . $this->id . '#', 'options' => $_options, 'direct' => true);
            return null;
        }
        return $this->_value;
    }
    public function setValue($_value) { $this->_value = $_value; }
    public static function byId($_id) { return isset(StubWorld::$cmds[$_id]) ? StubWorld::$cmds[$_id] : null; }
    public static function byEqLogicIdCmdName($_eqLogicId, $_name) { return null; }
}
