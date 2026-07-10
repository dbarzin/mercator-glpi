<?php

use App\Services\Glpi\Mappers\SiteMapper;

it('mappe le nom du site', function () {
    $result = (new SiteMapper)->map(glpiLocation(['locations_id' => 0]));

    expect($result['name'])->toBe('Siège Social');
});

it('mappe le commentaire dans la description', function () {
    $result = (new SiteMapper)->map(glpiLocation(['id' => 3, 'comment' => 'Bâtiment principal', 'locations_id' => 0]));

    expect($result['description'])->toStartWith('Bâtiment principal');
});

it('ne mappe pas building_id ni site_id (champs absents du Site Mercator)', function () {
    $result = (new SiteMapper)->map(glpiLocation(['locations_id' => 0]));

    expect($result)->not->toHaveKey('building_id');
    expect($result)->not->toHaveKey('site_id');
});
