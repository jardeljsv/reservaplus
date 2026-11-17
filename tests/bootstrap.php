<?php

declare(strict_types=1);

/**
 * Standalone unit-test helpers. This is not a substitute for GLPI integration.
 * Set GLPI_ROOT to load a disposable GLPI installation instead of declarations.
 * Never point integration tests at a production installation or database.
 */
$glpiRoot = getenv('GLPI_ROOT');
if (is_string($glpiRoot) && $glpiRoot !== '') {
    if (!is_file($glpiRoot . '/vendor/autoload.php')) {
        throw new RuntimeException('GLPI_ROOT does not contain a GLPI installation.');
    }
    require_once $glpiRoot . '/vendor/autoload.php';
    $kernel = new \Glpi\Kernel\Kernel();
    $kernel->boot();
    \Plugin::load('reservaplus');
} else {
    foreach (['READ' => 1, 'UPDATE' => 2, 'CREATE' => 4, 'DELETE' => 8, 'PURGE' => 16] as $name => $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }
    if (!function_exists('__')) {
        function __(string $message, ?string $domain = null): string { return $message; }
    }
    if (!function_exists('_n')) {
        function _n(string $one, string $many, int $number, ?string $domain = null): string { return $number === 1 ? $one : $many; }
    }
    // Declaration-only parent, deliberately without database/mutation behavior.
    if (!class_exists('CommonDBTM', false)) {
        class CommonDBTM
        {
            public array $fields = [];
            public array $input = [];
            public function getID(): int { return (int) ($this->fields['id'] ?? 0); }
        }
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Reservaplus\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (str_contains($relative, '..')) {
        return;
    }
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

function rp_assert(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rp_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($message !== '' ? $message . ': ' : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function rp_throws(callable $operation, string $class = Throwable::class, string $contains = ''): Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        if (!$error instanceof $class) {
            throw new RuntimeException('Expected ' . $class . ', got ' . get_class($error), 0, $error);
        }
        if ($contains !== '' && !str_contains($error->getMessage(), $contains)) {
            throw new RuntimeException('Exception did not contain expected text: ' . $contains, 0, $error);
        }
        return $error;
    }
    throw new RuntimeException('Expected exception ' . $class . ' was not thrown.');
}

function rp_test(string $name, callable $operation): void
{
    try {
        $operation();
        fwrite(STDOUT, 'PASS ' . $name . PHP_EOL);
    } catch (Throwable $error) {
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL);
        throw $error;
    }
}
