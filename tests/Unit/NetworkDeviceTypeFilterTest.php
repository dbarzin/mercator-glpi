<?php

use App\Services\Glpi\Handlers\NetworkDeviceSyncHandler;
use App\Services\Glpi\Handlers\PhysicalSecurityDeviceSyncHandler;
use App\Services\Glpi\Handlers\RouterSyncHandler;
use App\Services\Glpi\Handlers\StorageDeviceSyncHandler;
use App\Services\Glpi\Handlers\WifiTerminalSyncHandler;
use App\Services\Glpi\Mappers\NetworkDeviceMapper;
use App\Services\Glpi\Mappers\PhysicalSecurityDeviceMapper;
use App\Services\Glpi\Mappers\RouterMapper;
use App\Services\Glpi\Mappers\StorageDeviceMapper;
use App\Services\Glpi\Mappers\WifiTerminalMapper;

// ── Tests filtrage par sous-type NetworkEquipment ─────────────────────────────

function networkEquipmentWithType(mixed $typeValue): array
{
    return ['networkequipmenttypes_id' => $typeValue, 'name' => 'NET-TEST'];
}

// ── NetworkDeviceSyncHandler (physical-switches) ──────────────────────────────

it('NetworkDeviceSyncHandler accepte tous les types si la config est vide', function () {
    config(['glpi.network_device_types.switches' => []]);

    $handler = new NetworkDeviceSyncHandler(new NetworkDeviceMapper);

    expect($handler->filterItem(networkEquipmentWithType('Switch')))->toBeTrue();
    expect($handler->filterItem(networkEquipmentWithType('Router')))->toBeTrue();
    expect($handler->filterItem(networkEquipmentWithType(0)))->toBeTrue();
});

it('NetworkDeviceSyncHandler filtre par nom de type si configuré', function () {
    config(['glpi.network_device_types.switches' => ['Switch']]);

    $handler = new NetworkDeviceSyncHandler(new NetworkDeviceMapper);

    expect($handler->filterItem(networkEquipmentWithType('Switch')))->toBeTrue();
    expect($handler->filterItem(networkEquipmentWithType('Router')))->toBeFalse();
});

// ── RouterSyncHandler (physical-routers) ──────────────────────────────────────

it('RouterSyncHandler exclut tout si la config est vide', function () {
    config(['glpi.network_device_types.routers' => []]);

    $handler = new RouterSyncHandler(new RouterMapper);

    expect($handler->filterItem(networkEquipmentWithType('Router')))->toBeFalse();
});

it('RouterSyncHandler accepte les types configurés', function () {
    config(['glpi.network_device_types.routers' => ['Router']]);

    $handler = new RouterSyncHandler(new RouterMapper);

    expect($handler->filterItem(networkEquipmentWithType('Router')))->toBeTrue();
    expect($handler->filterItem(networkEquipmentWithType('Switch')))->toBeFalse();
});

// ── WifiTerminalSyncHandler (wifi-terminals) ──────────────────────────────────

it('WifiTerminalSyncHandler exclut tout si la config est vide', function () {
    config(['glpi.network_device_types.wifi_terminals' => []]);

    $handler = new WifiTerminalSyncHandler(new WifiTerminalMapper);

    expect($handler->filterItem(networkEquipmentWithType('Wifi Access Point')))->toBeFalse();
});

it('WifiTerminalSyncHandler accepte les types configurés', function () {
    config(['glpi.network_device_types.wifi_terminals' => ['Wifi Access Point']]);

    $handler = new WifiTerminalSyncHandler(new WifiTerminalMapper);

    expect($handler->filterItem(networkEquipmentWithType('Wifi Access Point')))->toBeTrue();
    expect($handler->filterItem(networkEquipmentWithType('Switch')))->toBeFalse();
});

// ── PhysicalSecurityDeviceSyncHandler (physical-security-devices) ────────────

it('PhysicalSecurityDeviceSyncHandler exclut tout si la config est vide', function () {
    config(['glpi.network_device_types.physical_security_devices' => []]);

    $handler = new PhysicalSecurityDeviceSyncHandler(new PhysicalSecurityDeviceMapper);

    expect($handler->filterItem(networkEquipmentWithType('Caméra IP')))->toBeFalse();
});

it('PhysicalSecurityDeviceSyncHandler accepte les types configurés', function () {
    config(['glpi.network_device_types.physical_security_devices' => ['Caméra IP']]);

    $handler = new PhysicalSecurityDeviceSyncHandler(new PhysicalSecurityDeviceMapper);

    expect($handler->filterItem(networkEquipmentWithType('Caméra IP')))->toBeTrue();
    expect($handler->filterItem(networkEquipmentWithType('Switch')))->toBeFalse();
});

// ── StorageDeviceSyncHandler (storage-devices) ────────────────────────────────

it('StorageDeviceSyncHandler exclut tout si la config est vide', function () {
    config(['glpi.network_device_types.storage_devices' => []]);

    $handler = new StorageDeviceSyncHandler(new StorageDeviceMapper);

    expect($handler->filterItem(networkEquipmentWithType('Baie de stockage')))->toBeFalse();
});

it('StorageDeviceSyncHandler accepte les types configurés', function () {
    config(['glpi.network_device_types.storage_devices' => ['Baie de stockage']]);

    $handler = new StorageDeviceSyncHandler(new StorageDeviceMapper);

    expect($handler->filterItem(networkEquipmentWithType('Baie de stockage')))->toBeTrue();
    expect($handler->filterItem(networkEquipmentWithType('Switch')))->toBeFalse();
});
