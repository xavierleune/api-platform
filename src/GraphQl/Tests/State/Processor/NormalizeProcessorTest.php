<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\GraphQl\Tests\State\Processor;

use ApiPlatform\GraphQl\Serializer\SerializerContextBuilderInterface;
use ApiPlatform\GraphQl\State\Processor\NormalizeProcessor;
use ApiPlatform\Metadata\GraphQl\Mutation;
use ApiPlatform\Metadata\GraphQl\Query;
use ApiPlatform\Metadata\GraphQl\QueryCollection;
use ApiPlatform\Metadata\GraphQl\Subscription;
use ApiPlatform\State\Pagination\ArrayPaginator;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\PartialPaginatorInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class NormalizeProcessorTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @dataProvider processItems
     */
    public function testProcess($body, $operation): void
    {
        $context = ['args' => []];
        $serializerContext = ['resource_class' => $operation->getClass()];
        $normalizer = $this->createMock(NormalizerInterface::class);
        $serializerContextBuilder = $this->createMock(SerializerContextBuilderInterface::class);
        $serializerContextBuilder->expects($this->once())->method('create')->with($operation->getClass(), $operation, $context, normalization: true)->willReturn($serializerContext);
        $normalizer->expects($this->once())->method('normalize')->with($body, 'graphql', $serializerContext);
        $processor = new NormalizeProcessor($normalizer, $serializerContextBuilder, new Pagination());
        $processor->process($body, $operation, [], $context);
    }

    public function processItems(): array
    {
        return [
            [new \stdClass(), new Query(class: 'foo')],
            [new \stdClass(), new Mutation(class: 'foo', shortName: 'Foo')],
            [new \stdClass(), new Subscription(class: 'foo', shortName: 'Foo')],
        ];
    }

    /**
     * @dataProvider processCollection
     */
    public function testProcessCollection($body, $operation, $args, ?array $expectedResult, bool $pageBasedPagination, string $expectedExceptionClass = null, string $expectedExceptionMessage = null): void
    {
        $context = ['args' => $args];
        $serializerContext = ['resource_class' => $operation->getClass()];
        $normalizer = $this->prophesize(NormalizerInterface::class);

        $serializerContextBuilder = $this->createMock(SerializerContextBuilderInterface::class);
        $serializerContextBuilder->expects($this->once())->method('create')->with($operation->getClass(), $operation, $context, normalization: true)->willReturn($serializerContext);
        foreach ($body as $v) {
            $normalizer->normalize($v, 'graphql', $serializerContext)->willReturn(['normalized_item'])->shouldBeCalledOnce();
        }

        if ($expectedExceptionClass) {
            $this->expectException($expectedExceptionClass);
            $this->expectExceptionMessage($expectedExceptionMessage);
        }

        $processor = new NormalizeProcessor($normalizer->reveal(), $serializerContextBuilder, new Pagination());
        $result = $processor->process(\is_callable($body) ? $body($this) : $body, $operation, [], $context);
        $this->assertSame($expectedResult, $result);
    }

    public function processCollection(): iterable
    {
        $partialPaginatorFactory = function (self $that): PartialPaginatorInterface {
            $partialPaginatorProphecy = $that->prophesize(PartialPaginatorInterface::class);
            $partialPaginatorProphecy->count()->willReturn(2);
            $partialPaginatorProphecy->valid()->willReturn(false);
            $partialPaginatorProphecy->getItemsPerPage()->willReturn(2.0);
            $partialPaginatorProphecy->rewind();

            return $partialPaginatorProphecy->reveal();
        };

        yield 'cursor - not paginator' => [[], new QueryCollection(class: 'foo'), [], null, false, \LogicException::class, 'Collection returned by the collection data provider must implement ApiPlatform\State\Pagination\PaginatorInterface or ApiPlatform\State\Pagination\PartialPaginatorInterface.'];
        yield 'cursor - empty paginator' => [new ArrayPaginator([], 0, 0), new QueryCollection(class: 'foo'), [], ['totalCount' => 0., 'edges' => [], 'pageInfo' => ['startCursor' => null, 'endCursor' => null, 'hasNextPage' => false, 'hasPreviousPage' => false]], false];
        yield 'cursor - paginator' => [new ArrayPaginator([(object) ['test' => 'a'], (object) ['test' => 'b'], (object) ['test' => 'c']], 0, 2), new QueryCollection(class: 'foo'), [],  ['totalCount' => 3., 'edges' => [['node' => ['normalized_item'], 'cursor' => 'MA=='], ['node' => ['normalized_item'], 'cursor' => 'MQ==']], 'pageInfo' => ['startCursor' => 'MA==', 'endCursor' => 'MQ==', 'hasNextPage' => true, 'hasPreviousPage' => false]], false];
        yield 'cursor - paginator with after cursor' => [new ArrayPaginator([(object) ['test' => 'a'], (object) ['test' => 'b'], (object) ['test' => 'c']], 1, 2), new QueryCollection(class: 'foo'), ['after' => 'MA=='], ['totalCount' => 3., 'edges' => [['node' => ['normalized_item'], 'cursor' => 'MQ=='], ['node' => ['normalized_item'], 'cursor' => 'Mg==']], 'pageInfo' => ['startCursor' => 'MQ==', 'endCursor' => 'Mg==', 'hasNextPage' => false, 'hasPreviousPage' => true]], false];
        yield 'cursor - paginator with bad after cursor' => [new ArrayPaginator([], 0, 0), new QueryCollection(class: 'foo'), ['after' => '-'], null, false, \UnexpectedValueException::class, 'Cursor - is invalid'];
        yield 'cursor - paginator with empty after cursor' => [new ArrayPaginator([], 0, 0), new QueryCollection(class: 'foo'), ['after' => ''], null, false, \UnexpectedValueException::class, 'Empty cursor is invalid'];
        yield 'cursor - paginator with before cursor' => [new ArrayPaginator([(object) ['test' => 'a'], (object) ['test' => 'b'], (object) ['test' => 'c']], 1, 1), new QueryCollection(class: 'foo'), ['before' => 'Mg=='], ['totalCount' => 3., 'edges' => [['node' => ['normalized_item'], 'cursor' => 'MQ==']], 'pageInfo' => ['startCursor' => 'MQ==', 'endCursor' => 'MQ==', 'hasNextPage' => true, 'hasPreviousPage' => true]], false];
        yield 'cursor - paginator with bad before cursor' => [new ArrayPaginator([], 0, 0), new QueryCollection(class: 'foo'), ['before' => '-'], null, false, \UnexpectedValueException::class, 'Cursor - is invalid'];
        yield 'cursor - paginator with empty before cursor' => [new ArrayPaginator([], 0, 0), new QueryCollection(class: 'foo'), ['before' => ''], null, false, \UnexpectedValueException::class, 'Empty cursor is invalid'];
        yield 'cursor - paginator with last' => [new ArrayPaginator([(object) ['test' => 'a'], (object) ['test' => 'b'], (object) ['test' => 'c']], 1, 2), new QueryCollection(class: 'foo'), ['last' => 2], ['totalCount' => 3., 'edges' => [['node' => ['normalized_item'], 'cursor' => 'MQ=='], ['node' => ['normalized_item'], 'cursor' => 'Mg==']], 'pageInfo' => ['startCursor' => 'MQ==', 'endCursor' => 'Mg==', 'hasNextPage' => false, 'hasPreviousPage' => true]], false];
        yield 'cursor - partial paginator' => [$partialPaginatorFactory, new QueryCollection(class: 'foo'), [], ['totalCount' => 0., 'edges' => [], 'pageInfo' => ['startCursor' => 'MA==', 'endCursor' => 'MQ==', 'hasNextPage' => false, 'hasPreviousPage' => false]], false];
        yield 'cursor - partial paginator with after cursor' => [$partialPaginatorFactory, new QueryCollection(class: 'foo'), ['after' => 'MA=='], ['totalCount' => 0., 'edges' => [], 'pageInfo' => ['startCursor' => 'MQ==', 'endCursor' => 'Mg==', 'hasNextPage' => false, 'hasPreviousPage' => true]], false];
        yield 'page - not paginator' => [[], new QueryCollection(class: 'foo'), [], [], true, \LogicException::class, 'Collection returned by the collection data provider must implement ApiPlatform\State\Pagination\PaginatorInterface or ApiPlatform\State\Pagination\PartialPaginatorInterface.'];
        yield 'page - empty paginator' => [new ArrayPaginator([], 0, 0), (new QueryCollection(class: 'foo'))->withPaginationType('page'), [], ['collection' => [], 'paginationInfo' => ['itemsPerPage' => .0, 'totalCount' => .0, 'lastPage' => 1.0]], true];
        yield 'page - paginator' => [new ArrayPaginator([(object) ['test' => 'a'], (object) ['test' => 'b'], (object) ['test' => 'c']], 0, 2), (new QueryCollection(class: 'foo'))->withPaginationType('page'), [], ['collection' => [['normalized_item'], ['normalized_item']], 'paginationInfo' => ['itemsPerPage' => 2.0, 'totalCount' => 3.0, 'lastPage' => 2.0]], true];
        yield 'page - paginator with page' => [new ArrayPaginator([(object) ['test' => 'a'], (object) ['test' => 'b'], (object) ['test' => 'c']], 2, 2), (new QueryCollection(class: 'foo'))->withPaginationType('page'), [], ['collection' => [['normalized_item']], 'paginationInfo' => ['itemsPerPage' => 2.0, 'totalCount' => 3.0, 'lastPage' => 2.0]], true];
        yield 'page - partial paginator' => [$partialPaginatorFactory, (new QueryCollection(class: 'foo'))->withPaginationType('page'), [], ['collection' => [], 'paginationInfo' => ['itemsPerPage' => 2.0]], true];
        yield 'page - partial paginator with page' => [$partialPaginatorFactory, (new QueryCollection(class: 'foo'))->withPaginationType('page'), [], ['collection' => [], 'paginationInfo' => ['itemsPerPage' => 2.0]], true];
    }
}
