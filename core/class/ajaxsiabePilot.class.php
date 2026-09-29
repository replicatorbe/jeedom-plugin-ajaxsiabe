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
 * Pilotage de l'alarme par le cloud, confirmé par le SIA : les décisions, et
 * elles seules.
 *
 * Pourquoi deux plugins pour une seule alarme : le SIA est unidirectionnel (le
 * hub parle, Jeedom écoute, rien ne remonte), il ne peut donc ni armer ni
 * désarmer. Seul le plugin officiel ajaxSystem, par le cloud d'Ajax, sait
 * donner un ordre. Mais le cloud est lent et sans garantie : son retour d'état
 * arrive en une seconde ou en plusieurs minutes, parfois jamais. Le SIA, lui,
 * arrive en une seconde, du hub lui-même. D'où le cycle : l'ordre part par le
 * cloud, c'est le SIA qui dit s'il a pris effet ; faute de confirmation dans
 * le délai, un nouvel essai, puis une alerte.
 *
 * Cette classe ne touche à rien de Jeedom : ni base, ni cache, ni commande.
 * Elle reçoit l'ordre en cours et ce qu'on sait de l'instant, et rend ce qu'il
 * faut faire (envoyer, publier, alerter) avec l'ordre à mémoriser. C'est ce qui
 * permet de la vérifier hors de Jeedom, dans tests/test_pilot.php, sans
 * jamais approcher du vrai hub ni de la vraie centrale : un essai qui se
 * tromperait ne doit pas pouvoir désarmer une maison.
 *
 * Chargée par ajaxsiabe.class.php, jamais seule : l'autoload du coeur ne
 * connaît que la classe qui porte le nom du plugin.
 */
class ajaxsiabePilot {

    /* Délai de confirmation par défaut : le hub envoie sa trame dans la
     * seconde, mais l'ordre, lui, transite par le cloud Jeedom puis le cloud
     * Ajax. Une minute laisse passer un cloud lent sans faire attendre une
     * alerte trop longtemps. */
    const DEFAULT_DELAY = 60;
    const DELAY_MIN = 10;
    const DELAY_MAX = 3600;

    /* Un nouvel essai par défaut : un ordre perdu par le cloud est fréquent,
     * deux de suite beaucoup moins ; au-delà on ne ferait que retarder
     * l'alerte. */
    const DEFAULT_RETRIES = 1;
    const RETRIES_MAX = 5;

    /* Divergence SIA / cloud tolérée avant alerte : le cloud met parfois plus
     * d'une minute à suivre un changement de mode que le SIA a déjà
     * rapporté. En secondes. */
    const DEFAULT_TOLERANCE = 120;

    /* Un ordre déclaré en échec reste en mémoire un quart d'heure : si le SIA
     * le confirme quand même (cloud très lent), on le dit plutôt que de
     * laisser croire à un échec. */
    const FAILED_MEMORY = 900;

    /* Mode visé par chaque ordre. La panique n'est pas un mode : elle est
     * confirmée par une alarme panique reçue par le SIA. */
    public static $_targets = array(
        'arm'    => 'armed',
        'night'  => 'night',
        'disarm' => 'disarmed',
        'panic'  => 'panic',
    );

    /* Libellés des ordres, tels qu'ils figurent dans « Dernier ordre » et dans
     * la balise #ordre# des actions. */
    public static $_orderLabels = array(
        'arm'    => 'Armer',
        'night'  => 'Mode nuit',
        'disarm' => 'Désarmer',
        'panic'  => 'Panique',
    );

    /* Libellés des modes, les mêmes que la commande « Mode » du hub (voir
     * ajaxsiabe::$_armingLabels), plus la panique. */
    public static $_modeLabels = array(
        'disarmed' => 'Désarmé',
        'armed'    => 'Armé',
        'night'    => 'Mode nuit',
        'partial'  => 'Armé partiel',
        'panic'    => 'Panique',
    );

