<?php

declare(strict_types=1);

namespace AutoMapper\Tests\Extractor;

use AutoMapper\Exception\InvalidMappingException;
use AutoMapper\Extractor\FromTargetMappingExtractor;
use AutoMapper\MapperMetadata;
use AutoMapper\Tests\AutoMapperBaseTest;
use AutoMapper\Tests\Fixtures;
use AutoMapper\Transformer\ArrayTransformerFactory;
use AutoMapper\Transformer\BuiltinTransformerFactory;
use AutoMapper\Transformer\ChainTransformerFactory;
use AutoMapper\Transformer\DateTimeTransformerFactory;
use AutoMapper\Transformer\MultipleTransformerFactory;
use AutoMapper\Transformer\NullableTransformerFactory;
use AutoMapper\Transformer\ObjectTransformerFactory;
use AutoMapper\Transformer\UniqueTypeTransformerFactory;
use Doctrine\Common\Annotations\AnnotationReader;
use Symfony\Component\PropertyInfo\Extractor\PhpStanExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AnnotationLoader;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;

/**
 * @author Baptiste Leduc <baptiste.leduc@gmail.com>
 */
class FromTargetMappingExtractorTest extends AutoMapperBaseTest
{
    protected FromTargetMappingExtractor $fromTargetMappingExtractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fromTargetMappingExtractorBootstrap();
    }

    private function fromTargetMappingExtractorBootstrap(bool $private = true): void
    {
        if (class_exists(AttributeLoader::class)) {
            $loaderClass = new AttributeLoader();
        } else {
            $loaderClass = new AnnotationLoader(new AnnotationReader());
        }
        $classMetadataFactory = new ClassMetadataFactory($loaderClass);
        $flags = ReflectionExtractor::ALLOW_PUBLIC;

        if ($private) {
            $flags |= ReflectionExtractor::ALLOW_PROTECTED | ReflectionExtractor::ALLOW_PRIVATE;
        }

        $reflectionExtractor = new ReflectionExtractor(null, null, null, true, $flags);

        $phpStanExtractor = new PhpStanExtractor();
        $propertyInfoExtractor = new PropertyInfoExtractor(
            [$reflectionExtractor],
            [$phpStanExtractor, $reflectionExtractor],
            [$reflectionExtractor],
            [$reflectionExtractor]
        );

        $transformerFactory = new ChainTransformerFactory([
            new MultipleTransformerFactory(),
            new NullableTransformerFactory(),
            new UniqueTypeTransformerFactory(),
            new DateTimeTransformerFactory(),
            new BuiltinTransformerFactory(),
            new ArrayTransformerFactory(),
            new ObjectTransformerFactory(),
        ]);

        $transformerFactory->setAutoMapperRegistry($this->autoMapper);

        $this->fromTargetMappingExtractor = new FromTargetMappingExtractor(
            $propertyInfoExtractor,
            $reflectionExtractor,
            $reflectionExtractor,
            $transformerFactory,
            $classMetadataFactory
        );
    }

    public function testWithSourceAsArray(): void
    {
        $userReflection = new \ReflectionClass(Fixtures\User::class);
        $mapperMetadata = new MapperMetadata($this->autoMapper, $this->fromTargetMappingExtractor, source: 'array', target: Fixtures\User::class, isTargetReadOnlyClass: false, mapPrivateProperties: true);
        $targetPropertiesMapping = $this->fromTargetMappingExtractor->getPropertiesMapping($mapperMetadata);

        self::assertCount(\count($userReflection->getProperties()), $targetPropertiesMapping);
        foreach ($targetPropertiesMapping as $propertyMapping) {
            self::assertTrue($userReflection->hasProperty($propertyMapping->property));
        }
    }

    public function testWithSourceAsStdClass(): void
    {
        $userReflection = new \ReflectionClass(Fixtures\User::class);
        $mapperMetadata = new MapperMetadata($this->autoMapper, $this->fromTargetMappingExtractor, source: 'stdClass', target: Fixtures\User::class, isTargetReadOnlyClass: false, mapPrivateProperties: true);
        $targetPropertiesMapping = $this->fromTargetMappingExtractor->getPropertiesMapping($mapperMetadata);

        self::assertCount(\count($userReflection->getProperties()), $targetPropertiesMapping);
        foreach ($targetPropertiesMapping as $propertyMapping) {
            self::assertTrue($userReflection->hasProperty($propertyMapping->property));
        }
    }

    public function testWithTargetAsEmpty(): void
    {
        $mapperMetadata = new MapperMetadata($this->autoMapper, $this->fromTargetMappingExtractor, source: 'array', target: Fixtures\Empty_::class, isTargetReadOnlyClass: false, mapPrivateProperties: true);
        $targetPropertiesMapping = $this->fromTargetMappingExtractor->getPropertiesMapping($mapperMetadata);

        self::assertCount(0, $targetPropertiesMapping);
    }

    public function testWithTargetAsPrivate(): void
    {
        $privateReflection = new \ReflectionClass(Fixtures\Private_::class);
        $mapperMetadata = new MapperMetadata($this->autoMapper, $this->fromTargetMappingExtractor, source: 'array', target: Fixtures\Private_::class, isTargetReadOnlyClass: false, mapPrivateProperties: true);
        $targetPropertiesMapping = $this->fromTargetMappingExtractor->getPropertiesMapping($mapperMetadata);
        self::assertCount(\count($privateReflection->getProperties()), $targetPropertiesMapping);

        $this->fromTargetMappingExtractorBootstrap(false);
        $mapperMetadata = new MapperMetadata($this->autoMapper, $this->fromTargetMappingExtractor, source: 'array', target: Fixtures\Private_::class, isTargetReadOnlyClass: false, mapPrivateProperties: true);
        $targetPropertiesMapping = $this->fromTargetMappingExtractor->getPropertiesMapping($mapperMetadata);
        self::assertCount(0, $targetPropertiesMapping);
    }

    public function testWithTargetAsArray(): void
    {
        self::expectException(InvalidMappingException::class);
        self::expectExceptionMessage('Only array or stdClass are accepted as a source');

        $mapperMetadata = new MapperMetadata($this->autoMapper, $this->fromTargetMappingExtractor, source: Fixtures\User::class, target: 'array', isTargetReadOnlyClass: false, mapPrivateProperties: true);
        $this->fromTargetMappingExtractor->getPropertiesMapping($mapperMetadata);
    }

    public function testWithTargetAsStdClass(): void
    {
        self::expectException(InvalidMappingException::class);
        self::expectExceptionMessage('Only array or stdClass are accepted as a source');

        $mapperMetadata = new MapperMetadata($this->autoMapper, $this->fromTargetMappingExtractor, source: Fixtures\User::class, target: 'stdClass', isTargetReadOnlyClass: false, mapPrivateProperties: true);
        $this->fromTargetMappingExtractor->getPropertiesMapping($mapperMetadata);
    }
}
