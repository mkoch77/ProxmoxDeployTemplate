<?php

namespace App;

use PDO;

class Migrator
{
    public static function run(): void
    {
        $db = Database::connection();
        $db->exec('CREATE TABLE IF NOT EXISTS migrations (
            version INTEGER PRIMARY KEY,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )');

        $applied = $db->query('SELECT version FROM migrations')
            ->fetchAll(PDO::FETCH_COLUMN);

        $migrations = self::getMigrations();
        foreach ($migrations as $version => $sql) {
            if (!in_array($version, $applied, true)) {
                foreach (self::splitStatements($sql) as $statement) {
                    $db->exec($statement);
                }
                $stmt = $db->prepare('INSERT INTO migrations (version) VALUES (?)');
                $stmt->execute([$version]);
            }
        }
    }

    private static function splitStatements(string $sql): array
    {
        return array_values(array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => $s !== ''
        ));
    }

    private static function getMigrations(): array
    {
        return [
            1 => self::migration001(),
            2 => self::migration002(),
            3 => self::migration003(),
            4 => self::migration004(),
            5 => self::migration005(),
            6 => self::migration006(),
            7 => self::migration007(),
            8 => self::migration008(),
            9 => self::migration009(),
           10 => self::migration010(),
           11 => self::migration011(),
           12 => self::migration012(),
           13 => self::migration013(),
           14 => self::migration014(),
           15 => self::migration015(),
           16 => self::migration016(),
           17 => self::migration017(),
           18 => self::migration018(),
           19 => self::migration019(),
           20 => self::migration020(),
           21 => self::migration021(),
           22 => self::migration022(),
        ];
    }

    private static function migration001(): string
    {
        return "
            CREATE TABLE IF NOT EXISTS users (
                id SERIAL PRIMARY KEY,
                username VARCHAR(255) NOT NULL UNIQUE,
                display_name VARCHAR(255) NOT NULL DEFAULT '',
                email VARCHAR(255) DEFAULT '',
                password_hash VARCHAR(255) DEFAULT NULL,
                auth_provider VARCHAR(50) NOT NULL DEFAULT 'local',
                entraid_oid VARCHAR(255) DEFAULT NULL UNIQUE,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS roles (
                id SERIAL PRIMARY KEY,
                name VARCHAR(100) NOT NULL UNIQUE,
                description VARCHAR(255) DEFAULT ''
            );

            CREATE TABLE IF NOT EXISTS permissions (
                id SERIAL PRIMARY KEY,
                key VARCHAR(100) NOT NULL UNIQUE,
                description VARCHAR(255) DEFAULT ''
            );

            CREATE TABLE IF NOT EXISTS role_permissions (
                role_id INTEGER NOT NULL,
                permission_id INTEGER NOT NULL,
                PRIMARY KEY (role_id, permission_id),
                FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
                FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS user_roles (
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, role_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS user_sessions (
                id VARCHAR(255) PRIMARY KEY,
                user_id INTEGER NOT NULL,
                ip_address VARCHAR(45),
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            INSERT INTO roles (name, description) VALUES
                ('admin', 'Full access'),
                ('operator', 'VM management and deployment'),
                ('viewer', 'Read only')
            ON CONFLICT (name) DO NOTHING;

            INSERT INTO permissions (key, description) VALUES
                ('vm.start', 'Start VMs/CTs'),
                ('vm.stop', 'Stop VMs/CTs'),
                ('vm.reboot', 'Reboot VMs/CTs'),
                ('vm.shutdown', 'Shut down VMs/CTs'),
                ('template.deploy', 'Deploy templates'),
                ('cluster.health.view', 'View cluster health'),
                ('cluster.maintenance', 'Manage maintenance mode'),
                ('users.manage', 'Manage users')
            ON CONFLICT (key) DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = 'admin'
            ON CONFLICT DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'operator' AND p.key IN (
                    'vm.start','vm.stop','vm.reboot','vm.shutdown',
                    'template.deploy','cluster.health.view'
                )
            ON CONFLICT DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'viewer' AND p.key = 'cluster.health.view'
            ON CONFLICT DO NOTHING;
        ";
    }

    private static function migration002(): string
    {
        return "
            CREATE TABLE IF NOT EXISTS maintenance_nodes (
                node_name VARCHAR(255) PRIMARY KEY,
                status VARCHAR(50) NOT NULL DEFAULT 'entering',
                started_by INTEGER,
                started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                migration_tasks TEXT DEFAULT '[]',
                FOREIGN KEY (started_by) REFERENCES users(id)
            );
        ";
    }

    private static function migration003(): string
    {
        return "
            CREATE TABLE IF NOT EXISTS drs_settings (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                enabled INTEGER NOT NULL DEFAULT 0,
                automation_level VARCHAR(50) NOT NULL DEFAULT 'manual',
                cpu_weight INTEGER NOT NULL DEFAULT 50,
                ram_weight INTEGER NOT NULL DEFAULT 50,
                threshold INTEGER NOT NULL DEFAULT 3,
                interval_minutes INTEGER NOT NULL DEFAULT 5,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            INSERT INTO drs_settings (id) VALUES (1) ON CONFLICT (id) DO NOTHING;

            CREATE TABLE IF NOT EXISTS drs_runs (
                id SERIAL PRIMARY KEY,
                triggered_by VARCHAR(50) NOT NULL DEFAULT 'cron',
                node_count INTEGER NOT NULL DEFAULT 0,
                cluster_avg_score REAL NOT NULL DEFAULT 0,
                recommendations_count INTEGER NOT NULL DEFAULT 0,
                executed_count INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS drs_recommendations (
                id SERIAL PRIMARY KEY,
                run_id INTEGER NOT NULL,
                vmid INTEGER NOT NULL,
                vm_name VARCHAR(255) NOT NULL DEFAULT '',
                vm_type VARCHAR(50) NOT NULL DEFAULT 'qemu',
                source_node VARCHAR(255) NOT NULL,
                target_node VARCHAR(255) NOT NULL,
                reason TEXT NOT NULL DEFAULT '',
                impact_score REAL NOT NULL DEFAULT 0,
                status VARCHAR(50) NOT NULL DEFAULT 'pending',
                upid VARCHAR(255) DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                applied_at TIMESTAMP DEFAULT NULL,
                FOREIGN KEY (run_id) REFERENCES drs_runs(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_drs_recommendations_run ON drs_recommendations(run_id);
            CREATE INDEX IF NOT EXISTS idx_drs_runs_created ON drs_runs(created_at);

            INSERT INTO permissions (key, description) VALUES
                ('drs.view', 'View loadbalancer recommendations'),
                ('drs.manage', 'Manage loadbalancer settings')
            ON CONFLICT (key) DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'admin' AND p.key IN ('drs.view', 'drs.manage')
            ON CONFLICT DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'operator' AND p.key = 'drs.view'
            ON CONFLICT DO NOTHING;
        ";
    }

    private static function migration004(): string
    {
        return "
            ALTER TABLE users ADD COLUMN IF NOT EXISTS theme VARCHAR(50) NOT NULL DEFAULT 'auto';
        ";
    }

    private static function migration005(): string
    {
        return "
            ALTER TABLE drs_settings ADD COLUMN IF NOT EXISTS max_concurrent INTEGER NOT NULL DEFAULT 3;
        ";
    }

    private static function migration006(): string
    {
        return "
            INSERT INTO permissions (key, description) VALUES
                ('vm.migrate', 'Migrate VMs/CTs between nodes')
            ON CONFLICT (key) DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'admin' AND p.key = 'vm.migrate'
            ON CONFLICT DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'operator' AND p.key = 'vm.migrate'
            ON CONFLICT DO NOTHING;
        ";
    }

    private static function migration007(): string
    {
        return "
            ALTER TABLE drs_runs ADD COLUMN IF NOT EXISTS skipped_reasons TEXT DEFAULT NULL;
        ";
    }

    private static function migration008(): string
    {
        return "
            INSERT INTO permissions (key, description) VALUES
                ('vm.delete', 'Delete VMs/CTs')
            ON CONFLICT (key) DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'admin' AND p.key = 'vm.delete'
            ON CONFLICT DO NOTHING;
        ";
    }

    private static function migration009(): string
    {
        return "
            INSERT INTO permissions (key, description) VALUES
                ('cluster.ha', 'Manage HA resources')
            ON CONFLICT (key) DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'admin' AND p.key = 'cluster.ha'
            ON CONFLICT DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'operator' AND p.key = 'cluster.ha'
            ON CONFLICT DO NOTHING;
        ";
    }

    private static function migration011(): string
    {
        return "
            CREATE TABLE IF NOT EXISTS user_permission_overrides (
                user_id INTEGER NOT NULL,
                permission_id INTEGER NOT NULL,
                granted INTEGER NOT NULL DEFAULT 1,
                PRIMARY KEY (user_id, permission_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
            );

            INSERT INTO permissions (key, description) VALUES
                ('community.install', 'Install community scripts via SSH on nodes')
            ON CONFLICT (key) DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name IN ('admin', 'operator') AND p.key = 'community.install'
            ON CONFLICT DO NOTHING;
        ";
    }

    private static function migration012(): string
    {
        return "
            INSERT INTO permissions (key, description) VALUES
                ('community.install', 'Install community scripts via SSH on nodes')
            ON CONFLICT (key) DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name IN ('admin', 'operator') AND p.key = 'community.install'
            ON CONFLICT DO NOTHING;
        ";
    }

    private static function migration013(): string
    {
        return "ALTER TABLE users ADD COLUMN IF NOT EXISTS ssh_public_keys TEXT NOT NULL DEFAULT ''";
    }

    private static function migration014(): string
    {
        return "ALTER TABLE users ADD COLUMN IF NOT EXISTS default_storage VARCHAR(255) NOT NULL DEFAULT ''";
    }

    private static function migration015(): string
    {
        return "
            CREATE TABLE IF NOT EXISTS custom_images (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                filename VARCHAR(255) NOT NULL UNIQUE,
                default_user VARCHAR(100) NOT NULL DEFAULT 'user',
                ostype VARCHAR(50) NOT NULL DEFAULT 'l26',
                uploaded_by INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (uploaded_by) REFERENCES users(id)
            )
        ";
    }

    private static function migration016(): string
    {
        return "
            CREATE TABLE IF NOT EXISTS node_metrics (
                id BIGSERIAL PRIMARY KEY,
                node VARCHAR(255) NOT NULL,
                ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                cpu_pct REAL NOT NULL DEFAULT 0,
                mem_used BIGINT NOT NULL DEFAULT 0,
                mem_total BIGINT NOT NULL DEFAULT 0,
                disk_read_bytes BIGINT NOT NULL DEFAULT 0,
                disk_write_bytes BIGINT NOT NULL DEFAULT 0,
                disk_read_iops REAL NOT NULL DEFAULT 0,
                disk_write_iops REAL NOT NULL DEFAULT 0,
                net_in_bytes BIGINT NOT NULL DEFAULT 0,
                net_out_bytes BIGINT NOT NULL DEFAULT 0
            );
            CREATE INDEX IF NOT EXISTS idx_node_metrics_node_ts ON node_metrics(node, ts);
            CREATE INDEX IF NOT EXISTS idx_node_metrics_ts ON node_metrics(ts);

            CREATE TABLE IF NOT EXISTS vm_metrics (
                id BIGSERIAL PRIMARY KEY,
                vmid INTEGER NOT NULL,
                node VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL DEFAULT '',
                vm_type VARCHAR(10) NOT NULL DEFAULT 'qemu',
                ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                status VARCHAR(50) NOT NULL DEFAULT 'unknown',
                cpu_pct REAL NOT NULL DEFAULT 0,
                cpu_count INTEGER NOT NULL DEFAULT 0,
                mem_used BIGINT NOT NULL DEFAULT 0,
                mem_total BIGINT NOT NULL DEFAULT 0,
                disk_read_bytes BIGINT NOT NULL DEFAULT 0,
                disk_write_bytes BIGINT NOT NULL DEFAULT 0,
                disk_read_iops REAL NOT NULL DEFAULT 0,
                disk_write_iops REAL NOT NULL DEFAULT 0,
                net_in_bytes BIGINT NOT NULL DEFAULT 0,
                net_out_bytes BIGINT NOT NULL DEFAULT 0
            );
            CREATE INDEX IF NOT EXISTS idx_vm_metrics_vmid_ts ON vm_metrics(vmid, ts);
            CREATE INDEX IF NOT EXISTS idx_vm_metrics_ts ON vm_metrics(ts);

            CREATE TABLE IF NOT EXISTS monitoring_settings (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                retention_days INTEGER NOT NULL DEFAULT 30,
                collection_interval INTEGER NOT NULL DEFAULT 10,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            INSERT INTO monitoring_settings (id) VALUES (1) ON CONFLICT (id) DO NOTHING;

            INSERT INTO permissions (key, description) VALUES
                ('monitoring.view', 'View monitoring data and charts'),
                ('monitoring.manage', 'Manage monitoring settings')
            ON CONFLICT (key) DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'admin' AND p.key IN ('monitoring.view', 'monitoring.manage')
            ON CONFLICT DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'operator' AND p.key = 'monitoring.view'
            ON CONFLICT DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'viewer' AND p.key = 'monitoring.view'
            ON CONFLICT DO NOTHING;
        ";
    }

    private static function migration017(): string
    {
        return "
            CREATE TABLE IF NOT EXISTS guest_ips (
                vmid INTEGER NOT NULL,
                node VARCHAR(255) NOT NULL,
                ips TEXT NOT NULL DEFAULT '[]',
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (vmid, node)
            )
        ";
    }

    private static function migration010(): string
    {
        return "
            CREATE TABLE IF NOT EXISTS rolling_update_sessions (
                id SERIAL PRIMARY KEY,
                nodes TEXT NOT NULL,
                node_statuses TEXT NOT NULL DEFAULT '{}',
                status VARCHAR(50) NOT NULL DEFAULT 'running',
                started_by INTEGER,
                started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (started_by) REFERENCES users(id)
            );

            INSERT INTO permissions (key, description) VALUES
                ('cluster.update', 'Run rolling node updates')
            ON CONFLICT (key) DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'admin' AND p.key = 'cluster.update'
            ON CONFLICT DO NOTHING;
        ";
    }

    private static function migration018(): string
    {
        return "
            CREATE TABLE IF NOT EXISTS app_logs (
                id SERIAL PRIMARY KEY,
                level VARCHAR(20) NOT NULL DEFAULT 'info',
                category VARCHAR(100) NOT NULL DEFAULT 'general',
                message TEXT NOT NULL,
                context TEXT DEFAULT NULL,
                user_id INTEGER DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_app_logs_created ON app_logs (created_at DESC);
            CREATE INDEX IF NOT EXISTS idx_app_logs_level ON app_logs (level);
            CREATE INDEX IF NOT EXISTS idx_app_logs_category ON app_logs (category);
        ";
    }

    private static function migration019(): string
    {
        return "
            CREATE TABLE IF NOT EXISTS windows_images (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                iso_filename VARCHAR(500) NOT NULL,
                autounattend_xml TEXT DEFAULT NULL,
                product_key VARCHAR(50) DEFAULT NULL,
                install_guest_tools BOOLEAN DEFAULT TRUE,
                extra_drivers TEXT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                uploaded_by INTEGER DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (uploaded_by) REFERENCES users(id)
            );
        ";
    }

    private static function migration020(): string
    {
        return "
            INSERT INTO permissions (key, description)
            VALUES ('logs.view', 'View application logs')
            ON CONFLICT (key) DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r, permissions p
            WHERE r.name = 'admin' AND p.key = 'logs.view'
            ON CONFLICT DO NOTHING;
        ";
    }

    private static function migration021(): string
    {
        return "
            CREATE TABLE IF NOT EXISTS rightsizing_applied (
                vmid INTEGER NOT NULL,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (vmid)
            );
        ";
    }

    private static function migration022(): string
    {
        return "
            CREATE TABLE IF NOT EXISTS service_templates (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                base_image VARCHAR(100) NOT NULL,
                icon VARCHAR(50) NOT NULL DEFAULT 'bi-box-seam',
                color VARCHAR(20) NOT NULL DEFAULT '#6c757d',
                cores INTEGER NOT NULL DEFAULT 2,
                memory INTEGER NOT NULL DEFAULT 2048,
                disk_size INTEGER NOT NULL DEFAULT 10,
                packages TEXT NOT NULL DEFAULT '',
                runcmd TEXT NOT NULL DEFAULT '',
                tags TEXT NOT NULL DEFAULT '',
                created_by INTEGER REFERENCES users(id),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ";
    }

    /**
     * Seed default service templates after migrations.
     * Uses name-based check so new built-in templates are added automatically.
     */
    public static function seed(): void
    {
        $db = Database::connection();

        try {
            $existing = $db->query('SELECT name FROM service_templates')
                ->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Exception $e) {
            return; // table doesn't exist yet
        }

        $builtins = self::getBuiltinServiceTemplates();
        $stmt = $db->prepare('INSERT INTO service_templates
            (name, description, base_image, icon, color, cores, memory, disk_size, packages, runcmd, tags)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        foreach ($builtins as $tpl) {
            if (in_array($tpl['name'], $existing, true)) continue;
            $stmt->execute([
                $tpl['name'], $tpl['description'], $tpl['base_image'],
                $tpl['icon'], $tpl['color'],
                $tpl['cores'], $tpl['memory'], $tpl['disk_size'],
                $tpl['packages'], $tpl['runcmd'], $tpl['tags'],
            ]);
        }
    }

    private static function getBuiltinServiceTemplates(): array
    {
        return [
            [
                'name' => 'LAMP Server',
                'description' => 'Apache, MySQL, PHP on Ubuntu 24.04 — ready-to-use web server stack',
                'base_image' => 'ubuntu-24.04',
                'icon' => 'bi-stack',
                'color' => '#E95420',
                'cores' => 2, 'memory' => 2048, 'disk_size' => 20,
                'packages' => implode("\n", [
                    'apache2', 'mysql-server', 'php', 'libapache2-mod-php',
                    'php-mysql', 'php-curl', 'php-gd', 'php-mbstring', 'php-xml', 'php-zip',
                ]),
                'runcmd' => implode("\n", [
                    'systemctl enable apache2',
                    'systemctl enable mysql',
                    'systemctl start apache2',
                    'systemctl start mysql',
                    'ufw allow in "Apache Full"',
                    'mysql -e "ALTER USER \'root\'@\'localhost\' IDENTIFIED WITH mysql_native_password BY \'changeme\'; FLUSH PRIVILEGES;"',
                    'echo "<?php phpinfo(); ?>" > /var/www/html/info.php',
                    'systemctl restart apache2',
                ]),
                'tags' => 'lamp;webserver',
            ],
            [
                'name' => 'Nextcloud Server',
                'description' => 'Nextcloud with Apache, MariaDB & PHP 8.2 on Debian 12 — private cloud storage',
                'base_image' => 'debian-12',
                'icon' => 'bi-cloud',
                'color' => '#0082c9',
                'cores' => 2, 'memory' => 4096, 'disk_size' => 50,
                'packages' => implode("\n", [
                    'apache2', 'mariadb-server',
                    'php', 'php-gd', 'php-mysql', 'php-curl', 'php-mbstring',
                    'php-intl', 'php-gmp', 'php-bcmath', 'php-xml', 'php-zip',
                    'php-imagick', 'php-apcu', 'php-redis', 'php-bz2',
                    'libapache2-mod-php',
                    'redis-server', 'unzip', 'curl', 'sudo',
                ]),
                'runcmd' => implode("\n", [
                    'systemctl enable apache2 mariadb redis-server',
                    'systemctl start apache2 mariadb redis-server',
                    // MariaDB: create nextcloud database and user
                    'mysql -e "CREATE DATABASE IF NOT EXISTS nextcloud CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"',
                    'mysql -e "CREATE USER IF NOT EXISTS \'nextcloud\'@\'localhost\' IDENTIFIED BY \'changeme\';"',
                    'mysql -e "GRANT ALL PRIVILEGES ON nextcloud.* TO \'nextcloud\'@\'localhost\'; FLUSH PRIVILEGES;"',
                    // Download and install Nextcloud
                    'curl -sL https://download.nextcloud.com/server/releases/latest.zip -o /tmp/nextcloud.zip',
                    'unzip -q /tmp/nextcloud.zip -d /var/www/',
                    'chown -R www-data:www-data /var/www/nextcloud',
                    'rm /tmp/nextcloud.zip',
                    // Create data directory
                    'mkdir -p /var/www/nextcloud/data',
                    'chown www-data:www-data /var/www/nextcloud/data',
                    // Apache config
                    'cat > /etc/apache2/sites-available/nextcloud.conf << \'APACHEEOF\'
<VirtualHost *:80>
    DocumentRoot /var/www/nextcloud
    <Directory /var/www/nextcloud>
        Require all granted
        AllowOverride All
        Options FollowSymLinks MultiViews
        <IfModule mod_dav.c>
            Dav off
        </IfModule>
    </Directory>
</VirtualHost>
APACHEEOF',
                    'a2ensite nextcloud.conf',
                    'a2dissite 000-default.conf',
                    'a2enmod rewrite headers env dir mime',
                    // PHP tuning
                    'sed -i "s/memory_limit = .*/memory_limit = 512M/" /etc/php/8.2/apache2/php.ini',
                    'sed -i "s/upload_max_filesize = .*/upload_max_filesize = 1024M/" /etc/php/8.2/apache2/php.ini',
                    'sed -i "s/post_max_size = .*/post_max_size = 1024M/" /etc/php/8.2/apache2/php.ini',
                    'sed -i "s/max_execution_time = .*/max_execution_time = 300/" /etc/php/8.2/apache2/php.ini',
                    // Restart Apache
                    'systemctl restart apache2',
                    // Run Nextcloud CLI installer
                    'sudo -u www-data php /var/www/nextcloud/occ maintenance:install --database "mysql" --database-name "nextcloud" --database-user "nextcloud" --database-pass "changeme" --admin-user "admin" --admin-pass "changeme"',
                    // Set trusted domain to all (user should restrict later)
                    'sudo -u www-data php /var/www/nextcloud/occ config:system:set trusted_domains 1 --value="*"',
                    // Enable Redis memcache
                    'sudo -u www-data php /var/www/nextcloud/occ config:system:set memcache.local --value="\\OC\\Memcache\\APCu"',
                    'sudo -u www-data php /var/www/nextcloud/occ config:system:set memcache.locking --value="\\OC\\Memcache\\Redis"',
                    'sudo -u www-data php /var/www/nextcloud/occ config:system:set redis host --value="localhost"',
                    'sudo -u www-data php /var/www/nextcloud/occ config:system:set redis port --value="6379" --type=integer',
                ]),
                'tags' => 'nextcloud;cloud;storage',
            ],
        ];
    }
}
