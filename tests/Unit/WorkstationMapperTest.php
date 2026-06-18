<?php

use App\Services\Glpi\Mappers\WorkstationMapper;

// ── Helpers ───────────────────────────────────────────────────────────────────

function glpiComputer(array $overrides = []): array
{
    return array_merge(
        json_decode(file_get_contents(__DIR__.'/../Fixtures/glpi_computers.json'), true)[0],
        $overrides
    );
}

function buildingsMap(): array
{
    $buildings = json_decode(
        file_get_contents(__DIR__.'/../Fixtures/mercator_buildings.json'),
        true
    )['data'];

    $map = [];
    foreach ($buildings as $b) {
        $map[strtolower($b['name'])] = [
            'id' => $b['id'],
            'site_id' => $b['site_id'] ?? null,
        ];
    }

    return $map;
}

// ── Champs de base ────────────────────────────────────────────────────────────

it('mappe le nom du poste', function () {
    $result = (new WorkstationMapper)->map(glpiComputer(), ['buildings_map' => []]);

    expect($result['name'])->toBe('PC-DIDIER-01');
});

it('mappe le numéro de série', function () {
    $result = (new WorkstationMapper)->map(glpiComputer(), ['buildings_map' => []]);

    expect($result['serial_number'])->toBe('SN123456');
});

it('mappe le fabricant et le modèle', function () {
    $result = (new WorkstationMapper)->map(glpiComputer(), ['buildings_map' => []]);

    expect($result['manufacturer'])->toBe('Dell');
    expect($result['model'])->toBe('Latitude 5520');
});

it('mappe le système d\'exploitation', function () {
    $result = (new WorkstationMapper)->map(glpiComputer(), ['buildings_map' => []]);

    expect($result['operating_system'])->toBe('Windows 11 Pro');
});

it('mappe le statut', function () {
    $result = (new WorkstationMapper)->map(glpiComputer(), ['buildings_map' => []]);

    expect($result['status'])->toBe('En production');
});

it('positionne update_source à GLPI', function () {
    $result = (new WorkstationMapper)->map(glpiComputer(), ['buildings_map' => []]);

    expect($result['update_source'])->toBe('GLPI');
});

// ── Description ──────────────────────────────────────────────────────────────

it('mappe le commentaire dans la description', function () {
    $result = (new WorkstationMapper)->map(glpiComputer(['id' => 42, 'comment' => 'Mon poste']), ['buildings_map' => []]);

    expect($result['description'])->toStartWith('Mon poste');
});

it('ne mappe pas de description si le commentaire est vide (pas de champs non mappés)', function () {
    $result = (new WorkstationMapper)->map([
        'id' => 42, 'name' => 'PC', 'comment' => '',
        'serial' => 'SN', 'computertypes_id' => 'T', 'manufacturers_id' => 'M',
        'computermodels_id' => 'Mo', 'operatingsystems_id' => 'OS', 'states_id' => 'S',
        'users_id' => 'U', 'locations_id' => 0, 'ram' => 8192, 'date_last_boot' => null,
    ], ['buildings_map' => []]);

    expect($result)->not->toHaveKey('description');
});

it('sérialise les champs GLPI non mappés dans la description', function () {
    $result = (new WorkstationMapper)->map(glpiComputer(), ['buildings_map' => []]);

    expect($result['description'])->toContain('"otherserial" : "INV-2024-001"');
});

// ── Résolution building_id ────────────────────────────────────────────────────

it('résout le building_id quand le nom de salle correspond', function () {
    $result = (new WorkstationMapper)->map(
        glpiComputer(['locations_id' => 'Salle 101']),
        ['buildings_map' => buildingsMap()]
    );

    expect($result['building_id'])->toBe(5);
});

it('résout le site_id depuis le building correspondant', function () {
    $result = (new WorkstationMapper)->map(
        glpiComputer(['locations_id' => 'Salle 101']),
        ['buildings_map' => buildingsMap()]
    );

    expect($result['site_id'])->toBe(1);
});

it('retourne null si le nom de salle ne correspond à aucun building', function () {
    $result = (new WorkstationMapper)->map(
        glpiComputer(['locations_id' => 'Salle Inconnue']),
        ['buildings_map' => buildingsMap()]
    );

    expect($result)->not->toHaveKey('building_id');
    expect($result)->not->toHaveKey('site_id');
});

it('retourne null si locations_id est 0 (non renseigné)', function () {
    $result = (new WorkstationMapper)->map(
        glpiComputer(['locations_id' => 0]),
        ['buildings_map' => buildingsMap()]
    );

    expect($result)->not->toHaveKey('building_id');
    expect($result)->not->toHaveKey('site_id');
});

