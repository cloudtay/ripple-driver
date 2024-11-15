<?php
/**
 * Copyright © 2024 cclilshy
 * Email: jingnigg@gmail.com
 *
 * This software is licensed under the MIT License.
 * For full license details, please visit: https://opensource.org/licenses/MIT
 *
 * By using this software, you agree to the terms of the license.
 * Contributions, suggestions, and feedback are always welcome!
 */

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()->in(__DIR__)
    ->name('*.php')
    ->notName('*.blade.php');

$config = new Config();

$config->setFinder($finder);
$config->setRiskyAllowed(true);

return $config->setRules([
    '@PSR12'                       => true,
    'native_function_invocation'   => [
        'include' => ['@all'],
        'scope'   => 'all',
        'strict'  => true,
    ],
    'native_constant_invocation'   => [
        'include' => ['@all'],
        'scope'   => 'all',
        'strict'  => true,
    ],
    'global_namespace_import'      => [
        'import_classes'   => true,
        'import_constants' => true,
        'import_functions' => true,
    ],
    'declare_strict_types'         => true,
    'linebreak_after_opening_tag'  => false,
    'blank_line_after_opening_tag' => false,
    'no_unused_imports'            => true,
]);