    /*
     * Valeurs de la commande « Etat » d'un hub du plugin ajaxSystem, et le mode
     * qu'elles désignent. Relevées dans son code le 29/09/2026 :
     * - core/php/jeeAjaxSystem.php convertit l'état poussé par le cloud :
     *   0 → DISARMED, 1 → ARMED, 2 → NIGHT_MODE ;
     * - refreshData() recopie l'état brut de l'API Ajax, que le widget du
     *   plugin (templateWidget()) connaît sous ces formes : ARMED, DISARMED,
     *   NIGHT_MODE, ARMED_NIGHT_MODE_ON, ARMED_NIGHT_MODE_OFF,
     *   DISARMED_NIGHT_MODE_ON, DISARMED_NIGHT_MODE_OFF, PANIC ;
     * - PARTIALLY_ARMED est la valeur de l'API Ajax en mode groupes quand une
     *   partie seulement est armée.
     * « Armé avec mode nuit » reste armé : le système entier est sous
     * surveillance, le SIA l'annonce comme un armement. PANIC n'est pas un
     * mode : il n'est pas traduit, donc jamais comparé. Les valeurs 0, 1 et 2
     * couvrent une valeur brute qui échapperait à la conversion.
     */
    const DEFAULT_CLOUD_MAP = "DISARMED=disarmed\nDISARMED_NIGHT_MODE_OFF=disarmed\nARMED=armed\nARMED_NIGHT_MODE_OFF=armed\nARMED_NIGHT_MODE_ON=armed\nNIGHT_MODE=night\nDISARMED_NIGHT_MODE_ON=night\nPARTIALLY_ARMED=partial\n0=disarmed\n1=armed\n2=night";

    /* ================================================================ RÉGLAGES */

    /* Délai de confirmation d'un ordre, borné : un délai nul ferait échouer
     * tout ordre avant même que le cloud l'ait reçu. */
    public static function delay($_value) {
        $value = trim((string) $_value);
        if ($value === '' || !is_numeric($value)) {
            return self::DEFAULT_DELAY;
        }
        return max(self::DELAY_MIN, min(self::DELAY_MAX, (int) $value));
    }

    public static function retries($_value) {
        $value = trim((string) $_value);
        if ($value === '' || !is_numeric($value)) {
            return self::DEFAULT_RETRIES;
        }
        return max(0, min(self::RETRIES_MAX, (int) $value));
    }

    /* Tolérance saisie en minutes, rendue en secondes. */
    public static function tolerance($_minutes) {
        $value = trim((string) $_minutes);
        if ($value === '' || !is_numeric($value) || (float) $value < 0) {
            return self::DEFAULT_TOLERANCE;
        }
        return (int) round((float) $value * 60);
    }

    /* « #6802# » → 6802 ; tout le reste → null. */
    public static function cmdIdOf($_expression) {
        return preg_match('/^#(\d+)#$/', trim((string) $_expression), $matches) ? (int) $matches[1] : null;
    }

    /* =================================================== CORRESPONDANCE CLOUD */

    /*
     * Table « valeur=mode », une ligne par valeur, comme les utilisateurs et
     * les groupes du hub. Le mode s'écrit avec sa clé (armed) ou son libellé
     * (Armé), au choix : l'utilisateur écrit ce qu'il lit sur la commande
     * « Mode ». Une table vide vaut celle d'ajaxSystem. Les lignes illisibles
     * sont ignorées : une faute de frappe ne doit pas faire croire à une
     * divergence.
     */
    public static function parseCloudMap($_text) {
        $text = trim((string) $_text);
        if ($text === '') {
            $text = self::DEFAULT_CLOUD_MAP;
        }
        $byLabel = array();
        foreach (self::$_modeLabels as $key => $label) {
            $byLabel[self::fold($label)] = $key;
            $byLabel[self::fold($key)] = $key;
        }
        $map = array();
        foreach (preg_split('/\r?\n/', $text) as $line) {
            if (!preg_match('/^\s*([^=:]+?)\s*[=:]\s*(.+?)\s*$/u', $line, $m)) {
                continue;
            }
            $mode = self::fold($m[2]);
            if (!isset($byLabel[$mode]) || $byLabel[$mode] === 'panic') {
                continue;
            }
            $map[self::fold($m[1])] = $byLabel[$mode];
        }
        return $map;
    }

