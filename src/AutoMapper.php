<?php

declare(strict_types=1);

namespace AutoMapper;

use AutoMapper\Exception\NoMappingFoundException;
use AutoMapper\Extractor\FromSourceMappingExtractor;
use AutoMapper\Extractor\FromTargetMappingExtractor;
use AutoMapper\Extractor\MapToContextPropertyInfoExtractorDecorator;
use AutoMapper\Extractor\SourceTargetMappingExtractor;
use AutoMapper\Generator\MapperGenerator;
use AutoMapper\Generator\Shared\ClassDiscriminatorResolver;
use AutoMapper\Loader\ClassLoaderInterface;
use AutoMapper\Loader\EvalLoader;
use AutoMapper\Transformer\ArrayTransformerFactory;
use AutoMapper\Transformer\BuiltinTransformerFactory;
use AutoMapper\Transformer\ChainTransformerFactory;
use AutoMapper\Transformer\CustomTransformer\CustomTransformerFactory;
use AutoMapper\Transformer\CustomTransformer\CustomTransformerInterface;
use AutoMapper\Transformer\CustomTransformer\CustomTransformersRegistry;
use AutoMapper\Transformer\DateTimeTransformerFactory;
use AutoMapper\Transformer\EnumTransformerFactory;
use AutoMapper\Transformer\MultipleTransformerFactory;
use AutoMapper\Transformer\NullableTransformerFactory;
use AutoMapper\Transformer\ObjectTransformerFactory;
use AutoMapper\Transformer\SymfonyUidTransformerFactory;
use AutoMapper\Transformer\TransformerFactoryInterface;
use AutoMapper\Transformer\UniqueTypeTransformerFactory;
use Doctrine\Common\Annotations\AnnotationReader;
use Symfony\Component\PropertyInfo\Extractor\PhpStanExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorFromClassMetadata;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AnnotationLoader;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\AdvancedNameConverterInterface;
use Symfony\Component\Uid\AbstractUid;

/**
 * Maps a source data structure (object or array) to a target one.
 *
 * @author Joel Wurtz <jwurtz@jolicode.com>
 */
class AutoMapper implements AutoMapperInterface, AutoMapperRegistryInterface, MapperGeneratorMetadataRegistryInterface
{
    public const VERSION = '8.3.0-DEV';
    public const VERSION_ID = 80300;
    public const MAJOR_VERSION = 8;
    public const MINOR_VERSION = 3;
    public const RELEASE_VERSION = 0;
    public const EXTRA_VERSION = 'DEV';

    /** @var MapperGeneratorMetadataInterface[] */
    private array $metadata = [];

    /** @var GeneratedMapper[] */
    private array $mapperRegistry = [];

    public function __construct(
        private readonly ClassLoaderInterface $classLoader,
        private readonly ChainTransformerFactory $chainTransformerFactory,
        public readonly CustomTransformersRegistry $customTransformersRegistry,
        private readonly ?MapperGeneratorMetadataFactoryInterface $mapperConfigurationFactory = null,
    ) {
        $this->chainTransformerFactory->setAutoMapperRegistry($this);
    }

    public function register(MapperGeneratorMetadataInterface $configuration): void
    {
        $this->metadata[$configuration->getSource()][$configuration->getTarget()] = $configuration;
    }

    public function getMapper(string $source, string $target): MapperInterface
    {
        $metadata = $this->getMetadata($source, $target);

        if (null === $metadata) {
            throw new NoMappingFoundException('No mapping found for source ' . $source . ' and target ' . $target);
        }

        $className = $metadata->getMapperClassName();

        if (\array_key_exists($className, $this->mapperRegistry)) {
            return $this->mapperRegistry[$className];
        }

        if (!class_exists($className)) {
            $this->classLoader->loadClass($metadata);
        }

        /** @var GeneratedMapper $mapper */
        $mapper = new $className();
        $this->mapperRegistry[$className] = $mapper;

        $mapper->injectMappers($this);

        foreach ($metadata->getCallbacks() as $property => $callback) {
            $mapper->addCallback($property, $callback);
        }

        $mapper->setCustomTransformers($this->customTransformersRegistry->getCustomTransformers());

        return $this->mapperRegistry[$className];
    }

    public function hasMapper(string $source, string $target): bool
    {
        return null !== $this->getMetadata($source, $target);
    }

    public function map(array|object $source, string|array|object $target, array $context = []): array|object|null
    {
        $sourceType = $targetType = null;

        if (\is_object($source)) {
            $sourceType = $source::class;
        } elseif (\is_array($source)) {
            $sourceType = 'array';
        }

        if (\is_object($target)) {
            $targetType = $target::class;
            $context[MapperContext::TARGET_TO_POPULATE] = $target;
        } elseif (\is_array($target)) {
            $targetType = 'array';
            $context[MapperContext::TARGET_TO_POPULATE] = $target;
        } elseif (\is_string($target)) {
            $targetType = $target;
        }

        if ('array' === $sourceType && 'array' === $targetType) {
            throw new NoMappingFoundException('Cannot map this value, both source and target are array.');
        }

        return $this->getMapper($sourceType, $targetType)->map($source, $context);
    }

