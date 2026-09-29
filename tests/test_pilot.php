<?php
/* Essai du pilotage de l'alarme par le cloud, confirmé par le SIA, et de la
 * surveillance croisée SIA ↔ cloud. Sans Jeedom, sans démon, sans réseau.
 *
 *   php tests/test_pilot.php
 *
 * Deux parties :
 *  1. le moteur de décision (core/class/ajaxsiabePilot.class.php), seul ;
 *  2. la classe du plugin elle-même, dans un dossier temporaire où le coeur
 *     de Jeedom est remplacé par une doublure (tests/stub_core.php) : les
 *     ordres « partent » dans un carnet, jamais vers une commande réelle.
 *
 * Aucune trame n'est envoyée à un démon, aucun équipement réel n'est créé :
 * un essai du pilotage qui se tromperait de cible pourrait désarmer une
 * maison, il ne doit rien pouvoir atteindre. */

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

/* Arborescence d'un Jeedom factice : <tmp>/core/php/core.inc.php est la
 * doublure, <tmp>/plugins/ajaxsiabe/ une copie des fichiers utiles. Le moteur
 * est chargé depuis cette copie, que la classe du plugin chargera à son tour. */
$dir = sys_get_temp_dir() . '/ajaxsiabe-pilot-' . getmypid();
foreach (array('/core/php', '/plugins/ajaxsiabe/core/class', '/plugins/ajaxsiabe/core/config',
               '/plugins/ajaxsiabe/resources/ajaxsiabed', '/plugins/ajaxsiabe/data/journal', '/tmp') as $sub) {
    @mkdir($dir . $sub, 0775, true);
}
register_shutdown_function(function () use ($dir) {
    exec('rm -rf ' . escapeshellarg($dir));
});
copy(__DIR__ . '/stub_core.php', $dir . '/core/php/core.inc.php');
foreach (array('core/class/ajaxsiabe.class.php', 'core/class/ajaxsiabePilot.class.php', 'core/config/sia_codes.json',
               'resources/ajaxsiabed/AjaxSiaCodec.php') as $file) {
    copy(__DIR__ . '/../' . $file, $dir . '/plugins/ajaxsiabe/' . $file);
}

/* ======================================================= 1. LE MOTEUR SEUL */

require_once $dir . '/plugins/ajaxsiabe/core/class/ajaxsiabePilot.class.php';

$settings = array('delay' => 60, 'retries' => 1);
$t0 = mktime(22, 3, 10, 9, 29, 2026);

/* --- Ordre confirmé */
$r = ajaxsiabePilot::start('arm', $settings, 'disarmed', $t0, null);
check('ordre : envoyé', $r['send'] === true && $r['pending'] === 1 && $r['failed'] === 0);
check('ordre : mémorisé avec son échéance', $r['order']['target'] === 'armed' && $r['order']['deadline'] === $t0 + 60);
check('ordre : « en attente »', strpos($r['text'], 'Armer — en attente') === 0);
$order = $r['order'];
check('confirmation : un autre mode ne confirme pas', ajaxsiabePilot::confirm($order, 'night', $t0 + 2) === null);
$c = ajaxsiabePilot::confirm($order, 'armed', $t0 + 4);
check('confirmation : texte exact', $c !== null && $c['text'] === 'Armer — confirmé par le SIA à 22:03:14 (4 s)');
check('confirmation : ordre terminé', $c['order'] === null && $c['pending'] === 0 && $c['failed'] === 0);
check('pas d\'échéance avant le délai', ajaxsiabePilot::tick($order, 'disarmed', $t0 + 59) === null);

/* --- Déjà dans l'état demandé */
$r = ajaxsiabePilot::start('disarm', $settings, 'disarmed', $t0, null);
check('déjà : rien n\'est envoyé', $r['send'] === false && $r['order'] === null && $r['already'] === true);
check('déjà : dit comme tel', strpos($r['text'], 'Désarmer — déjà dans cet état selon le SIA') === 0 && strpos($r['text'], 'ordre non envoyé') !== false);
$r = ajaxsiabePilot::start('panic', $settings, 'disarmed', $t0, null);
check('panique : jamais « déjà »', $r['send'] === true && $r['order']['target'] === 'panic');

