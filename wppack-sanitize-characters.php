<?php

/*
 * This file is part of the WPPack package.
 *
 * (c) Tsuyoshi Tsurushima
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Plugin Name: WPPack Sanitize Characters
 * Description: Sanitizes invisible and control characters (zero-width spaces, BOM, bidi controls, C0/C1 controls, soft hyphens) out of posts, ACF fields and search terms, so copy-pasted text always matches. Includes a WP-CLI command to clean existing data.
 * Version: 1.0.0
 * Requires PHP: 8.2
 * Requires at least: 6.7
 * Author: WPPack
 * License: MIT
 * Text Domain: wppack-sanitize-characters
 */

namespace WPPack\Plugin\SanitizeCharactersPlugin;

if (!defined('ABSPATH')) {
    exit;
}

// Composer installs resolve the classes through the site autoloader (see the
// PSR-4 mapping in composer.json); plain plugin installs load them here.
if (!class_exists(SanitizeCharactersPlugin::class)) {
    require __DIR__ . '/src/SanitizeCharactersPlugin.php';
    require __DIR__ . '/src/DatabaseCleaner.php';
    require __DIR__ . '/src/CleanCommand.php';
}

SanitizeCharactersPlugin::boot();
