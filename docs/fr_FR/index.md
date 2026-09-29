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
plus d'armer ou de désarmer le système depuis Jeedom.

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
Tout événement venu de l'appareil remet sa liaison à 1, sauf celui qui en
annonce la perte.

Supprimer une zone retire ses sabotages et batteries faibles en cours de la
synthèse du hub. Supprimer un hub supprime ses zones ; il sera recréé à son
prochain message si la création automatique est active.

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