/* --- Nouvel essai puis échec */
$r = ajaxsiabePilot::start('night', $settings, 'disarmed', $t0, null);
$order = $r['order'];
$t = ajaxsiabePilot::tick($order, 'disarmed', $t0 + 60);
check('essai : renvoyé à l\'échéance', $t !== null && $t['send'] === true && $t['alert'] === false && $t['pending'] === 1);
check('essai : 2/2 annoncé', strpos($t['text'], 'nouvel essai (2/2)') !== false);
$order = $t['order'];
check('essai : échéance repoussée', $order['deadline'] === $t0 + 120 && $order['retries'] === 0 && $order['attempt'] === 2);
$t = ajaxsiabePilot::tick($order, 'disarmed', $t0 + 120);
check('échec : alerte, rien de renvoyé', $t['send'] === false && $t['alert'] === true && $t['failed'] === 1 && $t['pending'] === 0);
check('échec : « NON confirmé »', $t['text'] === 'Mode nuit — NON confirmé par le SIA (2 essais, 60 s chacun)');
$failed = $t['order'];
check('échec : gardé en mémoire', $failed['status'] === 'failed');
check('échec : pas de seconde alerte', ajaxsiabePilot::tick($failed, 'disarmed', $t0 + 180) === null);
$late = ajaxsiabePilot::confirm($failed, 'night', $t0 + 200);
check('confirmation tardive : dite comme telle', $late !== null && $late['late'] === true && strpos($late['text'], 'après l\'alerte d\'échec') !== false && $late['failed'] === 0);
$forget = ajaxsiabePilot::tick($failed, 'disarmed', $t0 + 120 + ajaxsiabePilot::FAILED_MEMORY + 1);
check('échec : oublié après un quart d\'heure, sans rien publier', $forget['order'] === null && $forget['publish'] === false);
$r = ajaxsiabePilot::start('arm', array('delay' => 60, 'retries' => 0), 'disarmed', $t0, null);
$t = ajaxsiabePilot::tick($r['order'], 'disarmed', $t0 + 61);
check('sans nouvel essai : échec direct', $t['alert'] === true && $t['text'] === 'Armer — NON confirmé par le SIA (1 essai, 60 s d\'attente)');
$c = ajaxsiabePilot::confirm(ajaxsiabePilot::tick(ajaxsiabePilot::start('arm', $settings, 'disarmed', $t0, null)['order'], 'disarmed', $t0 + 60)['order'], 'armed', $t0 + 65);
check('confirmation au 2e essai', strpos($c['text'], '(65 s), au 2e essai') !== false);
$t = ajaxsiabePilot::tick(ajaxsiabePilot::start('arm', $settings, 'disarmed', $t0, null)['order'], 'armed', $t0 + 60);
check('échéance : état déjà conforme, pas de renvoi', $t['send'] === false && $t['alert'] === false && $t['order'] === null);

/* --- Ordre remplacé */
$first = ajaxsiabePilot::start('night', $settings, 'disarmed', $t0, null);
$second = ajaxsiabePilot::start('arm', $settings, 'disarmed', $t0 + 5, $first['order']);
check('remplacement : l\'ordre précédent est nommé', $second['replaced'] === 'Mode nuit' && $second['order']['key'] === 'arm');
check('remplacement : l\'ancien ne confirme plus', ajaxsiabePilot::confirm($second['order'], 'night', $t0 + 6) === null);
$third = ajaxsiabePilot::start('disarm', $settings, 'disarmed', $t0 + 6, $second['order']);
check('remplacement par un ordre déjà acquis : plus rien en attente', $third['order'] === null && $third['replaced'] === 'Armer');

