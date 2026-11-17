<?php

declare(strict_types=1);

namespace DynaPulse\Http;

/**
 * Small autowiring container. Controllers and services receive dependencies by
 * type-hint; registered instances win, otherwise the class is constructed once
 * and reused for the request.
 */
final class Container
{
    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, callable(self):mixed> */
    private array $factories = [];

    public function set(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    /** @param callable(self):mixed $factory */
    public function factory(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    public function get(string $id): mixed
    {
        // Anything that type-hints the container itself receives this instance.
        if ($id === self::class) {
            return $this;
        }

        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }

        if (!class_exists($id)) {
            throw new \RuntimeException('Container cannot resolve ' . $id);
        }

        $reflection = new \ReflectionClass($id);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $this->instances[$id] = $reflection->newInstance();
        }

        if (!$constructor->isPublic()) {
            throw new \RuntimeException('Cannot instantiate ' . $id . ': constructor is not public');
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            throw new \RuntimeException(sprintf(
                'Cannot resolve parameter $%s of %s::__construct()',
                $parameter->getName(),
                $id,
            ));
        }

        return $this->instances[$id] = $reflection->newInstanceArgs($arguments);
    }
}
