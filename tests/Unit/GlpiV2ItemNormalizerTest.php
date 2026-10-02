<?php

use App\Services\Glpi\V2\ItemNormalizer;

// ── ItemNormalizer : réponse API GLPI v2 → forme v1 attendue par les Mappers ──

function v2ComputerFixture(): array
{
    return [
        'id' => 2,
        'name' => 'PC-001',
        'comment' => 'Poste accueil',
        'serial' => 'SN-PC-001',
        'is_deleted' => false,
        'is_dynamic' => true,
        'date_mod' => '2026-10-01T19:22:17+00:00',
        'last_boot' => null,
        'status' => ['id' => 1, 'name' => 'En production'],
        'entity' => ['id' => 0, 'name' => 'Entité racine', 'completename' => 'Entité racine'],
        'manufacturer' => ['id' => 1, 'name' => 'Dell'],
        'user' => ['id' => 2, 'name' => 'glpi'],
        'user_tech' => null,
        'location' => ['id' => 3, 'name' => 'Salle 101'],
        'type' => ['id' => 1, 'name' => 'Poste de travail'],
        'model' => ['id' => 1, 'name' => 'OptiPlex 7090'],
        'group' => [['id' => 4, 'name' => 'Support']],
        'group_tech' => [],
    ];
}

it('renomme les relations v2 en colonnes v1 et les expanse en nom (expand_dropdowns=1)', function () {
    $item = (new ItemNormalizer)->normalize('Computer', v2ComputerFixture(), true);

    expect($item)
        ->toMatchArray([
            'id' => 2,
            'name' => 'PC-001',
            'comment' => 'Poste accueil',
            'serial' => 'SN-PC-001',
            'states_id' => 'En production',
            'entities_id' => 'Entité racine',
            'manufacturers_id' => 'Dell',
            'users_id' => 'glpi',
            'users_id_tech' => 0,
            'locations_id' => 'Salle 101',
            'computertypes_id' => 'Poste de travail',
            'computermodels_id' => 'OptiPlex 7090',
            'groups_id' => ['Support'],
            'groups_id_tech' => [],
        ])
        ->not->toHaveKeys(['status', 'entity', 'location', 'type', 'model', 'manufacturer']);
});

it('renvoie les ids bruts (0 si vide) quand expand_dropdowns=0', function () {
    $item = (new ItemNormalizer)->normalize('Computer', v2ComputerFixture(), false);

    expect($item['locations_id'])->toBe(3)
        ->and($item['computertypes_id'])->toBe(1)
        ->and($item['states_id'])->toBe(1)
        ->and($item['users_id_tech'])->toBe(0)
        ->and($item['groups_id'])->toBe([4]);
});

it('convertit booléens en 0/1 et dates ISO 8601 en "Y-m-d H:i:s" (forme v1)', function () {
    date_default_timezone_set('UTC');

    $item = (new ItemNormalizer)->normalize('Computer', v2ComputerFixture(), true);

    expect($item['is_deleted'])->toBe(0)
        ->and($item['is_dynamic'])->toBe(1)
        ->and($item['date_mod'])->toBe('2026-10-01 19:22:17')
        ->and($item['last_boot'])->toBeNull();
});

it('résout le chemin complet des dropdowns arborescents via le resolver (Location, State, Entity)', function () {
    $calls = [];
    $resolver = function (string $itemType, int $id) use (&$calls) {
        $calls[] = "{$itemType}#{$id}";

        return match ("{$itemType}#{$id}") {
            'Location#3' => 'Siège > Bâtiment A > Salle 101',
            'State#1' => 'Actif > En production',
            default => null,
        };
    };

    $item = (new ItemNormalizer($resolver))->normalize('Computer', v2ComputerFixture(), true);

    expect($item['locations_id'])->toBe('Siège > Bâtiment A > Salle 101')
        ->and($item['states_id'])->toBe('Actif > En production')
        // completename déjà fourni par l'objet entity : pas d'appel au resolver
        ->and($item['entities_id'])->toBe('Entité racine')
        ->and($calls)->not->toContain('Entity#0');
});

it('retombe sur le nom propre si le chemin complet n\'est pas résolu', function () {
    $item = (new ItemNormalizer(fn () => null))->normalize('Computer', v2ComputerFixture(), true);

    expect($item['locations_id'])->toBe('Salle 101');
});

