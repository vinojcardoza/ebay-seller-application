<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Entities\Category;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Full-path labels for categories, e.g. "Sports › Tennis", for select boxes
 * and product groupings.
 */
class CategoryPaths
{
    /**
     * Create the helper.
     */
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Path labels keyed by category ID, sorted by label. Walks `parent_id`
     * upwards, stopping at top level (0), unknown parents or loops.
     *
     * @return array<int, string>
     */
    public function labels(): array
    {
        $categories = [];

        foreach ($this->em->getRepository(Category::class)->findAll() as $category) {
            $categories[$category->getId()] = $category;
        }

        $labels = [];

        foreach ($categories as $id => $category) {
            $path = [$category->getTitle()];
            $seen = [$id => true];
            $parentId = $category->getParentId() ?? 0;

            while ($parentId > 0 && isset($categories[$parentId]) && !isset($seen[$parentId])) {
                $seen[$parentId] = true;
                array_unshift($path, $categories[$parentId]->getTitle());
                $parentId = $categories[$parentId]->getParentId() ?? 0;
            }

            $labels[$id] = implode(' › ', $path);
        }

        asort($labels, SORT_NATURAL | SORT_FLAG_CASE);

        return $labels;
    }

    /**
     * The category's ID plus the IDs of all categories below it, at any depth.
     *
     * @return list<int>
     */
    public function withDescendants(int $categoryId): array
    {
        $children = [];

        foreach ($this->em->getRepository(Category::class)->findAll() as $category) {
            $children[$category->getParentId() ?? 0][] = $category->getId();
        }

        $ids = [];
        $queue = [$categoryId];

        while ($queue !== []) {
            $id = array_shift($queue);

            if (in_array($id, $ids, true)) {
                continue;
            }

            $ids[] = $id;
            array_push($queue, ...($children[$id] ?? []));
        }

        return $ids;
    }
}
