<?php

namespace App\Services\Glpi\Mappers;

class NetworkDeviceMapper
{
    public function map(array $item, array $context): array
    {
        return array_filter([
            'name'        => $item['name'] ?? null,
            'description' => '[glpi_id:' . $item['id'] . '] ' . ($item['comment'] ?? ''),
            'type'        => $this->mapType($item['networkequipmenttypes_id'] ?? null),
            'manufacturer' => $context['manufacturers'][$item['manufacturers_id']] ?? null,
            'model'       => $context['networkequipmentmodels'][$item['networkequipmentmodels_id']] ?? null,
            'serial'      => $item['serial'] ?? null,
            'ip_address'  => $this->getFirstIpAddress($item),
            'mac_address' => $this->getFirstMacAddress($item),
            'status'      => $context['states'][$item['states_id']] ?? null,
            'location'    => $context['locations'][$item['locations_id']] ?? null,
            'update_source' => 'GLPI',
        ], fn($v) => $v !== null);
    }

    private function mapType(?int $typeId): ?string
    {
        $types = [
            1 => 'Router',
            2 => 'Switch',
            3 => 'Firewall',
            // Ajoutez d'autres types selon votre GLPI
        ];
        return $types[$typeId] ?? null;
    }

    private function getFirstIpAddress(array $item): ?string
    {
        if (!isset($item['connections'])) {
            return null;
        }
        foreach ($item['connections'] as $connection) {
            if (isset($connection['ipaddresses']['ip'])) {
                return $connection['ipaddresses']['ip'];
            }
        }
        return null;
    }

    private function getFirstMacAddress(array $item): ?string
    {
        if (!isset($item['connections'])) {
            return null;
        }
        foreach ($item['connections'] as $connection) {
            if (isset($connection['mac'])) {
                return $connection['mac'];
            }
        }
        return null;
    }
}