/* --- Correspondance des valeurs du cloud */
$map = ajaxsiabePilot::parseCloudMap('');
foreach (array('DISARMED' => 'disarmed', 'ARMED' => 'armed', 'NIGHT_MODE' => 'night', 'DISARMED_NIGHT_MODE_ON' => 'night',
               'DISARMED_NIGHT_MODE_OFF' => 'disarmed', 'ARMED_NIGHT_MODE_ON' => 'armed', 'ARMED_NIGHT_MODE_OFF' => 'armed',
               'PARTIALLY_ARMED' => 'partial', '0' => 'disarmed', '1' => 'armed', '2' => 'night') as $raw => $mode) {
    check('table par défaut : ' . $raw, ajaxsiabePilot::cloudMode($map, $raw) === $mode);
}
check('table par défaut : PANIC jamais comparé', ajaxsiabePilot::cloudMode($map, 'PANIC') === null);
check('valeur vide : rien', ajaxsiabePilot::cloudMode($map, '') === null);
check('casse et espaces ignorés', ajaxsiabePilot::cloudMode($map, ' armed ') === 'armed');
$custom = ajaxsiabePilot::parseCloudMap("Armée=Armé\nNuit : Mode nuit\nOFF=disarmed\nbad line\nX=panique\nY=inexistant");
check('table personnelle : libellés', ajaxsiabePilot::cloudMode($custom, 'ARMÉE') === 'armed' && ajaxsiabePilot::cloudMode($custom, 'nuit') === 'night');
check('table personnelle : clés', ajaxsiabePilot::cloudMode($custom, 'off') === 'disarmed');
check('table personnelle : lignes illisibles ignorées', count($custom) === 3);
check('table personnelle : remplace la table par défaut', ajaxsiabePilot::cloudMode($custom, 'ARMED') === null);

/* --- Divergence cloud puis retour */
$tol = 120;
$e = ajaxsiabePilot::cloudCheck(array(), 'armed', 'armed', false, false, $t0, $tol);
check('cohérent : 1, pas d\'alerte', $e['coherent'] === 1 && $e['alert'] === null && !$e['recovered']);
$e = ajaxsiabePilot::cloudCheck($e['episode'], 'armed', 'disarmed', false, false, $t0 + 10, $tol);
check('divergence naissante : tolérée', $e['coherent'] === 1 && $e['alert'] === null && $e['episode']['since'] === $t0 + 10);
$e = ajaxsiabePilot::cloudCheck($e['episode'], 'armed', 'disarmed', false, false, $t0 + 129, $tol);
check('divergence : toujours tolérée juste avant', $e['alert'] === null);
$e = ajaxsiabePilot::cloudCheck($e['episode'], 'armed', 'disarmed', false, false, $t0 + 130, $tol);
check('divergence : alerte à la tolérance', $e['alert'] === 'divergence' && $e['coherent'] === 0);
$e = ajaxsiabePilot::cloudCheck($e['episode'], 'armed', 'disarmed', false, false, $t0 + 400, $tol);
check('divergence : une seule alerte par épisode', $e['alert'] === null && $e['coherent'] === 0);
$e = ajaxsiabePilot::cloudCheck($e['episode'], 'armed', 'armed', false, false, $t0 + 460, $tol);
check('retour à la normale : signalé une fois', $e['recovered'] === true && $e['coherent'] === 1 && $e['episode']['alerted'] === 0);
$e = ajaxsiabePilot::cloudCheck($e['episode'], 'armed', 'armed', false, false, $t0 + 520, $tol);
check('retour à la normale : pas deux fois', $e['recovered'] === false);
$e = ajaxsiabePilot::cloudCheck(array(), 'armed', 'disarmed', true, false, $t0, 0);
check('SIA muet, cloud qui répond : signalé comme tel', $e['alert'] === 'silent');
$e = ajaxsiabePilot::cloudCheck(array(), 'armed', 'disarmed', false, true, $t0, 0);
check('ordre en cours : pas de comparaison', $e['alert'] === null && $e['coherent'] === null);
$e = ajaxsiabePilot::cloudCheck(array(), 'armed', null, false, false, $t0, 0);
check('valeur cloud inconnue : pas de conclusion', $e['alert'] === null && $e['coherent'] === null);

/* --- Réglages bornés */
check('délai par défaut', ajaxsiabePilot::delay('') === 60 && ajaxsiabePilot::delay('abc') === 60);
check('délai borné', ajaxsiabePilot::delay('1') === 10 && ajaxsiabePilot::delay('99999') === 3600);
check('essais par défaut et bornés', ajaxsiabePilot::retries('') === 1 && ajaxsiabePilot::retries('0') === 0 && ajaxsiabePilot::retries('9') === 5);
check('tolérance en minutes', ajaxsiabePilot::tolerance('') === 120 && ajaxsiabePilot::tolerance('0.5') === 30);
check('identifiant de commande', ajaxsiabePilot::cmdIdOf(' #6802# ') === 6802 && ajaxsiabePilot::cmdIdOf('#[a][b][c]#') === null);

/* ============================== 2. LA CLASSE DU PLUGIN, CŒUR EN DOUBLURE */

