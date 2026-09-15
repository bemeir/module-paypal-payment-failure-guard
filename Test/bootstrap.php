<?php

declare(strict_types=1);

// Reuse installed Magento classes without running application bootstrap, Composer autoload
// scripts, module registration, or connecting to a database. Supports a standalone module checkout.
$vendor = getenv('MAGENTO_VENDOR_PATH') ?: dirname(__DIR__) . '/vendor';
$vendor = realpath($vendor);
if ($vendor === false || !is_file($vendor . '/composer/ClassLoader.php')) {
    throw new RuntimeException('Set MAGENTO_VENDOR_PATH to a Magento vendor directory with dev dependencies.');
}
if (!class_exists(Composer\Autoload\ClassLoader::class, false)) {
    require $vendor . '/composer/ClassLoader.php';
}
$loader = new Composer\Autoload\ClassLoader($vendor);
foreach (require $vendor . '/composer/autoload_psr4.php' as $prefix => $paths) {
    $loader->addPsr4($prefix, $paths);
}
foreach (require $vendor . '/composer/autoload_namespaces.php' as $prefix => $paths) {
    $loader->add($prefix, $paths);
}
$classMap = require $vendor . '/composer/autoload_classmap.php';
foreach (array_keys($classMap) as $class) {
    // Always test this checkout, even when an older module is installed in the host project.
    if (str_starts_with($class, 'Magetu\\PaypalPaymentFailureGuard\\')) {
        unset($classMap[$class]);
    }
}
$loader->addClassMap($classMap);
$loader->setPsr4('Magetu\\PaypalPaymentFailureGuard\\', dirname(__DIR__));
$loader->register(true);
if (!function_exists('__')) {
    require $vendor . '/magento/framework/Phrase/__.php';
}
