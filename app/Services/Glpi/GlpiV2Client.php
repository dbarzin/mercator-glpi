<?php

namespace App\Services\Glpi;

use App\Services\Glpi\Contracts\GlpiClientInterface;
use App\Services\Glpi\V2\ItemNormalizer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Client de l'API GLPI v2 (High-Level REST API, /api.php/v2, GLPI ≥ 11).
 *
 * Expose la même interface que le client v1 (GlpiClient) : appelé avec les itemtypes
 * et paramètres v1 (expand_dropdowns, with_networkports…), il interroge les routes v2
 * et renvoie des items normalisés dans la forme v1 (cf. ItemNormalizer), de sorte que
 * GlpiSyncService, VmLinkSyncService et les Mappers fonctionnent à l'identique.
 *
 * Authentification OAuth2 (grant "password") : client_id/client_secret d'un client
 * OAuth GLPI + identifiant/mot de passe d'un compte GLPI. Le jeton (1 h) est renouvelé
 * automatiquement (refresh_token, à défaut nouvelle authentification).
 *
 * Certaines relations ne sont pas exposées par les routes REST v2 mais le sont par
 * l'endpoint GraphQL de la même API (scope OAuth "graphql") : Appliance_Item, ports
 * réseau, installations logicielles, machines virtuelles. Les adresses IP ne sont
 * exposées ni en REST ni en GraphQL (GLPI 11.0, API 2.3) : elles sont lues dans le
 * dernier fichier d'inventaire du GLPI Agent de l'item, quand il en a un.
 */
class GlpiV2Client implements GlpiClientInterface
{
    /**
     * Itemtype v1 → route de collection v2.
     */
    private const RESOURCES = [
        'Computer' => 'Assets/Computer',
        'NetworkEquipment' => 'Assets/NetworkEquipment',
        'Phone' => 'Assets/Phone',
        'Peripheral' => 'Assets/Peripheral',
        'Rack' => 'Assets/Rack',
        'Software' => 'Assets/Software',
        'Appliance' => 'Assets/Appliance',
        'Certificate' => 'Assets/Certificate',
        'Cluster' => 'Management/Cluster',
        'Database' => 'Management/Database',
        'DatabaseInstance' => 'Management/DatabaseInstance',
        'Domain' => 'Management/Domain',
        'Location' => 'Dropdowns/Location',
        'State' => 'Dropdowns/State',
        'Entity' => 'Administration/Entity',
    ];

    /**
     * Itemtypes v1 servis par l'endpoint GraphQL (pas de route de collection REST v2).
     */
    private const GRAPHQL_COLLECTIONS = [
        'ItemVirtualMachine' => ['type' => 'VirtualMachine', 'fields' => 'id itemtype items_id name uuid vcpu ram comment is_deleted'],
        // Itemtype GLPI 10 : même donnée, servie par la même requête en v2.
        'ComputerVirtualMachine' => ['type' => 'VirtualMachine', 'fields' => 'id itemtype items_id name uuid vcpu ram comment is_deleted'],
    ];

    /**
     * Dropdowns arborescents (completename / level / parent).
     */
    private const TREE_ITEMTYPES = ['Location', 'State', 'Entity'];

    private const PAGE_SIZE = 1000;

    /**
     * Marge (secondes) avant expiration à partir de laquelle le jeton est renouvelé.
     */
    private const TOKEN_REFRESH_MARGIN = 60;

    private ?string $accessToken = null;

    private ?string $refreshToken = null;

    private int $expiresAt = 0;

    private ?int $entityId;

    private bool $entityRestricted = true;

    /** @var array<string, array<int, string>> itemtype dropdown → id → completename */
    private array $completenames = [];

    private bool $networkPortsWarned = false;

    /** @var array<string, int>|null "{itemtype}_{items_id}" → id agent (cf. agentIndex()) */
    private ?array $agents = null;

    private ItemNormalizer $normalizer;

    public function __construct(private readonly array $config)
    {
        $this->entityId = $config['entity_id'] ?? null;
        $this->normalizer = new ItemNormalizer(fn (string $itemType, int $id) => $this->completename($itemType, $id));
    }

    // -------------------------------------------------------------------------
    // Entité
    // -------------------------------------------------------------------------

