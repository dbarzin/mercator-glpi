<?php

namespace App\Providers;

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiClient;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Glpi\Handlers\ApplicationSyncHandler;
use App\Services\Glpi\Handlers\PeripheralSyncHandler;
use App\Services\Glpi\Handlers\PhoneSyncHandler;
use App\Services\Glpi\Handlers\WorkstationSyncHandler;
use App\Services\Glpi\Mappers\ApplicationMapper;
use App\Services\Glpi\Mappers\PeripheralMapper;
use App\Services\Glpi\Mappers\PhoneMapper;
use App\Services\Glpi\Mappers\WorkstationMapper;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use App\Services\Mercator\MercatorClient;
use Illuminate\Support\ServiceProvider;

use App\Services\Glpi\Handlers\NetworkDeviceSyncHandler;
use App\Services\Glpi\Mappers\NetworkDeviceMapper;

use App\Services\Glpi\Handlers\LocationSyncHandler;
use App\Services\Glpi\Mappers\LocationMapper;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Interfaces liées aux implémentations concrètes → mockables sans crash
        $this->app->singleton(GlpiClientInterface::class, fn() =>
        new GlpiClient(config('glpi.glpi'))
        );

        $this->app->singleton(MercatorClientInterface::class, fn() =>
        new MercatorClient(config('glpi.mercator'))
        );

        // Aliases pour résolution par classe concrète (ex: injection dans handle())
        $this->app->alias(GlpiClientInterface::class, GlpiClient::class);
        $this->app->alias(MercatorClientInterface::class, MercatorClient::class);

        $this->app->singleton(WorkstationMapper::class);
        $this->app->singleton(ApplicationMapper::class);
        $this->app->singleton(PeripheralMapper::class);
        $this->app->singleton(PhoneMapper::class);

        $this->app->singleton(WorkstationSyncHandler::class, fn($app) =>
        new WorkstationSyncHandler($app->make(WorkstationMapper::class))
        );

        $this->app->singleton(ApplicationSyncHandler::class, fn($app) =>
        new ApplicationSyncHandler($app->make(ApplicationMapper::class))
        );

        $this->app->singleton(PeripheralSyncHandler::class, fn($app) =>
        new PeripheralSyncHandler($app->make(PeripheralMapper::class))
        );

        $this->app->singleton(PhoneSyncHandler::class, fn($app) =>
        new PhoneSyncHandler($app->make(PhoneMapper::class))
        );

        $this->app->singleton(NetworkDeviceSyncHandler::class, fn($app) =>
        new NetworkDeviceSyncHandler($app->make(NetworkDeviceMapper::class))
        );

        $this->app->singleton(LocationSyncHandler::class, fn($app) =>
        new LocationSyncHandler($app->make(LocationMapper::class))
        );

        $this->app->singleton(GlpiSyncService::class);
    }
}
