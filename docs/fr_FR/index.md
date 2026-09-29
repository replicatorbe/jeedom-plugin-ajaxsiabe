# Plugin Ajax SIA

Le plugin reçoit en local les événements d'une alarme **Ajax**. Il utilise le
protocole **SIA DC-09**, celui des centres de télésurveillance : Jeedom tient
ce rôle, et le hub lui envoie ce qu'il enverrait à un télésurveilleur.

Ni cloud, ni compte Ajax, ni dépendance à installer. Le récepteur est un démon
PHP qui écoute sur le réseau local.

## Ce que le plugin reçoit

- l'**armement**, le **désarmement** et le **mode nuit**, avec le nom de
  l'utilisateur, du clavier ou de la télécommande qui les a faits ;
- les **alarmes** : intrusion, incendie, inondation, gaz, panique, agression,
  médicale ;
- les **sabotages**, les **coupures et retours du secteur**, les **batteries
  faibles** ;
- les **pertes de liaison** des appareils et le **brouillage radio** ;
- les **tests de liaison** périodiques. Ils permettent à Jeedom de voir le
  hub se taire.

### Ce qu'il ne reçoit pas

Le SIA est un protocole d'alarme, et il ne va que dans un sens. Il transmet
des événements, pas des états continus. On n'y trouve ni l'ouverture d'une
porte quand le système est désarmé, ni les températures. Il ne permet pas non
plus d'armer ou de désarmer le système depuis Jeedom : pour cela, le hub
s'appuie sur le plugin officiel Ajax (cloud), voir
[Piloter l'alarme avec le plugin Ajax (cloud)](#piloter-lalarme-avec-le-plugin-ajax-cloud).

Le hub envoie ses messages sur le réseau local. S'il perd sa connexion
Ethernet ou Wi-Fi et bascule sur le réseau mobile, il ne peut plus joindre
l'adresse privée de Jeedom. La supervision de la liaison le signale.

## Installation

1. Installez le plugin, activez-le, puis démarrez le démon s'il ne démarre pas
   seul.
2. Dans la configuration du plugin, choisissez le **port de réception**
   (7777 par défaut, en TCP ; l'UDP est désactivé par défaut, les hubs Ajax
   n'en ont pas besoin). Si vous activez le chiffrement côté Ajax, saisissez
   la même **clé de chiffrement** : dès lors, tout message en clair est
   refusé.
3. Sur la page du plugin, le panneau **Raccorder un hub** donne tout ce qu'il
   faut recopier, avec un bouton de copie par valeur : adresse IP, port, un
   numéro d'objet libre, et la clé. Le bouton **Générer une clé** en crée une
   et l'enregistre aussitôt dans la configuration du plugin.

   Dans l'application **Ajax PRO** (gratuite), ouvrez Hub → Paramètres →
   **Centre de télésurveillance** et réglez :
   - Protocole : **SIA DC-09 (SIA-DCS)**. Contact ID (ADM-CID) est aussi pris
     en charge ;
   - Adresse IP : celle de Jeedom sur le réseau local. Port : celui du plugin ;
   - Numéro d'objet : le numéro de compte, de 3 à 16 caractères
     hexadécimaux ;
   - **Chiffrement** : recommandé, avec une clé de 16, 24 ou 32 caractères ;
   - **Intervalle de test (ping)** : court, de 1 à 5 minutes. C'est lui qui
     règle la supervision de la liaison, active après trois intervalles
     mesurés (ou tout de suite avec un délai saisi sur le hub).
4. Le hub apparaît dans le plugin dès son premier message (rechargez la
   page). Chaque appareil apparaît ensuite au premier événement qui le
   concerne. La création automatique s'arrête à cinq hubs ; au-delà, ou si
   « Créer les hubs inconnus » est décoché, ajoutez le hub à la main avec le
   bouton **Ajouter** et son numéro de compte. Les appareils (zones), eux, ne
   se créent pas à la main : ils naissent à leur premier événement, ou par le
   bouton **Nommer la zone** du journal.

La page du plugin affiche en permanence l'état du récepteur : port ouvert,
nombre de trames reçues, heure du dernier message de chaque hub.

### Configuration du plugin

| Réglage | Rôle |
|---|---|
| Port de réception | Port sur lequel les hubs envoient leurs messages (7777 par défaut, TCP). |
| Écouter aussi en UDP | Inutile pour Ajax, qui émet en TCP. En UDP, l'adresse d'un émetteur peut être usurpée. |
| Clé de chiffrement | La clé générale : elle sert aux hubs qui n'ont pas la leur. Dès qu'une clé s'applique à un hub, ses messages en clair sont refusés. |
| Refuser les messages mal datés | Coché par défaut, et à laisser coché : c'est la protection de la norme contre le rejeu d'une trame capturée. Une horloge de hub qui avance ou retarde de façon stable (2 minutes au plus) est mesurée et corrigée d'elle-même. Ne décocher que si le journal montre des refus « Mal daté » répétés. |
| Adresses autorisées | Adresses IP exactes des hubs, séparées par des virgules : ni plage, ni masque, ni nom d'hôte (une entrée invalide est refusée à l'enregistrement). Vide : tout émetteur est accepté. |
| Créer les hubs inconnus | Crée un hub au premier message d'un numéro de compte inconnu, cinq au plus. Décoché, ces messages sont accusés et gardés au journal, et le hub se crée à la main. |
| Journal conservé | Nombre de jours de journal SIA gardés, 50 Mo par jour au plus. |
| Port des ordres | Port local (127.0.0.1) par lequel Jeedom parle au démon. À changer seulement en cas de conflit, puis redémarrer le démon. |

## Équipements

### Hub

Il est créé au premier message d'un numéro de compte inconnu. Vous pouvez aussi
le créer à la main (bouton **Ajouter**) avant de configurer Ajax : un
équipement ajouté à la main est toujours un hub.

| Réglage | Rôle |
|---|---|
| Numéro de compte | Le numéro d'objet saisi dans Ajax PRO (3 à 16 caractères hexadécimaux selon la norme ; Jeedom en accepte de 1 à 16). |
| Clé de chiffrement | La clé propre à ce hub, stockée chiffrée. Vide, c'est la clé générale du plugin qui sert. |
| Liaison perdue après | En minutes. Vide : le délai vaut 2,5 fois l'intervalle médian mesuré entre les tests de liaison, plus 30 s, et jamais moins de trois minutes ; il faut trois intervalles mesurés (quatre tests) avant qu'il s'applique. Aucune perte n'est déclarée tant que le démon est arrêté : c'est alors le récepteur qui est sourd, pas le hub. |
| Créer les appareils | Crée une zone au premier événement d'un appareil. |
| Utilisateurs | Une ligne par utilisateur : `numéro=nom`. Exemple : `1=Jérôme`. Le journal montre le numéro transmis à chaque armement. |
| Groupes | En mode groupes seulement : `numéro=nom`, un par ligne. Déclarer les groupes active le suivi du mode groupe par groupe ; sans groupe déclaré, tout armement vaut pour le système entier. |

| Commande | Contenu |
|---|---|
| Mode | `Désarmé`, `Armé`, `Mode nuit` ou `Armé partiel`. Vide tant qu'aucun changement de mode n'a été reçu. Si des groupes sont déclarés sur le hub, c'est la synthèse des groupes : tous armés → Armé, tous désarmés → Désarmé, un mélange → Armé partiel. |
| Armée | 1 si le système est armé, partiellement armé ou en mode nuit. |
| Mode changé par | Nom de l'utilisateur ou de l'appareil ; à défaut le nom du groupe, ou « le système » pour un changement automatique. |
| Alarme | 1 tant qu'une alarme est en cours. Chaque nouvelle alarme redéclenche les scénarios, même si une autre était déjà en cours. |
| Type d’alarme | Intrusion, Incendie, Inondation, Gaz, Panique… |
| Origine de l’alarme | Nom de la zone qui a déclenché. |
| Alarme intrusion, incendie, inondation, gaz, panique | Une commande binaire par famille, masquée par défaut : 1 tant qu'une alarme de cette famille est en cours. Incendie couvre aussi la chaleur et le sprinkler, inondation le gel, panique l'agression, la contrainte, l'urgence et le médical. |
| Acquitter l’alarme | Remet l'alarme à zéro côté Jeedom. |
| Réinitialiser les défauts | Remet au repos sabotages, batteries, liaisons des appareils, brouillage et secteur, par exemple quand un rétablissement a été perdu pendant un arrêt de Jeedom. Un défaut toujours présent revient au prochain message du hub. Masquée par défaut. |
| Sabotage | 1 tant qu'au moins un sabotage est en cours. |
| Secteur | 0 pendant une coupure de courant. |
| Batterie faible | 1 tant qu'au moins une batterie est faible, hub compris. |
| Brouillage | 1 pendant un brouillage radio. |
| Liaison | 0 quand le hub se tait au-delà du délai de supervision. |
| Appareil injoignable | 1 tant qu'un appareil du hub a perdu sa liaison (même sans équipement de zone). |
| Dernier contact | Date du dernier message reçu, test de liaison compris. |
| Dernier utilisateur | Nom de l'utilisateur du dernier événement, vide s'il n'en concerne aucun. Masquée par défaut. |
| Dernière zone | Nom de l'appareil du dernier événement, vide s'il n'en concerne aucun. Masquée par défaut. |
| Catégorie du dernier événement | Alarme, Armement, Sabotage, Panne, Alimentation, Batterie, Liaison, Test, Accès, Système ou Information. Masquée par défaut. |
| Dernier événement | L'événement en toutes lettres, par exemple « Armement par Jérôme ». |
| Dernier code SIA | Le code brut, par exemple `CL`. |
| Armer, Mode nuit, Désarmer | Actions : l'ordre part par le plugin Ajax (cloud) et c'est le SIA qui le confirme. Refusées tant que l'onglet « Pilotage cloud » n'est pas réglé. Types génériques ALARM_ARMED, ALARM_SET_MODE et ALARM_RELEASED. |
| Panique | Action, masquée par défaut, confirmée par l'alarme panique du SIA. |
| Dernier ordre | « Armer — confirmé par le SIA à 22:03:14 (4 s) », « … — en attente de confirmation par le SIA », « … — NON confirmé par le SIA (2 essais, 60 s chacun) », « … — déjà dans cet état selon le SIA … : ordre non envoyé », « … — refusé : … ». |
| Ordre en cours | 1 entre l'envoi d'un ordre et sa confirmation (ou son échec). |
| Échec du dernier ordre | 1 quand le dernier ordre n'a pas été confirmé par le SIA ; historisée. Repasse à 0 au prochain ordre, ou si la confirmation arrive quand même. |
| État cloud | L'état du hub selon le cloud, traduit : Désarmé, Armé, Mode nuit, Armé partiel, ou « Inconnu (valeur) ». |
| Cohérence cloud | 1 tant que le SIA et le cloud sont d'accord (ou que l'écart reste dans la tolérance), 0 au-delà. |

