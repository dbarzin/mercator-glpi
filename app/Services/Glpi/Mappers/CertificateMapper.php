<?php

namespace App\Services\Glpi\Mappers;

class CertificateMapper
{
    public function map(array $item, array $context): array
    {
        return array_filter([
            'name'        => $item['name'] ?? null,
            'description' => '[glpi_id:' . $item['id'] . '] ' . ($item['comment'] ?? ''),
            'type'        => $item['certificatetypes_id'] ?? null,
            'status'      => $context['states'][$item['states_id']] ?? null,
            'expiration_date' => $item['date_expiration'] ?? null,
            'update_source' => 'GLPI',
        ], fn($v) => $v !== null);
    }
}