# Changelog

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