**Fin d'une alarme.**

- Les alarmes d'intrusion (porte forcée et sortie comprises) restent
  actives jusqu'au désarmement complet, à leur annulation ou à
  l'acquittement : qu'une porte se referme ne prouve pas que l'intrus est
  reparti.
- Les alarmes de panique, d'agression, de contrainte, médicale et
  d'urgence restent actives jusqu'à leur annulation, au « système rétabli
  après alarme » d'Ajax ou à l'acquittement. Le désarmement ne les efface
  pas : un désarmement sous contrainte arrive justement avec son
  désarmement.
- Les alarmes techniques (incendie, chaleur, eau, gaz, gel, sprinkler)
  retombent quand leur détecteur revient au repos, chacune avec son propre
  rétablissement (un détecteur fumée et CO peut signaler les deux).
  Désarmer ne les efface pas : désarmer n'éteint pas un incendie.
- Désarmer un seul groupe n'efface rien.
- Sur une zone, « Alarme » repasse à 0 dès que le détecteur revient au
  repos ; c'est le hub qui garde la mémoire.
- Quand plusieurs alarmes sont en cours, « Type d’alarme » et « Origine de
  l’alarme » montrent la plus récente encore active.

**Nouvel événement.** « Dernier événement », « Dernier code SIA » et
« Catégorie du dernier événement » déclenchent les scénarios même quand la
valeur ne change pas. Deux armements de suite par la même personne sont donc
bien deux déclenchements. « Dernier utilisateur » et « Dernière zone » sont
mis à jour juste avant : un scénario déclenché par le dernier événement lit
les siens.

