<?php

namespace App\Services\Glpi\V2;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Convertit un item renvoyé par l'API GLPI v2 (High-Level API, GLPI ≥ 11) dans la
 * forme "à plat" de l'API v1 (apirest.php) attendue par GlpiSyncService et les Mappers.
 *
 * L'API v2 expose les relations sous forme d'objets ("location": {"id": 3, "name":
 * "Salle 101"}) et renomme certains champs ("last_boot", "date_buy"…). L'API v1, elle,
 * renvoie des colonnes SQL ("locations_id"), expansées en nom (expand_dropdowns=1) ou
 * laissées en id (expand_dropdowns=0). Normaliser ici, en un seul endroit, permet de
 * garder intacts les Mappers, les filtres (statut, sous-type, entité) et la
 * réconciliation, et donc de conserver les tests existants comme filet de non-régression.
 */
class ItemNormalizer
{
    /**
     * Relations v2 communes à tous les itemtypes → colonne v1.
     */
    private const COMMON_RELATIONS = [
        'entity' => 'entities_id',
        'location' => 'locations_id',
        'status' => 'states_id',
        'state' => 'states_id',
        'manufacturer' => 'manufacturers_id',
        'user' => 'users_id',
        'user_tech' => 'users_id_tech',
        'group' => 'groups_id',
        'group_tech' => 'groups_id_tech',
        'network' => 'networks_id',
        'autoupdatesystem' => 'autoupdatesystems_id',
    ];

    /**
     * Relations v2 propres à un itemtype → colonne v1 (prioritaires sur COMMON_RELATIONS).
     */
    private const TYPE_RELATIONS = [
        'Computer' => ['type' => 'computertypes_id', 'model' => 'computermodels_id'],
        'NetworkEquipment' => [
            'type' => 'networkequipmenttypes_id',
            'model' => 'networkequipmentmodels_id',
            'snmp_credential' => 'snmpcredentials_id',
        ],
        'Phone' => ['type' => 'phonetypes_id', 'model' => 'phonemodels_id', 'power_supply' => 'phonepowersupplies_id'],
        'Peripheral' => ['type' => 'peripheraltypes_id', 'model' => 'peripheralmodels_id'],
        'Rack' => ['type' => 'racktypes_id', 'model' => 'rackmodels_id', 'room' => 'dcrooms_id'],
        'Software' => ['category' => 'softwarecategories_id', 'parent' => 'softwares_id'],
        'Appliance' => ['type' => 'appliancetypes_id', 'environment' => 'applianceenvironments_id'],
        'Certificate' => ['type' => 'certificatetypes_id'],
        'Cluster' => ['type' => 'clustertypes_id'],
        'Domain' => ['type' => 'domaintypes_id'],
        'Database' => ['instance' => 'databaseinstances_id'],
        'DatabaseInstance' => [
            'type' => 'databaseinstancetypes_id',
            'category' => 'databaseinstancecategories_id',
        ],
        'Location' => ['parent' => 'locations_id'],
        'State' => ['parent' => 'states_id'],
        'Entity' => ['parent' => 'entities_id'],
        'Item_OperatingSystem' => [
            'operatingsystem' => 'operatingsystems_id',
            'version' => 'operatingsystemversions_id',
            'edition' => 'operatingsystemeditions_id',
            'servicepack' => 'operatingsystemservicepacks_id',
            'architecture' => 'operatingsystemarchitectures_id',
            'kernel_version' => 'operatingsystemkernelversions_id',
        ],
        'Item_Disk' => ['filesystem' => 'filesystems_id'],
        'Item_DeviceProcessor' => ['processor' => 'deviceprocessors_id'],
        'Infocom' => [
            'budget' => 'budgets_id',
            'supplier' => 'suppliers_id',
            'business_criticity' => 'businesscriticities_id',
        ],
        'ItemVirtualMachine' => [
            'state' => 'virtualmachinestates_id',
            'system' => 'virtualmachinesystems_id',
            'type' => 'virtualmachinetypes_id',
        ],
    ];

