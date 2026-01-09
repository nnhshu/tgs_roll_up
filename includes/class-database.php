<?php
/**
 * Database class for TGS Sync Roll-Up
 *
 * Tạo và quản lý bảng sync_roll_up_config
 *
 * @package TGS_Sync_Roll_Up
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Sync_Roll_Up_Database
{
    /**
     * Config table name
     */
    private $config_table;

    /**
     * Constructor
     */
    public function __construct()
    {
        global $wpdb;
        $this->config_table = $wpdb->prefix . 'sync_roll_up_config';
    }

    /**
     * Get config table name
     */
    public static function get_config_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'sync_roll_up_config';
    }

    /**
     * Create tables
     */
    public static function create_tables()
    {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $charset_collate = $wpdb->get_charset_collate();

        // 1. Tạo bảng config
        $config_table = self::get_config_table_name();

        $sql = "CREATE TABLE $config_table (
            config_id BIGINT NOT NULL AUTO_INCREMENT,
            blog_id BIGINT NOT NULL,

            -- Cấu hình shop cha
            parent_blog_ids JSON,

            -- Cấu hình cron
            sync_interval VARCHAR(50) DEFAULT 'hourly',
            sync_enabled TINYINT DEFAULT 1,
            last_sync_at DATETIME,
            next_sync_at DATETIME,

            -- Cấu hình roll-up
            auto_rollup_daily TINYINT DEFAULT 1,
            rollup_time TIME DEFAULT '00:30:00',


            created_at DATETIME NOT NULL,
            updated_at DATETIME,

            PRIMARY KEY (config_id),
            UNIQUE KEY uk_blog_id (blog_id)
        ) $charset_collate;";

        dbDelta($sql);

        // 2. Tạo bảng roll_up cho site hiện tại đang activate plugin
        $current_blog_id = get_current_blog_id();
        self::create_roll_up_table($current_blog_id);
    }

    /**
     * Tạo bảng roll_up cho một blog cụ thể
     *
     * @param int $blog_id Blog ID
     */
    public static function create_roll_up_table($blog_id)
    {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'roll_up';

        $sql = "CREATE TABLE $table_name (
            roll_up_id BIGINT NOT NULL AUTO_INCREMENT,
            blog_id BIGINT NOT NULL,
            local_product_name_id BIGINT NOT NULL,
            global_product_name_id BIGINT,
            roll_up_date DATE NOT NULL,
            roll_up_day INT NOT NULL,
            roll_up_month INT NOT NULL,
            roll_up_year INT NOT NULL,
            amount DECIMAL(15,2) DEFAULT 0,
            quantity INT DEFAULT 0,
            type TINYINT DEFAULT 0,
            meta JSON,
            created_at DATETIME NOT NULL,
            updated_at DATETIME,

            PRIMARY KEY (roll_up_id),
            UNIQUE KEY uk_blog_day_month_year_product_type (blog_id, roll_up_day, roll_up_month, roll_up_year, local_product_name_id, type),
            KEY idx_blog_id (blog_id),
            KEY idx_type (type)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Drop tables
     */
    public static function drop_tables()
    {
        global $wpdb;

        // 1. Xóa bảng config
        $config_table = self::get_config_table_name();
        $wpdb->query("DROP TABLE IF EXISTS $config_table");

        // 2. Xóa bảng roll_up của site hiện tại
        $current_blog_id = get_current_blog_id();
        $roll_up_table = $wpdb->prefix . $current_blog_id . '_roll_up';
        $wpdb->query("DROP TABLE IF EXISTS $roll_up_table");
    }

    /**
     * Get config for current blog
     */
    public function get_config($blog_id = null)
    {
        global $wpdb;

        if ($blog_id === null) {
            $blog_id = get_current_blog_id();
        }

        // Check if table exists first
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->config_table}'");
        
        if (!$table_exists) {
            // Table doesn't exist, create it
            self::create_tables();
        }

        $config = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->config_table} WHERE blog_id = %d",
                $blog_id
            )
        );

        if ($config) {
            $config->parent_blog_ids = json_decode($config->parent_blog_ids, true) ?: array();
        } else {
            // Return default config as object
            $config = (object) array(
                'config_id'                 => 0,
                'blog_id'                   => $blog_id,
                'parent_blog_ids'           => array(),
                'sync_interval'             => 'hourly',
                'sync_enabled'              => 1,
                'last_sync_at'              => null,
                'next_sync_at'              => null,
                'auto_rollup_daily'         => 1,
                'rollup_time'               => '00:30:00',
            );
        }

        return $config;
    }

    /**
     * Save config for current blog
     */
    public function save_config($data, $blog_id = null)
    {
        global $wpdb;

        if ($blog_id === null) {
            $blog_id = get_current_blog_id();
        }

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT config_id FROM {$this->config_table} WHERE blog_id = %d",
                $blog_id
            )
        );

        // Handle parent_blog_ids - có thể là array hoặc đã là JSON string
        $parent_ids = isset($data['parent_blog_ids']) ? $data['parent_blog_ids'] : array();
        if (is_string($parent_ids)) {
            // Đã là JSON string rồi
            $parent_ids_json = $parent_ids;
        } else {
            // Là array, cần encode
            $parent_ids_json = json_encode($parent_ids);
        }

        $save_data = array(
            'blog_id'           => $blog_id,
            'parent_blog_ids'   => $parent_ids_json,
            'sync_interval'     => isset($data['sync_interval']) ? $data['sync_interval'] : 'hourly',
            'sync_enabled'      => isset($data['sync_enabled']) ? intval($data['sync_enabled']) : 1,
            'auto_rollup_daily' => isset($data['auto_rollup_daily']) ? intval($data['auto_rollup_daily']) : 1,
            'updated_at'        => current_time('mysql'),
        );

        if ($existing) {
            return $wpdb->update(
                $this->config_table,
                $save_data,
                array('config_id' => $existing)
            );
        } else {
            $save_data['created_at'] = current_time('mysql');
            return $wpdb->insert($this->config_table, $save_data);
        }
    }

    /**
     * Update tracking IDs
     */
    public function update_tracking($data, $blog_id = null)
    {
        global $wpdb;

        if ($blog_id === null) {
            $blog_id = get_current_blog_id();
        }

        $update_data = array(
            'updated_at' => current_time('mysql'),
        );

        if (isset($data['last_processed_ledger_id'])) {
            $update_data['last_processed_ledger_id'] = $data['last_processed_ledger_id'];
        }

        if (isset($data['last_processed_lot_id'])) {
            $update_data['last_processed_lot_id'] = $data['last_processed_lot_id'];
        }

        if (isset($data['last_processed_person_id'])) {
            $update_data['last_processed_person_id'] = $data['last_processed_person_id'];
        }

        if (isset($data['last_sync_at'])) {
            $update_data['last_sync_at'] = $data['last_sync_at'];
        }

        return $wpdb->update(
            $this->config_table,
            $update_data,
            array('blog_id' => $blog_id)
        );
    }

    /**
     * Get all blogs in multisite
     */
    public function get_all_blogs()
    {
        if (!is_multisite()) {
            return array(
                (object) array(
                    'blog_id' => 1,
                    'blogname' => get_bloginfo('name'),
                    'siteurl' => get_site_url(),
                )
            );
        }

        $sites = get_sites(array(
            'number' => 1000,
            'public' => 1,
        ));

        $blogs = array();
        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            $blogs[] = (object) array(
                'blog_id'  => $site->blog_id,
                'blogname' => get_bloginfo('name'),
                'siteurl'  => get_site_url(),
            );
            restore_current_blog();
        }

        return $blogs;
    }

    /**
     * Get sync status for dashboard
     */
    public function get_sync_status($blog_id = null)
    {
        $config = $this->get_config($blog_id);

        // Calculate next sync time
        $next_scheduled = wp_next_scheduled('tgs_sync_rollup_cron');

        return array(
            'sync_enabled'       => (bool) $config->sync_enabled,
            'sync_interval'      => $config->sync_interval,
            'last_sync_at'       => $config->last_sync_at,
            'next_sync_at'       => $next_scheduled ? date('Y-m-d H:i:s', $next_scheduled) : null,
            'parent_blog_ids'    => $config->parent_blog_ids,
            'parent_blogs_count' => is_array($config->parent_blog_ids) ? count($config->parent_blog_ids) : 0,
        );
    }
}