Exemples :

- incendie : déclencheur `#[Maison][Hub Ajax][Alarme incendie]# == 1` ;
- arrivée de Jérôme : déclencheur `#[Maison][Hub Ajax][Dernier événement]#`,
  condition `#[Maison][Hub Ajax][Dernier utilisateur]# == "Jérôme"` et
  `#[Maison][Hub Ajax][Mode]# == "Désarmé"`.

**Messages.** Un hub qui se tait au-delà du délai de supervision fait
apparaître un message dans le centre de messages de Jeedom, retiré dès qu'il
se manifeste. De même si le récepteur ne parvient pas à ouvrir son port.

### Zone (appareil)

Une zone correspond à un appareil Ajax, identifié par son numéro dans le hub.
Elle est créée sous le nom « Zone N ». **Renommez-la d'après l'appareil** :
son nom sert ensuite dans les événements et dans « Origine de l’alarme ».

Commandes : Alarme, Sabotage, Batterie faible, Liaison, Dernier événement.

Une zone peut être liée à son appareil du plugin Ajax (cloud) : voir
[Zones liées aux appareils du cloud](#zones-liées-aux-appareils-du-cloud).
Tout événement venu de l'appareil remet sa liaison à 1, sauf celui qui en
annonce la perte.

Supprimer une zone retire ses sabotages et batteries faibles en cours de la
synthèse du hub. Supprimer un hub supprime ses zones ; il sera recréé à son
prochain message si la création automatique est active.

## Piloter l'alarme avec le plugin Ajax (cloud)

L'objectif : **une seule alarme dans Jeedom**, le hub de ce plugin. Les
scénarios, JeedomConnect, Google Home ou un plugin de présence l'arment et le
désarment par ses commandes « Armer », « Mode nuit » et « Désarmer », et
lisent son « Mode ».

### Pourquoi deux plugins

- Le **SIA** est rapide (le hub envoie sa trame dans la seconde), local et
  signé de sa clé, mais **unidirectionnel** : Jeedom écoute, il ne peut rien
  commander.
- Le plugin officiel **Ajax** (`ajaxSystem`) passe par le cloud Jeedom puis
  le cloud Ajax. Il sait **armer et désarmer**, mais ses retours d'état
  arrivent en une seconde, en plusieurs minutes, ou pas du tout.

Le hub SIA donne donc ses ordres par le plugin Ajax, et c'est le SIA qui dit
s'ils ont pris effet. Le SIA reste la source de vérité de l'état : le
« Mode » et « Armée » du hub ne suivent jamais le cloud.

### Le cycle d'un ordre

1. « Armer » est exécuté (scénario, tableau de bord, assistant vocal…).
2. **Si le SIA indique déjà le mode demandé**, rien n'est envoyé :
   « Dernier ordre » dit « déjà dans cet état selon le SIA ». Le hub
   n'émettrait d'ailleurs aucune trame pour un mode qui ne change pas.
3. Sinon, la commande du plugin Ajax réglée pour cet ordre est lancée **en
   tâche de fond**, l'ordre attendu est mémorisé (il survit à un redémarrage
   de Jeedom), « Ordre en cours » passe à 1. L'action rend la main
   aussitôt : ni l'appelant ni le cron n'attendent le cloud.
