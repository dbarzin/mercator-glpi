<?php

$parseIds = fn (?string $s): array => array_values(
    array_filter(array_map('trim', explode(',', $s ?? '')))
);

return [

    'glpi' => [
        'url' => env('GLPI_URL'),
        'app_token' => env('GLPI_APP_TOKEN'),
        'user_token' => env('GLPI_USER_TOKEN'),
        'entity_id' => filled(env('GLPI_ENTITY_ID')) ? (int) env('GLPI_ENTITY_ID') : null,
    ],

    'mercator' => [
        'url' => env('MERCATOR_URL'),
        'login' => env('MERCATOR_LOGIN'),
        'password' => env('MERCATOR_PASSWORD'),
    ],

    'sync' => [
        'dry_run' => env('SYNC_DRY_RUN', false),
    ],

    // Filtrage par statut GLPI (states_id) — vide = tous statuts acceptés
    'allowed_states' => [
        'default' => $parseIds(env('GLPI_ALLOWED_STATES')),
        'Computer' => $parseIds(env('GLPI_ALLOWED_STATES_COMPUTERS')),
        'Phone' => $parseIds(env('GLPI_ALLOWED_STATES_PHONES')),
        'Peripheral' => $parseIds(env('GLPI_ALLOWED_STATES_PERIPHERALS')),
        'NetworkEquipment' => $parseIds(env('GLPI_ALLOWED_STATES_NETWORK_EQUIPMENT')),
        'Rack' => $parseIds(env('GLPI_ALLOWED_STATES_RACKS')),
    ],

    // Routage des Computer par sous-type (computertypes_id) — IDs ou noms GLPI
    'computer_types' => [
        'workstations' => $parseIds(env('GLPI_COMPUTER_TYPES_WORKSTATIONS')),
        'logical_servers' => $parseIds(env('GLPI_COMPUTER_TYPES_LOGICAL_SERVERS')),
        'physical_servers' => $parseIds(env('GLPI_COMPUTER_TYPES_PHYSICAL_SERVERS')),
    ],

    // Routage des NetworkEquipment par sous-type (networkequipmenttypes_id) — IDs ou noms GLPI.
    // "switches" vide = comportement historique (catch-all : tout NetworkEquipment non
    // explicitement routé vers un autre sous-type devient un physical-switch Mercator).
    // Les autres sous-types sont opt-in : vide = désactivé.
    'network_device_types' => [
        'switches' => $parseIds(env('GLPI_NETWORK_DEVICE_TYPES_SWITCHES')),
        'routers' => $parseIds(env('GLPI_NETWORK_DEVICE_TYPES_ROUTERS')),
        'wifi_terminals' => $parseIds(env('GLPI_NETWORK_DEVICE_TYPES_WIFI_TERMINALS')),
        'physical_security_devices' => $parseIds(env('GLPI_NETWORK_DEVICE_TYPES_PHYSICAL_SECURITY_DEVICES')),
        'storage_devices' => $parseIds(env('GLPI_NETWORK_DEVICE_TYPES_STORAGE_DEVICES')),
    ],

    // Routage des Appliance GLPI (services numériques) : "activities" (historique) ou
    // "applications" (issue #12). Toute autre valeur retombe sur "activities" (cf.
    // ApplianceSyncHandler::resolveEndpoint()).
    'appliance_mercator_endpoint' => env('GLPI_APPLIANCE_MERCATOR_ENDPOINT', 'activities'),

    // Filtrage des Domain par type (domaintypes_id) — IDs ou noms GLPI, vide = tous types acceptés
    'domain_types' => $parseIds(env('GLPI_DOMAIN_TYPES')),

    // Filtrage des Software par catégorie (softwarecategories_id) — IDs ou noms GLPI, vide = toutes catégories acceptées
    'software_categories' => $parseIds(env('GLPI_SOFTWARE_CATEGORIES')),

];
