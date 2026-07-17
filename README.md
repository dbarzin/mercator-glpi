# mercator-glpi — Connecteur GLPI → Mercator

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://php.net)
[![Laravel Zero](https://img.shields.io/badge/Laravel%20Zero-11.x-red)](https://laravel-zero.com)
[![Licence GPL](https://img.shields.io/badge/Licence-GPL-green)](LICENSE)

---

## Table des matières

- [À propos](#à-propos)
- [Architecture](#architecture)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [Configuration](#configuration)
  - [Fichier .env — référence complète](#fichier-env--référence-complète)
  - [Configuration côté GLPI](#configuration-côté-glpi)
  - [Configuration côté Mercator](#configuration-côté-mercator)
- [Utilisation](#utilisation)
  - [Synchronisation complète](#synchronisation-complète)
  - [Ordre d'exécution et dépendances](#ordre-dexécution-et-dépendances)
  - [Synchronisation par type](#synchronisation-par-type)
  - [Options disponibles](#options-disponibles)
  - [Exemples de commandes courantes](#exemples-de-commandes-courantes)
- [Types d'actifs synchronisés](#types-dactifs-synchronisés)
  - [Tableau de correspondance GLPI → Mercator](#tableau-de-correspondance-glpi--mercator)
  - [Détail des champs par type](#détail-des-champs-par-type)
- [Filtrage des actifs](#filtrage-des-actifs)
  - [Par entité GLPI](#par-entité-glpi)
  - [Par statut](#par-statut)
  - [Par sous-type (Computer)](#par-sous-type-computer)
  - [Par sous-type (NetworkEquipment)](#par-sous-type-networkequipment)
- [Planification automatique](#planification-automatique)
- [Logs et diagnostic](#logs-et-diagnostic)
  - [Activer les logs debug](#activer-les-logs-debug)
  - [Lire les logs](#lire-les-logs)
  - [Cas d'erreurs fréquents et solutions](#cas-derreurs-fréquents-et-solutions)
- [Étendre le connecteur](#étendre-le-connecteur)
- [Tests](#tests)
- [Licence](#licence)

---

## À propos

`mercator-glpi` est une application PHP en ligne de commande qui synchronise les actifs de votre inventaire **GLPI** vers la cartographie du système d'information **Mercator**.

La synchronisation est **unidirectionnelle** : GLPI est la source de vérité, Mercator est la destination. Aucune écriture n'est jamais effectuée dans GLPI.

Les actifs présents dans Mercator mais absents de GLPI sont **conservés** (comportement non-destructif par défaut). Ils peuvent avoir été créés manuellement ou provenir d'une autre source.

---

## Architecture

Le connecteur repose sur le patron **Strategy** : chaque type d'actif est géré par un Handler + un Mapper indépendants, orchestrés par un service central.

```
GlpiSyncCommand              — Commande CLI (glpi:sync), options, affichage
GlpiSyncService              — Orchestration : récupération, filtrage, création/mise à jour
  ├── SyncHandler             — Interface de chaque type d'actif
  │   ├── WorkstationSyncHandler            → endpoint: workstations
  │   ├── ApplicationSyncHandler            → endpoint: applications
  │   ├── PeripheralSyncHandler             → endpoint: peripherals
  │   ├── PhoneSyncHandler                  → endpoint: phones
  │   ├── NetworkDeviceSyncHandler          → endpoint: physical-switches
  │   ├── RouterSyncHandler                 → endpoint: physical-routers
  │   ├── WifiTerminalSyncHandler           → endpoint: wifi-terminals
  │   ├── PhysicalSecurityDeviceSyncHandler → endpoint: physical-security-devices
  │   ├── StorageDeviceSyncHandler          → endpoint: storage-devices
  │   ├── RackSyncHandler                   → endpoint: bays
  │   ├── ApplianceSyncHandler              → endpoint: activities
  │   ├── SiteSyncHandler                   → endpoint: sites
  │   ├── LocationSyncHandler               → endpoint: buildings
  │   ├── LogicalServerSyncHandler          → endpoint: logical-servers
  │   ├── PhysicalServerSyncHandler         → endpoint: physical-servers
  │   ├── CertificateSyncHandler            → endpoint: certificates
  │   └── ClusterSyncHandler                → endpoint: clusters
  ├── syncLinks()             — Liens workstation ↔ application (via Computer_SoftwareVersion)
  ├── syncActivityLinks()     — Liens activité ↔ application (via Appliance._items.Software)
  └── Mapper                  — Transformation champ à champ GLPI → Mercator
GlpiClient                   — Client HTTP GLPI (API REST v1, session-token)
MercatorClient               — Client HTTP Mercator (API REST, Bearer token)
```

**Clé de réconciliation** : les actifs sont mis en correspondance prioritairement via le champ Mercator `ext_refs`, qui porte un tag `{GLPI}<id>` (valeur multivaluée, séparée par `|`, ex. `{PROXMOX}vm123|{GLPI}42`). `strtolower(name)` n'est utilisé qu'en fallback, pour les items Mercator pas encore tagués (premier sync après migration, ou items créés manuellement portant le même nom). Une fois la correspondance établie, `ext_refs` est renseigné/mis à jour, ce qui garantit que les synchronisations suivantes (y compris après un renommage côté GLPI) continuent de cibler le même enregistrement Mercator. `ext_refs` est calculé de façon centralisée par `GlpiSyncService` (pas par les Mappers) et fusionné pour préserver les références issues d'autres sources.

**Traçabilité** : l'identifiant GLPI n'est jamais inscrit dans le champ `description` ; il est uniquement porté par `ext_refs`. `description` ne contient que le `comment` GLPI suivi des champs non mappés sérialisés.

**Champs non mappés** : les champs GLPI qui n'ont pas de champ Mercator dédié (ex. numéro de série alternatif, statut, type de baie…) sont automatiquement sérialisés à la suite de la description au format `"nom_champ" : "valeur"`. Les champs vides, nuls ou à 0 sont ignorés. Les structures complexes (`_networkports`, `_devices`…) sont également ignorées.

**Pagination** : chaque `SyncHandler::glpiQueryParams()` demande une première page de 1000 items (`range=0-999`), mais `GlpiClient::getItems()` boucle automatiquement sur les pages suivantes en s'appuyant sur le header `Content-Range` renvoyé par GLPI (`start-end/total`) jusqu'à récupérer la collection complète. Aucune collection GLPI (Software, Computer…) n'est donc tronquée au-delà de 1000 items ; il n'y a pas de réglage à activer, c'est automatique.

---

## Prérequis

| Composant | Version minimale |
|---|---|
| PHP | 8.2 |
| Composer | 2.x |
| GLPI | 10.x |
| Mercator | dernière version stable |
| Extension PHP `curl` | — |
| Extension PHP `json` | — |

L'API REST GLPI doit être activée et les tokens configurés (voir [Configuration côté GLPI](#configuration-côté-glpi)).

**Mémoire** : la commande `application` relève automatiquement `memory_limit` à 512M pour son propre process si la valeur PHP CLI configurée est inférieure (sans jamais abaisser une valeur déjà plus haute, ni toucher à une limite illimitée `-1`) — un sync complet charge en mémoire des collections GLPI entières (paginées au-delà de 1000 items, cf. [Filtrage des actifs](#filtrage-des-actifs)), ce qui peut dépasser le défaut PHP courant (souvent 128M). Ce réglage ne s'applique qu'à ce process CLI, jamais au PHP-FPM/Apache qui sert vos autres applications. Pour un très gros parc GLPI, si 512M ne suffit toujours pas, augmentez `memory_limit` dans le php.ini utilisé par la CLI (ou lancez avec `php -d memory_limit=1G application glpi:sync`).

---

## Installation

```bash
# 1. Cloner le dépôt
git clone https://github.com/dbarzin/mercator-glpi
cd mercator-glpi

# 2. Installer les dépendances
composer install --no-dev --optimize-autoloader

# 3. Copier et compléter la configuration
cp .env.sample .env
# Renseignez les valeurs dans .env (voir section Configuration)
```

### GLPI en Docker (développement / test)

Un stack Docker est fourni pour tester contre une instance GLPI locale :

```bash
./glpi.sh start    # Démarre GLPI sur http://localhost:8080
./glpi.sh status   # Affiche l'état des conteneurs
./glpi.sh logs     # Consulte les logs en temps réel
./glpi.sh stop     # Arrête les conteneurs
```

Lors du premier démarrage, un wizard d'installation s'affiche dans le navigateur. Renseignez les paramètres de base de données suivants :

| Champ | Valeur |
|---|---|
| Serveur SQL | `glpi-db` |
| Utilisateur | `glpi` |
| Mot de passe | `glpi` |
| Base de données | `glpi` |

---

## Configuration

### Fichier .env — référence complète

Copiez `.env.sample` vers `.env` et renseignez les valeurs :

| Variable | Défaut | Description                                                                                                                                                               | Exemple                                                |
|---|---|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------|
| `GLPI_URL` | — | URL de base de l'instance GLPI (sans slash final)                                                                                                                         | `http://127.0.0.1:8080` ou `https://glpi.domain.local` |
| `GLPI_APP_TOKEN` | — | Token applicatif GLPI (Configuration → General → API → Clients de l'API → GLPI)                                                                                            | `abc123…`                                              |
| `GLPI_USER_TOKEN` | — | Token utilisateur GLPI (Administration → Users → GLPI → Jeton d'API)                                                                                                      | `xyz789…`                                              |
| `GLPI_ENTITY_ID` | _(vide)_ | ID de l'entité GLPI à synchroniser ; vide = toutes les entités                                                                                                            | `3`                                                    |
| `GLPI_ALLOWED_STATES` | _(vide)_ | Noms ou IDs de statuts autorisés, séparés par virgules ; vide = tous                                                                                                      | `En production,En stock`                               |
| `GLPI_ALLOWED_STATES_COMPUTERS` | _(vide)_ | Surcharge du filtre statut pour les `Computer`                                                                                                                            | `En production`                                        |
| `GLPI_ALLOWED_STATES_PHONES` | _(vide)_ | Surcharge du filtre statut pour les `Phone`                                                                                                                               | `En production`                                        |
| `GLPI_ALLOWED_STATES_PERIPHERALS` | _(vide)_ | Surcharge du filtre statut pour les `Peripheral`                                                                                                                          | —                                                      |
| `GLPI_ALLOWED_STATES_NETWORK_EQUIPMENT` | _(vide)_ | Surcharge du filtre statut pour les `NetworkEquipment`                                                                                                                    | —                                                      |
| `GLPI_ALLOWED_STATES_RACKS` | _(vide)_ | Surcharge du filtre statut pour les `Rack`                                                                                                                                | —                                                      |
| `GLPI_COMPUTER_TYPES_WORKSTATIONS` | _(vide)_ | Noms ou IDs de `computertypes` routés vers `workstations` ; vide = tous                                                                                                   | `Poste de travail,Laptop`                              |
| `GLPI_COMPUTER_TYPES_LOGICAL_SERVERS` | _(vide)_ | Noms ou IDs de `computertypes` routés vers `logical-servers` ; vide = désactivé                                                                                           | `Machine virtuelle`                                    |
| `GLPI_COMPUTER_TYPES_PHYSICAL_SERVERS` | _(vide)_ | Noms ou IDs de `computertypes` routés vers `physical-servers` ; vide = désactivé                                                                                          | `Serveur physique`                                     |
| `GLPI_NETWORK_DEVICE_TYPES_SWITCHES` | _(vide)_ | Noms ou IDs de `networkequipmenttypes` routés vers `physical-switches` ; vide = **tous** les `NetworkEquipment` non repris par les filtres ci-dessous (comportement historique) | `Switch`                                               |
| `GLPI_NETWORK_DEVICE_TYPES_ROUTERS` | _(vide)_ | Noms ou IDs de `networkequipmenttypes` routés vers `physical-routers` ; vide = désactivé                                                                                  | `Routeur`                                              |
| `GLPI_NETWORK_DEVICE_TYPES_WIFI_TERMINALS` | _(vide)_ | Noms ou IDs de `networkequipmenttypes` routés vers `wifi-terminals` ; vide = désactivé                                                                                    | `Borne Wifi`                                           |
| `GLPI_NETWORK_DEVICE_TYPES_PHYSICAL_SECURITY_DEVICES` | _(vide)_ | Noms ou IDs de `networkequipmenttypes` routés vers `physical-security-devices` ; vide = désactivé                                                                         | `Caméra IP`                                            |
| `GLPI_NETWORK_DEVICE_TYPES_STORAGE_DEVICES` | _(vide)_ | Noms ou IDs de `networkequipmenttypes` routés vers `storage-devices` ; vide = désactivé                                                                                   | `Baie de stockage`                                     |
| `GLPI_DOMAIN_TYPES` | _(vide)_ | Noms ou IDs de `domaintypes` autorisés pour les `Domain` ; vide = tous                                                                                                    | `Interne,Externe`                                      |
| `GLPI_SOFTWARE_CATEGORIES` | _(vide)_ | Noms ou IDs de `softwarecategories` autorisés pour les `Software` ; vide = toutes                                                                                        | `Bureautique,Navigateur`                               |
| `MERCATOR_URL` | — | URL de base de l'instance Mercator (sans slash final)                                                                                                                     | `https://mercator.acme.fr`                             |
| `MERCATOR_LOGIN` | — | Email du compte Mercator utilisé pour l'API                                                                                                                               | `sync@acme.fr`                                         |
| `MERCATOR_PASSWORD` | — | Mot de passe du compte Mercator                                                                                                                                           | `motdepasse`                                           |
| `SYNC_DRY_RUN` | `false` | Si `true`, simule sans écrire dans Mercator                                                                                                                               | `true`                                                 |
| `LOG_LEVEL` | `info` | Niveau de verbosité des logs (`debug`, `info`, `warning`, `error`)                                                                                                        | `debug`                                                |

### Configuration côté GLPI

#### 1. Activer l'API REST

> **Configuration → Générale → API**
> - Activer l'API REST : **Oui**
> - Activer la connexion avec credentials : **Oui**

#### 2. Créer l'`APP_TOKEN`

> **Configuration → Générale → API → Clients de l'API → Ajouter**
> - Nom : `mercator-glpi-connector`
> - Copiez le token généré → `GLPI_APP_TOKEN` dans `.env`

#### 3. Créer le `USER_TOKEN`

> **Mon compte → Mes préférences → Accès distant (API) → Régénérer**
> - Copiez le token → `GLPI_USER_TOKEN` dans `.env`

Le compte associé doit avoir accès en lecture aux types à synchroniser (Ordinateurs, Logiciels, Périphériques, Téléphones, Équipements réseau, Baies…).

#### 4. Vérifier la connexion

```bash
curl -H "Authorization: user_token $GLPI_USER_TOKEN" \
     -H "App-Token: $GLPI_APP_TOKEN" \
     "$GLPI_URL/apirest.php/initSession"
# Attendu : {"session_token": "..."}
```

### Configuration côté Mercator

Le compte Mercator utilisé doit disposer des droits d'écriture (création + modification) sur tous les endpoints synchronisés. Aucune configuration supplémentaire n'est requise côté Mercator.

---

## Utilisation

### Synchronisation complète

```bash
php application glpi:sync
```

Lance la synchronisation de tous les types dans l'ordre recommandé (voir ci-dessous).

### Ordre d'exécution et dépendances

Certains types doivent être synchronisés avant d'autres pour que les liens et résolutions de FK fonctionnent correctement :

```
 1. sites                       → crée les sites Mercator (racines des locations GLPI)
 2. locations                   → crée les buildings Mercator (building_id/site_id utilisés par les autres types)
 3. racks                       → crée les bays Mercator (bay_id résolu par network_devices/routers/physical_security_devices/storage_devices ci-dessous)
 4. applications                → crée le catalogue applicatif (nécessaire pour links et activity_links)
 5. appliances                  → crée les activities (nécessaire pour activity_links)
 6. workstations                ┐
 7. peripherals                 │
 8. phones                      │
 9. network_devices             │ peuvent s'exécuter dans n'importe quel ordre
10. routers                     │ une fois que sites/locations/racks sont faits
11. wifi_terminals              │
12. physical_security_devices   │
13. storage_devices             │
14. logical_servers             │
15. physical_servers            ┘
16. links                       → lie les workstations ↔ applications (nécessite 4 et 6)
17. activity_links               → lie les activities ↔ applications (nécessite 4 et 5)
```

Cet ordre est appliqué automatiquement lors de la synchronisation complète (`php application glpi:sync`).

`certificates` et `clusters` ne font **pas** partie de la synchronisation complète par défaut : ils ne sont exécutés que si on les demande explicitement via `--type=certificates` / `--type=clusters`.

### Synchronisation par type

```bash
php application glpi:sync --type=sites
php application glpi:sync --type=locations
php application glpi:sync --type=applications
php application glpi:sync --type=appliances
php application glpi:sync --type=workstations
php application glpi:sync --type=peripherals
php application glpi:sync --type=phones
php application glpi:sync --type=network_devices
php application glpi:sync --type=routers
php application glpi:sync --type=wifi_terminals
php application glpi:sync --type=physical_security_devices
php application glpi:sync --type=storage_devices
php application glpi:sync --type=racks
php application glpi:sync --type=logical_servers
php application glpi:sync --type=physical_servers
php application glpi:sync --type=certificates     # pas inclus dans la sync complète, opt-in
php application glpi:sync --type=clusters         # pas inclus dans la sync complète, opt-in
php application glpi:sync --type=links            # liens workstation ↔ application
php application glpi:sync --type=activity_links   # liens activité ↔ application
```

### Options disponibles

| Option | Défaut | Description |
|---|---|---|
| `--type=<type>` | tous les types | Type d'actif à synchroniser (répétable) |
| `--dry-run` | `false` | Simule la synchronisation sans écrire dans Mercator |
| `--entity=<id>` | valeur de `GLPI_ENTITY_ID` | Filtre sur une entité GLPI précise (récursif) |

### Exemples de commandes courantes

```bash
# Synchronisation complète en simulation (valider avant le premier run réel)
php application glpi:sync --dry-run

# Synchroniser uniquement les postes de travail de l'entité 3
php application glpi:sync --type=workstations --entity=3

# Synchroniser plusieurs types sans toucher aux autres
php application glpi:sync --type=workstations --type=applications

# Rafraîchir uniquement les liens (après ajout de logiciels dans GLPI)
php application glpi:sync --type=links --type=activity_links

# Vérifier que les équipements réseau sont bien mappés (simulation)
php application glpi:sync --dry-run --type=network_devices

# Synchroniser les serveurs logiques et physiques
php application glpi:sync --type=logical_servers --type=physical_servers
```

---

## Types d'actifs synchronisés

### Tableau de correspondance GLPI → Mercator

| Option `--type` | Source GLPI | Endpoint Mercator | Nature |
|---|---|---|---|
| `sites` | `Location` (racines, sans parent) | `sites` | actifs |
| `locations` | `Location` | `buildings` | actifs |
| `applications` | `Software` | `applications` | actifs |
| `appliances` | `Appliance` | `activities` | actifs |
| `workstations` | `Computer` (filtré par `GLPI_COMPUTER_TYPES_WORKSTATIONS`) | `workstations` | actifs |
| `peripherals` | `Peripheral` | `peripherals` | actifs |
| `phones` | `Phone` | `phones` | actifs |
| `network_devices` | `NetworkEquipment` (filtré par `GLPI_NETWORK_DEVICE_TYPES_SWITCHES` ; vide = tous les `NetworkEquipment` non repris par les filtres routers/wifi_terminals/physical_security_devices/storage_devices) | `physical-switches` | actifs |
| `routers` | `NetworkEquipment` (filtré par `GLPI_NETWORK_DEVICE_TYPES_ROUTERS`, opt-in) | `physical-routers` | actifs |
| `wifi_terminals` | `NetworkEquipment` (filtré par `GLPI_NETWORK_DEVICE_TYPES_WIFI_TERMINALS`, opt-in) | `wifi-terminals` | actifs |
| `physical_security_devices` | `NetworkEquipment` (filtré par `GLPI_NETWORK_DEVICE_TYPES_PHYSICAL_SECURITY_DEVICES`, opt-in) | `physical-security-devices` | actifs |
| `storage_devices` | `NetworkEquipment` (filtré par `GLPI_NETWORK_DEVICE_TYPES_STORAGE_DEVICES`, opt-in) | `storage-devices` | actifs |
| `racks` | `Rack` | `bays` | actifs |
| `logical_servers` | `Computer` (filtré par `GLPI_COMPUTER_TYPES_LOGICAL_SERVERS`) | `logical-servers` | actifs |
| `physical_servers` | `Computer` (filtré par `GLPI_COMPUTER_TYPES_PHYSICAL_SERVERS`) | `physical-servers` | actifs |
| `certificates` | `Certificate` (pas dans la sync complète par défaut) | `certificates` | actifs |
| `clusters` | `Cluster` (pas dans la sync complète par défaut) | `clusters` | actifs |
| `links` | `Computer_SoftwareVersion` | pivot `workstation_application` | liens |
| `activity_links` | `Appliance._items.Software` | pivot `activity_application` | liens |

### Détail des champs par type

#### Sites (`Location` racine → `sites`)

| Champ GLPI | Champ Mercator |
|---|---|
| `name` | `name` |
| `comment` | `description` |
| Champs non mappés | sérialisés dans `description` |

> Seules les `Location` GLPI sans parent (`locations_id` vide ou `0`) deviennent un Site Mercator. Les `Location` (`buildings`) racines pointent vers le Site créé pour la même racine — **synchroniser `sites` avant `locations`.**

#### Bâtiments (`Location` → `buildings`)

| Champ GLPI | Champ Mercator |
|---|---|
| `name` | `name` |
| `comment` | `description` |
| `locations_id` (location parente) | `building_id` (résolution par nom) ; hérite aussi `site_id` du parent (ou du Site racine si la Location est elle-même une racine) |
| Champs non mappés (adresse, ville…) | sérialisés dans `description` |

> Les bâtiments créés ici servent de référence pour résoudre `building_id` et `site_id` dans tous les autres types d'actifs (workstations, physical-switches…). **Synchroniser `sites` puis `locations` en premier.**
>
> La hiérarchie des localisations GLPI (salle → bâtiment parent) est préservée via `building_id`. Les données géographiques n'ayant pas de champ dédié dans le modèle `buildings` Mercator (adresse, code postal, ville, pays, GPS) sont sérialisées dans `description`.

#### Logiciels (`Software` → `applications`)

| Champ GLPI | Champ Mercator |
|---|---|
| `name` | `name` + `product` |
| `comment` | `description` |
| `manufacturers_id` | `vendor` + `editor` |
| `softwarecategories_id` | `type` |
| `users_id_tech` | `responsible` |
| `date` | `install_date` |

> Le champ Mercator `applications.name` est limité à 64 caractères : un nom de logiciel GLPI plus long est automatiquement tronqué à 64 caractères avant envoi (évite un rejet HTTP 422 "max characters"). `product` conserve le nom GLPI complet, non tronqué. La troncature est journalisée en `LOG_LEVEL=debug`.
>
> Le catalogue applicatif doit exister avant de synchroniser `links` et `activity_links`. **Synchroniser `applications` avant ces deux types.**
>
> `GLPI_SOFTWARE_CATEGORIES` filtre les `Software` par `softwarecategories_id` (noms ou IDs, séparés par virgules) ; vide = toutes catégories acceptées.
>
> **Attention** : un logiciel importé automatiquement par un agent d'inventaire GLPI n'a en général **aucune catégorie assignée** (`softwarecategories_id` vide/0) — assigner une catégorie est une action manuelle dans GLPI. Si vous configurez `GLPI_SOFTWARE_CATEGORIES` alors qu'aucun logiciel n'a de catégorie renseignée côté GLPI, **tous les `Software` seront exclus** (`Filtre sous-type : N item(s) exclus, 0 conservé(s)` dans les logs). En `LOG_LEVEL=debug`, chaque exclusion liste la valeur `softwarecategories_id` reçue pour diagnostiquer. Laissez la variable vide si vos logiciels ne sont pas catégorisés dans GLPI.

#### Activités (`Appliance` → `activities`)

| Champ GLPI | Champ Mercator |
|---|---|
| `name` | `name` |
| `comment` | `description` |
| `users_id_tech` | `responsible` |

#### Postes de travail / serveurs logiques / serveurs physiques (`Computer` → `workstations` / `logical-servers` / `physical-servers`)

`LogicalServerMapper` et `PhysicalServerMapper` délèguent entièrement à `WorkstationMapper` — seul le filtre de sous-type (`GLPI_COMPUTER_TYPES_*`) et l'endpoint Mercator changent.

| Champ GLPI | Champ Mercator |
|---|---|
| `name` | `name` |
| `comment` | `description` |
| `computertypes_id` | `type` |
| `manufacturers_id` | `manufacturer` |
| `computermodels_id` | `model` |
| `serial` | `serial_number` |
| `operatingsystems_id` | `operating_system` |
| `states_id` | `status` |
| `users_id` | `other_user` |
| `locations_id` | `building_id` + `site_id` (résolution par nom) |
| Premier port IPv4 | `address_ip` |
| Premier port MAC | `mac_address` |
| Type de port (Ethernet/Wifi) | `network_port_type` |
| `ram` | `memory` (formaté en Go ou Mo) |
| Premier CPU (`_devices`) | `cpu` |
| Somme des disques | `disk` |
| `date_last_boot` | `last_inventory_date` |
| `_infocoms.order_date` | `warranty_start_date` |
| `_infocoms.buy_date` | `purchase_date` |
| `_infocoms.warranty_expiration` | `warranty_end_date` |
| `_infocoms.warranty_duration` | `warranty_period` (formaté en mois) |
| `_infocoms.value` | `fin_value` |
| `"GLPI"` | `update_source` |

#### Équipements réseau — switches (`NetworkEquipment` → `physical-switches`)

| Champ GLPI | Champ Mercator |
|---|---|
| `name` | `name` |
| `comment` | `description` |
| `networkequipmenttypes_id` | `type` |
| `manufacturers_id` | `vendor` |
| `networkequipmentmodels_id` | `product` |
| `locations_id` | `building_id` + `site_id` (résolution par nom) |
| Rack GLPI (relation `Item_Rack`) | `bay_id` (résolu via la bay Mercator déjà synchronisée) |
| Champs non mappés (serial, statut…) | sérialisés dans `description` |

#### Équipements réseau — routeurs (`NetworkEquipment` → `physical-routers`)

Filtré par `GLPI_NETWORK_DEVICE_TYPES_ROUTERS` (opt-in, vide = aucun équipement synchronisé).

| Champ GLPI | Champ Mercator |
|---|---|
| `name` | `name` |
| `comment` | `description` |
| `networkequipmenttypes_id` | `type` |
| `locations_id` | `building_id` + `site_id` (résolution par nom) |
| Rack GLPI (relation `Item_Rack`) | `bay_id` (résolu via la bay Mercator déjà synchronisée) |

#### Équipements réseau — bornes Wifi (`NetworkEquipment` → `wifi-terminals`)

Filtré par `GLPI_NETWORK_DEVICE_TYPES_WIFI_TERMINALS` (opt-in, vide = aucun équipement synchronisé).

| Champ GLPI | Champ Mercator |
|---|---|
| `name` | `name` |
| `comment` | `description` |
| `networkequipmenttypes_id` | `type` |
| `manufacturers_id` | `vendor` |
| `networkequipmentmodels_id` | `product` |
| `locations_id` | `building_id` + `site_id` (résolution par nom) |
| Premier port IPv4 | `address_ip` |

#### Équipements réseau — dispositifs de sécurité physique (`NetworkEquipment` → `physical-security-devices`)

Filtré par `GLPI_NETWORK_DEVICE_TYPES_PHYSICAL_SECURITY_DEVICES` (opt-in, vide = aucun équipement synchronisé).

| Champ GLPI | Champ Mercator |
|---|---|
| `name` | `name` |
| `comment` | `description` |
| `networkequipmenttypes_id` | `type` |
| `locations_id` | `building_id` + `site_id` (résolution par nom) |
| Rack GLPI (relation `Item_Rack`) | `bay_id` (résolu via la bay Mercator déjà synchronisée) |
| Premier port IPv4 | `address_ip` |

#### Équipements réseau — dispositifs de stockage (`NetworkEquipment` → `storage-devices`)

Filtré par `GLPI_NETWORK_DEVICE_TYPES_STORAGE_DEVICES` (opt-in, vide = aucun équipement synchronisé).

| Champ GLPI | Champ Mercator |
|---|---|
| `name` | `name` |
| `comment` | `description` |
| `networkequipmenttypes_id` | `type` |
| `locations_id` | `building_id` + `site_id` (résolution par nom) |
| Rack GLPI (relation `Item_Rack`) | `bay_id` (résolu via la bay Mercator déjà synchronisée) |
| Premier port IPv4 | `address_ip` |

#### Baies (`Rack` → `bays`)

| Champ GLPI | Champ Mercator |
|---|---|
| `name` | `name` |
| `comment` | `description` |
| `locations_id` | `building_id` + `site_id` (résolution par nom) |

#### Certificats (`Certificate` → `certificates`)

| Champ GLPI | Champ Mercator |
|---|---|
| `name` | `name` |
| `comment` | `description` |
| `certificatetypes_id` | `type` |
| `states_id` | `status` |
| `date_expiration` | `expiration_date` |
| `"GLPI"` | `update_source` |

#### Clusters (`Cluster` → `clusters`)

| Champ GLPI | Champ Mercator |
|---|---|
| `name` | `name` |
| `comment` | `description` |
| `clustertypes_id` | `type` |
| `states_id` | `status` |
| `"GLPI"` | `update_source` |

#### Liens workstation ↔ application (`links`)

Pour chaque poste de travail présent dans Mercator, récupère individuellement ses logiciels installés depuis GLPI (`with_softwares=1`) et met à jour le pivot `workstation_application`.

Source GLPI : `Computer._softwares[].softwares_id` (nom du logiciel).

#### Liens activité ↔ application (`activity_links`)

Pour chaque appliance présente dans Mercator en tant qu'activité, récupère individuellement ses logiciels liés depuis GLPI (`with_items=1`) et met à jour le pivot `activity_application` côté application.

Source GLPI : `Appliance._items.Software[].name` (logiciels directement rattachés à l'appliance dans GLPI).

**Prérequis** : `applications` et `appliances` doivent avoir été synchronisés au préalable.

**Résolution de la localisation** : le champ `locations_id` GLPI (nom de bâtiment ou salle) est mis en correspondance par nom (insensible à la casse) avec les bâtiments Mercator. Si le nom correspond, `building_id` et `site_id` sont renseignés automatiquement. Il est donc recommandé de synchroniser `locations` en premier.

---

## Filtrage des actifs

### Par entité GLPI

Pour synchroniser uniquement les actifs d'une entité GLPI précise, renseignez son ID. L'ID est visible dans **Administration → Entités** dans GLPI.

```bash
# Via option CLI (priorité absolue sur .env)
php application glpi:sync --entity=3

# Via .env (appliqué à toutes les exécutions)
GLPI_ENTITY_ID=3
```

Le filtrage est **récursif** : les sous-entités sont également incluses (`is_recursive=1`).

### Par statut

Permet de n'importer que les actifs dans des états précis (ex : exclure les actifs "Mis au rebut").

Les valeurs peuvent être des **noms de statuts** (tels qu'ils apparaissent dans GLPI avec dropdowns expandés) ou des **IDs numériques**.

```ini
# Accepter uniquement les actifs en production et en stock
GLPI_ALLOWED_STATES=En production,En stock

# Surcharger pour les ordinateurs seulement (prioritaire sur GLPI_ALLOWED_STATES)
GLPI_ALLOWED_STATES_COMPUTERS=En production

# Laisser vide pour désactiver le filtre sur les téléphones
GLPI_ALLOWED_STATES_PHONES=
```

**Priorité** : la config spécifique au type (`GLPI_ALLOWED_STATES_COMPUTERS`) est prioritaire sur la config globale (`GLPI_ALLOWED_STATES`). Si les deux sont vides, aucun filtrage n'est appliqué (tous les statuts sont acceptés).

> **Itemtypes sans statut** : `Location`, `Domain` et `Software` ne possèdent pas d'attribut `states_id` dans GLPI (pour les `Software`, le statut existe au niveau `SoftwareVersion`, non synchronisé). Le filtre statut est donc ignoré pour ces types — un avertissement est journalisé si `GLPI_ALLOWED_STATES` (ou une variante spécifique) est configuré alors qu'il ne peut pas s'appliquer. Pour filtrer les `Software`, utilisez `GLPI_SOFTWARE_CATEGORIES` (filtrage par `softwarecategories_id`, noms ou IDs).

### Par sous-type (Computer)

Dans GLPI, le type `Computer` regroupe des actifs hétérogènes (postes de travail, laptops, serveurs physiques, VMs…). Ce filtrage permet de router chaque sous-type vers le bon endpoint Mercator.

Les valeurs peuvent être des **noms de types** (tels qu'ils apparaissent dans GLPI) ou des **IDs numériques**.

```ini
# Postes de travail et laptops → workstations
GLPI_COMPUTER_TYPES_WORKSTATIONS=Poste de travail,Laptop

# VMs et conteneurs → logical-servers
GLPI_COMPUTER_TYPES_LOGICAL_SERVERS=Machine virtuelle,Conteneur

# Serveurs physiques → physical-servers
GLPI_COMPUTER_TYPES_PHYSICAL_SERVERS=Serveur physique
```

> **Important** : `GLPI_COMPUTER_TYPES_WORKSTATIONS` vide = tous les `Computer` vont dans `workstations` (rétrocompatible). En revanche, `GLPI_COMPUTER_TYPES_LOGICAL_SERVERS` et `GLPI_COMPUTER_TYPES_PHYSICAL_SERVERS` vides = **désactivé** (opt-in explicite requis).

**Combinaison des filtres :**

```bash
# Synchroniser uniquement les serveurs physiques en production de l'entité 1
php application glpi:sync \
  --type=physical_servers \
  --entity=1
```

```ini
# .env correspondant
GLPI_COMPUTER_TYPES_PHYSICAL_SERVERS=Serveur physique
GLPI_ALLOWED_STATES_COMPUTERS=En production
GLPI_ENTITY_ID=1
```

### Par sous-type (NetworkEquipment)

De façon symétrique, le type GLPI `NetworkEquipment` regroupe switches, routeurs, bornes Wifi, dispositifs de sécurité physique et dispositifs de stockage. Chaque sous-type est routé vers son propre endpoint Mercator via `GLPI_NETWORK_DEVICE_TYPES_*`.

```ini
# Switches → physical-switches
GLPI_NETWORK_DEVICE_TYPES_SWITCHES=Switch

# Routeurs → physical-routers
GLPI_NETWORK_DEVICE_TYPES_ROUTERS=Routeur

# Bornes Wifi → wifi-terminals
GLPI_NETWORK_DEVICE_TYPES_WIFI_TERMINALS=Borne Wifi

# Caméras / contrôleurs d'accès → physical-security-devices
GLPI_NETWORK_DEVICE_TYPES_PHYSICAL_SECURITY_DEVICES=Caméra IP

# Baies de stockage réseau → storage-devices
GLPI_NETWORK_DEVICE_TYPES_STORAGE_DEVICES=Baie de stockage
```

> **Important** : `GLPI_NETWORK_DEVICE_TYPES_SWITCHES` vide = tous les `NetworkEquipment` non capturés par les autres filtres vont dans `physical-switches` (comportement historique, rétrocompatible). `GLPI_NETWORK_DEVICE_TYPES_ROUTERS`, `_WIFI_TERMINALS`, `_PHYSICAL_SECURITY_DEVICES` et `_STORAGE_DEVICES` vides = **désactivé** (opt-in explicite requis) — aucun `NetworkEquipment` n'est routé vers ces endpoints.

### Par type (Domain)

```ini
# Ne synchroniser que les domaines internes
GLPI_DOMAIN_TYPES=Interne
```

Vide = tous les `Domain` sont acceptés, quel que soit leur `domaintypes_id`.

> **Note sur `--entity`/`GLPI_ENTITY_ID` et `Domain`** : l'API GLPI ne restreint pas toujours les `Domain` retournés à l'entité active de la session, contrairement aux autres itemtypes. Le connecteur applique donc un filtrage explicite côté client pour ce type (comparaison de l'entité de chaque domaine avec l'entité configurée, y compris ses sous-entités) — aucune configuration supplémentaire n'est nécessaire, `--entity`/`GLPI_ENTITY_ID` suffit.

---

## Planification automatique

Pour une synchronisation quotidienne à 02h00 via le planificateur Laravel :

```bash
# Ajouter au crontab du serveur
* * * * * cd /opt/mercator-glpi && php application schedule:run >> /dev/null 2>&1
```

La fréquence est configurable dans `app/Console/Kernel.php` :

```php
$schedule->command('glpi:sync')->dailyAt('02:00');
```

Les logs sont écrits dans `storage/logs/laravel.log`.

---

## Logs et diagnostic

### Activer les logs debug

Renseignez dans `.env` :

```ini
LOG_LEVEL=debug
```

En mode debug, chaque requête HTTP (URL, code retour, corps en cas d'erreur) et chaque item traité (action CREATE/UPDATE, payload tronqué à 500 caractères) sont journalisés.

### Lire les logs

```bash
# Suivre les logs en temps réel
tail -f storage/logs/laravel.log

# Filtrer par type d'actif
grep "\[workstations\]" storage/logs/laravel.log

# Filtrer les erreurs uniquement
grep "ERROR" storage/logs/laravel.log

# Filtrer les liens workstation↔application
grep "\[links\]" storage/logs/laravel.log

# Filtrer les liens activité↔application
grep "\[activity_links\]" storage/logs/laravel.log
```

**Exemples de messages debug et leur interprétation :**

```
[2024-06-01 02:00:01] DEBUG: [GLPI] GET Computer {"params":{"entities_id":3,"is_recursive":1}}
→ Requête vers GLPI pour les ordinateurs de l'entité 3

[2024-06-01 02:00:02] DEBUG: [workstations] Filtre statut [Computer] : 5 item(s) exclus, 37 conservé(s)
→ 5 ordinateurs exclus car leur statut n'est pas dans GLPI_ALLOWED_STATES_COMPUTERS

[2024-06-01 02:00:03] DEBUG: [workstations] CREATE PC-NOUVEAU-01 — payload: {"name":"PC-NOUVEAU-01",...}
→ Ce poste n'existe pas encore dans Mercator — il sera créé

[2024-06-01 02:00:04] INFO:  [links] PC-DIDIER-01 → 3 application(s) : [20, 21, 22]
→ 3 applications liées au poste dans Mercator

[2024-06-01 02:00:05] INFO:  [activity_links] ERP → 2 activité(s) : [100, 101]
→ L'application ERP est liée à 2 activités dans Mercator

[2024-06-01 02:00:06] DEBUG: [activity_links] Appliance sans activité Mercator : MON-APP
→ L'appliance "MON-APP" n'a pas de correspondance dans les activités Mercator
   (vérifier que --type=appliances a bien été exécuté avant)
```

### Cas d'erreurs fréquents et solutions

#### Authentification GLPI échoue (401)

```
Échec de l'authentification : 401
```

Vérifications :
- L'API REST est activée dans GLPI (**Configuration → Générale → API**)
- `GLPI_APP_TOKEN` et `GLPI_USER_TOKEN` sont corrects et non expirés

Test manuel :
```bash
curl -H "Authorization: user_token $GLPI_USER_TOKEN" \
     -H "App-Token: $GLPI_APP_TOKEN" \
     "$GLPI_URL/apirest.php/initSession"
# Attendu : {"session_token": "..."}
```

#### Erreur 429 — « Too Many Attempts. »

**Symptôme**
L'API de Mercator renvoie un code HTTP `429 Too Many Attempts.`. La synchronisation
s'interrompt et un lot d'assets peut ne pas être transmis.

**Cause**
Le connecteur effectue de nombreux appels successifs (un ou plusieurs par asset).
Lorsque le volume dépasse le quota de requêtes autorisé par Mercator sur une fenêtre
de temps donnée, le limiteur de débit (*rate limiter*) de l'API rejette les requêtes
excédentaires.

**Résolution**
Augmentez le quota de l'API limiter dans le fichier `.env` **de Mercator**
(et non celui du connecteur), puis videz le cache de configuration.

```env
# Nombre maximal de requêtes autorisées par unité de temps
API_RATE_LIMIT=600
# Unité de temps (en minutes)
API_RATE_LIMIT_DECAY=1
```

Ces deux paramètres se lisent ainsi : **600 requêtes par tranche de 1 minute**.
Adaptez `API_RATE_LIMIT` à votre volumétrie d'inventaire et à la capacité du serveur
Mercator.

Après modification, rechargez la configuration côté Mercator :

```bash
php artisan config:clear
```

> **Note** — Si l'erreur persiste malgré un quota élevé, vérifiez qu'aucun reverse
> proxy ou WAF en amont de Mercator n'applique sa propre limitation de débit.

#### Workstations non synchronisées (mauvais filtre de type ou de statut)

Les ordinateurs existent dans GLPI mais n'apparaissent pas dans Mercator.

Vérifications :
1. Si `GLPI_COMPUTER_TYPES_WORKSTATIONS` est renseigné, les noms doivent correspondre exactement aux `computertypes` GLPI (avec `expand_dropdowns=1`, le nom est retourné, pas l'ID numérique).
2. Si `GLPI_ALLOWED_STATES` ou `GLPI_ALLOWED_STATES_COMPUTERS` est renseigné, vérifiez les valeurs autorisées.
3. Activez `LOG_LEVEL=debug` pour voir les items filtrés.

```bash
php application glpi:sync --dry-run --type=workstations
```

#### `building_id` null (correspondance localisation)

Le champ `building_id` reste vide dans Mercator après la sync.

**Cause** : le nom de la localisation GLPI (`locations_id`) ne correspond à aucun bâtiment Mercator.

Vérifiez que les `Location` GLPI ont bien été synchronisées vers `buildings` Mercator en premier :

```bash
php application glpi:sync --type=locations
```

Puis comparez les noms :

```bash
# Noms des localisations GLPI
curl -H "Session-Token: $SESSION" -H "App-Token: $APP_TOKEN" \
  "$GLPI_URL/apirest.php/Location" | jq '.[].name'

# Noms des bâtiments Mercator
curl -H "Authorization: Bearer $TOKEN" \
  "$MERCATOR_URL/api/buildings" | jq '.data[].name'
```

Les noms doivent correspondre exactement (insensible à la casse).

#### Aucun lien workstation↔application créé

Prérequis :
1. La sync `workstations` doit avoir été exécutée avant `links`
2. La sync `applications` doit avoir été exécutée avant `links`
3. Les logiciels doivent être installés sur les postes dans GLPI (via GLPI Agent ou saisie manuelle)

```bash
LOG_LEVEL=debug php application glpi:sync --type=links
grep "\[links\]" storage/logs/laravel.log
```

#### Aucun lien activité↔application créé

Prérequis :
1. La sync `appliances` doit avoir été exécutée avant `activity_links` (pour créer les activités)
2. La sync `applications` doit avoir été exécutée avant `activity_links` (pour créer le catalogue)
3. Les logiciels doivent être **directement rattachés aux Appliances** dans GLPI (onglet "Éléments liés" → Software)

```bash
LOG_LEVEL=debug php application glpi:sync --type=activity_links
grep "\[activity_links\]" storage/logs/laravel.log
```

---

## Étendre le connecteur

Pour ajouter un nouveau type d'actif GLPI (ex. `Monitor` → `monitors`) :

**1. Créer le mapper** `app/Services/Glpi/Mappers/MonitorMapper.php`

```php
use App\Services\Glpi\Mappers\Concerns\AppendsUnmappedFields;

class MonitorMapper
{
    use AppendsUnmappedFields;

    public function map(array $item, array $context): array
    {
        return array_filter([
            'name'        => $item['name'],
            // ext_refs (tag {GLPI}N) est ajouté automatiquement par GlpiSyncService —
            // ne pas l'inclure ici, ni l'identifiant GLPI dans description.
            'description' => $this->buildDescription($item, [/* champs déjà mappés ci-dessous */]),
            // ... autres champs
        ], fn($v) => $v !== null);
    }
}
```

**2. Créer le handler** `app/Services/Glpi/Handlers/MonitorSyncHandler.php`

```php
class MonitorSyncHandler implements SyncHandler
{
    public function __construct(private readonly MonitorMapper $mapper) {}
    public function glpiItemType(): string        { return 'Monitor'; }
    public function mercatorEndpoint(): string    { return 'monitors'; }
    public function glpiQueryParams(): array      { return ['range' => '0-999', 'expand_dropdowns' => 1]; }
    public function processOrphans(): bool        { return true; }
    public function filterItem(array $item): bool { return true; }
    public function map(array $glpiItem, array $context): array { return $this->mapper->map($glpiItem, $context); }
}
```

**3. Enregistrer** dans `AppServiceProvider::register()` :

```php
$this->app->singleton(MonitorMapper::class);
$this->app->singleton(MonitorSyncHandler::class, fn($app) =>
    new MonitorSyncHandler($app->make(MonitorMapper::class))
);
```

**4. Ajouter au tableau** `$handlers` de `GlpiSyncCommand` (et à `$defaultTypes` si le type doit faire partie de la synchronisation complète — `certificates` et `clusters` n'y figurent volontairement pas, voir [Ordre d'exécution et dépendances](#ordre-dexécution-et-dépendances)) :

```php
'monitors' => MonitorSyncHandler::class,
```

---

## Tests

```bash
# Tous les tests
./vendor/bin/pest

# Fichier spécifique
./vendor/bin/pest tests/Unit/WorkstationMapperTest.php
./vendor/bin/pest tests/Unit/GlpiActivityLinksTest.php
./vendor/bin/pest tests/Unit/GlpiStatusFilterTest.php
./vendor/bin/pest tests/Unit/ComputerTypeFilterTest.php

# Avec couverture de code
./vendor/bin/pest --coverage
```

Les tests utilisent **Mockery** — aucun appel réseau réel. Les fixtures JSON réalistes se trouvent dans `tests/Fixtures/`.

| Suite | Ce qui est testé |
|---|---|
| `WorkstationMapperTest` | Mapping complet d'un Computer GLPI |
| `ApplicationMapperTest` | Mapping d'un Software GLPI |
| `PeripheralMapperTest` | Mapping d'un Peripheral GLPI |
| `PhoneMapperTest` | Mapping d'un Phone GLPI |
| `NetworkDeviceMapperTest` | Mapping d'un NetworkEquipment → PhysicalSwitch |
| `RouterMapperTest` | Mapping d'un NetworkEquipment → PhysicalRouter |
| `WifiTerminalMapperTest` | Mapping d'un NetworkEquipment → WifiTerminal |
| `PhysicalSecurityDeviceMapperTest` | Mapping d'un NetworkEquipment → PhysicalSecurityDevice |
| `StorageDeviceMapperTest` | Mapping d'un NetworkEquipment → StorageDevice |
| `RackMapperTest` | Mapping d'un Rack → Bay |
| `ApplianceMapperTest` | Mapping d'une Appliance → Activity |
| `SiteMapperTest` | Mapping d'une Location racine → Site |
| `LocationMapperTest` | Mapping d'une Location → Building |
| `GlpiSyncServiceTest` | Logique create / update / orphelins |
| `GlpiSyncServiceLinksTest` | Liens workstation ↔ application (`syncLinks`) |
| `GlpiSyncServiceLocationHierarchyTest` | Résolution `building_id`/`site_id` à travers la hiérarchie Location → Site |
| `GlpiActivityLinksTest` | Liens activité ↔ application (`syncActivityLinks`) |
| `GlpiEntityFilterTest` | Filtrage par entité GLPI (`--entity`, `GLPI_ENTITY_ID`) |
| `GlpiClientPaginationTest` | Pagination automatique de `GlpiClient::getItems()` au-delà de 1000 items (`Content-Range`) |
| `GlpiStatusFilterTest` | Filtrage par statut (`GLPI_ALLOWED_STATES*`) |
| `ComputerTypeFilterTest` | Filtrage par sous-type Computer (`GLPI_COMPUTER_TYPES_*`) |
| `NetworkDeviceTypeFilterTest` | Filtrage par sous-type NetworkEquipment (`GLPI_NETWORK_DEVICE_TYPES_*`) |
| `SoftwareCategoryFilterTest` | Filtrage par catégorie Software (`GLPI_SOFTWARE_CATEGORIES`) |
| `SiteSyncHandlerTest` | Filtre `filterItem()` du `SiteSyncHandler` (Location racine uniquement) |
| `GlpiSyncCommandTest` (`tests/Feature/`) | Intégration commande CLI |

> Il n'existe pas (encore) de test dédié pour `CertificateMapper`/`CertificateSyncHandler` ni `ClusterMapper`/`ClusterSyncHandler`.

---

## Licence

Ce projet est distribué sous licence **GPL**. Voir le fichier [LICENSE](LICENSE) pour les détails.