4. Quand la trame SIA du mode demandé arrive (`CL` armement, `NL` mode nuit,
   `OP` désarmement, `PA` pour la panique), l'ordre est **confirmé** :
   « Armer — confirmé par le SIA à 22:03:14 (4 s) ».
5. Sans confirmation dans le **délai** (60 s par défaut, vérifié chaque
   minute), l'ordre est **renvoyé** (un nouvel essai par défaut).
6. Toujours rien : l'ordre est déclaré **en échec**. « Échec du dernier
   ordre » passe à 1, un message apparaît dans le centre de messages et les
   **actions d'échec** sont jouées une fois. Si la trame arrive quand même
   dans le quart d'heure, « Dernier ordre » le dit et l'échec est levé.

Un nouvel ordre **remplace** l'ordre en cours (le journal le mentionne). Un
autre mode reçu pendant l'attente (quelqu'un désarme au clavier) ne termine
pas l'ordre : on attend le mode demandé jusqu'à l'échéance.

**Garde-fous.** Sans commande réglée, ou si elle a été supprimée, l'action
est **refusée** : l'appelant reçoit une erreur et « Dernier ordre » dit
pourquoi. Une commande du plugin Ajax SIA lui-même est refusée (l'ordre
tournerait en rond). Aucun événement reçu, ni du SIA ni du cloud, ne donne
jamais d'ordre, et en particulier jamais de désarmement : seule une action
explicite le fait, et seul le cron renvoie un ordre non confirmé.