    /* Le mode que désigne une valeur du cloud, ou null si elle n'est pas dans
     * la table (valeur inconnue, vide, PANIC). */
    public static function cloudMode($_map, $_raw) {
        $raw = self::fold($_raw);
        if ($raw === '') {
            return null;
        }
        return isset($_map[$raw]) ? $_map[$raw] : null;
    }

    /* Casse et espaces ignorés, accents conservés (« Armé » ≠ « Arme » n'a pas
     * d'importance : les deux sont dans la table ou aucun). */
    private static function fold($_text) {
        return mb_strtoupper(trim((string) $_text), 'UTF-8');
    }

    public static function modeLabel($_mode) {
        return isset(self::$_modeLabels[$_mode]) ? self::$_modeLabels[$_mode] : '';
    }

    /* ================================================================ ORDRES */

    /*
     * Un ordre part. $_settings : 'delay' et 'retries', déjà bornés.
     * $_siaMode : le mode que le SIA a rapporté en dernier (clé, ou null si
     * aucun encore). $_previous : l'ordre mémorisé jusqu'ici, qu'il remplace.
     *
     * Rend :
     *   order    ordre à mémoriser (null : rien en attente) ;
     *   send     vrai s'il faut exécuter la commande du cloud ;
     *   text     « Dernier ordre » ;
     *   pending  « Ordre en cours » ;
     *   failed   « Échec du dernier ordre » ;
     *   replaced libellé de l'ordre remplacé, ou '' ;
     *   already  vrai si le SIA est déjà dans l'état demandé.
     */
    public static function start($_key, $_settings, $_siaMode, $_now, $_previous = null) {
        if (!isset(self::$_targets[$_key])) {
            throw new Exception('Ordre inconnu : ' . $_key);
        }
        $label = self::$_orderLabels[$_key];
        $target = self::$_targets[$_key];
        $replaced = '';
        if (is_array($_previous) && isset($_previous['status']) && $_previous['status'] === 'pending') {
            $replaced = self::$_orderLabels[$_previous['key']];
        }
        /* Déjà dans l'état : on ne renvoie rien. Un ordre de plus au cloud
         * n'apporterait rien, et le SIA ne le confirmerait jamais (le hub
         * n'émet pas de trame pour un mode qui ne change pas) : il finirait en
         * fausse alerte. La panique n'est jamais « déjà » : c'est un geste. */
        if ($target !== 'panic' && $_siaMode === $target) {
            return array(
                'order'    => null,
                'send'     => false,
                'text'     => $label . ' — ' . 'déjà dans cet état selon le SIA à' . ' ' . date('H:i:s', $_now) . ' : ' . 'ordre non envoyé',
                'pending'  => 0,
                'failed'   => 0,
                'replaced' => $replaced,
                'already'  => true,
            );
        }
        $delay = isset($_settings['delay']) ? (int) $_settings['delay'] : self::DEFAULT_DELAY;
        $order = array(
            'key'      => $_key,
            'target'   => $target,
            'status'   => 'pending',
            'started'  => $_now,
            'sent'     => $_now,
            'deadline' => $_now + $delay,
            'delay'    => $delay,
            'attempt'  => 1,
            'retries'  => isset($_settings['retries']) ? (int) $_settings['retries'] : self::DEFAULT_RETRIES,
        );
        return array(
            'order'    => $order,
            'send'     => true,
            'text'     => $label . ' — ' . 'en attente de confirmation par le SIA (envoyé à' . ' ' . date('H:i:s', $_now) . ')',
            'pending'  => 1,
            'failed'   => 0,
            'replaced' => $replaced,
            'already'  => false,
        );
    }

