<?php

namespace App\Providers;

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\GlpiClient;
use App\Services\Glpi\GlpiSyncService;
use App\Services\Glpi\Handlers\ApplicationSyncHandler;
use App\Services\Glpi\Handlers\ApplianceSyncHandler;
use App\Services\Glpi\Handlers\CertificateSyncHandler;
use App\Services\Glpi\Handlers\ClusterSyncHandler;
use App\Services\Glpi\Handlers\DatabaseSyncHandler;
use App\Services\Glpi\Handlers\DomainSyncHandler;
use App\Services\Glpi\Handlers\LocationSyncHandler;
use App\Services\Glpi\Handlers\LogicalServerSyncHandler;
use App\Services\Glpi\Handlers\NetworkDeviceSyncHandler;
use App\Services\Glpi\Handlers\PeripheralSyncHandler;
use App\Services\Glpi\Handlers\PhoneSyncHandler;
use App\Services\Glpi\Handlers\PhysicalSecurityDeviceSyncHandler;
use App\Services\Glpi\Handlers\PhysicalServerSyncHandler;
use App\Services\Glpi\Handlers\RackSyncHandler;
use App\Services\Glpi\Handlers\RouterSyncHandler;
use App\Services\Glpi\Handlers\SiteSyncHandler;
use App\Services\Glpi\Handlers\StorageDeviceSyncHandler;
use App\Services\Glpi\Handlers\WifiTerminalSyncHandler;
use App\Services\Glpi\Handlers\WorkstationSyncHandler;
use App\Services\Glpi\Mappers\ApplicationMapper;
use App\Services\Glpi\Mappers\ApplianceMapper;
use App\Services\Glpi\Mappers\CertificateMapper;
use App\Services\Glpi\Mappers\ClusterMapper;
use App\Services\Glpi\Mappers\DatabaseMapper;
use App\Services\Glpi\Mappers\DomainMapper;
use App\Services\Glpi\Mappers\LocationMapper;
use App\Services\Glpi\Mappers\LogicalServerMapper;
use App\Services\Glpi\Mappers\NetworkDeviceMapper;
use App\Services\Glpi\Mappers\PeripheralMapper;
use App\Services\Glpi\Mappers\PhoneMapper;
use App\Services\Glpi\Mappers\PhysicalSecurityDeviceMapper;
use App\Services\Glpi\Mappers\PhysicalServerMapper;
use App\Services\Glpi\Mappers\RackMapper;
use App\Services\Glpi\Mappers\RouterMapper;
use App\Services\Glpi\Mappers\SiteMapper;
use App\Services\Glpi\Mappers\StorageDeviceMapper;
use App\Services\Glpi\Mappers\WifiTerminalMapper;
use App\Services\Glpi\Mappers\WorkstationMapper;
use App\Services\Mercator\Contracts\MercatorClientInterface;
use App\Services\Mercator\MercatorClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Clients HTTP
        $this->app->singleton(GlpiClientInterface::class, fn() =>
            new GlpiClient(config('glpi.glpi'))
        );

        $this->app->singleton(MercatorClientInterface::class, fn() =>
            new MercatorClient(config('glpi.mercator'))
        );

        $this->app->alias(GlpiClientInterface::class, GlpiClient::class);
        $this->app->alias(MercatorClientInterface::class, MercatorClient::class);

        // Mappers
        $this->app->singleton(WorkstationMapper::class);
        $this->app->singleton(ApplicationMapper::class);
        $this->app->singleton(PeripheralMapper::class);
        $this->app->singleton(PhoneMapper::class);
        $this->app->singleton(NetworkDeviceMapper::class);
        $this->app->singleton(RouterMapper::class);
        $this->app->singleton(WifiTerminalMapper::class);
        $this->app->singleton(PhysicalSecurityDeviceMapper::class);
        $this->app->singleton(StorageDeviceMapper::class);
        $this->app->singleton(RackMapper::class);
        $this->app->singleton(ApplianceMapper::class);
        $this->app->singleton(LocationMapper::class);
        $this->app->singleton(SiteMapper::class);
        $this->app->singleton(CertificateMapper::class);
        $this->app->singleton(ClusterMapper::class);
        $this->app->singleton(DomainMapper::class);
        $this->app->singleton(DatabaseMapper::class);

        $this->app->singleton(LogicalServerMapper::class, fn($app) =>
            new LogicalServerMapper($app->make(WorkstationMapper::class))
        );

        $this->app->singleton(PhysicalServerMapper::class, fn($app) =>
            new PhysicalServerMapper($app->make(WorkstationMapper::class))
        );

        // Handlers — types existants
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

        // Handlers — nouveaux types (Évolution 4)
        $this->app->singleton(NetworkDeviceSyncHandler::class, fn($app) =>
            new NetworkDeviceSyncHandler($app->make(NetworkDeviceMapper::class))
        );

        $this->app->singleton(RouterSyncHandler::class, fn($app) =>
            new RouterSyncHandler($app->make(RouterMapper::class))
        );

        $this->app->singleton(WifiTerminalSyncHandler::class, fn($app) =>
            new WifiTerminalSyncHandler($app->make(WifiTerminalMapper::class))
        );

        $this->app->singleton(PhysicalSecurityDeviceSyncHandler::class, fn($app) =>
            new PhysicalSecurityDeviceSyncHandler($app->make(PhysicalSecurityDeviceMapper::class))
        );

        $this->app->singleton(StorageDeviceSyncHandler::class, fn($app) =>
            new StorageDeviceSyncHandler($app->make(StorageDeviceMapper::class))
        );

        $this->app->singleton(RackSyncHandler::class, fn($app) =>
            new RackSyncHandler($app->make(RackMapper::class))
        );

        $this->app->singleton(ApplianceSyncHandler::class, fn($app) =>
            new ApplianceSyncHandler($app->make(ApplianceMapper::class))
        );

        $this->app->singleton(LocationSyncHandler::class, fn($app) =>
            new LocationSyncHandler($app->make(LocationMapper::class))
        );

        $this->app->singleton(SiteSyncHandler::class, fn($app) =>
            new SiteSyncHandler($app->make(SiteMapper::class))
        );

        // Handlers — sous-types Computer (Évolution 5)
        $this->app->singleton(LogicalServerSyncHandler::class, fn($app) =>
            new LogicalServerSyncHandler($app->make(LogicalServerMapper::class))
        );

        $this->app->singleton(PhysicalServerSyncHandler::class, fn($app) =>
            new PhysicalServerSyncHandler($app->make(PhysicalServerMapper::class))
        );

        $this->app->singleton(ClusterSyncHandler::class, fn($app) =>
            new ClusterSyncHandler($app->make(ClusterMapper::class))
        );

        $this->app->singleton(CertificateSyncHandler::class, fn($app) =>
            new CertificateSyncHandler($app->make(CertificateMapper::class))
        );

        $this->app->singleton(DomainSyncHandler::class, fn($app) =>
            new DomainSyncHandler($app->make(DomainMapper::class))
        );

        $this->app->singleton(DatabaseSyncHandler::class, fn($app) =>
            new DatabaseSyncHandler($app->make(DatabaseMapper::class))
        );

        $this->app->singleton(GlpiSyncService::class);
    }
}
