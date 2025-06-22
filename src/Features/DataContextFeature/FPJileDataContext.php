<?php

namespace FPJ\RoutingKit\Features\DataContextFeature;

use FPJ\RoutingKit\Contracts\FPJContextEntitiesInterface;
use FPJ\RoutingKit\Contracts\FPJDataRepositoryInterface;
use FPJ\RoutingKit\Contracts\FPJEntityInterface;
use Illuminate\Support\Collection;

class FPJileDataContext implements FPJContextEntitiesInterface
{
    protected string $id;

    protected FPJDataRepositoryInterface $fpjRepository;
    protected ?Collection $treeEntitys = null;
    protected ?Collection $flattenedEntitys = null;

    /**
     * Construye una nueva instancia de FPJileDataContext.
     *
     * @param FPJDataRepositoryInterface $fpjRepository El repositorio de entidades FPJ.
     */
    public function __construct(string $id, FPJDataRepositoryInterface $fpjRepository)
    {
        $this->id = $id;
        $this->fpjRepository = $fpjRepository;
    }

    /**
     * Crea una nueva instancia de FPJileDataContext.
     *
     * @param FPJDataRepositoryInterface $fpjRepository El repositorio de entidades FPJ.
     * @return static La nueva instancia de DataContext.
     */
    public static function make(string $id, FPJDataRepositoryInterface $fpjRepository): static
    {
        return new static($id, $fpjRepository);
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Obtiene una colección aplanada de todas las entidades.
     *
     * @return Collection Colección aplanada de entidades.
     */
    public function getFlattenedEntitys(): Collection
    {
        if ($this->flattenedEntitys === null) {
            $this->flattenedEntitys = $this->flattenTreeEntities($this->fpjRepository->getData());
        }
        //dd($this->flattenedEntitys);
        return $this->flattenedEntitys;
    }

    /**
     * Obtiene una colección de entidades en forma de árbol.
     *
     * @return Collection Colección de entidades en forma de árbol.
     */
    public function getTreeEntitys(): Collection
    {
        if ($this->treeEntitys === null) {
            $clonedCollection = $this->cloneCollectionDeep($this->getFlattenedEntitys());

            $this->treeEntitys = $this->buildTreeFromFlattened($clonedCollection);
        }
        return $this->treeEntitys;
    }

    /**
     * Aplana una colección de entidades en forma de árbol a una colección plana.
     *
     * @param Collection $tree Colección de entidades en forma de árbol.
     * @param string|null $parentId El ID del padre para las entidades anidadas.
     * @return Collection Colección aplanada de entidades.
     */
    protected function flattenTreeEntities(Collection $tree, ?string $parentId = null): Collection
    {
        $flat = collect();
        foreach ($tree as $entity) {
            if (!$entity instanceof FPJEntityInterface) {
                continue;
            }
            if ($entity->getParentId() === null && $parentId !== null) {
                $entity->setParentId($parentId);
            }
            $entity->setContextKey($this->id);
            $flat->put($entity->getId(), $entity);
            $items = collect($entity->getItems() ?? []);
            if ($items->isNotEmpty()) {
                $flat = $flat->merge(
                    $this->flattenTreeEntities($items, $entity->getId())
                );
            }
            $entity->setItems([]);
        }
        return $flat;
    }

    protected function cloneCollectionDeep(Collection $collection): Collection
    {
        return $collection->mapWithKeys(function ($entity, $key) {
            $clone = clone $entity;

            // También clona sus hijos si hay
            $items = $clone->getItems();
            if ($items instanceof Collection && $items->isNotEmpty()) {
                $clone->setItems($this->cloneCollectionDeep($items)->toArray());
            } else {
                $clone->setItems([]); // Evita que queden hijos arrastrados
            }

            return [$key => $clone];
        });
    }


    /**
     * Reconstruye un árbol de entidades a partir de una colección aplanada.
     *
     * @param Collection $flat Colección aplanada de entidades.
     * @return Collection Colección de entidades en forma de árbol.
     */
    protected function buildTreeFromFlattened(Collection $flat): Collection
    {
        $tree = collect();
        foreach ($flat as $id => $entity) {
            $parentId = $entity->getParentId();

            if ($parentId !== null && $flat->has($parentId)) {
                $parent = $flat->get($parentId);
                $parent->addItem($entity);
            } else {
                $tree->push($entity);
            }
        }

        return $tree;
    }


    /**
     * Reescribe todas las entidades en el repositorio con la colección proporcionada.
     *
     * @param Collection|null $entities Colección de entidades en forma de árbol para reescribir. Si es null, usa el árbol de entidades actual.
     */
    public function rewriteAllEntities(?Collection $entities = null): void
    {
        if ($entities === null) {
            $entities = $this->getTreeEntitys();
        }
        $this->fpjRepository->rewrite($entities);
    }

    /**
     * Agrega una entidad al contexto y la guarda a través del repositorio.
     *
     * @param FPJEntityInterface $entity La entidad a agregar.
     * @param string|FPJEntityInterface|null $parent El ID o la entidad padre.
     */
    public function addEntity(FPJEntityInterface $entity, string|FPJEntityInterface|null $parent = null): void
    {
        $currentTree = $this->getTreeEntitys();
        $parentId = $parent instanceof FPJEntityInterface ? $parent->getId() : $parent;

        if ($parentId !== null) {
            $updatedTree = $this->addFpEntityRecursive($currentTree, $entity, $parentId);
        } else {
            $currentTree->push($entity);
            $updatedTree = $currentTree;
        }

        $this->treeEntitys = $updatedTree;
        $this->flattenedEntitys = null; // Invalida la caché de entidades aplanadas
        $this->fpjRepository->rewrite($this->treeEntitys);
    }

    /**
     * Elimina una entidad del contexto por su ID y la guarda a través del repositorio.
     *
     * @param string|FPJEntityInterface $entityId El ID o la entidad a eliminar.
     */
    public function removeEntity(string|FPJEntityInterface $entityId): bool
    {
        $idToRemove = $entityId instanceof FPJEntityInterface ? $entityId->getId() : $entityId;
        $currentTree = $this->getTreeEntitys();
        $updatedTree = $this->removeFpEntityRecursive($currentTree, $idToRemove);

        $this->treeEntitys = $updatedTree;
        $this->flattenedEntitys = $this->flattenedEntitys->forget($idToRemove); // Elimina la entidad de la colección aplanada
        $this->fpjRepository->rewrite($this->treeEntitys);
        return true;
    }

    /**
     * Verifica si una entidad existe por su ID.
     *
     * @param string $entityId El ID de la entidad a verificar.
     * @return bool `true` si la entidad existe, `false` en caso contrario.
     */
    public function exists(string $entityId): bool
    {
        return $this->getFlattenedEntitys()->has($entityId);
    }

    /**
     * Agrega recursivamente una nueva entidad a un árbol de entidades.
     *
     * @param Collection $entities Colección de entidades actuales.
     * @param FPJEntityInterface $newEntity La nueva entidad a agregar.
     * @param string $parentId El ID de la entidad padre donde se agregará la nueva entidad.
     * @return Collection La colección de entidades actualizada.
     */
    protected function addFpEntityRecursive(Collection $entities, FPJEntityInterface $newEntity, string $parentId): Collection
    {
        return $entities->map(function ($entity) use ($newEntity, $parentId) {
            if ($entity->getId() === $parentId) {
                $newEntity->setParentId($parentId);
                $newEntity->setLevel($entity->getLevel() + 1);
                $entity->addItem($newEntity);
            }
            if ($entity->getItems()->isNotEmpty()) {
                $entity->setItems(
                    $this->addFpEntityRecursive(collect($entity->getItems()), $newEntity, $parentId)->toArray()
                );
            }
            return $entity;
        });
    }

    /**
     * Elimina recursivamente una entidad y sus hijos de una colección de entidades en forma de árbol.
     *
     * @param Collection $entities Colección de entidades en forma de árbol.
     * @param string $entityId El ID de la entidad a eliminar.
     * @return Collection La colección de entidades sin la entidad eliminada.
     */
    protected function removeFpEntityRecursive(Collection $entities, string $entityId): Collection
    {
        return $entities->reject(function ($entity) use ($entityId) {
            return $entity->getId() === $entityId;
        })->map(function ($entity) use ($entityId) {
            if ($entity->getItems()->isNotEmpty()) {
                $entity->setItems(
                    $this->removeFpEntityRecursive(collect($entity->getItems()), $entityId)->toArray()
                );
            }
            return $entity;
        });
    }
}