    /*
     * Le SIA vient de rapporter un mode (ou une alarme panique, $_mode =
     * 'panic'). Rend null si cela ne confirme pas l'ordre mémorisé, sinon le
     * résultat à publier, l'ordre étant alors terminé.
     *
     * Un autre mode que celui visé ne termine rien : quelqu'un a pu désarmer
     * au clavier pendant que l'ordre d'armer traversait le cloud. On continue
     * d'attendre le mode demandé jusqu'à l'échéance.
     */
    public static function confirm($_order, $_mode, $_now) {
        if (!is_array($_order) || !isset($_order['target']) || $_order['target'] !== $_mode) {
            return null;
        }
        $label = self::$_orderLabels[$_order['key']];
        $elapsed = max(0, $_now - (int) $_order['started']);
        $text = $label . ' — ' . 'confirmé par le SIA à' . ' ' . date('H:i:s', $_now) . ' (' . self::seconds($elapsed) . ')';
        $late = ($_order['status'] === 'failed');
        if ($late) {
            $text .= ', ' . 'après l\'alerte d\'échec';
        } elseif ((int) $_order['attempt'] > 1) {
            $text .= ', ' . 'au' . ' ' . self::ordinal((int) $_order['attempt']) . ' ' . 'essai';
        }
        return array(
            'order'   => null,
            'send'    => false,
            'text'    => $text,
            'pending' => 0,
            'failed'  => 0,
            'late'    => $late,
            'elapsed' => $elapsed,
        );
    }

    /*
     * Passage du cron : l'échéance de l'ordre est-elle dépassée ? Rend null
     * s'il n'y a rien à faire, sinon :
     *   send  vrai pour un nouvel essai ;
     *   alert vrai quand l'ordre est déclaré en échec ;
     *   order l'ordre à mémoriser (null : oublié).
     */
    public static function tick($_order, $_siaMode, $_now) {
        if (!is_array($_order) || !isset($_order['status'])) {
            return null;
        }
        $label = self::$_orderLabels[$_order['key']];
        if ($_order['status'] === 'failed') {
            if ($_now - (int) $_order['failedAt'] > self::FAILED_MEMORY) {
                /* Rien à publier : « Dernier ordre » dit déjà l'échec. */
                return array('order' => null, 'send' => false, 'alert' => false, 'publish' => false);
            }
            return null;
        }
        if ($_now < (int) $_order['deadline']) {
            return null;
        }
        /* Le mode demandé est là sans qu'une trame l'ait annoncé pendant
         * l'ordre (un autre geste l'a produit juste avant) : c'est acquis,
         * inutile d'insister auprès du cloud. */
        if ($_order['target'] !== 'panic' && $_siaMode === $_order['target']) {
            return array(
                'order'   => null,
                'send'    => false,
                'alert'   => false,
                'publish' => true,
                'text'    => $label . ' — ' . 'état conforme selon le SIA à' . ' ' . date('H:i:s', $_now),
                'pending' => 0,
                'failed'  => 0,
            );
        }
        $order = $_order;
        if ((int) $order['retries'] > 0) {
            $order['retries'] = (int) $order['retries'] - 1;
            $order['attempt'] = (int) $order['attempt'] + 1;
            $order['sent'] = $_now;
            $order['deadline'] = $_now + (int) $order['delay'];
            return array(
                'order'   => $order,
                'send'    => true,
                'alert'   => false,
                'publish' => true,
                'text'    => $label . ' — ' . 'pas de confirmation du SIA après' . ' ' . self::seconds((int) $order['delay'])
                           . ', ' . 'nouvel essai' . ' (' . $order['attempt'] . '/' . ((int) $order['attempt'] + (int) $order['retries']) . ') '
                           . 'à' . ' ' . date('H:i:s', $_now),
                'pending' => 1,
                'failed'  => 0,
            );
        }
        $order['status'] = 'failed';
        $order['failedAt'] = $_now;
        $attempts = (int) $order['attempt'];
        return array(
            'order'   => $order,
            'send'    => false,
            'alert'   => true,
            'publish' => true,
            'text'    => $label . ' — ' . 'NON confirmé par le SIA' . ' (' . $attempts . ' ' . (($attempts > 1) ? 'essais' : 'essai')
                       . ', ' . self::seconds((int) $order['delay']) . ' ' . (($attempts > 1) ? 'chacun' : 'd\'attente') . ')',
            'pending' => 0,
            'failed'  => 1,
        );
    }