Chaque ordre, confirmation, nouvel essai ou échec est écrit dans le log
`ajaxsiabe` et dans le **Journal SIA**, entre les trames, avec le statut
« Jeedom ».

À savoir : si la case « Vérifier l'état avant exécution » du coeur est
active, le plugin Ajax ignore un ordre quand **son** état indique déjà le mode
demandé. Si le cloud se trompe, l'ordre n'est pas envoyé, le SIA ne confirme
rien et l'échec est signalé : c'est voulu, l'alarme n'est pas dans l'état
demandé.

### Réglages (onglet « Pilotage cloud » du hub)

| Réglage | Clé de configuration | Défaut |
|---|---|---|
| Commande du cloud pour Armer, Mode nuit, Désarmer, Panique | `order_arm_cmd`, `order_night_cmd`, `order_disarm_cmd`, `order_panic_cmd` (`#id#`) | vide : ordre refusé |
| Délai de confirmation (s) | `order_arm_delay`… | 60 (10 à 3600) |
| Nouvel(s) essai(s) | `order_arm_retries`… | 1 (0 à 5) |
| Actions en cas d'échec d'un ordre | `order_actions` | aucune |
| Commande d'état du cloud | `cloud_state_cmd` (`#id#`) | vide : pas de surveillance |
| Tolérance (min) | `cloud_tolerance` | 2 |
| Correspondance des valeurs | `cloud_state_map` | table d'ajaxSystem |
| Actions en cas de divergence | `cloud_actions` | aucune |

Les actions se choisissent comme dans un scénario (commande ou bloc
message, scénario, variable), avec titre et message. Balises des actions
d'échec : `#ordre#` (Armer…), `#mode#` (mode visé), `#hub#`, `#essais#`,
`#message#` (la phrase complète). Balises des actions de divergence :
`#hub#`, `#mode#` (mode SIA), `#etat_cloud#`, `#coherent#` (0 à l'alerte, 1
au retour), `#message#`.

### Surveillance croisée SIA et cloud

Avec une commande d'état du cloud réglée, le hub compare chaque minute (et
dès que l'état du cloud change) le mode du SIA à celui du cloud :

- « État cloud » montre l'état du cloud traduit ; « Cohérence cloud » vaut 1
  tant qu'ils sont d'accord ;
- un écart qui dure **au-delà de la tolérance** (2 min par défaut : le cloud
  suit souvent avec retard) fait passer « Cohérence cloud » à 0, écrit un
  message et joue les actions de divergence, **une fois par épisode** ;
- le **retour à la normale** est signalé à son tour (message retiré, actions
  rejouées avec `#coherent#` à 1) ;
- si le SIA **se tait** (liaison perdue, récepteur arrêté) alors que le cloud
  répond avec un autre état, l'alerte le dit comme tel. Le message de perte
  de liaison du hub indique aussi depuis quand le cloud Ajax a donné signe de
  vie : un hub muet en SIA mais vivant dans le cloud, c'est un problème de
  réseau local, pas une centrale éteinte ;
- pendant un ordre en cours, rien n'est comparé : l'ordre a sa propre
  alerte.

Le mode du hub ne bascule **jamais** sur la foi du cloud.

