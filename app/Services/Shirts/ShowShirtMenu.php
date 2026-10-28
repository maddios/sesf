<?php

namespace App\Services\Shirts;

/**
 * Every shirt type for one event (ShowShirtCatalog::forShow). An event without show_shirt_type rows is not
 * configured and offers every type at its default price (legacy behaviour); once configured it offers only
 * the selected types.
 */
final readonly class ShowShirtMenu
{
    /** @var array<int, ShirtOffer> type id => offer, every shirt type in id order */
    private array $types;

    public bool $configured;

    /**
     * @param  list<ShirtOffer>  $types
     */
    public function __construct(array $types)
    {
        $byId = [];
        $configured = false;
        foreach ($types as $type) {
            $byId[$type->id] = $type;
            $configured = $configured || $type->selected;
        }
        $this->types = $byId;
        $this->configured = $configured;
    }

    /**
     * Every shirt type, offered or not.
     *
     * @return list<ShirtOffer>
     */
    public function all(): array
    {
        return array_values($this->types);
    }

    /**
     * The types members can order at this event, in id order.
     *
     * @return list<ShirtOffer>
     */
    public function offered(): array
    {
        return array_values(array_filter($this->types, fn (ShirtOffer $type): bool => $this->isOffered($type->id)));
    }

    public function isOffered(int $typeId): bool
    {
        $type = $this->types[$typeId] ?? null;

        return $type !== null && (! $this->configured || $type->selected);
    }

    public function find(int $typeId): ?ShirtOffer
    {
        return $this->types[$typeId] ?? null;
    }

    /** Effective price of a type at this event, offered or not; null for an unknown type. */
    public function priceOf(int $typeId): ?string
    {
        return $this->find($typeId)?->price;
    }

    /**
     * Unit price of an order line: its snapshot, else the type's effective price here, else 0 (no type row; legacy
     * crashed on it, see docs/pricing.md).
     */
    public function linePrice(?string $snapshot, int $typeId): string
    {
        return $snapshot ?? $this->priceOf($typeId) ?? '0.00';
    }
}
