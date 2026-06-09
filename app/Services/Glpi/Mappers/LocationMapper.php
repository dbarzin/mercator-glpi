// app/Services/Glpi/Mappers/LocationMapper.php
<?php

namespace App\Services\Glpi\Mappers;

class LocationMapper
{
    public function map(array $item, array $context): array
    {
        return array_filter([
            'name'        => $item['name'] ?? null,
            'description' => '[glpi_id:' . $item['id'] . '] ' . ($item['comment'] ?? ''),
            'address'     => $item['address'] ?? null,
            'city'        => $item['town'] ?? null,
            'country'     => $item['country'] ?? null,
            'update_source' => 'GLPI',
        ], fn($v) => $v !== null);
    }
}