    public function setEntityId(?int $entityId): void
    {
        $this->entityId = $entityId;
        $this->completenames = [];
        $this->agents = null;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    /**
     * En v2, l'entité active est portée par chaque requête (en-têtes GLPI-Entity /
     * GLPI-Entity-Recursive) : lever la restriction revient simplement à ne pas les
     * envoyer le temps du callback (périmètre par défaut du compte API).
     */
    public function withoutEntityRestriction(callable $callback): mixed
    {
        if ($this->entityId === null) {
            return $callback();
        }

        $previous = $this->entityRestricted;
        $this->entityRestricted = false;

        try {
            return $callback();
        } finally {
            $this->entityRestricted = $previous;
        }
    }

    // -------------------------------------------------------------------------
    // Authentification OAuth2
    // -------------------------------------------------------------------------

    public function authenticate(): void
    {
        foreach (['client_id', 'client_secret', 'username', 'password'] as $key) {
            if (blank($this->config[$key] ?? null)) {
                throw new RuntimeException(
                    'Configuration GLPI API v2 incomplète : GLPI_'.strtoupper($key).' manquant '
                    .'(cf. README, section « Configuration côté GLPI »)'
                );
            }
        }

        $this->requestToken([
            'grant_type' => 'password',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'username' => $this->config['username'],
            'password' => $this->config['password'],
            'scope' => $this->config['scope'] ?? 'api graphql',
        ]);
    }

    /**
     * L'API v2 est sans session : rien à fermer côté serveur, le jeton expire seul.
     */
    public function killSession(): void
    {
        $this->accessToken = null;
        $this->refreshToken = null;
        $this->expiresAt = 0;
    }

    private function requestToken(array $form): void
    {
        $url = $this->baseUrl().'/api.php/token';
        Log::debug('[GLPI] POST '.$url, ['grant_type' => $form['grant_type']]);

        $response = Http::asForm()->acceptJson()->post($url, $form);

        Log::debug('[GLPI] token → HTTP '.$response->status());

        if ($response->failed() || ! $response->json('access_token')) {
            Log::debug('[GLPI] Erreur token : '.$response->body());
            $detail = $response->json('error_description') ?? $response->json('message') ?? $response->json('error');

            throw new RuntimeException(
                'Authentification GLPI (OAuth2) échouée : '.$response->status().($detail ? ' — '.$detail : '')
            );
        }

        $this->accessToken = $response->json('access_token');
        $this->refreshToken = $response->json('refresh_token') ?? $this->refreshToken;
        $this->expiresAt = time() + (int) ($response->json('expires_in') ?? 3600);
    }

    /**
     * Renouvelle le jeton s'il expire bientôt : refresh_token si disponible, sinon
     * nouvelle authentification complète (un run sur un gros parc dépasse aisément 1 h).
     */
    private function ensureFreshToken(): void
    {
        if ($this->accessToken === null) {
            throw new RuntimeException('GlpiV2Client non authentifié. Appeler authenticate() d\'abord.');
        }

        if (time() < $this->expiresAt - self::TOKEN_REFRESH_MARGIN) {
            return;
        }

        $this->renewToken();
    }

    private function renewToken(): void
    {
        if ($this->refreshToken !== null) {
            try {
                $this->requestToken([
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $this->refreshToken,
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret'],
                ]);

                return;
            } catch (RuntimeException $e) {
                Log::debug('[GLPI] Échec refresh_token ('.$e->getMessage().'), nouvelle authentification');
                $this->refreshToken = null;
            }
        }

        $this->authenticate();
    }

    // -------------------------------------------------------------------------
    // Items
    // -------------------------------------------------------------------------

    /**
     * Récupère un item par son id. Paramètres v1 supportés : expand_dropdowns,
     * with_networkports, with_devices (processeurs), with_disks, with_infocoms,
     * with_softwares. Les autres (with_items…) sont ignorés, comme en v1.
     */
    public function getItem(string $itemType, int $id, array $params = []): array
    {
        // Défaut v1 de getItem : expand_dropdowns=0 (ids bruts).
        $expand = $this->expand($params, false);
        $path = $this->resource($itemType).'/'.$id;

        $item = $this->normalizer->normalize($itemType, $this->get($path, [], "{$itemType}/{$id}"), $expand);

        if (! empty($params['with_networkports'])) {
            $item['_networkports'] = $this->fetchNetworkPorts($itemType, $id);
        }

        if (! empty($params['with_devices'])) {
            $item['_devices'] = $this->fetchDevices($itemType, $id, $expand);
        }

        if (! empty($params['with_disks'])) {
            $item['_disks'] = array_map(
                fn ($disk) => $this->normalizer->normalize('Item_Disk', $disk, $expand),
                $this->listOrEmpty($this->resource($itemType)."/{$id}/Volume", "{$itemType}/{$id}/Volume")
            );
        }

        if (! empty($params['with_infocoms'])) {
            $infocom = $this->getOrNull($this->resource($itemType)."/{$id}/Infocom", "{$itemType}/{$id}/Infocom");
            $item['_infocoms'] = $infocom === null ? [] : $this->normalizer->normalize('Infocom', $infocom, $expand);
        }

        if (! empty($params['with_softwares'])) {
            $item['_softwares'] = $this->fetchSoftwareInstallations($itemType, $id, $expand);
        }

        return $item;
    }

    /**
     * Sous-items supportés : Item_OperatingSystem (route OSInstallation) et
     * Appliance_Item (GraphQL, aucune route REST de listing en v2).
     */
    public function getSubItems(string $itemType, int $id, string $subItemType, array $params = []): array
    {
        $expand = $this->expand($params, false);

        return match ($subItemType) {
            'Item_OperatingSystem' => array_map(
                fn ($os) => $this->normalizer->normalize('Item_OperatingSystem', $os, $expand),
                $this->listOrEmpty($this->resource($itemType)."/{$id}/OSInstallation", "{$itemType}/{$id}/OSInstallation")
            ),
            'Appliance_Item' => $this->fetchApplianceItems($id),
            default => throw new RuntimeException("Sous-item GLPI non supporté par l'API v2 : {$itemType}/{$id}/{$subItemType}"),
        };
    }

    /**
     * Récupère tous les items d'un itemtype (pagination start/limit + Content-Range).
     * Les items supprimés (corbeille) et les gabarits sont exclus, comme en v1.
     */
    public function getItems(string $itemType, array $extraParams = []): array
    {
        $expand = $this->expand($extraParams);

        if ($itemType === 'Item_Rack') {
            return $this->fetchItemRacks();
        }

        if (isset(self::GRAPHQL_COLLECTIONS[$itemType])) {
            $spec = self::GRAPHQL_COLLECTIONS[$itemType];
            $rows = $this->graphqlCollection($spec['type'], $spec['fields']);

            return array_values(array_map(
                fn ($row) => $this->normalizer->normalize('ItemVirtualMachine', $row, $expand),
                array_filter($rows, fn ($row) => empty($row['is_deleted']))
            ));
        }

        $rows = $this->getCollection($this->resource($itemType), $itemType);

        if (in_array($itemType, self::TREE_ITEMTYPES, true)) {
            $rows = $this->repairTreeFields($itemType, $rows);
        }

        $items = [];

        foreach ($rows as $raw) {
            if (! empty($raw['is_deleted']) || ! empty($raw['is_template'])) {
                continue;
            }
            $items[] = $this->normalizer->normalize($itemType, $raw, $expand);
        }

        Log::debug("[GLPI] {$itemType} → ".count($items).' item(s) reçu(s) au total');

        return $items;
    }

    // -------------------------------------------------------------------------
    // Relations (enrichissements)
    // -------------------------------------------------------------------------

    /**
     * Ports réseau au format v1 de with_networkports : instantiation_type → ports.
     *
     * Les ports (nom, MAC, type) viennent de GraphQL. L'API v2 n'exposant pas les
     * adresses IP (ni en REST ni en GraphQL, GLPI 11), celles-ci sont lues dans le
     * dernier fichier d'inventaire envoyé par le GLPI Agent de l'item, s'il en a un
     * (cf. fetchAgentInterfaces()), et rattachées aux ports par adresse MAC. Une
     * interface de l'inventaire sans port GLPI correspondant devient un port à part.
     * Les IP saisies manuellement dans GLPI, ou d'un item sans agent propre (switch
     * inventorié en SNMP…), ne sont pas récupérables en v2.
     *
     * Dégradé (warning unique, pas d'échec) si le scope GraphQL n'est pas accordé.
     */
    private function fetchNetworkPorts(string $itemType, int $id): array
    {
        try {
            $ports = $this->graphqlCollection(
                'NetworkPort',
                'id name mac instantiation_type logical_number is_deleted',
                "itemtype=={$itemType};items_id=={$id}"
            );
        } catch (RuntimeException $e) {
            if (! $this->networkPortsWarned) {
                Log::warning('[GLPI] Ports réseau indisponibles via GraphQL ('.$e->getMessage().') — seules les interfaces des inventaires d\'agent seront synchronisées');
                $this->networkPortsWarned = true;
            }

            $ports = [];
        }

        $interfaces = $this->fetchAgentInterfaces($itemType, $id);
        $matchedMacs = [];

        $byType = [];

        foreach ($ports as $port) {
            if (! empty($port['is_deleted'])) {
                continue;
            }

            $mac = $this->normalizeMac($port['mac'] ?? null);
            $ips = [];

            if ($mac !== null && isset($interfaces[$mac])) {
                $ips = $interfaces[$mac]['ips'];
                $matchedMacs[$mac] = true;
            }

            $type = $port['instantiation_type'] ?: 'NetworkPort';
            $byType[$type][] = $this->v1Port($port['id'], $port['name'], $port['mac'], $port['logical_number'], $ips);
        }

        foreach ($interfaces as $mac => $interface) {
            if (isset($matchedMacs[$mac]) || $interface['ips'] === []) {
                continue;
            }

            $type = $interface['wifi'] ? 'NetworkPortWifi' : 'NetworkPortEthernet';
            $byType[$type][] = $this->v1Port(null, $interface['name'], $interface['mac'], null, $interface['ips']);
        }

        return $byType;
    }

    private function v1Port(?int $id, ?string $name, ?string $mac, ?int $logicalNumber, array $ips): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'mac' => $mac,
            'logical_number' => $logicalNumber,
            'NetworkName' => ['IPAddress' => array_map(fn ($ip) => ['name' => $ip], $ips)],
        ];
    }

    /**
     * Interfaces réseau du dernier inventaire de l'agent GLPI rattaché à l'item
     * (route /Inventory/Agent/{id}/InventoryFile, format JSON natif ou XML hérité),
     * indexées par MAC normalisée : ['name', 'mac', 'wifi', 'ips' => [...]].
     * Une interface peut apparaître plusieurs fois (une entrée par IPv4/IPv6) : ses
     * IP sont cumulées dans l'ordre de l'inventaire. Interfaces sans MAC (loopback)
     * ignorées.
     */
    private function fetchAgentInterfaces(string $itemType, int $id): array
    {
        $agentId = $this->agentIndex()["{$itemType}_{$id}"] ?? null;

        if ($agentId === null) {
            return [];
        }

        $response = $this->send('get', "Inventory/Agent/{$agentId}/InventoryFile");

        if (! $response->successful()) {
            // 404 : fichier d'inventaire absent (purgé, ou item créé avant l'agent)
            Log::debug("[GLPI] Inventaire de l'agent #{$agentId} ({$itemType} #{$id}) indisponible : HTTP ".$response->status().' — adresses IP non synchronisées');

            return [];
        }

        $networks = $this->parseInventoryNetworks($response->body());
        $interfaces = [];

        foreach ($networks as $network) {
            $mac = $this->normalizeMac($network['macaddr'] ?? null);

            if ($mac === null) {
                continue;
            }

            $interfaces[$mac] ??= [
                'name' => $network['description'] ?? null,
                'mac' => $network['macaddr'],
                'wifi' => in_array(strtolower((string) ($network['type'] ?? '')), ['wifi', 'wireless'], true),
                'ips' => [],
            ];

            foreach (['ipaddress', 'ipaddress6'] as $key) {
                $ip = trim((string) ($network[$key] ?? ''));
                if ($ip !== '' && ! in_array($ip, $interfaces[$mac]['ips'], true)) {
                    $interfaces[$mac]['ips'][] = $ip;
                }
            }
        }

        Log::debug("[GLPI] Inventaire agent #{$agentId} ({$itemType} #{$id}) : ".count($interfaces).' interface(s) avec MAC');

        return $interfaces;
    }

    /**
     * Liste "networks" d'un fichier d'inventaire, clés en minuscules :
     * JSON natif GLPI Agent (content.networks) ou XML FusionInventory
     * (REQUEST/CONTENT/NETWORKS).
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseInventoryNetworks(string $body): array
    {
        $body = trim($body);

        if (str_starts_with($body, '{')) {
            $networks = json_decode($body, true)['content']['networks'] ?? [];

            return is_array($networks) ? array_values(array_filter($networks, 'is_array')) : [];
        }

        if (! str_starts_with($body, '<')) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);

        if ($xml === false || ! isset($xml->CONTENT->NETWORKS)) {
            return [];
        }

        $networks = [];
        foreach ($xml->CONTENT->NETWORKS as $network) {
            $networks[] = array_change_key_case(array_map('strval', (array) $network), CASE_LOWER);
        }

        return $networks;
    }

    /**
     * Index "{itemtype}_{items_id}" → id de l'agent GLPI (le plus récemment vu s'il y
     * en a plusieurs), chargé une seule fois. Dégradé en index vide (warning unique)
     * si le compte API n'a pas le droit de lire les agents.
     *
     * @return array<string, int>
     */
    private function agentIndex(): array
    {
        if ($this->agents !== null) {
            return $this->agents;
        }

        $this->agents = [];
        $lastContact = [];

        try {
            $agents = $this->getCollection('Inventory/Agent', 'Inventory/Agent');
        } catch (RuntimeException $e) {
            Log::warning('[GLPI] Agents d\'inventaire illisibles ('.$e->getMessage().') — adresses IP non synchronisées');

            return $this->agents;
        }

        foreach ($agents as $agent) {
            if (empty($agent['itemtype']) || empty($agent['items_id'])) {
                continue;
            }

            $key = $agent['itemtype'].'_'.$agent['items_id'];
            $contact = (string) ($agent['last_contact'] ?? '');

            if (! isset($this->agents[$key]) || strcmp($contact, $lastContact[$key]) > 0) {
                $this->agents[$key] = (int) $agent['id'];
                $lastContact[$key] = $contact;
            }
        }

        Log::debug('[GLPI] '.count($this->agents).' item(s) avec agent d\'inventaire');

        return $this->agents;
    }

    private function normalizeMac(?string $mac): ?string
    {
        $mac = strtolower(trim((string) $mac));

        return $mac === '' || $mac === '00:00:00:00:00:00' ? null : $mac;
    }

    /**
     * Composants au format v1 de with_devices. Seuls les processeurs sont repris
     * (seul composant exploité par les Mappers).
     */
    private function fetchDevices(string $itemType, int $id, bool $expand): array
    {
        $processors = $this->listOrEmpty($this->resource($itemType)."/{$id}/Component/Processor", "{$itemType}/{$id}/Component/Processor");

        return [
            'Item_DeviceProcessor' => array_map(
                fn ($p) => $this->normalizer->normalize('Item_DeviceProcessor', $p, $expand),
                $processors
            ),
        ];
    }

    /**
     * Logiciels installés au format v1 de with_softwares (softwares_id = id du
     * Software, ou son nom si expand_dropdowns=1).
     */
    private function fetchSoftwareInstallations(string $itemType, int $id, bool $expand): array
    {
        $rows = $this->graphqlCollection(
            'SoftwareInstallation',
            'id softwareversion { id name software { id name } }',
            "itemtype=={$itemType};items_id=={$id}"
        );

        $softwares = [];

        foreach ($rows as $row) {
            $software = $row['softwareversion']['software'] ?? null;

            if ($software === null) {
                continue;
            }

            $softwares[] = [
                'id' => $row['id'],
                'softwares_id' => $expand ? $software['name'] : $software['id'],
                'softwareversions_id' => $expand ? ($row['softwareversion']['name'] ?? 0) : ($row['softwareversion']['id'] ?? 0),
                'name' => $software['name'],
            ];
        }

        return $softwares;
    }

    /**
     * Lignes pivot Appliance_Item d'une Appliance (id, appliances_id, items_id, itemtype).
     */
    private function fetchApplianceItems(int $applianceId): array
    {
        $rows = $this->graphqlCollection(
            'Appliance_Item',
            'id itemtype items_id appliance { id }',
            "appliance.id=={$applianceId}"
        );

        return array_map(fn ($row) => [
            'id' => $row['id'],
            'appliances_id' => $row['appliance']['id'] ?? $applianceId,
            'items_id' => $row['items_id'],
            'itemtype' => $row['itemtype'],
        ], $rows);
    }

    /**
     * Relation item ↔ baie (Item_Rack v1), reconstituée depuis le champ "items" des
     * Rack v2 : "{itemtype, items_id}" + racks_id.
     */
    private function fetchItemRacks(): array
    {
        $rows = [];

        foreach ($this->getCollection($this->resource('Rack'), 'Rack') as $rack) {
            foreach ($rack['items'] ?? [] as $rackItem) {
                $rows[] = [
                    'id' => $rackItem['id'] ?? null,
                    'racks_id' => $rack['id'],
                    'itemtype' => $rackItem['itemtype'] ?? null,
                    'items_id' => $rackItem['items_id'] ?? null,
                ];
            }
        }

        return $rows;
    }

    /**
     * Chemin complet (completename) d'un dropdown arborescent, chargé une fois par
     * itemtype (une seule requête de collection) puis mis en cache.
     */
    private function completename(string $itemType, int $id): ?string
    {
        if (! isset($this->completenames[$itemType])) {
            $this->completenames[$itemType] = [];

            try {
                $rows = $this->repairTreeFields($itemType, $this->getCollection($this->resource($itemType), $itemType));

                foreach ($rows as $row) {
                    $this->completenames[$itemType][(int) $row['id']] = (string) $row['completename'];
                }
            } catch (RuntimeException $e) {
                Log::debug("[GLPI] Résolution des chemins {$itemType} impossible : ".$e->getMessage());
            }
        }

        return $this->completenames[$itemType][$id] ?? null;
    }

    /**
     * Complète completename/level des dropdowns arborescents.
     *
     * GLPI 11.0.8 (API 2.3) renvoie completename et level à null, dans les réponses de
     * collection, pour tout nœud ayant des enfants (le GET unitaire est correct). Or
     * level ordonne la création des Building parents avant leurs enfants (cf.
     * GlpiSyncService, étape 3c) et completename sert au filtre explicite par entité :
     * on les recalcule depuis la chaîne des parents ("Parent > Enfant", racine = 1),
     * avec un GET unitaire pour un parent absent de la collection (hors périmètre).
     */
    private function repairTreeFields(string $itemType, array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $resolve = function (int $id, array $seen) use (&$resolve, &$byId, $itemType): array {
            if (! isset($byId[$id])) {
                try {
                    $byId[$id] = $this->get($this->resource($itemType).'/'.$id, [], "{$itemType}/{$id}");
                } catch (RuntimeException) {
                    return ['completename' => null, 'level' => 0];
                }
            }

            $row = $byId[$id];

            if (($row['completename'] ?? null) !== null) {
                return [
                    'completename' => $row['completename'],
                    'level' => (int) ($row['level'] ?? substr_count($row['completename'], ' > ') + 1),
                ];
            }

            $parentId = $row['parent']['id'] ?? null;
            $name = (string) ($row['name'] ?? '');

            if ($parentId === null || isset($seen[(int) $parentId])) {
                $resolved = ['completename' => $name, 'level' => 1];
            } else {
                $parent = $resolve((int) $parentId, $seen + [$id => true]);
                $resolved = $parent['completename'] === null
                    ? ['completename' => $name, 'level' => 1]
                    : ['completename' => $parent['completename'].' > '.$name, 'level' => $parent['level'] + 1];
            }

            $byId[$id]['completename'] = $resolved['completename'];
            $byId[$id]['level'] = $resolved['level'];

            return $resolved;
        };

        foreach ($rows as &$row) {
            $resolved = $resolve((int) $row['id'], []);
            $row['completename'] = $resolved['completename'];
            $row['level'] = $resolved['level'];
        }
        unset($row);

        return $rows;
    }

    // -------------------------------------------------------------------------
    // HTTP
    // -------------------------------------------------------------------------

    /**
     * Parcourt toutes les pages d'une collection REST v2 : GLPI renvoie 206 et un
     * Content-Range "start-end/total" tant que la collection n'est pas complète.
     */
    private function getCollection(string $path, string $label): array
    {
        $items = [];
        $start = 0;

        while (true) {
            $response = $this->send('get', $path, ['start' => $start, 'limit' => self::PAGE_SIZE]);
            $this->assertOk($response, $label);

            $page = $response->json() ?? [];
            foreach ($page as $entry) {
                $items[] = $entry;
            }

            $total = $this->parseTotal($response->header('Content-Range'));
            $start += count($page);

            if ($page === [] || $total === null || $start >= $total) {
                break;
            }
        }

        return $items;
    }

    private function get(string $path, array $query, string $label): array
    {
        $response = $this->send('get', $path, $query);
        $this->assertOk($response, $label);

        return $response->json() ?? [];
    }

    /**
     * GET tolérant au 404 (ex. Infocom inexistant pour cet item).
     */
    private function getOrNull(string $path, string $label): ?array
    {
        $response = $this->send('get', $path);

        if ($response->status() === 404) {
            return null;
        }

        $this->assertOk($response, $label);

        return $response->json() ?? [];
    }

    private function listOrEmpty(string $path, string $label): array
    {
        return $this->getOrNull($path, $label) ?? [];
    }

    /**
     * Requête GraphQL paginée sur une collection ("{ Type(filter, start, limit) { … } }").
     */
    private function graphqlCollection(string $type, string $fields, ?string $filter = null): array
    {
        $rows = [];
        $start = 0;

        while (true) {
            $args = ['start: '.$start, 'limit: '.self::PAGE_SIZE];
            if ($filter !== null) {
                array_unshift($args, 'filter: '.json_encode($filter));
            }

            $query = '{ '.$type.'('.implode(', ', $args).') { '.$fields.' } }';
            $response = $this->send('post', 'GraphQL/', ['query' => $query]);
            $this->assertOk($response, "GraphQL {$type}");

            if ($response->json('errors')) {
                throw new RuntimeException(
                    "Erreur GraphQL GLPI ({$type}) : ".($response->json('errors.0.message') ?? 'inconnue')
                );
            }

            $page = $response->json("data.{$type}") ?? [];
            foreach ($page as $row) {
                $rows[] = $row;
            }

            $total = $response->json("extensions.pagination.{$type}.total_count");
            $start += count($page);

            if ($page === [] || $total === null || $start >= (int) $total) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Envoie une requête authentifiée ; sur 401 (jeton révoqué/expiré côté serveur),
     * renouvelle le jeton et rejoue une fois.
     */
    private function send(string $method, string $path, array $data = []): Response
    {
        $this->ensureFreshToken();

        $url = $this->apiUrl($path);
        Log::debug('[GLPI] '.strtoupper($method).' '.$path, $data);

        $response = $this->request()->{$method}($url, $data);

        if ($response->status() === 401) {
            Log::debug('[GLPI] 401 sur '.$path.' — renouvellement du jeton');
            $this->renewToken();
            $response = $this->request()->{$method}($url, $data);
        }

        Log::debug('[GLPI] '.$path.' → HTTP '.$response->status());

        return $response;
    }

    private function assertOk(Response $response, string $label): void
    {
        if ($response->successful()) {
            return;
        }

        Log::debug("[GLPI] Erreur {$label} : ".$response->body());

        $detail = $response->json('title') ?? $response->json('status');
        $hint = $response->status() === 403 && str_contains((string) $response->json('detail'), 'scope')
            ? ' (scope OAuth manquant : le client GLPI doit autoriser « api » et « graphql »)'
            : '';

        throw new RuntimeException(
            "Erreur lors de la récupération de {$label} : ".$response->status()
            .(is_string($detail) ? ' — '.$detail : '').$hint
        );
    }

    private function request(): PendingRequest
    {
        $headers = [];

        if ($this->entityId !== null && $this->entityRestricted) {
            $headers['GLPI-Entity'] = (string) $this->entityId;
            $headers['GLPI-Entity-Recursive'] = 'true';
        }

        return Http::withToken($this->accessToken)
            ->acceptJson()
            ->withHeaders($headers);
    }

    private function resource(string $itemType): string
    {
        return self::RESOURCES[$itemType]
            ?? throw new RuntimeException("Itemtype GLPI non supporté par l'API v2 : {$itemType}");
    }

    private function expand(array $params, bool $default = true): bool
    {
        return (bool) ($params['expand_dropdowns'] ?? $default);
    }

    private function parseTotal(?string $header): ?int
    {
        if ($header === null || ! preg_match('#^\d+-\d+/(\d+)$#', trim($header), $m)) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * URL racine GLPI ; tolère une URL saisie avec /apirest.php ou /api.php.
     */
    private function baseUrl(): string
    {
        $url = rtrim((string) $this->config['url'], '/');

        return preg_replace('#/(apirest\.php|api\.php)(/.*)?$#', '', $url);
    }

    private function apiUrl(string $path): string
    {
        return $this->baseUrl().'/api.php/v2/'.ltrim($path, '/');
    }
}
