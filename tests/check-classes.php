<?php
/* Contrôles par réflexion contre le coeur de Jeedom installé, communs à tous
 * les plugins.
 *
 *   php tests/check-classes.php              # tout, Jeedom requis
 *   php tests/check-classes.php --statique   # la lecture seule, sans Jeedom
 *   php ~/dev/tools/check-classes.php [--statique] [dossier_du_plugin]
 *
 * Ces pièges ont ceci de commun qu'ils sont invisibles à la relecture,
 * invisibles à « php -l », et invisibles au jeu d'essai hors ligne : ils ne se
 * manifestent que dans un vrai Jeedom, et leur symptôme ne ressemble pas à leur
 * cause. Tous se vérifient en quelques lignes de réflexion.
 *
 * Une partie se lit pourtant dans le texte seul (1, 2, 4, 5, 6, 8, 9, 10) : le
 * mode --statique ne fait qu'elle, pour tourner là où Jeedom n'est pas — une
 * intégration continue, un poste de développement. Il échoue s'il n'a rien pu
 * contrôler : un contrôle qui ne trouve rien à lire ne prouve rien.
 *
 * Toutes les classes de core/class/*.class.php sont lues, et chacune selon ce
 * qu'elle est : les pièges de DB::save() et d'utils::a2o() ne concernent que
 * les classes eqLogic et cmd — et les traits, qui finissent dedans.
 *
 * Le fichier est générique : l'identifiant du plugin est lu dans
 * plugin_info/info.json. Copié tel quel dans le dossier tests/ d'un plugin, il
 * contrôle ce plugin ; lancé depuis ~/dev/tools, il contrôle le dossier passé
 * en argument, ou à défaut le plugin du dossier courant. */

$arguments = array_slice(isset($argv) ? $argv : array(), 1);
$statique = in_array('--statique', $arguments, true);
$cheminsFournis = array_values(array_filter($arguments, function ($_a) { return strpos($_a, '--') !== 0; }));

/* La racine du plugin : l'argument, sinon le parent de ce fichier s'il est dans
 * un plugin, sinon le premier dossier de plugin en remontant depuis le dossier
 * courant. */
if (count($cheminsFournis) > 0) {
    $racine = $cheminsFournis[0];
} elseif (is_file(__DIR__ . '/../plugin_info/info.json')) {
    $racine = __DIR__ . '/..';
} else {
    $racine = getcwd();
    while (!is_file($racine . '/plugin_info/info.json') && dirname($racine) !== $racine) {
        $racine = dirname($racine);
    }
}
$racine = realpath($racine);
$infos = $racine ? json_decode((string) @file_get_contents($racine . '/plugin_info/info.json'), true) : null;
if (!is_array($infos) || empty($infos['id'])) {
    echo "Aucun plugin Jeedom trouvé : plugin_info/info.json absent ou illisible.\n";
    exit(1);
}
$id = $infos['id'];

if (!$statique) {
    $core = '/var/www/html/core/php/core.inc.php';
    if (!is_readable($core)) {
        echo "Jeedom introuvable : contrôle ignoré (php tests/check-classes.php --statique pour la lecture seule).\n";
        exit(0);
    }
    require_once $core;
}

$titre = $statique ? 'Contrôles statiques' : 'Contrôles du coeur';
$problems = array();
$controles = 0;

/* ------------------------------------------------------------------ 0 ---
 * Les sources, découpées classe par classe.
 *
 * Chaque morceau porte sa nature : `eqLogic` ou `cmd` s'il en hérite (même par
 * une classe intermédiaire du plugin), `trait` pour un trait, `autre` sinon. */
