# Ajax SIA — plugin Jeedom

Reçoit en local les événements d'une alarme **Ajax** par le protocole **SIA
DC-09** des centres de télésurveillance. Jeedom tient le rôle du centre de
télésurveillance : aucun cloud, aucun compte, aucune dépendance.

- Armement, désarmement et mode nuit, avec le nom de l'utilisateur.
- Alarmes intrusion, incendie, inondation, gaz, panique…
- Sabotages, coupures secteur, batteries faibles, pertes de liaison, brouillage.
- Messages chiffrés AES et contrôle de l'horodatage.
- Hub et appareils créés tout seuls au premier message.
- Journal de toutes les trames reçues, avec suivi en direct.
- Supervision de la liaison avec le hub.

Documentation : [docs/fr_FR/index.md](docs/fr_FR/index.md)

## Développement

- `php tests/test_codec.php` : jeu d'essai du codec SIA, sans Jeedom ni réseau.
- `php tests/test_daemon.php` : essai de bout en bout du démon contre un faux
  callback, sur des ports libres (quelques secondes).
- `php tools/sia-send.php --demo` : simulateur de hub, qui envoie une séquence
  d'événements au récepteur. `--help` pour les options.

La correspondance Contact ID → SIA reprend celle de
[pysiaalarm](https://github.com/eavanvalkenburg/pysiaalarm) (licence MIT).

## Licence

AGPL v3.
