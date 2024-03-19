<?php

declare(strict_types=1);

namespace AutoMapper\Metadata;

use Symfony\Component\PropertyInfo\Type;

/**
 * @internal
 *
 * @extends \SplObjectStorage<Type, Type[]>
 */
final class TypesMatching extends \SplObjectStorage
{
    public function getSourceUniqueType(): ?Type
    {
        if (0 === \count($this) || \count($this) > 1) {
            return null;
        }

        // Get first type from the SplObjectStorage
        $this->rewind();
        $sourceType = $this->current();

        if (!$sourceType instanceof Type) {
            return null;
        }

        return $sourceType;
    }

    public function getTargetUniqueType(Type $sourceType): ?Type
    {
        $targetTypes = $this[$sourceType] ?? [];

        if (0 === \count($targetTypes) || \count($targetTypes) > 1 || !$targetTypes[0] instanceof Type) {
            return null;
        }

        return $targetTypes[0];
    }

    /**
     * @param Type[] $sourceTypes
     * @param Type[] $targetTypes
     */
    public static function fromSourceAndTargetTypes(array $sourceTypes, array $targetTypes): self
    {
        $types = new self();

        foreach ($sourceTypes as $sourceType) {
            $types[$sourceType] = $targetTypes;
        }

        return $types;
    }
}