$fichiers = glob($racine . '/core/class/*.class.php');
if (!is_array($fichiers) || count($fichiers) === 0) {
    echo $titre . " : aucune classe trouvée dans core/class — rien n'a pu être contrôlé.\n";
    exit(1);
}
$sources = array();
$morceaux = array();
$parents = array();
foreach ($fichiers as $chemin) {
    $nom = 'core/class/' . basename($chemin);
    $source = file_get_contents($chemin);
    $sources[$nom] = $source;
    preg_match_all('/^[ \t]*(?:(?:abstract|final)\s+)*(class|trait)\s+(\w+)(?:\s+extends\s+\\\\?(\w+))?/m',
                   $source, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($m as $rang => $declaration) {
        $debut = $declaration[0][1];
        $fin = isset($m[$rang + 1]) ? $m[$rang + 1][0][1] : strlen($source);
        $classe = $declaration[2][0];
        $parent = (isset($declaration[3]) && $declaration[3][1] >= 0) ? $declaration[3][0] : '';
        $parents[$classe] = $parent;
        $morceaux[] = array('fichier' => $nom, 'classe' => $classe, 'trait' => ($declaration[1][0] === 'trait'),
                            'texte' => substr($source, $debut, $fin - $debut));
    }
}
/* La nature, en remontant les parents déclarés dans le plugin. */
function natureDe($_classe, $_parents) {
    $vus = array();
    while (isset($_parents[$_classe]) && !isset($vus[$_classe])) {
        $vus[$_classe] = true;
        $_classe = $_parents[$_classe];
        if ($_classe === 'eqLogic' || $_classe === 'cmd') {
            return $_classe;
        }
    }
    return 'autre';
}
foreach ($morceaux as $i => $morceau) {
    $morceaux[$i]['nature'] = $morceau['trait'] ? 'trait' : natureDe($morceau['classe'], $parents);
}
$duCoeur = array_filter($morceaux, function ($_m) { return $_m['nature'] !== 'autre'; });

/* ------------------------------------------------------------------ 1 ---
 * Toute propriété d'une classe eqLogic ou cmd doit commencer par un souligné.
 * DB::save() traite les autres comme des colonnes de la table : une propriété
 * « $refreshError » fait échouer la création d'un équipement sur « Unknown
 * column », sans que le journal du plugin en dise un mot. Les propriétés
 * statiques n'en sont pas, un type déclaré ne change rien. */
foreach ($duCoeur as $morceau) {
    $controles++;
    preg_match_all('/^\s*(?:private|protected|public|var)\s+(?:readonly\s+)?(?!static\b|function\b|const\b)'
                   . '(?:\??[\w\\\\|]+\s+)?\$(\w+)/m', $morceau['texte'], $m);
    foreach ($m[1] as $name) {
        if (strpos($name, '_') !== 0) {
            $problems[] = $morceau['classe'] . ' : propriété sans souligné initial : $' . $name
                . ' — DB::save() la prendra pour une colonne de la table.';
        }
    }
}

/* ------------------------------------------------------------------ 2 ---
 * Aucune méthode ne doit s'appeler « set » suivi d'une clé du formulaire, et
 * surtout pas setCmd(). À l'enregistrement, utils::a2o() appelle « set » + clé
 * pour chaque clé reçue, et la page envoie toujours une clé « cmd » : une
 * méthode privée de ce nom tue la sauvegarde sur une erreur fatale, avant toute
 * écriture. La page se rafraîchit, la saisie disparaît, le journal reste muet. */
$forbidden = array('setId', 'setName', 'setLogicalId', 'setGeneric_type', 'setObject_id',
                   'setEqType_name', 'setIsVisible', 'setIsEnable', 'setConfiguration',
                   'setTimeout', 'setCategory', 'setDisplay', 'setOrder', 'setComment',
                   'setTags', 'setCmd');
foreach ($duCoeur as $morceau) {
    $controles++;
    foreach ($forbidden as $name) {
        if (preg_match('/function\s+' . $name . '\s*\(/i', $morceau['texte'])) {
            $problems[] = $morceau['classe'] . ' : méthode interdite : ' . $name . '() — utils::a2o() '
                . 'l\'appellera à chaque enregistrement et tuera la sauvegarde.';
        }
    }
}

/* ------------------------------------------------------------------ 3 ---
 * Une méthode héritée ne peut pas voir sa visibilité réduite. eqLogic et cmd
 * exposent publiquement getCache(), setCache(), getStatus(), setStatus() et bien
 * d'autres : les redéclarer en privé est une erreur fatale AU CHARGEMENT de la
 * classe. Or le coeur charge la classe de chaque plugin actif sur chaque page —
 * toute l'interface de Jeedom tombe alors en HTTP 500, pas seulement le plugin.
 * Réflexion sur le coeur : hors mode statique seulement. */
if (!$statique) {
    $rank = array('private' => 0, 'protected' => 1, 'public' => 2);
    foreach ($duCoeur as $morceau) {
        $controles++;
        preg_match_all('/^\s*(private|protected|public)\s+(?:static\s+)?function\s+(\w+)/m',
                       $morceau['texte'], $m, PREG_SET_ORDER);
        $cibles = ($morceau['nature'] === 'trait') ? array('eqLogic', 'cmd') : array($morceau['nature']);
        foreach ($cibles as $parent) {
            $ref = new ReflectionClass($parent);
            foreach ($m as $declaration) {
                $visibility = $declaration[1];
                $name = $declaration[2];
                if (!$ref->hasMethod($name)) {
                    continue;
                }
                $inherited = $ref->getMethod($name);
                $parentVisibility = $inherited->isPrivate() ? 'private'
                    : ($inherited->isProtected() ? 'protected' : 'public');
                if ($rank[$visibility] < $rank[$parentVisibility]) {
                    $problems[] = $morceau['classe'] . ' : visibilité réduite sur une méthode héritée : '
                        . $name . '() est ' . $visibility . ' ici et ' . $parentVisibility . ' dans ' . $parent
                        . ' — erreur fatale au chargement, Jeedom entier en HTTP 500.';
                }
            }
        }
    }
}

/* ------------------------------------------------------------------ 4 ---
 * La classe de commande est obligatoire, même vide : core/ajax/eqLogic.ajax.php
 * refuse de créer ou d'ouvrir un équipement si elle manque. */
$controles++;
$cmdTrouvee = false;
foreach ($morceaux as $morceau) {
    if ($morceau['classe'] === $id . 'Cmd' && $morceau['nature'] === 'cmd') {
        $cmdTrouvee = true;
    }
}
if (!$cmdTrouvee) {
    $problems[] = 'Classe ' . $id . 'Cmd absente — impossible de créer un équipement.';
}

/* ------------------------------------------------------------------ 5 ---
 * Les points d'entrée que le coeur appelle sur la CLASSE et non sur un objet
 * doivent être déclarés static et publics.
 *
 * desktop/php/health.php teste method_exists() puis appelle <plugin>::health()
 * en statique : une méthode d'instance passe le test et lève une Error à
 * l'appel. Or le coeur l'entoure d'un catch (Exception), qui n'attrape pas les
 * Error de PHP 8. Ce n'est donc pas le plugin qui tombe, mais la page Santé de
 * toute l'installation, en HTTP 500. Le même raisonnement vaut pour les crons,
 * et pour onSource() : jeeListener.php l'appelle sur la classe, dans un
 * processus séparé où l'erreur ne se voit nulle part. */
$staticHooks = array('health', 'cron', 'cron5', 'cron10', 'cron15', 'cron30',
                     'cronHourly', 'cronDaily', 'deamon_info', 'deamon_start',
                     'deamon_stop', 'deamon_changeAutoMode', 'dependancy_info',
                     'dependancy_install', 'templateWidget', 'pull', 'onSource',
                     'sourcesCandidates');
foreach ($duCoeur as $morceau) {
    if ($morceau['nature'] === 'cmd') {
        continue;
    }
    $controles++;
    foreach ($staticHooks as $hook) {
        if (preg_match('/^\s*(private|protected|public)(\s+static)?\s+function\s+' . $hook . '\s*\(/mi',
                       $morceau['texte'], $m)) {
            if (!isset($m[2]) || trim($m[2]) === '') {
                $problems[] = $morceau['classe'] . ' : point d\'entrée non statique : ' . $hook
                    . '() — le coeur l\'appelle sur la classe, l\'Error qui en résulte n\'est pas '
                    . 'rattrapée et emporte la page qui l\'invoque.';
            }
            if (isset($m[1]) && $m[1] !== 'public') {
                $problems[] = $morceau['classe'] . ' : point d\'entrée non public : ' . $hook
                    . '() — le coeur ne pourra pas l\'appeler.';
            }
        }
    }
}

/* ------------------------------------------------------------------ 6 ---
 * Aucun nom de commande ne doit contenir d'apostrophe ni les neuf autres
 * caractères que cleanComponanteName() (core/php/utils.inc.php) RETIRE
 * silencieusement : « Niveau d'aspiration » devient « Niveau daspiration » sur
 * le tableau de bord, et rien n'en avertit. */
$interdits = array("\\'" => "'", '&' => '&', '#' => '#', ']' => ']', '[' => '[',
                   '%' => '%', '/' => '/', '"' => '"', '*' => '*');
foreach ($sources as $nom => $source) {
    $controles++;
    preg_match_all('/\x27name\x27\s*=>\s*__\(\x27((?:[^\x27\\\\]|\\\\.)*)\x27/', $source, $m);
    foreach (array_unique($m[1]) as $label) {
        foreach ($interdits as $motif => $caractere) {
            if (strpos($label, $motif) !== false) {
                $problems[] = $nom . ' : nom de commande contenant « ' . $caractere . ' » : '
                    . str_replace("\\'", "'", $label)
                    . ' — cmd::setName() le retirera sans prévenir.';
                break;
            }
        }
    }
}

/* ------------------------------------------------------------------ 7 ---
 * Les types génériques posés sur les commandes doivent exister dans le coeur.
 * Un type inventé n'est pas refusé : il est enregistré tel quel, et la commande
 * devient invisible pour tout ce qui range les équipements par type générique —
 * les assistants vocaux, les widgets, la vue Maison. Le plugin paraît alors
 * fonctionner, et ses données n'apparaissent nulle part ailleurs. */
if (!$statique) {
    $generics = jeedom::getConfiguration('cmd::generic_type');
    foreach ($sources as $nom => $source) {
        $controles++;
        /* Un type suivi d'une concaténation (« 'WEATHER_CONDITION_ID_' . $i ») n'est
         * qu'un préfixe : on ne juge que les types écrits en entier. */
        preg_match_all('/\x27generic\x27\s*=>\s*\x27([A-Z0-9_]+)\x27(?!\s*\.)/', $source, $m);
        foreach (array_unique($m[1]) as $generic) {
            if ($generic !== '' && !isset($generics[$generic])) {
                $problems[] = $nom . ' : type générique inconnu du coeur : ' . $generic
                    . ' — la commande sera ignorée par tout ce qui range les équipements par type générique.';
            }
        }
    }
}

/* ------------------------------------------------------------------ 8 ---
 * Un « Deny from all » au mauvais endroit coupe le plugin sans rien dire.
 *
 * Les dossiers servis au navigateur — core/ajax, desktop — doivent rester
 * accessibles : un .htaccess qui les ferme fait échouer chaque appel du plugin
 * en 403, et rien ne le montre côté Jeedom. L'interface tourne dans le vide, le
 * journal du plugin reste muet, et la cause n'apparaît que dans
 * /var/www/html/log/http.error : « client denied by server configuration ».
 *
 * C'est arrivé, sur core/ajax : le sélecteur de lampes d'un plugin frère
 * tournait indéfiniment.
 *
 * À l'inverse, plugin_info doit rester fermé SAUF aux images, sinon l'icône du
 * plugin est refusée et le menu de Jeedom affiche une image cassée. */
$controles++;
foreach (array('core/ajax', 'desktop', 'desktop/php', 'desktop/js', 'desktop/modal') as $dossier) {
    if (file_exists($racine . '/' . $dossier . '/.htaccess')) {
        $problems[] = 'Dossier servi au navigateur protégé par un .htaccess : ' . $dossier
            . ' — chaque appel finira en 403, sans trace côté Jeedom.';
    }
}
$icone = $racine . '/plugin_info/.htaccess';
if (file_exists($icone)) {
    $contenu = file_get_contents($icone);
    if (strpos($contenu, 'allow from all') === false) {
        $problems[] = 'plugin_info/.htaccess ferme tout : l\'icône du plugin sera refusée '
            . 'et le menu affichera une image cassée. Ajouter l\'exception <Files> sur les images.';
    }
}

/* ------------------------------------------------------------------ 9 ---
 * Les classes auxiliaires ne sont pas connues de l'autoload du coeur.
 *
 * jeedom::autoload() ne sait charger que la classe qui porte le nom du plugin
 * (core/php/core.inc.php) : toutes les autres — celles des autres fichiers de
 * core/class — n'existent que parce que <id>.class.php les require. Un
 * fichier servi au navigateur — page, modale, ajax, page de configuration —
 * qui en nomme une avant d'avoir touché à la classe principale meurt donc sur « Class
 * not found ».
 *
 * Et le symptôme ne désigne pas la cause : la page reste vide, le journal du
 * plugin ne dit rien, tout est dans /var/www/html/log/http.error. C'est arrivé
 * sur la page de configuration d'un plugin frère, à sa première ouverture. */
$auxiliaires = array();
foreach ($morceaux as $morceau) {
    if ($morceau['fichier'] !== 'core/class/' . $id . '.class.php' && !$morceau['trait']) {
        $auxiliaires[] = $morceau['classe'];
    }
}
if (count($auxiliaires) > 0) {
    $motifAux = '/\b(' . implode('|', array_map('preg_quote', $auxiliaires)) . ')::/';
    /* class_exists('<id>') compte comme un premier appel : il déclenche l'autoload. */
    $motifPremier = '/\b(' . preg_quote($id, '/') . '|' . implode('|', array_map('preg_quote', $auxiliaires)) . ')::'
                  . '|\bclass_exists\(\s*[\x27"](' . preg_quote($id, '/') . ')[\x27"]/';
    $servis = array_merge(glob($racine . '/plugin_info/*.php') ?: array(),
                          glob($racine . '/core/ajax/*.php') ?: array(),
                          glob($racine . '/desktop/php/*.php') ?: array(),
                          glob($racine . '/desktop/modal/*.php') ?: array());
    foreach ($servis as $chemin) {
        $controles++;
        $fichier = substr($chemin, strlen($racine) + 1);
        $contenu = file_get_contents($chemin);
        if (!preg_match($motifAux, $contenu)) {
            continue;
        }
        /* Soit le fichier charge la classe principale lui-même, soit il l'a
         * nommée avant, ce qui déclenche l'autoload et amène les autres. */
        if (preg_match('/require_once[^;]*' . preg_quote($id, '/') . '\.class\.php/', $contenu)) {
            continue;
        }
        if (!preg_match($motifPremier, $contenu, $premier)) {
            continue;
        }
        $premiere = ($premier[1] !== '') ? $premier[1] : $premier[2];
        if ($premiere === $id) {
            continue;
        }
        $problems[] = 'Classe auxiliaire nommée sans chargement : ' . $fichier
            . ' appelle ' . $premiere . ':: sans require_once de la classe principale '
            . '— l\'autoload du coeur ne la connaît pas, la page meurt sur « Class not found ».';
    }
}

/* ----------------------------------------------------------------- 10 ---
 * Chaque fichier de core/class doit être chargé par un autre.
 *
 * Même raison que le 9, vue de l'autre côté : un fichier ajouté au découpage
 * sans son require_once n'est jamais lu, et la première méthode qui y vit
 * meurt sur « Class not found » — dans un cron, là où personne ne regarde. */
foreach (array_keys($sources) as $nom) {
    if ($nom === 'core/class/' . $id . '.class.php') {
        continue;
    }
    $controles++;
    $motif = '/require(?:_once)?[^;]*[\/\x27"]' . preg_quote(basename($nom), '/') . '/';
    $charge = false;
    foreach ($sources as $autre => $source) {
        if ($autre !== $nom && preg_match($motif, $source)) {
            $charge = true;
            break;
        }
    }
    if (!$charge) {
        $problems[] = $nom . ' n\'est chargé par aucune autre classe — l\'autoload du coeur ne le '
            . 'trouvera pas, ses classes n\'existeront pas.';
    }
}

/* ---------------------------------------------------------------- BILAN --- */
/* Aucune classe eqLogic lue : les contrôles 1, 2 et 5 n'ont rien vu, et
 * « aucun problème » ne voudrait rien dire. */
if ($controles === 0 || count($duCoeur) === 0) {
    echo $titre . " : rien n'a pu être contrôlé (" . count($fichiers) . " fichier(s), "
        . count($duCoeur) . " classe(s) eqLogic/cmd).\n";
    exit(1);
}
if (empty($problems)) {
    echo $titre . ' : aucun problème (' . $controles . ' contrôle(s), ' . count($fichiers)
        . ' fichier(s), ' . count($morceaux) . " classe(s)).\n";
    exit(0);
}
echo $titre . " : " . count($problems) . " problème(s)\n";
foreach ($problems as $problem) {
    echo '  - ' . $problem . "\n";
}
exit(1);