require_once $dir . '/plugins/ajaxsiabe/core/class/ajaxsiabe.class.php';
StubWorld::$tmp = $dir . '/tmp';

/* Un monde neuf : le hub SIA, le hub ajaxSystem et ses commandes. */
function world() {
    StubWorld::reset();
    $cloudHub = new eqLogic();
    $cloudHub->setEqType_name('ajaxSystem')->setName('Ajax hub')->setLogicalId('00028FF0')->setConfiguration('type', 'hub');
    $cloudHub->save();
    $w = array('cloudHub' => $cloudHub);
    $w['state'] = cmd::make($cloudHub, 'state', 'info', 'Etat', 'DISARMED');
    $w['ARM'] = cmd::make($cloudHub, 'ARM', 'action', 'Armement');
    $w['NIGHT_MODE'] = cmd::make($cloudHub, 'NIGHT_MODE', 'action', 'Mode nuit');
    $w['DISARM'] = cmd::make($cloudHub, 'DISARM', 'action', 'Desarmement');
    $w['PANIC'] = cmd::make($cloudHub, 'PANIC', 'action', 'Panic');
    $device = new eqLogic();
    $device->setEqType_name('ajaxSystem')->setName('Baie vitrée salon')->setLogicalId('00214F0D')
           ->setConfiguration('type', 'device')->setConfiguration('hub_id', '00028FF0');
    $device->save();
    $w['device'] = $device;
    $notify = new eqLogic();
    $notify->setEqType_name('virtual')->setName('Téléphone');
    $notify->save();
    $w['notify'] = cmd::make($notify, 'notify', 'action', 'Notifier');

    $hub = new ajaxsiabe();
    $hub->setEqType_name('ajaxsiabe')->setName('Hub Ajax 2701')->setConfiguration('type', 'hub')->setConfiguration('account', '2701');
    $hub->save();
    foreach (ajaxsiabe::$_hubCommands as $definition) {
        $class = ($definition['type'] == 'action') ? 'ajaxsiabeCmd' : 'cmd';
        $class::make($hub, $definition['logicalId'], $definition['type'], $definition['name'],
                     isset($definition['initial']) ? $definition['initial'] : '');
    }
    $hub->getCmd('info', 'arming')->setValue('Désarmé');
    $w['hub'] = $hub;
    return $w;
}

function value($_hub, $_logicalId) {
    return $_hub->getCmd('info', $_logicalId)->execCmd();
}

/* Ce que le carnet d'exécutions dit de la commande #id#. */
function sent($_cmd) {
    return count(array_filter(StubWorld::$execs, function ($_e) use ($_cmd) {
        return $_e['cmd'] === '#' . $_cmd->getId() . '#';
    }));
}

/* Fait passer l'échéance de l'ordre mémorisé, sans attendre une minute. */
function expire($_hub) {
    $order = StubWorld::$cache['ajaxsiabe::order::' . $_hub->getId()];
    $order['deadline'] = time() - 1;
    StubWorld::$cache['ajaxsiabe::order::' . $_hub->getId()] = $order;
}

function journal() {
    global $dir;
    $file = $dir . '/plugins/ajaxsiabe/data/journal/' . date('Y-m-d') . '.jsonl';
    return is_file($file) ? array_map(function ($_l) { return json_decode($_l, true); }, file($file, FILE_IGNORE_NEW_LINES)) : array();
}

/* --- Refus propre : aucune commande configurée */
$w = world();
$hub = $w['hub'];
$refused = '';
try {
    $hub->getCmd('action', 'order_arm')->execute();
} catch (Exception $e) {
    $refused = $e->getMessage();
}
check('refus : l\'appelant reçoit une erreur', strpos($refused, 'refusé') !== false && strpos($refused, 'aucune commande du cloud') !== false);
check('refus : dit dans « Dernier ordre »', strpos(value($hub, 'order_last'), 'Armer — refusé') === 0);
check('refus : rien d\'exécuté', count(StubWorld::$execs) === 0);

/* --- Refus : commande introuvable, ou du plugin lui-même */
$hub->setConfiguration('order_arm_cmd', '#999999#');
try { $hub->sendOrder('arm'); $refused = ''; } catch (Exception $e) { $refused = $e->getMessage(); }
check('refus : commande introuvable', strpos($refused, 'introuvable') !== false);
$hub->setConfiguration('order_arm_cmd', '#' . $hub->getCmd('action', 'order_arm')->getId() . '#');
try { $hub->sendOrder('arm'); $refused = ''; } catch (Exception $e) { $refused = $e->getMessage(); }
check('refus : pas de boucle sur ses propres commandes', strpos($refused, 'Ajax SIA') !== false && count(StubWorld::$execs) === 0);

