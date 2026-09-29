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

require_once __DIR__ . '/../../../core/php/core.inc.php';

function ajaxsiabe_install() {
    ajaxsiabe_prepareData();
    /* Le démon appelle toujours le callback depuis 127.0.0.1 : restreindre l'API
     * du plugin à la boucle locale ne gêne rien et évite qu'elle réponde au LAN. */
    ajaxsiabe_apiLocalOnly();
}

/* La clé du plugin d'abord : créée plus tard, par le premier démarrage du
 * démon, elle remettrait le mode d'accès à « activé ». */
function ajaxsiabe_apiLocalOnly() {
    jeedom::getApiKey('ajaxsiabe');
    config::save('api::ajaxsiabe::mode', 'localhost', 'core');
}

function ajaxsiabe_update() {
    ajaxsiabe_prepareData();
    ajaxsiabe_apiLocalOnly();
    /* Recrée les commandes ajoutées par une nouvelle version, et chiffre les
     * clés des hubs enregistrées en clair par la version 0.1. Un équipement
     * qui refuse de s'enregistrer ne doit pas interrompre la mise à jour. */
    foreach (eqLogic::byType('ajaxsiabe') as $eqLogic) {
        try {
            $eqLogic->save();
            ajaxsiabe_restoreApostrophes($eqLogic);
        } catch (Throwable $e) {
            log::add('ajaxsiabe', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
}

/* Jeedom retire l'apostrophe droite des noms de commandes : la version 0.2
 * a créé « Type dalarme ». Les noms portent désormais l'apostrophe
 * typographique ; on ne renomme que ce qui n'a pas été personnalisé. */
function ajaxsiabe_restoreApostrophes($_eqLogic) {
    $names = array(
        'alarm_type'  => array('Type dalarme', 'Type d’alarme'),
        'alarm_zone'  => array('Origine de lalarme', 'Origine de l’alarme'),
        'reset_alarm' => array('Acquitter lalarme', 'Acquitter l’alarme'),
    );
    foreach ($names as $logicalId => $rename) {
        $cmd = $_eqLogic->getCmd(null, $logicalId);
        if (is_object($cmd) && $cmd->getName() === $rename[0]
            && !is_object(cmd::byEqLogicIdCmdName($_eqLogic->getId(), $rename[1]))) {
            $cmd->setName($rename[1]);
            $cmd->save();
        }
    }
}

function ajaxsiabe_remove() {
    try {
        ajaxsiabe::deamon_stop();
    } catch (Throwable $e) {
        // le plugin peut être désactivé alors que la classe n'est plus chargeable
    }
}

/* Le journal contient les messages déchiffrés : il doit exister et rester
 * interdit d'accès direct, seule la page du plugin le lit. */
function ajaxsiabe_prepareData() {
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir . '/journal')) {
        @mkdir($dir . '/journal', 0775, true);
    }
    @file_put_contents($dir . '/.htaccess', "Order allow,deny\nDeny from all\n");
}
