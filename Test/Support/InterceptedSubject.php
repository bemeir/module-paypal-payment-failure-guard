<?php

declare(strict_types=1);

namespace Magetu\PaypalPaymentFailureGuard\Test\Support;

use Magento\Framework\Code\Generator\Io;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Interception\Code\Generator\Interceptor;
use Magento\Framework\Interception\PluginListInterface;

/** Generate and exercise Magento's interception code without booting its application. */
final class InterceptedSubject
{
    public static function wrap(object $subject, PluginListInterface $plugins): object
    {
        $type = get_class($subject);
        $class = $type . '\\Interceptor';
        if (!class_exists($class, false)) {
            $directory = sys_get_temp_dir() . '/magetu-guard-' . bin2hex(random_bytes(8));
            $driver = new File();
            try {
                $generator = new Interceptor($type, $class, new Io($driver, $directory));
                $file = $generator->generate();
                if ($file === false) {
                    throw new \RuntimeException(implode('; ', $generator->getErrors()));
                }
                require $file;
            } finally {
                if (is_dir($directory)) {
                    $driver->deleteDirectory($directory);
                }
            }
        }

        // Reuse the subject's mocked boundary dependencies. Bypass only the generated constructor's
        // global ObjectManager lookup, then execute the generated methods and real interceptor trait.
        $intercepted = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $reflection = new \ReflectionObject($subject);
        do {
            foreach ($reflection->getProperties() as $property) {
                if (!$property->isStatic() && $property->isInitialized($subject)) {
                    $property->setValue($intercepted, $property->getValue($subject));
                }
            }
            $reflection = $reflection->getParentClass();
        } while ($reflection !== false);
        (new \ReflectionProperty($class, 'pluginList'))->setValue($intercepted, $plugins);
        (new \ReflectionProperty($class, 'subjectType'))->setValue($intercepted, $type);
        return $intercepted;
    }
}
