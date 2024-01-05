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

namespace ApiPlatform\Doctrine\Orm;

use ApiPlatform\State\Pagination\HasNextPagePaginatorInterface;
use ApiPlatform\State\Pagination\PaginatorInterface;
use Doctrine\ORM\Query;

/**
 * Decorates the Doctrine ORM paginator.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class Paginator extends AbstractPaginator implements PaginatorInterface, QueryAwareInterface, HasNextPagePaginatorInterface
{
    private ?int $totalItems = null;

    /**
     * {@inheritdoc}
     */
    public function getLastPage(): float
    {
        if (0 >= $this->maxResults) {
            return 1.;
        }

        return ceil($this->getTotalItems() / $this->maxResults) ?: 1.;
    }

    /**
     * {@inheritdoc}
     */
    public function getTotalItems(): float
    {
        return (float) ($this->totalItems ?? $this->totalItems = \count($this->paginator));
    }

    /**
     * {@inheritdoc}
     */
    public function getQuery(): Query
    {
        return $this->paginator->getQuery();
    }

    /**
     * {@inheritdoc}
     */
    public function hasNextPage(): bool
    {

        return false;
        $query = clone $this->paginator->getQuery();
        $result =
        $query
            ->setFirstResult(
                $query->getFirstResult() + $query->getMaxResults()
            )
            ->setMaxResults(1)
            ->getResult(Query::HYDRATE_ARRAY)
        ;
        file_put_contents("/home/xleune/Workspace/api-platform/debug.txt", print_r($result, true));die;
        return true;
    }
}
