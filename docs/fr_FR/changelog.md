# Changelog

## 0.3 — 29/09/2026

Corrections après la mise en service sur un vrai hub Ajax.

- Horloge du hub : l'écart de chaque hub est mesuré et la fenêtre
  d'horodatage centrée dessus. Le hub essayé avance de 33 s : une fois le
  chiffrement activé, tous ses messages auraient été refusés.
- Noms des commandes « Type d’alarme », « Origine de l’alarme » et
  « Acquitter l’alarme » : Jeedom en retirait l'apostrophe. Les commandes
  déjà créées sont renommées à la mise à jour si elles n'ont pas été
  personnalisées.
- Supervision automatique : attend trois intervalles de test mesurés. Le
  premier test suit l'enregistrement des réglages dans Ajax PRO et ne donne
  pas le vrai rythme.
- Codes alignés sur la table officielle des codes d'événements d'Ajax :
  NC (mode nuit par scénario, pris pour un état du réseau), NP (mode nuit
  désactivé par un utilisateur, qui créait une zone), AF (armement par
  scénario, qui créait une zone), OR (acquittement après alarme, pris pour
  un désarmement), HV (agression confirmée, absent), SM/SC (appareil
  déplacé : sabotage), YA (batterie reconnectée), chambre de fumée,
  court-circuit, firmware, liaison photo.
- Contact ID selon Ajax : capot du hub (145), clavier et badge (409, qui
  créaient une zone), contrainte (423, pris pour une porte forcée),
  notifications du capot coupées (383, prises pour un sabotage), mode nuit
  de groupe (442), et 129, 139, 142, 154, 300, 305, 306, 308, 330, 337,
  353, 354, 389, 391, 393, 406, 455, 461, 550, 570, 573, 627, 750.
- Armement de groupe (CG) : le groupe nommé est « Armé », le hub « Armé
  partiel » seulement si d'autres groupes ne le sont pas.

Relecture complète du 29/09/2026.

- Sécurité :
  - le point d'entrée du démon n'accepte plus que la clé du plugin, depuis
    la machine elle-même : la clé API d'un simple utilisateur ou un en-tête
    X-Real-IP suffisaient à lire les clés AES et à simuler un désarmement ;
  - texte d'un événement assaini avant d'arriver au dashboard (injection de
    script par une trame fabriquée) ;
  - rejeu d'une trame chiffrée avec une autre séquence reconnu comme
    doublon ; rejeu de vieilles trames sans effet sur l'horloge apprise ;
    compte chiffré différent de l'en-tête refusé ;
  - adresses autorisées vérifiées à l'enregistrement (une plage ou un nom
    étaient ignorés, ce qui laissait tout passer).
- Fiabilité :
  - un lot que Jeedom n'a pas pu traiter (redémarrage, base indisponible)
    est renvoyé au lieu d'être perdu ;
  - lots traités un à la fois, et commandes publiées après l'enregistrement
    de l'état : un acquittement pendant une alarme n'est plus écrasé ;
  - état (mode, alarmes, défauts) gardé en base : une coupure de courant ne
    le fait plus revenir 30 minutes en arrière ;
  - horloge des hubs écrite sur disque seulement quand elle change, pour les
    seuls hubs connus.
- Logique :
  - « Mode changé par », « Dernier utilisateur », « Dernière zone »… sont à
    jour quand un scénario se déclenche sur « Mode » ou « Alarme » ;
  - une alarme par zone et par type : un détecteur fumée et CO garde son
    alarme incendie quand le CO retombe ;
  - le désarmement n'efface plus que l'intrusion : panique, agression,
    contrainte et médicale attendent l'acquittement ;
  - bouton panique de l'application (utilisateur 501) : plus de
    « Zone 501 » ;
  - nouvelles commandes « Réinitialiser les défauts » et « Appareil
    injoignable » ; « Appareil ne répond pas » (YX) marque la liaison
    perdue ; batterie absente et batterie faible suivies séparément ;
  - zone renumérotée : ses défauts quittent l'ancien numéro ; suppression
    d'un hub : son état et son message d'alerte sont bien effacés.
- Interface : sélecteur de type inutilisable retiré, journal et bandeau qui
  ne s'empilent plus (ni en onglet caché), copie qui signale un échec,
  bandeau qui ne tourne plus sans fin, accessibilité des boutons,
  documentation des réglages.

## 0.2 — 27/09/2026

- Assistant de raccordement : les valeurs à recopier dans Ajax PRO, un bouton
  de copie par valeur, et une clé générée en un clic.
- Journal : boutons « Nommer la zone » et « Nommer l'utilisateur » sous le
  détail d'un événement.
- Commandes pour les scénarios : une alarme binaire par famille (intrusion,
  incendie, inondation, gaz, panique), dernier utilisateur, dernière zone,
  catégorie du dernier événement.
- Message Jeedom quand un hub se tait ou que le récepteur n'écoute pas.
- Page Santé : réception, chiffrement, dernier message de chaque hub.

## 0.1 — 27/09/2026

Première version.

- Récepteur SIA DC-09 (SIA-DCS et Contact ID) en TCP et UDP, avec accusé de
  réception immédiat, chiffrement AES 128, 192 ou 256 bits et contrôle de
  l'horodatage.
- Découverte automatique des hubs et de leurs appareils.
- Mode, alarmes, sabotages, secteur, batteries, liaisons et brouillage.
- Supervision de la liaison réglée seule sur l'intervalle des tests du hub.
- Journal SIA de toutes les trames reçues, avec suivi en direct.
- Messages en clair refusés dès qu'une clé est connue ; clés des hubs
  stockées chiffrées.
- Mode suivi par groupe ; le désarmement n'efface plus les alarmes
  techniques en cours.
- Réception protégée contre les connexions muettes, le bruit et les
  changements de port impossibles.