/* --- Ordre confirmé par une trame SIA */
$w = world();
$hub = $w['hub'];
foreach (array('arm' => 'ARM', 'night' => 'NIGHT_MODE', 'disarm' => 'DISARM') as $key => $logical) {
    $hub->setConfiguration('order_' . $key . '_cmd', '#' . $w[$logical]->getId() . '#');
}
$hub->getCmd('action', 'order_arm')->execute();
check('ordre : commande du cloud lancée une fois', sent($w['ARM']) === 1);
$exec = end(StubWorld::$execs);
check('ordre : en tâche de fond', isset($exec['options']['background']) && $exec['options']['background'] == 1);
check('ordre : « Ordre en cours » à 1', (string) value($hub, 'order_pending') === '1');
$stored = config::byKey('order::' . $hub->getId(), 'ajaxsiabe', '');
check('ordre : persistant (base)', is_array($stored) && $stored['key'] === 'arm' && $stored['status'] === 'pending');
/* Cache perdu (redémarrage de Jeedom) : l'ordre est relu en base. */
unset(StubWorld::$cache['ajaxsiabe::order::' . $hub->getId()]);
unset(StubWorld::$cache['ajaxsiabe::state::' . $hub->getId()]);
$notes = journal();
check('ordre : noté au journal SIA', count($notes) >= 1 && end($notes)['status'] === 'jeedom' && strpos(end($notes)['note'], 'Armer') === 0);
$hub->applyEvent(array('code' => 'CL', 'addr' => '501', 'ri' => '1'));
check('confirmation : « Mode » armé par le SIA', value($hub, 'arming') === 'Armé');
check('confirmation : « Dernier ordre »', preg_match('/^Armer — confirmé par le SIA à \d\d:\d\d:\d\d \(\d+ s\)$/', value($hub, 'order_last')) === 1);
check('confirmation : plus d\'ordre en cours', (string) value($hub, 'order_pending') === '0' && (string) value($hub, 'order_failed') === '0');
check('confirmation : ordre oublié', config::byKey('order::' . $hub->getId(), 'ajaxsiabe', '') === '');
/* L'état du hub survit lui aussi à la perte du cache (défaut de la 0.3 : la
 * copie en base n'était jamais relue). */
unset(StubWorld::$cache['ajaxsiabe::state::' . $hub->getId()]);
$hub->applyEvent(array('code' => 'RP', 'addr' => '0000', 'ri' => '0'));
check('état : relu en base après perte du cache', strpos(StubWorld::$config['ajaxsiabe::state::' . $hub->getId()], '"armed"') !== false);
check('confirmation : pas de renvoi au cron', $hub->processOrders() === null && sent($w['ARM']) === 1);

/* --- Déjà dans l'état */
$hub->sendOrder('arm');
check('déjà armé : rien n\'est envoyé', sent($w['ARM']) === 1 && strpos(value($hub, 'order_last'), 'déjà dans cet état') !== false);

/* --- Nouvel essai puis échec, puis alerte */
$hub->setConfiguration('order_actions', array(array('cmd' => '#' . $w['notify']->getId() . '#',
    'options' => array('enable' => 1, 'title' => 'Alarme #hub#', 'message' => '#ordre# vers #mode# : #message# (#essais#)'))));
$hub->sendOrder('night');
check('essai : premier envoi', sent($w['NIGHT_MODE']) === 1);
expire($hub);
$hub->processOrders();
check('essai : renvoyé au cron', sent($w['NIGHT_MODE']) === 2 && strpos(value($hub, 'order_last'), 'nouvel essai (2/2)') !== false);
check('essai : toujours en cours', (string) value($hub, 'order_pending') === '1');
expire($hub);
$hub->processOrders();
check('échec : pas de troisième envoi', sent($w['NIGHT_MODE']) === 2);
check('échec : « Échec du dernier ordre » à 1', (string) value($hub, 'order_failed') === '1' && (string) value($hub, 'order_pending') === '0');
check('échec : « NON confirmé »', strpos(value($hub, 'order_last'), 'Mode nuit — NON confirmé par le SIA (2 essais') === 0);
check('échec : message au centre de messages', isset(StubWorld::$messages['orderFailed' . $hub->getId()]));
$alert = array_values(array_filter(StubWorld::$execs, function ($_e) use ($w) { return $_e['cmd'] === '#' . $w['notify']->getId() . '#'; }));
check('échec : action d\'alerte jouée une fois', count($alert) === 1);
check('échec : balises remplacées', count($alert) === 1 && $alert[0]['options']['title'] === 'Alarme Hub Ajax 2701'
      && strpos($alert[0]['options']['message'], 'Mode nuit vers Mode nuit : Hub Ajax 2701 : Mode nuit — NON confirmé') === 0
      && substr($alert[0]['options']['message'], -3) === '(2)');
