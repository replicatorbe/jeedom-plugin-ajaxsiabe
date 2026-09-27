# Changelog

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
