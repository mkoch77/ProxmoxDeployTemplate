#!/usr/bin/env php
<?php
/**
 * Creates the first admin user.
 * Usage: php cli/seed-admin.php <username> <password>
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\Migrator;
use App\UserManager;

// Run migrations
Migrator::run();

if ($argc < 3) {
    echo "Usage: php cli/seed-admin.php <username> <password>\n";
    exit(1);
}

$username = $argv[1];
$password = $argv[2];

// Check if user already exists
$existing = UserManager::getByUsername($username);
if ($existing) {
    echo "User '{$username}' already exists.\n";
    exit(1);
}

// Create user
$userId = UserManager::create([
    'username' => $username,
    'display_name' => $username,
    'password' => $password,
]);

// Assign admin role
$db = Database::connection();
$roleId = $db->query("SELECT id FROM roles WHERE name = 'admin'")->fetchColumn();
if ($roleId) {
    UserManager::assignRole($userId, (int) $roleId);
    echo "Admin user '{$username}' created successfully.\n";
} else {
    echo "User created, but admin role not found. Please check migrations.\n";
    exit(1);
}