**Correspondance des valeurs.** Une ligne par valeur : `valeur=mode`, le mode
s'écrivant `Désarmé`, `Armé`, `Mode nuit`, `Armé partiel` (ou `disarmed`,
`armed`, `night`, `partial`). Vide, c'est la table du plugin Ajax, relevée
dans son code :

| Valeur de « Etat » (ajaxSystem) | Mode |
|---|---|
| `DISARMED`, `DISARMED_NIGHT_MODE_OFF`, `0` | Désarmé |
| `ARMED`, `ARMED_NIGHT_MODE_OFF`, `ARMED_NIGHT_MODE_ON`, `1` | Armé |
| `NIGHT_MODE`, `DISARMED_NIGHT_MODE_ON`, `2` | Mode nuit |
| `PARTIALLY_ARMED` | Armé partiel |

Le plugin Ajax convertit l'état poussé par le cloud (0, 1, 2) en `DISARMED`,
`ARMED`, `NIGHT_MODE` ; les autres valeurs viennent de l'API Ajax lors d'une
synchronisation. `PANIC` n'est pas un mode : une valeur absente de la table
s'affiche « Inconnu (valeur) » et n'est jamais comparée.

### Zones liées aux appareils du cloud

Sur une zone, **Appareil Ajax (cloud)** la lie à un appareil du plugin Ajax
(clé `cloud_eqLogic`, l'identifiant de l'équipement ajaxSystem). Tant que
**Nom Ajax dans les événements** est coché (clé `cloud_name`, 1 par défaut),
le nom de l'appareil, tel que le cloud le connaît et renommages compris, sert
dans « Origine de l’alarme », « Dernière zone » et les événements. Le bouton
**Reprendre nom et pièce** recopie son nom et son objet parent dans la zone
(à sauvegarder ensuite).

Il n'y a **pas de correspondance automatique fiable** : le plugin Ajax
n'enregistre pas le numéro de zone SIA de ses appareils (le cloud le
transmet dans ses événements, sous le nom `cmsDeviceIndex`, mais le plugin ne
le garde pas). Seul son champ **Numéro de l'équipement**, rempli à la main,
peut servir : quand il est égal au numéro de la zone, la page propose l'appareil
(« Suggestion … Lier »). Le lien reste une décision manuelle.

### Exemple : l'installation de référence

Hub SIA « Hub Ajax 2701 » (compte 2701), plugin Ajax avec son hub « Ajax
hub » : commande d'état « Etat » (#6793#), actions « Armement » (#6802#),
« Mode nuit » (#6803#), « Desarmement » (#6804#), « Panic » (#6805#).

| Réglage | Valeur |
|---|---|
| Armer | `#6802#`, 60 s, 1 essai |
| Mode nuit | `#6803#`, 60 s, 1 essai |
| Désarmer | `#6804#`, 60 s, 1 essai |
| Panique | vide (ou `#6805#`) |
| Commande d'état du cloud | `#6793#` |
| Tolérance | 2 min |
| Correspondance | vide (table par défaut) |

Le même réglage par l'API JSON-RPC de Jeedom (méthode `eqLogic::save`,
équipement 528) :

```json
{"id": 528, "eqType_name": "ajaxsiabe", "configuration": {
  "order_arm_cmd": "#6802#", "order_night_cmd": "#6803#", "order_disarm_cmd": "#6804#",
  "order_arm_delay": 60, "order_night_delay": 60, "order_disarm_delay": 60,
  "order_arm_retries": 1, "order_night_retries": 1, "order_disarm_retries": 1,
  "cloud_state_cmd": "#6793#", "cloud_tolerance": 2,
  "order_actions": [{"cmd": "#123#", "options": {"enable": "1", "title": "Alarme #hub#", "message": "#message#"}}],
  "cloud_actions": [{"cmd": "#123#", "options": {"enable": "1", "title": "Alarme #hub#", "message": "#message#"}}]
}}
```

(`#123#` : une commande de notification de votre choix.) Et pour une zone,
par exemple la zone liée à la « Baie vitrée salon » (équipement ajaxSystem
519) : `{"id": <id de la zone>, "eqType_name": "ajaxsiabe", "configuration":
{"cloud_eqLogic": "519", "cloud_name": 1}}`.

## Journal SIA

