<?php
/**
 * Service Container
 * Dependency Injection Container cho plugin
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class ServiceContainer
{
    /**
     * @var array Bindings
     */
    private static $bindings = [];

    /**
     * @var array Singletons
     */
    private static $singletons = [];

    /**
     * @var array Instances
     */
    private static $instances = [];

    /**
     * Bind một interface/class với implementation
     *
     * @param string $abstract Interface hoặc class name
     * @param callable $concrete Resolver function
     * @param bool $singleton Có phải singleton không
     */
    public static function bind(string $abstract, callable $concrete, bool $singleton = false): void
    {
        self::$bindings[$abstract] = $concrete;

        if ($singleton) {
            self::$singletons[$abstract] = true;
        }
    }

    /**
     * Bind một singleton
     *
     * @param string $abstract Interface hoặc class name
     * @param callable $concrete Resolver function
     */
    public static function singleton(string $abstract, callable $concrete): void
    {
        self::bind($abstract, $concrete, true);
    }

    /**
     * Lấy instance của một service
     *
     * @param string $abstract Interface hoặc class name
     * @return mixed Instance
     * @throws Exception Nếu không tìm thấy binding
     */
    public static function make(string $abstract)
    {
        // Kiểm tra singleton instance đã tồn tại chưa
        if (isset(self::$singletons[$abstract]) && isset(self::$instances[$abstract])) {
            return self::$instances[$abstract];
        }

        // Kiểm tra binding có tồn tại không
        if (!isset(self::$bindings[$abstract])) {
            throw new Exception("No binding found for {$abstract}");
        }

        // Resolve instance
        $instance = call_user_func(self::$bindings[$abstract]);

        // Lưu singleton instance
        if (isset(self::$singletons[$abstract])) {
            self::$instances[$abstract] = $instance;
        }

        return $instance;
    }

    /**
     * Register tất cả services
     */
    public static function registerServices(): void
    {
        // Infrastructure - Singletons
        self::singleton('BlogContext', function() {
            return new BlogContext();
        });

        self::singleton(DataSourceInterface::class, function() {
            return new TgsShopDataSource();
        });

        self::singleton(RollUpRepositoryInterface::class, function() {
            return new ProductRollUpRepository();
        });

        self::singleton(ConfigRepositoryInterface::class, function() {
            return new ConfigRepository();
        });

        self::singleton('InventoryRollUpRepository', function() {
            return new InventoryRollUpRepository();
        });

        self::singleton('OrderRollUpRepository', function() {
            return new OrderRollUpRepository();
        });

        self::singleton('AccountingRollUpRepository', function() {
            return new AccountingRollUpRepository();
        });

        // Application - Use Cases (không phải singleton, tạo mới mỗi lần)
        self::bind(CalculateDailyProductRollup::class, function() {
            return new CalculateDailyProductRollup(
                self::make(DataSourceInterface::class),
                self::make(RollUpRepositoryInterface::class),
                self::make('BlogContext')
            );
        });

        self::bind(CalculateDailyInventory::class, function() {
            return new CalculateDailyInventory(
                self::make('BlogContext'),
                self::make(DataSourceInterface::class)
            );
        });

        self::bind(SyncToParentShop::class, function() {
            return new SyncToParentShop(
                self::make(RollUpRepositoryInterface::class),
                self::make(ConfigRepositoryInterface::class),
                self::make('BlogContext')
            );
        });

        self::bind(SyncInventoryToParentShop::class, function() {
            return new SyncInventoryToParentShop(
                self::make('InventoryRollUpRepository'),
                self::make(ConfigRepositoryInterface::class),
                self::make('BlogContext')
            );
        });

        self::bind(CalculateDailyOrder::class, function() {
            return new CalculateDailyOrder(
                self::make('BlogContext'),
                self::make(DataSourceInterface::class)
            );
        });

        self::bind(SyncOrderToParentShop::class, function() {
            return new SyncOrderToParentShop(
                self::make('OrderRollUpRepository'),
                self::make(ConfigRepositoryInterface::class),
                self::make('BlogContext')
            );
        });

        self::bind(CalculateDailyAccounting::class, function() {
            return new CalculateDailyAccounting(
                self::make('BlogContext'),
                self::make(DataSourceInterface::class)
            );
        });

        self::bind(SyncAccountingToParentShop::class, function() {
            return new SyncAccountingToParentShop(
                self::make('AccountingRollUpRepository'),
                self::make(ConfigRepositoryInterface::class),
                self::make('BlogContext')
            );
        });

        // Application - Services
        self::singleton(CronService::class, function() {
            return new CronService(
                self::make(CalculateDailyProductRollup::class),
                self::make(CalculateDailyInventory::class),
                self::make(SyncToParentShop::class),
                self::make(SyncInventoryToParentShop::class),
                self::make(CalculateDailyOrder::class),
                self::make(SyncOrderToParentShop::class),
                self::make(CalculateDailyAccounting::class),
                self::make(SyncAccountingToParentShop::class),
                self::make(ConfigRepositoryInterface::class),
                self::make(DataSourceInterface::class)
            );
        });

        // Presentation - AJAX Handlers
        self::singleton(SyncAjaxHandler::class, function() {
            return new SyncAjaxHandler(
                self::make(CalculateDailyProductRollup::class),
                self::make(CalculateDailyInventory::class),
                self::make(SyncToParentShop::class),
                self::make(SyncInventoryToParentShop::class),
                self::make(CalculateDailyOrder::class),
                self::make(SyncOrderToParentShop::class)
            );
        });

        self::singleton(ConfigAjaxHandler::class, function() {
            return new ConfigAjaxHandler(
                self::make(ConfigRepositoryInterface::class)
            );
        });

        self::singleton(DashboardAjaxHandler::class, function() {
            return new DashboardAjaxHandler(
                self::make(RollUpRepositoryInterface::class),
                self::make(ConfigRepositoryInterface::class)
            );
        });
    }

    /**
     * Reset container (dùng cho testing)
     */
    public static function reset(): void
    {
        self::$bindings = [];
        self::$singletons = [];
        self::$instances = [];
    }
}