it('mappe le parent d\'une Location sur locations_id (racine = 0)', function () {
    $normalizer = new ItemNormalizer;

    $child = $normalizer->normalize('Location', [
        'id' => 3, 'name' => 'Salle 101', 'completename' => 'Siège > Bâtiment A > Salle 101', 'level' => 3,
        'parent' => ['id' => 2, 'name' => 'Bâtiment A'],
    ], true);
    $root = $normalizer->normalize('Location', ['id' => 1, 'name' => 'Siège', 'level' => 1, 'parent' => null], true);

    expect($child['locations_id'])->toBe('Bâtiment A')
        ->and($child['level'])->toBe(3)
        ->and($root['locations_id'])->toBe(0);
});

it('mappe les relations propres à chaque itemtype', function (string $itemType, array $v2, array $expected) {
    expect((new ItemNormalizer)->normalize($itemType, $v2, true))->toMatchArray($expected);
})->with([
    'NetworkEquipment' => ['NetworkEquipment', ['type' => ['id' => 2, 'name' => 'Routeur'], 'model' => ['id' => 1, 'name' => 'C9300']], ['networkequipmenttypes_id' => 'Routeur', 'networkequipmentmodels_id' => 'C9300']],
    'Phone' => ['Phone', ['type' => ['id' => 1, 'name' => 'IP'], 'model' => null], ['phonetypes_id' => 'IP', 'phonemodels_id' => 0]],
    'Peripheral' => ['Peripheral', ['type' => ['id' => 1, 'name' => 'Imprimante']], ['peripheraltypes_id' => 'Imprimante']],
    'Rack (state)' => ['Rack', ['state' => ['id' => 1, 'name' => 'En production'], 'measured_power' => 10], ['states_id' => 'En production', 'mesured_power' => 10]],
    'Software' => ['Software', ['category' => ['id' => 1, 'name' => 'Navigateurs'], 'parent' => null], ['softwarecategories_id' => 'Navigateurs', 'softwares_id' => 0]],
    'Appliance' => ['Appliance', ['type' => ['id' => 1, 'name' => 'Métier'], 'external_id' => 'X1'], ['appliancetypes_id' => 'Métier', 'externalidentifier' => 'X1']],
    'Certificate' => ['Certificate', ['type' => ['id' => 1, 'name' => 'SSL'], 'date_expiration' => '2027-06-30'], ['certificatetypes_id' => 'SSL', 'date_expiration' => '2027-06-30']],
    'Cluster' => ['Cluster', ['type' => ['id' => 1, 'name' => 'Proxmox']], ['clustertypes_id' => 'Proxmox']],
    'Domain' => ['Domain', ['type' => ['id' => 1, 'name' => 'Interne'], 'date_expiration' => '2027-12-31 00:00:00'], ['domaintypes_id' => 'Interne', 'date_expiration' => '2027-12-31 00:00:00']],
    'Database' => ['Database', ['instance' => ['id' => 1, 'name' => 'PG-PROD']], ['databaseinstances_id' => 'PG-PROD']],
    'DatabaseInstance' => ['DatabaseInstance', ['type' => ['id' => 1, 'name' => 'PostgreSQL'], 'itemtype' => 'Computer', 'items_id' => 5, 'is_active' => true], ['databaseinstancetypes_id' => 'PostgreSQL', 'itemtype' => 'Computer', 'items_id' => 5, 'is_active' => 1]],
    'Item_OperatingSystem' => ['Item_OperatingSystem', ['operatingsystem' => ['id' => 2, 'name' => 'Windows 11'], 'version' => ['id' => 2, 'name' => '23H2']], ['operatingsystems_id' => 'Windows 11', 'operatingsystemversions_id' => '23H2']],
    'Item_Disk' => ['Item_Disk', ['name' => 'C:', 'mount_point' => 'C:', 'total_size' => 512000, 'free_size' => 1000], ['mountpoint' => 'C:', 'totalsize' => 512000, 'freesize' => 1000]],
    'Item_DeviceProcessor' => ['Item_DeviceProcessor', ['processor' => ['id' => 1, 'designation' => 'Intel Core i7'], 'frequency' => '2500', 'nbcores' => 8], ['deviceprocessors_id' => 'Intel Core i7', 'frequency' => '2500', 'nbcores' => 8]],
    'Infocom' => ['Infocom', ['date_buy' => '2024-01-15', 'date_order' => '2024-01-02', 'date_warranty' => '2024-01-15', 'warranty_duration' => 36, 'value' => 1200], ['buy_date' => '2024-01-15', 'order_date' => '2024-01-02', 'warranty_date' => '2024-01-15', 'warranty_duration' => 36, 'value' => 1200]],
]);