$hub->processOrders();
check('échec : pas de seconde alerte', count(array_filter(StubWorld::$execs, function ($_e) use ($w) { return $_e['cmd'] === '#' . $w['notify']->getId() . '#'; })) === 1);
$hub->applyEvent(array('code' => 'NL', 'addr' => '501', 'ri' => '1'));
check('confirmation tardive : signalée, échec levé', strpos(value($hub, 'order_last'), 'après l\'alerte d\'échec') !== false
      && (string) value($hub, 'order_failed') === '0' && !isset(StubWorld::$messages['orderFailed' . $hub->getId()]));

/* --- Ordre remplacé */
$hub->sendOrder('arm');
check('remplacement : premier ordre parti', sent($w['ARM']) === 2);
$hub->sendOrder('disarm');
check('remplacement : second ordre parti', sent($w['DISARM']) === 1);
$replacedLogs = array_filter(StubWorld::$logs, function ($_l) { return strpos($_l[1], 'remplace l\'ordre en attente « Armer »') !== false; });
check('remplacement : journalisé', count($replacedLogs) === 1);
$hub->applyEvent(array('code' => 'CL', 'addr' => '501', 'ri' => '1'));
check('remplacement : l\'ancien ordre ne se confirme plus', (string) value($hub, 'order_pending') === '1');
$hub->applyEvent(array('code' => 'OP', 'addr' => '501', 'ri' => '1'));
check('remplacement : le nouveau se confirme', strpos(value($hub, 'order_last'), 'Désarmer — confirmé par le SIA') === 0);

/* --- Panique : confirmée par l'alarme panique du SIA */
$hub->setConfiguration('order_panic_cmd', '#' . $w['PANIC']->getId() . '#');
$hub->sendOrder('panic');
check('panique : envoyée', sent($w['PANIC']) === 1 && (string) value($hub, 'order_pending') === '1');
$hub->applyEvent(array('code' => 'PA', 'addr' => '501', 'ri' => '1'));
check('panique : confirmée par l\'alarme SIA', strpos(value($hub, 'order_last'), 'Panique — confirmé par le SIA') === 0);

/* --- Désarmer n'est jamais déclenché par un événement reçu */
$before = sent($w['DISARM']);
foreach (array('CL', 'BA', 'OP', 'NL', 'PA', 'OR', 'CL', 'TA', 'RP') as $code) {
    $hub->applyEvent(array('code' => $code, 'addr' => '003', 'ri' => '1'));
}
$w['state']->setValue('ARMED');
$hub->evaluateCloud(true);
$hub->processOrders();
check('événements reçus : aucun ordre envoyé', sent($w['DISARM']) === $before && sent($w['ARM']) === 2 && sent($w['NIGHT_MODE']) === 2);

/* --- Divergence cloud puis retour */
$w = world();
$hub = $w['hub'];
$hub->getCmd('info', 'link')->setValue(1);
$hub->setConfiguration('cloud_state_cmd', '#' . $w['state']->getId() . '#');
$hub->setConfiguration('cloud_actions', array(array('cmd' => '#' . $w['notify']->getId() . '#',
    'options' => array('message' => '#coherent# #mode#/#etat_cloud#'))));