    /* Ordre abandonné faute de pouvoir l'envoyer (commande cloud disparue
     * entre deux essais) : c'est un échec, dit comme tel. */
    public static function abort($_order, $_reason, $_now) {
        $order = $_order;
        $order['status'] = 'failed';
        $order['failedAt'] = $_now;
        return array(
            'order'   => $order,
            'send'    => false,
            'alert'   => true,
            'publish' => true,
            'text'    => self::$_orderLabels[$_order['key']] . ' — ' . 'NON confirmé' . ' : ' . $_reason,
            'pending' => 0,
            'failed'  => 1,
        );
    }

    /* ================================================== SURVEILLANCE CROISÉE */

    /*
     * Compare le mode du SIA à celui du cloud. $_episode : ce qu'on a retenu
     * du dernier passage ('since' : début de la divergence, 'alerted' : alerte
     * déjà donnée, 'kind'). Le SIA reste la source de vérité : ceci ne change
     * jamais le mode du hub, ça ne fait que le signaler.
     *
     * Rend :
     *   episode   à mémoriser ;
     *   coherent  1, 0, ou null s'il n'y a rien à conclure (valeur inconnue,
     *             ordre en cours) : la commande garde sa valeur ;
     *   alert     'divergence', 'silent' (le SIA se tait, le cloud dit autre
     *             chose) ou null — une seule fois par épisode ;
     *   recovered vrai au retour à la normale d'un épisode signalé.
     */
    public static function cloudCheck($_episode, $_siaMode, $_cloudMode, $_siaSilent, $_orderPending, $_now, $_tolerance) {
        $episode = is_array($_episode) ? $_episode : array();
        $episode += array('since' => 0, 'alerted' => 0, 'kind' => '');
        $result = array('episode' => $episode, 'coherent' => null, 'alert' => null, 'recovered' => false);

        /* Rien de comparable : une valeur du cloud absente de la table (PANIC,
         * valeur inconnue), ou un SIA qui n'a encore rien dit. On ne conclut
         * ni dans un sens ni dans l'autre. */
        if ($_cloudMode === null || $_siaMode === null) {
            return $result;
        }
        /* Un ordre en cours fait diverger les deux par nature, le temps que
         * l'un puis l'autre suivent ; il a sa propre alerte. */
        if ($_orderPending) {
            return $result;
        }
        if ($_siaMode === $_cloudMode) {
            if (!empty($episode['alerted'])) {
                $result['recovered'] = true;
            }
            $result['episode'] = array('since' => 0, 'alerted' => 0, 'kind' => '');
            $result['coherent'] = 1;
            return $result;
        }
        $kind = $_siaSilent ? 'silent' : 'divergence';
        if (empty($episode['since'])) {
            $episode['since'] = $_now;
        }
        if (empty($episode['alerted']) && $_now - (int) $episode['since'] >= $_tolerance) {
            $episode['alerted'] = 1;
            $episode['kind'] = $kind;
            $result['alert'] = $kind;
        }
        $result['episode'] = $episode;
        /* Un cloud en retard n'est pas incohérent : la commande ne passe à 0
         * qu'au-delà de la tolérance. */
        $result['coherent'] = empty($episode['alerted']) ? 1 : 0;
        return $result;
    }

    /* ================================================================= TEXTES */

    /* « 4 s », « 95 s », « 2 min 5 s » : en secondes jusqu'à deux minutes,
     * l'unité dans laquelle le délai se règle. */
    public static function seconds($_seconds) {
        $s = max(0, (int) $_seconds);
        if ($s < 120) {
            return $s . ' s';
        }
        $rest = $s % 60;
        return floor($s / 60) . ' min' . ($rest > 0 ? ' ' . $rest . ' s' : '');
    }

    private static function ordinal($_n) {
        return ($_n == 1) ? '1er' : $_n . 'e';
    }
}
