<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Billing;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use SplFileInfo;

/**
 * Money must never pass through a float (D-03): no public Billing signature may
 * declare `float`, fall back to `mixed`, or leave a type out.
 */
final class BillingTypesDeclareNoFloatsTest extends TestCase
{
    public function test_billing_public_signatures_declare_no_float_mixed_or_missing_types(): void
    {
        $classes = $this->billingClasses();

        $this->assertNotEmpty($classes, 'No classes found under app/Domain/Billing.');

        $violations = [];

        foreach ($classes as $class) {
            array_push($violations, ...$this->violationsIn($class));
        }

        $this->assertSame([], $violations);
    }

    public function test_the_guard_reports_float_mixed_and_missing_types(): void
    {
        $offender = new class
        {
            public float $rate = 0.0;

            /** @phpstan-ignore missingType.parameter, return.unusedType */
            public function scaled(float $factor, mixed $context, $untyped): int|float
            {
                return 0;
            }

            /** @phpstan-ignore missingType.return */
            public function untypedReturn()
            {
                return null;
            }
        };

        $violations = $this->violationsIn(new ReflectionClass($offender));

        $this->assertCount(6, $violations);
    }

    /**
     * @return list<ReflectionClass>
     */
    private function billingClasses(): array
    {
        $root = dirname(__DIR__, 4) . '/app/Domain/Billing';
        $classes = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($root) + 1, -strlen('.php'));
            $className = 'App\\Domain\\Billing\\' . str_replace('/', '\\', $relativePath);

            if (! class_exists($className)) {
                $this->fail(sprintf('%s does not declare %s.', $file->getPathname(), $className));
            }

            $classes[] = new ReflectionClass($className);
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    private function violationsIn(ReflectionClass $class): array
    {
        $violations = [];

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $signature = $class->getName() . '::' . $method->getName() . '()';

            foreach ($method->getParameters() as $parameter) {
                if ($this->isForbidden($parameter->getType())) {
                    $violations[] = sprintf('%s parameter $%s', $signature, $parameter->getName());
                }
            }

            if (! $method->isConstructor() && $this->isForbidden($method->getReturnType())) {
                $violations[] = sprintf('%s return type', $signature);
            }
        }

        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            if ($this->isForbidden($property->getType())) {
                $violations[] = sprintf('%s::$%s property type', $class->getName(), $property->getName());
            }
        }

        return $violations;
    }

    private function isForbidden(?ReflectionType $type): bool
    {
        if ($type === null) {
            return true;
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if ($this->isForbidden($member)) {
                    return true;
                }
            }

            return false;
        }

        return $type instanceof ReflectionNamedType && in_array($type->getName(), ['float', 'mixed'], true);
    }
}