$hub->getCmd('info', 'arming')->setValue('Armé');
$w['state']->setValue('ARMED');
$hub->evaluateCloud(true);
check('cloud : « État cloud » normalisé', value($hub, 'cloud_state') === 'Armé' && (string) value($hub, 'cloud_coherent') === '1');
$w['state']->setValue('DISARMED');
$hub->evaluateCloud(true);
check('cloud : divergence naissante tolérée', (string) value($hub, 'cloud_coherent') === '1' && value($hub, 'cloud_state') === 'Désarmé');
$episode = StubWorld::$cache['ajaxsiabe::cloud::' . $hub->getId()];
$episode['since'] = time() - 121;
StubWorld::$cache['ajaxsiabe::cloud::' . $hub->getId()] = $episode;
$hub->evaluateCloud(true);
$notified = function () use ($w) {
    return array_values(array_filter(StubWorld::$execs, function ($_e) use ($w) { return $_e['cmd'] === '#' . $w['notify']->getId() . '#'; }));
};
check('cloud : divergence signalée', (string) value($hub, 'cloud_coherent') === '0' && isset(StubWorld::$messages['cloudDiverge' . $hub->getId()]));
check('cloud : action jouée avec ses balises', count($notified()) === 1 && $notified()[0]['options']['message'] === '0 Armé/Désarmé');
check('cloud : le mode du hub ne suit pas le cloud', value($hub, 'arming') === 'Armé' && (string) value($hub, 'armed') !== '0');
$hub->evaluateCloud(true);
check('cloud : une alerte par épisode', count($notified()) === 1);
$w['state']->setValue('ARMED');
$hub->evaluateCloud(true);
check('cloud : retour à la normale signalé', count($notified()) === 2 && $notified()[1]['options']['message'] === '1 Armé/Armé'
      && (string) value($hub, 'cloud_coherent') === '1' && !isset(StubWorld::$messages['cloudDiverge' . $hub->getId()]));
$w['state']->setValue('PANIC');
$hub->evaluateCloud(true);
check('cloud : valeur hors table montrée telle quelle', value($hub, 'cloud_state') === 'Inconnu (PANIC)' && (string) value($hub, 'cloud_coherent') === '1');
$w['state']->setValue('DISARMED');
StubWorld::$cache['ajaxsiabe::cloud::' . $hub->getId()] = array('since' => time() - 500, 'alerted' => 0, 'kind' => '');
$hub->getCmd('info', 'link')->setValue(0);
$hub->evaluateCloud(true);
check('cloud : SIA muet, signalé comme tel', strpos(StubWorld::$messages['cloudDiverge' . $hub->getId()], 'le SIA se tait') !== false);
$w['device']->setStatus('lastCommunication', date('Y-m-d H:i:s', time() - 300));
check('perte de liaison : nouvelles du cloud', strpos($hub->cloudNews(), 'signe de vie il y a 5 min') !== false);

/* --- Zone liée à un appareil du cloud */
$zone = new ajaxsiabe();
$zone->setEqType_name('ajaxsiabe')->setName('Zone 3')->setConfiguration('type', 'zone')
     ->setConfiguration('hub_id', $hub->getId())->setConfiguration('zone', 3);
$zone->save();
check('zone non liée : son nom', $hub->zoneName(3) === 'Zone 3');
$zone->setConfiguration('cloud_eqLogic', $w['device']->getId());
check('zone liée : le nom de l\'appareil Ajax', $hub->zoneName(3) === 'Baie vitrée salon');
$zone->setConfiguration('cloud_name', 0);
check('zone liée, nom Ajax décoché : son nom', $hub->zoneName(3) === 'Zone 3');
$zone->setConfiguration('cloud_eqLogic', $w['notify']->getEqLogic()->getId());
$zone->setConfiguration('cloud_name', 1);
check('zone liée à autre chose qu\'ajaxSystem : ignoré', $hub->zoneName(3) === 'Zone 3');
check('appareils du cloud listés', count(ajaxsiabe::cloudDevices()) === 1 && ajaxsiabe::cloudDevices()[0]['name'] === 'Baie vitrée salon');

/* --- Journal : les lignes de Jeedom se lisent entre les trames */
$read = ajaxsiabe::readJournal('', array('problems' => 1));
check('journal : les lignes de Jeedom ne sont pas des refus', $read['total'] === 0);
$read = ajaxsiabe::readJournal('', array());
check('journal : les lignes de Jeedom sont lues', $read['total'] > 0 && $read['entries'][0]['status'] === 'jeedom' && $read['entries'][0]['text'] !== '');

echo ($failures === 0 ? 'OK' : 'ÉCHECS : ' . $failures . ' /') . ' : ' . $checks . " contrôles\n";
exit($failures === 0 ? 0 : 1);
