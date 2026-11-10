<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Entities\Category;
use App\Entities\Product;
use App\Framework\Controller\ControllerAbstract;
use DateTime;
use Doctrine\ORM\EntityRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * CRUD actions for product categories.
 *
 * HTML forms can only send GET and POST, so updates and deletes are POST
 * routes (`/categories/{id}` and `/categories/{id}/delete`).
 */
class CategoriesController extends ControllerAbstract
{
    /**
     * Maximum length of a category title (`categories.title` is varchar(191)).
     */
    private const TITLE_MAX_LENGTH = 191;

    /**
     * List all categories ordered by menu order, then title.
     */
    public function index(): ResponseInterface
    {
        $categories = $this->repository()->findBy([], ['menuOrder' => 'ASC', 'title' => 'ASC']);

        return $this->render('categories/index', [
            'categories' => $categories,
            'titles' => $this->titlesById($categories),
        ]);
    }

    /**
     * Show a single category with its parent and child categories.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $category = $this->findCategory($args);

        if ($category === null) {
            return $this->notFound('Category not found.');
        }

        return $this->renderShow($category);
    }

    /**
     * Show the form for creating a category.
     */
    public function create(): ResponseInterface
    {
        return $this->renderForm('categories/create', null, [
            'title' => '',
            'parent_id' => '0',
            'menu_order' => '0',
        ]);
    }

    /**
     * Validate the submitted form and create a category, or redisplay the form with errors.
     */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $values = $this->formInput($request);
        $errors = $this->validate($values, null);

        if ($errors !== []) {
            return $this->renderForm('categories/create', null, $values, $errors);
        }

        $category = new Category();
        $this->fill($category, $values);

        $this->em->persist($category);
        $this->em->flush();

