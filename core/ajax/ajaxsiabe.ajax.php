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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    /* Le journal contient les messages déchiffrés de l'alarme : réservé aux
     * administrateurs, comme toute la configuration du plugin. */
    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    if (init('action') == 'status') {
        $info = ajaxsiabe::deamon_info();
        $hubs = array();
        foreach (ajaxsiabe::byTypeAndSearchConfiguration('ajaxsiabe', array('type' => ajaxsiabe::TYPE_HUB)) as $hub) {
            $last = (int) cache::byKey('ajaxsiabe::contact::' . $hub->getId())->getValue(0);
            $hubs[] = array(
                'id'          => $hub->getId(),
                'name'        => $hub->getName(),
                'account'     => $hub->getConfiguration('account'),
                'lastContact' => $last,
                'enabled'     => (int) $hub->getIsEnable(),
                'supervision' => $hub->supervisionDelay(),
            );
        }
        ajax::success(array(
            'daemon' => $info['state'],
            'status' => ($info['state'] == 'ok') ? ajaxsiabe::daemonStatus() : null,
            'hubs'   => $hubs,
            'daemonStart' => (int) cache::byKey('ajaxsiabe::daemonStart')->getValue(0),
            'now'    => time(),
        ));
    }

    if (init('action') == 'journalDates') {
        ajax::success(ajaxsiabe::journalDates());
    }

    if (init('action') == 'journal') {
        ajax::success(ajaxsiabe::readJournal(init('date', ''), array(
            'account'  => init('account', ''),
            'tests'    => init('tests', 1) == 1,
            'problems' => init('problems', 0) == 1,
            'search'   => init('search', ''),
            'limit'    => init('limit', 1000),
        )));
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

} catch (Throwable $e) {
    // Throwable et non Exception : en PHP 8 une Error n'hérite pas d'Exception
    // et donnerait un HTTP 500 muet.
    ajax::error(displayException($e), $e->getCode());
}
