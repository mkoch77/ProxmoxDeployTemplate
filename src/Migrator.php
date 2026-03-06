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
            applied_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');

        $applied = $db->query('SELECT version FROM migrations')
            ->fetchAll(PDO::FETCH_COLUMN);

        $migrations = self::getMigrations();
        foreach ($migrations as $version => $sql) {
            if (!in_array($version, $applied, true)) {
                $db->exec($sql);
                $stmt = $db->prepare('INSERT INTO migrations (version) VALUES (?)');
                $stmt->execute([$version]);
            }
        }
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
        ];
    }

    private static function migration001(): string
    {
        return "
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                display_name TEXT NOT NULL DEFAULT '',
                email TEXT DEFAULT '',
                password_hash TEXT DEFAULT NULL,
                auth_provider TEXT NOT NULL DEFAULT 'local',
                entraid_oid TEXT DEFAULT NULL UNIQUE,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT DEFAULT ''
            );

            CREATE TABLE IF NOT EXISTS permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key TEXT NOT NULL UNIQUE,
                description TEXT DEFAULT ''
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
                id TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                ip_address TEXT,
                user_agent TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                expires_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            INSERT OR IGNORE INTO roles (name, description) VALUES
                ('admin', 'Full access'),
                ('operator', 'VM management and deployment'),
                ('viewer', 'Read only');

            INSERT OR IGNORE INTO permissions (key, description) VALUES
                ('vm.start', 'Start VMs/CTs'),
                ('vm.stop', 'Stop VMs/CTs'),
                ('vm.reboot', 'Reboot VMs/CTs'),
                ('vm.shutdown', 'Shut down VMs/CTs'),
                ('template.deploy', 'Deploy templates'),
                ('cluster.health.view', 'View cluster health'),
                ('cluster.maintenance', 'Manage maintenance mode'),
                ('users.manage', 'Manage users');

            INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = 'admin';

            INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'operator' AND p.key IN (
                    'vm.start','vm.stop','vm.reboot','vm.shutdown',
                    'template.deploy','cluster.health.view'
                );

            INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'viewer' AND p.key = 'cluster.health.view';
        ";
    }

    private static function migration002(): string
    {
        return "
            CREATE TABLE IF NOT EXISTS maintenance_nodes (
                node_name TEXT PRIMARY KEY,
                status TEXT NOT NULL DEFAULT 'entering',
                started_by INTEGER,
                started_at TEXT DEFAULT CURRENT_TIMESTAMP,
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
                automation_level TEXT NOT NULL DEFAULT 'manual',
                cpu_weight INTEGER NOT NULL DEFAULT 50,
                ram_weight INTEGER NOT NULL DEFAULT 50,
                threshold INTEGER NOT NULL DEFAULT 3,
                interval_minutes INTEGER NOT NULL DEFAULT 5,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            INSERT OR IGNORE INTO drs_settings (id) VALUES (1);

            CREATE TABLE IF NOT EXISTS drs_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                triggered_by TEXT NOT NULL DEFAULT 'cron',
                node_count INTEGER NOT NULL DEFAULT 0,
                cluster_avg_score REAL NOT NULL DEFAULT 0,
                recommendations_count INTEGER NOT NULL DEFAULT 0,
                executed_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS drs_recommendations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id INTEGER NOT NULL,
                vmid INTEGER NOT NULL,
                vm_name TEXT NOT NULL DEFAULT '',
                vm_type TEXT NOT NULL DEFAULT 'qemu',
                source_node TEXT NOT NULL,
                target_node TEXT NOT NULL,
                reason TEXT NOT NULL DEFAULT '',
                impact_score REAL NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT 'pending',
                upid TEXT DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                applied_at TEXT DEFAULT NULL,
                FOREIGN KEY (run_id) REFERENCES drs_runs(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_drs_recommendations_run ON drs_recommendations(run_id);
            CREATE INDEX IF NOT EXISTS idx_drs_runs_created ON drs_runs(created_at);

            INSERT OR IGNORE INTO permissions (key, description) VALUES
                ('drs.view', 'View loadbalancer recommendations'),
                ('drs.manage', 'Manage loadbalancer settings');

            INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'admin' AND p.key IN ('drs.view', 'drs.manage');

            INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'operator' AND p.key = 'drs.view';
        ";
    }

    private static function migration004(): string
    {
        return "
            ALTER TABLE users ADD COLUMN theme TEXT NOT NULL DEFAULT 'auto';
        ";
    }

    private static function migration005(): string
    {
        return "
            ALTER TABLE drs_settings ADD COLUMN max_concurrent INTEGER NOT NULL DEFAULT 3;
        ";
    }

    private static function migration006(): string
    {
        return "
            INSERT OR IGNORE INTO permissions (key, description) VALUES
                ('vm.migrate', 'Migrate VMs/CTs between nodes');

            INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'admin' AND p.key = 'vm.migrate';

            INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'operator' AND p.key = 'vm.migrate';
        ";
    }

    private static function migration007(): string
    {
        return "
            ALTER TABLE drs_runs ADD COLUMN skipped_reasons TEXT DEFAULT NULL;
        ";
    }

    private static function migration008(): string
    {
        return "
            INSERT OR IGNORE INTO permissions (key, description) VALUES
                ('vm.delete', 'Delete VMs/CTs');

            INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'admin' AND p.key = 'vm.delete';
        ";
    }

    private static function migration009(): string
    {
        return "
            INSERT OR IGNORE INTO permissions (key, description) VALUES
                ('cluster.ha', 'Manage HA resources');

            INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'admin' AND p.key = 'cluster.ha';

            INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'operator' AND p.key = 'cluster.ha';
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

            INSERT OR IGNORE INTO permissions (key, description) VALUES
                ('community.install', 'Install community scripts via SSH on nodes');

            INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'admin' AND p.key = 'community.install';
        ";
    }

    private static function migration010(): string
    {
        return "
            CREATE TABLE IF NOT EXISTS rolling_update_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nodes TEXT NOT NULL,
                node_statuses TEXT NOT NULL DEFAULT '{}',
                status TEXT NOT NULL DEFAULT 'running',
                started_by INTEGER,
                started_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (started_by) REFERENCES users(id)
            );

            INSERT OR IGNORE INTO permissions (key, description) VALUES
                ('cluster.update', 'Run rolling node updates');

            INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r, permissions p
                WHERE r.name = 'admin' AND p.key = 'cluster.update';
        ";
    }
}
