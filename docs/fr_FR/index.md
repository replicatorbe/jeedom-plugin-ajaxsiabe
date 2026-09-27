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
   (7777 par défaut). Si vous activez le chiffrement côté Ajax, saisissez la
   même **clé de chiffrement**.
3. Dans l'application **Ajax PRO** (gratuite), ouvrez Hub → Paramètres →
   **Centre de télésurveillance** et réglez :
   - Protocole : **SIA DC-09 (SIA-DCS)**. Contact ID (ADM-CID) est aussi pris
     en charge ;
   - Adresse IP : celle de Jeedom sur le réseau local. Port : celui du plugin ;
   - Numéro d'objet : le numéro de compte, de 3 à 16 caractères
     hexadécimaux ;
   - **Chiffrement** : recommandé, avec une clé de 16, 24 ou 32 caractères ;
   - **Intervalle de test (ping)** : court, de 1 à 5 minutes. C'est lui qui
     règle la supervision de la liaison.
4. Le hub apparaît dans le plugin dès son premier message. Chaque appareil
   apparaît ensuite au premier événement qui le concerne.

La page du plugin affiche en permanence l'état du récepteur : port ouvert,
nombre de trames reçues, heure du dernier message de chaque hub.

## Équipements

### Hub

Il est créé au premier message d'un numéro de compte inconnu. Vous pouvez aussi
le créer à la main avant de configurer Ajax.

| Réglage | Rôle |
|---|---|
| Numéro de compte | Le numéro d'objet saisi dans Ajax PRO. |
| Clé de chiffrement | La clé propre à ce hub. Vide, c'est la clé générale du plugin qui sert. |
| Liaison perdue après | En minutes. Vide : le délai est déduit de l'intervalle mesuré entre deux tests de liaison. Le hub est déclaré perdu après deux tests et demi manqués, et jamais avant trois minutes. |
| Créer les appareils | Crée une zone au premier événement d'un appareil. |
| Utilisateurs | Une ligne par utilisateur : `numéro=nom`. Exemple : `1=Jérôme`. Le journal montre le numéro transmis à chaque armement. |
| Groupes | En mode groupes : `numéro=nom`. |

| Commande | Contenu |
|---|---|
| Mode | `Désarmé`, `Armé`, `Mode nuit` ou `Armé partiel`. |
| Armée | 1 si le système est armé ou en mode nuit. |
| Mode changé par | Nom de l'utilisateur ou de l'appareil. |
| Alarme | 1 tant qu'une alarme est en cours. |
| Type d'alarme | Intrusion, Incendie, Inondation, Gaz, Panique… |
| Origine de l'alarme | Nom de la zone qui a déclenché. |
| Acquitter l'alarme | Remet l'alarme à zéro côté Jeedom. |
| Sabotage | 1 tant qu'au moins un sabotage est en cours. |
| Secteur | 0 pendant une coupure de courant. |
| Batterie faible | 1 tant qu'au moins une batterie est faible, hub compris. |
| Brouillage | 1 pendant un brouillage radio. |
| Liaison | 0 quand le hub se tait au-delà du délai de supervision. |
| Dernier contact | Date du dernier message reçu, test de liaison compris. |
| Dernier événement | L'événement en toutes lettres, par exemple « Armement par Jérôme ». |
| Dernier code SIA | Le code brut, par exemple `CL`. |

**Fin d'une alarme.** Une alarme d'intrusion, de panique ou d'agression reste
active jusqu'au désarmement, à l'annulation ou à l'acquittement : qu'une porte
se referme ne prouve pas que l'intrus est reparti. Une alarme technique
(incendie, eau, gaz, gel) retombe quand son détecteur revient au repos.

**Nouvel événement.** « Dernier événement » et « Dernier code SIA »
déclenchent les scénarios même quand la valeur ne change pas. Deux armements
de suite par la même personne sont donc bien deux déclenchements.

### Zone (appareil)

Une zone correspond à un appareil Ajax, identifié par son numéro dans le hub.
Elle est créée sous le nom « Zone N ». **Renommez-la d'après l'appareil** :
son nom sert ensuite dans les événements et dans « Origine de l'alarme ».

Commandes : Alarme, Sabotage, Batterie faible, Liaison, Dernier événement.

## Journal SIA

Le journal garde chaque trame reçue, lue en clair : acceptée, en double ou
refusée, test de liaison compris. Il est conservé 90 jours par défaut.

C'est l'outil pour mettre le raccordement au point et pour savoir ce que le
hub envoie :

- cochez **Suivi en direct** ;
- faites un geste sur le système : armer, désarmer, ouvrir une porte armée,
  ouvrir un boîtier ;
- la ligne apparaît. Cliquez dessus pour voir la trame brute, son contenu
  déchiffré, l'écart d'horloge du hub et la réponse envoyée.

Motifs de refus :

| Statut | Signification |
|---|---|
| Clé fausse | Message chiffré que ni la clé du hub ni la clé générale ne déchiffrent. |
| Mal daté | Message chiffré daté de plus de 40 s dans le passé ou de 20 s dans le futur. La norme l'impose pour empêcher le rejeu d'une trame capturée. Le refus donne l'heure au hub, qui se recale et renvoie son message. |
| CRC faux, Longueur fausse, Illisible | Trame abîmée ou qui n'est pas du SIA DC-09. |
| Refusé | Adresse absente de la liste des adresses autorisées. |
| Doublon | Message réémis par le hub. Il reçoit un accusé de réception mais n'est traité qu'une fois. |

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
- Activez le chiffrement dans Ajax PRO. Sans lui, n'importe quel appareil du
  réseau local peut envoyer un faux message à Jeedom.
- Renseignez la liste des **adresses autorisées** avec l'adresse IP du hub.
- Le journal contient les messages déchiffrés. Il n'est lisible que par un
  administrateur.
