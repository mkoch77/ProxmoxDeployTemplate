<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Config;

Bootstrap::init();
Request::requireMethod('GET');
Auth::requireAuth();

// All available cloud images grouped by distro family
$allImages = [
    'ubuntu' => [
        'ubuntu-24.04'      => ['name' => 'Ubuntu 24.04 LTS',   'subtitle' => 'Noble Numbat',    'color' => '#E95420', 'default_user' => 'ubuntu'],
        'ubuntu-22.04'      => ['name' => 'Ubuntu 22.04 LTS',   'subtitle' => 'Jammy Jellyfish', 'color' => '#E95420', 'default_user' => 'ubuntu'],
        'ubuntu-20.04'      => ['name' => 'Ubuntu 20.04 LTS',   'subtitle' => 'Focal Fossa',     'color' => '#E95420', 'default_user' => 'ubuntu'],
    ],
    'debian' => [
        'debian-12'         => ['name' => 'Debian 12',          'subtitle' => 'Bookworm',        'color' => '#D70A53', 'default_user' => 'debian'],
        'debian-11'         => ['name' => 'Debian 11',          'subtitle' => 'Bullseye',        'color' => '#D70A53', 'default_user' => 'debian'],
    ],
    'rocky' => [
        'rocky-9'           => ['name' => 'Rocky Linux 9',      'subtitle' => 'GenericCloud',    'color' => '#10B981', 'default_user' => 'rocky'],
    ],
    'alma' => [
        'almalinux-9'       => ['name' => 'AlmaLinux 9',        'subtitle' => 'GenericCloud',    'color' => '#1D6FA4', 'default_user' => 'almalinux'],
    ],
    'centos' => [
        'centos-stream-9'   => ['name' => 'CentOS Stream 9',    'subtitle' => 'GenericCloud',    'color' => '#9CDD05', 'default_user' => 'cloud-user'],
    ],
    'fedora' => [
        'fedora-41'         => ['name' => 'Fedora 41',          'subtitle' => 'Cloud Base',      'color' => '#51A2DA', 'default_user' => 'fedora'],
    ],
    'opensuse' => [
        'opensuse-leap-15.6' => ['name' => 'openSUSE Leap 15.6', 'subtitle' => 'Minimal Cloud', 'color' => '#73BA25', 'default_user' => 'opensuse'],
    ],
    'arch' => [
        'arch-linux'        => ['name' => 'Arch Linux',         'subtitle' => 'Rolling (latest)', 'color' => '#1793D1', 'default_user' => 'arch'],
    ],
];

// Filter by enabled distro families
$enabledDistros = array_filter(array_map('trim', explode(',', Config::get('CLOUD_DISTROS', 'ubuntu,debian,rocky,alma,centos,fedora,opensuse,arch'))));

$images = [];
foreach ($enabledDistros as $distro) {
    if (isset($allImages[$distro])) {
        foreach ($allImages[$distro] as $id => $img) {
            $images[$id] = $img;
        }
    }
}

Response::success(['images' => $images, 'enabled_distros' => $enabledDistros]);