it('résout le building_id insensiblement à la casse', function () {
    $result = (new WorkstationMapper)->map(
        glpiComputer(['locations_id' => 'SALLE 101']),
        ['buildings_map' => buildingsMap()]
    );

    expect($result['building_id'])->toBe(5);
});

// ── Réseau ────────────────────────────────────────────────────────────────────

it('extrait l\'adresse IP depuis les ports réseau', function () {
    $result = (new WorkstationMapper)->map(glpiComputer(), ['buildings_map' => []]);

    expect($result['address_ip'])->toBe('192.168.1.100');
});

it('extrait l\'adresse MAC en majuscules', function () {
    $result = (new WorkstationMapper)->map(glpiComputer(), ['buildings_map' => []]);

    expect($result['mac_address'])->toBe('00:11:22:33:44:55');
});

it('retourne Ethernet comme type de port', function () {
    $result = (new WorkstationMapper)->map(glpiComputer(), ['buildings_map' => []]);

    expect($result['network_port_type'])->toBe('Ethernet');
});

it('ne plante pas si aucun port réseau', function () {
    $result = (new WorkstationMapper)->map(
        glpiComputer(['_networkports' => []]),
        ['buildings_map' => []]
    );

    expect($result)->not->toHaveKey('address_ip');
    expect($result)->not->toHaveKey('mac_address');
});

// ── CPU ───────────────────────────────────────────────────────────────────────

it('extrait le CPU avec fréquence et nombre de cœurs', function () {
    $result = (new WorkstationMapper)->map(glpiComputer(), ['buildings_map' => []]);

    expect($result['cpu'])->toBe('Intel Core i7-1165G7 — 2800 MHz — 4 cœurs');
});

it('retourne null si aucun processeur', function () {
    $result = (new WorkstationMapper)->map(
        glpiComputer(['_devices' => []]),
        ['buildings_map' => []]
    );

    expect($result)->not->toHaveKey('cpu');
});

// ── RAM ───────────────────────────────────────────────────────────────────────

it('formate la RAM en Go si >= 1024 Mo', function () {
    $result = (new WorkstationMapper)->map(glpiComputer(['ram' => 16384]), ['buildings_map' => []]);

    expect($result['memory'])->toBe('16 Go');
});

it('formate la RAM en Mo si < 1024', function () {
    $result = (new WorkstationMapper)->map(glpiComputer(['ram' => 512]), ['buildings_map' => []]);

    expect($result['memory'])->toBe('512 Mo');
});

it('ne mappe pas la RAM si absente', function () {
    $result = (new WorkstationMapper)->map(glpiComputer(['ram' => null]), ['buildings_map' => []]);

    expect($result)->not->toHaveKey('memory');
});

// ── Disque ────────────────────────────────────────────────────────────────────

it('calcule la taille totale des disques', function () {
    $result = (new WorkstationMapper)->map(glpiComputer(), ['buildings_map' => []]);

    expect($result['disk'])->toBe(512000);
});

it('additionne plusieurs partitions', function () {
    $computer = glpiComputer([
        '_disks' => [
            ['name' => 'C:', 'totalsize' => 256000],
            ['name' => 'D:', 'totalsize' => 512000],
        ],
    ]);
    $result = (new WorkstationMapper)->map($computer, ['buildings_map' => []]);

    expect($result['disk'])->toBe(768000);
});

// ── Infocoms ──────────────────────────────────────────────────────────────────

it('mappe les dates de garantie', function () {
    $result = (new WorkstationMapper)->map(glpiComputer(), ['buildings_map' => []]);

    expect($result['purchase_date'])->toBe('2022-03-15');
    expect($result['warranty_start_date'])->toBe('2022-03-01');
    expect($result['warranty_end_date'])->toBe('2025-03-15');
    expect($result['warranty_period'])->toBe('36 mois');
    expect($result['fin_value'])->toBe(1299.0);
});

it('ignore les dates à 0000-00-00', function () {
    $computer = glpiComputer([
        '_infocoms' => ['buy_date' => '0000-00-00', 'warranty_expiration' => '0000-00-00'],
    ]);
    $result = (new WorkstationMapper)->map($computer, ['buildings_map' => []]);

    expect($result)->not->toHaveKey('purchase_date');
    expect($result)->not->toHaveKey('warranty_end_date');
});

// ── Valeurs nulles / 0 (dropdowns GLPI non renseignés) ───────────────────────

it('ignore les champs dropdowns à 0 (non renseignés)', function () {
    $computer = glpiComputer([
        'users_id' => 0,
        'states_id' => '0',
        'computertypes_id' => 0,
    ]);
    $result = (new WorkstationMapper)->map($computer, ['buildings_map' => []]);

    expect($result)->not->toHaveKey('other_user');
    expect($result)->not->toHaveKey('status');
    expect($result)->not->toHaveKey('type');
});
