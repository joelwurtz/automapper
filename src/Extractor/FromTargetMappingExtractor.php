<?php

declare(strict_types=1);

namespace AutoMapper\Extractor;

use AutoMapper\Exception\InvalidMappingException;
use AutoMapper\MapperGeneratorMetadataInterface;
use AutoMapper\Transformer\TransformerFactoryInterface;
use AutoMapper\Transformer\TransformerPropertyFactoryInterface;
use Symfony\Component\PropertyInfo\PropertyInfoExtractorInterface;
use Symfony\Component\PropertyInfo\PropertyReadInfo;
use Symfony\Component\PropertyInfo\PropertyReadInfoExtractorInterface;
use Symfony\Component\PropertyInfo\PropertyWriteInfo;
use Symfony\Component\PropertyInfo\PropertyWriteInfoExtractorInterface;
use Symfony\Component\PropertyInfo\Type;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\NameConverter\AdvancedNameConverterInterface;

/**
 * Mapping extracted only from target, useful when not having metadata on the source for dynamic data like array, \stdClass, ...
 *
 * Can use a NameConverter to use specific properties name in the source
 *
 * @author Joel Wurtz <jwurtz@jolicode.com>
 *
 * @internal
 */
final class FromTargetMappingExtractor extends MappingExtractor
{
    private const ALLOWED_SOURCES = ['array', \stdClass::class];

    public function __construct(
        PropertyInfoExtractorInterface $propertyInfoExtractor,
        PropertyReadInfoExtractorInterface $readInfoExtractor,
        PropertyWriteInfoExtractorInterface $writeInfoExtractor,
        TransformerFactoryInterface|TransformerPropertyFactoryInterface $transformerFactory,
        ClassMetadataFactoryInterface $classMetadataFactory = null,
        private readonly ?AdvancedNameConverterInterface $nameConverter = null,
    ) {
        parent::__construct($propertyInfoExtractor, $readInfoExtractor, $writeInfoExtractor, $transformerFactory, $classMetadataFactory);
    }

    public function getPropertiesMapping(MapperGeneratorMetadataInterface $mapperMetadata): array
    {
        $targetProperties = array_unique($this->propertyInfoExtractor->getProperties($mapperMetadata->getTarget()) ?? []);

        if (!\in_array($mapperMetadata->getSource(), self::ALLOWED_SOURCES, true)) {
            throw new InvalidMappingException('Only array or stdClass are accepted as a source');
        }

        $mapping = [];
        foreach ($targetProperties as $property) {
            if (!$this->isWritable($mapperMetadata->getTarget(), $property)) {
                continue;
            }

            $targetTypes = $this->propertyInfoExtractor->getTypes($mapperMetadata->getTarget(), $property);

            if (null === $targetTypes) {
                continue;
            }

            $sourceTypes = [];

            foreach ($targetTypes as $type) {
                $sourceType = $this->transformType($mapperMetadata->getSource(), $type);

                if ($sourceType) {
                    $sourceTypes[] = $sourceType;
                }
            }

            if ($this->transformerFactory instanceof TransformerPropertyFactoryInterface) {
                $transformer = $this->transformerFactory->getPropertyTransformer($sourceTypes, $targetTypes, $mapperMetadata, $property);
            } else {
                $transformer = $this->transformerFactory->getTransformer($sourceTypes, $targetTypes, $mapperMetadata);
            }

            if (null === $transformer) {
                continue;
            }

            $mapping[] = new PropertyMapping(
                $mapperMetadata,
                $this->getReadAccessor($mapperMetadata->getSource(), $mapperMetadata->getTarget(), $property),
                $this->getWriteMutator($mapperMetadata->getSource(), $mapperMetadata->getTarget(), $property, [
                    'enable_constructor_extraction' => false,
                ]),
                $this->getWriteMutator($mapperMetadata->getSource(), $mapperMetadata->getTarget(), $property, [
                    'enable_constructor_extraction' => true,
                ]),
                $transformer,
                $property,
                true,
                $this->getGroups($mapperMetadata->getSource(), $property),
                $this->getGroups($mapperMetadata->getTarget(), $property),
                $this->getMaxDepth($mapperMetadata->getTarget(), $property),
                $this->isIgnoredProperty($mapperMetadata->getSource(), $property),
                $this->isIgnoredProperty($mapperMetadata->getTarget(), $property),
                PropertyReadInfo::VISIBILITY_PUBLIC === ($this->readInfoExtractor->getReadInfo($mapperMetadata->getSource(), $property)?->getVisibility() ?? PropertyReadInfo::VISIBILITY_PUBLIC),
            );
        }

        return $mapping;
    }

    public function getReadAccessor(string $source, string $target, string $property): ?ReadAccessor
    {
        if (null !== $this->nameConverter) {
            $property = $this->nameConverter->normalize($property, $target, $source);
        }

        $sourceAccessor = new ReadAccessor(ReadAccessor::TYPE_ARRAY_DIMENSION, $property);

        if (\stdClass::class === $source) {
            $sourceAccessor = new ReadAccessor(ReadAccessor::TYPE_PROPERTY, $property);
        }

        return $sourceAccessor;
    }

    private function transformType(string $source, ?Type $type = null): ?Type
    {
        if (null === $type) {
            return null;
        }

        $builtinType = $type->getBuiltinType();
        $className = $type->getClassName();

        if (Type::BUILTIN_TYPE_OBJECT === $type->getBuiltinType() && \stdClass::class !== $type->getClassName()) {
            $builtinType = 'array' === $source ? Type::BUILTIN_TYPE_ARRAY : Type::BUILTIN_TYPE_OBJECT;
            $className = 'array' === $source ? null : \stdClass::class;
        }

        if (Type::BUILTIN_TYPE_OBJECT === $type->getBuiltinType() && (\DateTimeInterface::class === $type->getClassName() || is_subclass_of($type->getClassName(), \DateTimeInterface::class))) {
            $builtinType = 'string';
        }

        $collectionKeyTypes = $type->getCollectionKeyTypes();
        $collectionValueTypes = $type->getCollectionValueTypes();

        return new Type(
            $builtinType,
            $type->isNullable(),
            $className,
            $type->isCollection(),
            $this->transformType($source, $collectionKeyTypes[0] ?? null),
            $this->transformType($source, $collectionValueTypes[0] ?? null)
        );
    }

    /**
     * PropertyInfoExtractor::isWritable() is not enough: we want to know if the property is readonly and writable from the constructor.
     */
    private function isWritable(string $target, string $property): bool
    {
        if ($this->propertyInfoExtractor->isWritable($target, $property)) {
            return true;
        }

        if (\PHP_VERSION_ID < 80100) {
            return false;
        }

        try {
            $reflectionProperty = new \ReflectionProperty($target, $property);
        } catch (\ReflectionException $e) {
            // the property does not exist
            return false;
        }

        if (!$reflectionProperty->isReadOnly()) {
            return false;
        }

        $writeInfo = $this->writeInfoExtractor->getWriteInfo($target, $property, ['enable_constructor_extraction' => true]);
        if (null === $writeInfo || $writeInfo->getType() !== PropertyWriteInfo::TYPE_CONSTRUCTOR) {
            return false;
        }

        return true;
    }
}