        return $this->redirect('/categories/' . $category->getId());
    }

    /**
     * Show the form for editing an existing category.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function edit(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $category = $this->findCategory($args);

        if ($category === null) {
            return $this->notFound('Category not found.');
        }

        return $this->renderForm('categories/edit', $category, [
            'title' => $category->getTitle(),
            'parent_id' => (string) ($category->getParentId() ?? 0),
            'menu_order' => (string) ($category->getMenuOrder() ?? 0),
        ]);
    }

    /**
     * Validate the submitted form and update the category, or redisplay the form with errors.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function update(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $category = $this->findCategory($args);

        if ($category === null) {
            return $this->notFound('Category not found.');
        }

        $values = $this->formInput($request);
        $errors = $this->validate($values, $category);

        if ($errors !== []) {
            return $this->renderForm('categories/edit', $category, $values, $errors);
        }

        $this->fill($category, $values);
        $category->setUpdated(new DateTime());

        $this->em->flush();

        return $this->redirect('/categories/' . $category->getId());
    }

    /**
     * Delete a category, unless child categories, products or custom SKUs still use it.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function delete(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $category = $this->findCategory($args);

        if ($category === null) {
            return $this->notFound('Category not found.');
        }

        $blockers = $this->deleteBlockers($category);

        if ($blockers !== []) {
            return $this->renderShow($category, $blockers, 409);
        }

        $this->em->remove($category);
        $this->em->flush();

        return $this->redirect('/categories');
    }

    /**
     * Get the Doctrine repository for categories.
     *
     * @return EntityRepository<Category>
     */
    private function repository(): EntityRepository
    {
        return $this->em->getRepository(Category::class);
    }

    /**
     * Find the category named by the `id` route placeholder.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    private function findCategory(array $args): ?Category
    {
        return $this->repository()->find((int) ($args['id'] ?? 0));
    }

    /**
     * Render the show page, optionally with error messages and a status code.
     *
     * @param list<string> $errors Messages explaining why an action failed.
     */
    private function renderShow(Category $category, array $errors = [], int $status = 200): ResponseInterface
    {
        $parentId = $category->getParentId() ?? 0;

        return $this->render('categories/show', [
            'category' => $category,
            'parent' => $parentId > 0 ? $this->repository()->find($parentId) : null,
            'children' => $this->repository()->findBy(
                ['parentId' => $category->getId()],
                ['menuOrder' => 'ASC', 'title' => 'ASC']
            ),
            'errors' => $errors,
        ], $status);
    }

    /**
     * Render the create or edit form; responds 422 when there are validation errors.
     *
     * @param array{title: string, parent_id: string, menu_order: string} $values Current form values.
     * @param array<string, string> $errors Validation messages keyed by field name.
     */
    private function renderForm(
        string $template,
        ?Category $category,
        array $values,
        array $errors = []
    ): ResponseInterface {
        return $this->render($template, [
            'category' => $category,
            'values' => $values,
            'errors' => $errors,
            'parents' => $this->parentOptions($category),
        ], $errors === [] ? 200 : 422);
    }

    /**
     * Read the category fields from the submitted form, as trimmed strings.
     *
     * @return array{title: string, parent_id: string, menu_order: string}
     */
    private function formInput(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $field = static function (string $name, string $default) use ($body): string {
            $value = $body[$name] ?? $default;

            return is_scalar($value) ? trim((string) $value) : $default;
        };

        return [
            'title' => $field('title', ''),
            'parent_id' => $field('parent_id', '0'),
            'menu_order' => $field('menu_order', '0'),
        ];
    }

    /**
     * Validate form values, returning error messages keyed by field name.
     *
     * @param array{title: string, parent_id: string, menu_order: string} $values Submitted form values.
     * @param Category|null $category The category being edited, or null when creating.
     *
     * @return array<string, string>
     */
    private function validate(array $values, ?Category $category): array
    {
        $errors = [];

        if ($values['title'] === '') {
            $errors['title'] = 'Title is required.';
        } elseif (mb_strlen($values['title']) > self::TITLE_MAX_LENGTH) {
            $errors['title'] = sprintf('Title must be %d characters or fewer.', self::TITLE_MAX_LENGTH);
        }

        $parentId = filter_var($values['parent_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($parentId === false) {
            $errors['parent_id'] = 'Choose a valid parent category.';
        } elseif ($parentId > 0) {
            if ($this->repository()->find($parentId) === null) {
                $errors['parent_id'] = 'The selected parent category does not exist.';
            } elseif ($category !== null && $this->isSelfOrDescendant($parentId, $category->getId())) {
                $errors['parent_id'] = 'A category cannot be its own parent or a child of its own subcategory.';
            }
        }

        if (filter_var($values['menu_order'], FILTER_VALIDATE_INT) === false) {
            $errors['menu_order'] = 'Menu order must be a whole number.';
        }

        return $errors;
    }

    /**
     * Copy validated form values onto the category.
     *
     * @param array{title: string, parent_id: string, menu_order: string} $values Validated form values.
     */
    private function fill(Category $category, array $values): void
    {
        $category
            ->setTitle($values['title'])
            ->setParentId((int) $values['parent_id'])
            ->setMenuOrder((int) $values['menu_order']);
    }

    /**
     * Categories that can be chosen as the parent: all of them, except the
     * category being edited and its descendants (which would create a cycle).
     *
     * @return list<Category>
     */
    private function parentOptions(?Category $category): array
    {
        $all = $this->repository()->findBy([], ['title' => 'ASC']);

        if ($category === null) {
            return $all;
        }

        return array_values(array_filter(
            $all,
            fn (Category $option): bool => !$this->isSelfOrDescendant($option->getId(), $category->getId())
        ));
    }

    /**
     * Whether a category is the given ancestor or sits anywhere below it, found
     * by walking up the parent chain. Stops on existing cycles in the data.
     */
    private function isSelfOrDescendant(int $categoryId, int $ancestorId): bool
    {
        $parents = [];

        foreach ($this->repository()->findAll() as $row) {
            $parents[$row->getId()] = $row->getParentId() ?? 0;
        }

        $visited = [];
        $current = $categoryId;

        while ($current > 0 && !isset($visited[$current])) {
            if ($current === $ancestorId) {
                return true;
            }

            $visited[$current] = true;
            $current = $parents[$current] ?? 0;
        }

        return false;
    }

    /**
     * Reasons the category cannot be deleted: records that still reference it.
     *
     * @return list<string>
     */
    private function deleteBlockers(Category $category): array
    {
        $id = $category->getId();

        $counts = [
            'subcategory' => $this->repository()->count(['parentId' => $id]),
            'product' => $this->em->getRepository(Product::class)->count(['category' => $id]),
            'custom SKU' => (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM custom_skus WHERE category = ?',
                [$id]
            ),
        ];

        $blockers = [];

        foreach ($counts as $label => $count) {
            if ($count > 0) {
                $blockers[] = sprintf(
                    'It still has %d %s%s. Move or delete %s first.',
                    $count,
                    $label,
                    $count === 1 ? '' : 's',
                    $count === 1 ? 'it' : 'them'
                );
            }
        }

        return $blockers;
    }

    /**
     * Map category IDs to titles, for showing parent names in the list.
     *
     * @param array<Category> $categories
     *
     * @return array<int, string>
     */
    private function titlesById(array $categories): array
    {
        $titles = [];

        foreach ($categories as $category) {
            $titles[$category->getId()] = $category->getTitle();
        }

        return $titles;
    }
}