    public function getMetadata(string $source, string $target): ?MapperGeneratorMetadataInterface
    {
        if (!isset($this->metadata[$source][$target])) {
            if (null === $this->mapperConfigurationFactory) {
                return null;
            }

            $this->register($this->mapperConfigurationFactory->create($this, $source, $target));
        }

        return $this->metadata[$source][$target];
    }

    /**
     * @deprecated since 8.2, will be removed in 9.0.
     */
    public function bindTransformerFactory(TransformerFactoryInterface $transformerFactory): void
    {
        trigger_deprecation('jolicode/automapper', '8.2', 'The "%s()" method will be removed in version 9.0, transformer must be injected in the chain transformer factory constructor instead.', __METHOD__);

        if (!$this->chainTransformerFactory->hasTransformerFactory($transformerFactory)) {
            $this->chainTransformerFactory->addTransformerFactory($transformerFactory);
        }
    }

    public function bindCustomTransformer(CustomTransformerInterface $customTransformer, ?string $id = null): void
    {
        $this->customTransformersRegistry->addCustomTransformer($customTransformer, $id);
    }

    /**
     * @param list<TransformerFactoryInterface> $transformerFactories
     */
    public static function create(
        bool $mapPrivateProperties = false,
        ClassLoaderInterface $loader = null,
        AdvancedNameConverterInterface $nameConverter = null,
        string $classPrefix = 'Mapper_',
        bool $attributeChecking = true,
        bool $autoRegister = true,
        string $dateTimeFormat = \DateTimeInterface::RFC3339,
        bool $allowReadOnlyTargetToPopulate = false,
        array $transformerFactories = [],
    ): self {
        if (class_exists(AttributeLoader::class)) {
            $loaderClass = new AttributeLoader();
        } elseif (class_exists(AnnotationReader::class)) {
            $loaderClass = new AnnotationLoader(new AnnotationReader());
        } else {
            $loaderClass = new AnnotationLoader();
        }
        $classMetadataFactory = new ClassMetadataFactory($loaderClass);

        if (null === $loader) {
            $loader = new EvalLoader(
                new MapperGenerator(
                    new ClassDiscriminatorResolver(new ClassDiscriminatorFromClassMetadata($classMetadataFactory)),
                    $allowReadOnlyTargetToPopulate
                ));
        }

        $flags = ReflectionExtractor::ALLOW_PUBLIC | ReflectionExtractor::ALLOW_PROTECTED | ReflectionExtractor::ALLOW_PRIVATE;

        $reflectionExtractor = new ReflectionExtractor(accessFlags: $flags);

        $phpStanExtractor = new PhpStanExtractor();
        $propertyInfoExtractor = new PropertyInfoExtractor(
            [$reflectionExtractor],
            [$phpStanExtractor, $reflectionExtractor],
            [$reflectionExtractor],
            [new MapToContextPropertyInfoExtractorDecorator($reflectionExtractor)]
        );

        $customTransformerRegistry = new CustomTransformersRegistry();

        $factories = [
            new MultipleTransformerFactory(),
            new NullableTransformerFactory(),
            new UniqueTypeTransformerFactory(),
            new DateTimeTransformerFactory(),
            new BuiltinTransformerFactory(),
            new ArrayTransformerFactory(),
            new ObjectTransformerFactory(),
            new EnumTransformerFactory(),
            new CustomTransformerFactory($customTransformerRegistry),
        ];

        if (class_exists(AbstractUid::class)) {
            $factories[] = new SymfonyUidTransformerFactory();
        }

        foreach ($transformerFactories as $factory) {
            $factories[] = $factory;
        }

        $transformerFactory = new ChainTransformerFactory($factories);

        $sourceTargetMappingExtractor = new SourceTargetMappingExtractor(
            $propertyInfoExtractor,
            new MapToContextPropertyInfoExtractorDecorator($reflectionExtractor),
            $reflectionExtractor,
            $transformerFactory,
            $classMetadataFactory
        );

        $fromTargetMappingExtractor = new FromTargetMappingExtractor(
            $propertyInfoExtractor,
            $reflectionExtractor,
            $reflectionExtractor,
            $transformerFactory,
            $classMetadataFactory,
            $nameConverter
        );

        $fromSourceMappingExtractor = new FromSourceMappingExtractor(
            $propertyInfoExtractor,
            new MapToContextPropertyInfoExtractorDecorator($reflectionExtractor),
            $reflectionExtractor,
            $transformerFactory,
            $classMetadataFactory,
            $nameConverter
        );

        $autoMapper = $autoRegister ? new self(
            $loader,
            $transformerFactory,
            $customTransformerRegistry,
            new MapperGeneratorMetadataFactory(
                $sourceTargetMappingExtractor,
                $fromSourceMappingExtractor,
                $fromTargetMappingExtractor,
                $classPrefix,
                $attributeChecking,
                $dateTimeFormat,
                $mapPrivateProperties
            ),
        ) : new self($loader, $transformerFactory, $customTransformerRegistry);

        return $autoMapper;
    }
}