Le journal garde chaque trame reçue, lue en clair : acceptée, en double ou
refusée, test de liaison compris. Il est conservé 90 jours par défaut, dans
la limite de 50 Mo par jour, et exclu des sauvegardes de Jeedom. Les tests
de liaison automatiques sont masqués par défaut ; leur nombre s'affiche à
côté du compteur. Les refus répétés d'un même émetteur (bruit, adresse non
autorisée) sont résumés une fois par minute.

C'est l'outil pour mettre le raccordement au point et pour savoir ce que le
hub envoie :

- laissez **Suivi en direct** coché, sur « Aujourd'hui » ;
- faites un geste sur le système : armer, désarmer, ouvrir une porte armée,
  ouvrir un boîtier ;
- la ligne apparaît. Cliquez dessus pour voir la trame brute, son contenu
  déchiffré, l'écart d'horloge du hub et la réponse envoyée ;
- sous le détail, **Nommer la zone N** et **Nommer l'utilisateur N** donnent
  leur nom à l'appareil ou à la personne du geste que vous venez de faire. Le
  nom s'applique aussi aux événements passés.

Motifs de refus :

| Statut | Signification |
|---|---|
| En clair | Message non chiffré pour un compte dont la clé est connue : refusé, sans réponse. C'est ce qui empêche une machine du réseau de simuler un désarmement. |
| Clé fausse | Message chiffré que ni la clé du hub ni la clé générale ne déchiffrent. |
| Sans heure | Message chiffré sans horodatage, que la norme interdit. |
| Mal daté | Message chiffré daté de plus de 40 s dans le passé ou de 20 s dans le futur. La norme l'impose pour empêcher le rejeu d'une trame capturée. Le refus donne l'heure au hub, qui se recale et renvoie son message. Un hub dont l'horloge avance ou retarde (de 2 minutes au plus) est mesuré sur ses messages : après trois mesures concordantes, la fenêtre est centrée sur son heure à lui. |
| CRC faux, Illisible | Trame abîmée ou qui n'est pas du SIA DC-09. Une connexion qui en envoie cinq de suite est fermée. Une longueur annoncée fausse avec un CRC juste est acceptée et signalée par une icône. |
| Refusé | Adresse absente de la liste des adresses autorisées. |
| Doublon | Message réémis par le hub. Il reçoit un accusé de réception mais n'est traité qu'une fois. |
| Jeedom | Pas une trame : ce que Jeedom a fait lui-même (ordre passé par le cloud, confirmation, nouvel essai, échec, alerte de la surveillance croisée). |

## Santé

La page Santé de Jeedom indique pour le plugin : le port réellement ouvert,
le nombre de trames reçues et refusées, si le chiffrement est actif pour
chaque hub, et pour chaque hub son dernier message et son délai de
supervision.

## Idées de scénarios

- **Au désarmement par X** : allumer l'entrée, relancer le chauffage,
  annoncer l'arrivée de X.
- **À l'armement** : fermer les volets, passer le thermostat en absence,
  lancer une simulation de présence.
- **Sur une alarme d'intrusion** : prendre les captures des caméras de la
  zone et les envoyer en notification.
- **Sur une alarme d'inondation** : fermer la vanne d'arrivée d'eau.
- **Sur une perte de liaison** : prévenir par un autre canal que le cloud Ajax.
- **Au départ de tous** : exécuter « Armer » du hub, sans se soucier du
  cloud ; « Échec du dernier ordre » à 1 déclenche une notification.

## Sécurité

- Le port de réception n'a aucune raison d'être ouvert vers Internet.
- Activez le chiffrement dans Ajax PRO et saisissez la clé dans Jeedom. Sans
  clé, n'importe quel appareil du réseau local peut envoyer un faux message
  à Jeedom. Avec une clé, les messages en clair sont refusés.
- Renseignez la liste des **adresses autorisées** avec l'adresse IP du hub.
- Le journal contient les messages déchiffrés. La page n'est lisible que par
  un administrateur, et le dossier `data/` est protégé par un `.htaccess`.
  Sous un autre serveur web qu'Apache (nginx), interdisez l'accès à
  `plugins/ajaxsiabe/data/`.
