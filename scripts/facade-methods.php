<?php

declare(strict_types=1);
use Puntjes\Generated\PuntjesClient;

require __DIR__.'/../vendor/autoload.php';

[, $class, $file] = $argv + [null, PuntjesClient::class, __DIR__.'/../src/Facades/Puntjes.php'];

$client = new ReflectionClass($class);
$methods = array_filter(
    $client->getMethods(ReflectionMethod::IS_PUBLIC),
    static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $client->getName()
        && ! $method->isConstructor(),
);
usort($methods, static fn (ReflectionMethod $a, ReflectionMethod $b): int => strcmp($a->getName(), $b->getName()));

$render = static function (?ReflectionType $type) use (&$render): string {
    if ($type === null) {
        return 'mixed';
    }
    if ($type instanceof ReflectionNamedType) {
        $name = $type->isBuiltin() ? $type->getName() : '\\'.$type->getName();

        return ($type->allowsNull() && $type->getName() !== 'mixed' && $type->getName() !== 'null' ? '?' : '').$name;
    }
    $separator = $type instanceof ReflectionIntersectionType ? '&' : '|';

    return implode($separator, array_map($render, $type->getTypes()));
};

$lines = '';
foreach ($methods as $method) {
    $parameters = implode(', ', array_map(
        static fn (ReflectionParameter $parameter): string => $render($parameter->getType()).' $'.$parameter->getName(),
        $method->getParameters(),
    ));
    $lines .= " * @method static {$render($method->getReturnType())} {$method->getName()}({$parameters})\n";
}

$source = (string) file_get_contents($file);
$updated = preg_replace('/(?: \* @method static [^\n]*\n)+/', $lines, $source, 1, $count);

if ($count !== 1) {
    fwrite(STDERR, "{$file} has no @method static lines to replace.\n");
    exit(1);
}

file_put_contents($file, $updated);