    /**
     * Champs scalaires renommés entre v1 (colonne SQL) et v2.
     */
    private const TYPE_FIELDS = [
        'Appliance' => ['external_id' => 'externalidentifier'],
        'Certificate' => ['is_selfsign' => 'is_autosign'],
        'Domain' => ['date_domain_creation' => 'date_domaincreation'],
        'Rack' => ['measured_power' => 'mesured_power'],
        'Item_Disk' => ['mount_point' => 'mountpoint', 'total_size' => 'totalsize', 'free_size' => 'freesize'],
        'Infocom' => [
            'date_buy' => 'buy_date',
            'date_use' => 'use_date',
            'date_order' => 'order_date',
            'date_delivery' => 'delivery_date',
            'date_inventory' => 'inventory_date',
            'date_warranty' => 'warranty_date',
            'date_decommission' => 'decommission_date',
            'amortization_type' => 'sink_type',
            'amortization_time' => 'sink_time',
            'amortization_coeff' => 'sink_coeff',
        ],
    ];

    /**
     * Relations pointant vers un dropdown arborescent : l'API v1 (expand_dropdowns=1)
     * renvoie leur chemin complet (completename, ex. "Siège > Bâtiment A"), l'API v2
     * seulement le nom propre. Le chemin est alors résolu via $completenameResolver.
     */
    private const TREE_DROPDOWNS = [
        'entities_id' => 'Entity',
        'locations_id' => 'Location',
        'states_id' => 'State',
    ];

    /**
     * @param  null|callable(string, int): ?string  $completenameResolver  itemtype dropdown + id → completename
     */
    public function __construct(private readonly mixed $completenameResolver = null) {}

    /**
     * @param  string  $itemType  Itemtype GLPI au sens v1 (Computer, Location, Item_Disk…)
     * @param  bool  $expandDropdowns  Équivalent v1 de expand_dropdowns : nom (true) ou id (false)
     */
    public function normalize(string $itemType, array $item, bool $expandDropdowns = true): array
    {
        $relations = array_merge(self::COMMON_RELATIONS, self::TYPE_RELATIONS[$itemType] ?? []);
        $fields = self::TYPE_FIELDS[$itemType] ?? [];

        $out = [];

        foreach ($item as $key => $value) {
            if (isset($relations[$key]) && ($value === null || is_array($value))) {
                $column = $relations[$key];
                $out[$column] = $this->relationValue($column, $value, $expandDropdowns);

                continue;
            }

            $out[$fields[$key] ?? $key] = $this->scalarValue($value);
        }

        return $out;
    }

    /**
     * Valeur v1 d'une relation : nom (ou chemin complet) si $expand, id sinon, 0 si non
     * renseignée — comme l'API v1, qui renvoie 0 pour un dropdown vide.
     * Relation multiple (ex. "group": [{id, name}, …]) : liste de noms ou d'ids.
     */
    private function relationValue(string $column, ?array $value, bool $expand): mixed
    {
        if ($value === null) {
            return 0;
        }

        if (array_is_list($value)) {
            return array_map(fn ($v) => is_array($v) ? $this->relationValue($column, $v, $expand) : $v, $value);
        }

        $id = $value['id'] ?? null;

        if (! $expand) {
            return $id === null ? 0 : (int) $id;
        }

        $name = $value['completename'] ?? null;

        if ($name === null && isset(self::TREE_DROPDOWNS[$column]) && $id !== null && $this->completenameResolver !== null) {
            $name = ($this->completenameResolver)(self::TREE_DROPDOWNS[$column], (int) $id);
        }

        $name ??= $value['name'] ?? $value['designation'] ?? null;

        return $name ?? ($id === null ? 0 : (int) $id);
    }

    /**
     * Booléens → 0/1 (forme v1), dates ISO 8601 → "Y-m-d H:i:s" dans le fuseau local.
     */
    private function scalarValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $value)) {
            try {
                return (new DateTimeImmutable($value))
                    ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                    ->format('Y-m-d H:i:s');
            } catch (Throwable) {
                return $value;
            }
        }

        return $value;
    }
}
