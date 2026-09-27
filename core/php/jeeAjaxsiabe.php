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
 * Point d'entrée appelé exclusivement par le démon ajaxsiabed.
 *   GET  ?apikey=…&test=1        → vérification de joignabilité au démarrage
 *   GET  ?apikey=…&action=config → port, clés et comptes connus
 *   POST ?apikey=…  + corps JSON → lot de messages SIA déjà accusés au hub
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/../class/ajaxsiabe.class.php';

if (!jeedom::apiAccess(init('apikey'), 'ajaxsiabe')) {
    /* 401 et non 200 : le démon ne dispose que du code HTTP pour savoir si son
     * lot a été pris en compte. */
    http_response_code(401);
    echo __('Vous n\'êtes pas autorisé à effectuer cette action', __FILE__);
    die();
}

if (init('test') != '') {
    echo 'OK';
    die();
}

if (init('action') == 'config') {
    header('Content-Type: application/json');
    echo json_encode(ajaxsiabe::getDaemonConfig());
    die();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['events']) || !is_array($input['events'])) {
    die();
}

ajaxsiabe::handleMessages($input['events']);
echo 'OK';
