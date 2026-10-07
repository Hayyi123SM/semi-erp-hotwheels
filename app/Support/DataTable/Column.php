<?php

namespace App\Support\DataTable;

use Closure;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Column
{
    public const FORMATS = ['text', 'rupiah', 'number', 'date', 'datetime', 'enum', 'status', 'ownership'];

    public const ALIGNMENTS = ['left', 'center', 'right'];

    public const CARDS = ['title', 'subtitle', 'meta', 'badge', 'price', 'footer', 'hidden'];

    /**
     * The one place cell padding is defined.
     *
     * The head and the body used to declare their own spacing, and the body
     * declared none: the header text sat 16px inside its cell while the row text
     * touched the border, so no two columns lined up and every row read as
     * cramped. Both branches now take their rhythm from here.
     */
    public const CELL_PADDING = 'px-4 py-3 xl:px-6';

    /**
     * How reluctantly a column gives up horizontal space inside the table.
     *
     * Below md the table is replaced by cards, so a priority only ever changes
     * something between md and 2xl:
     *
     *   1  always shown
     *   2  hidden until xl (1280px)
     *   3  hidden until 2xl (1536px)
     */
    public const PRIORITIES = [1, 2, 3];

    public function __construct(
        public string $key,
        public string $label,
        public string $align = 'left',
        public ?string $sort = null,
        public ?string $format = null,
        public ?Closure $render = null,
        public ?Closure $value = null,
        public ?string $component = null,
        public ?Closure $visible = null,
        public ?string $card = null,
        public ?string $class = null,
        public ?string $headClass = null,
        public bool $mono = false,
        public bool $exportable = true,
        public array $componentData = [],
        public ?int $priority = null,
    ) {
        $this->guard('align', $align, self::ALIGNMENTS);
        $this->guard('priority', $priority, self::PRIORITIES);
    }

    public static function make(
        string $key,
        ?string $label = null,
        string $align = 'left',
        ?string $format = null,
        ?string $sort = null,
    ): self {
        $column = new self($key, $label ?? Str::headline(str_replace('.', ' ', $key)), $align);

        return $column
            ->whenFormat($format)
            ->whenSortable($sort);
    }

    public function align(string $align): static
    {
        $this->guard('align', $align, self::ALIGNMENTS);

        return $this->tap(fn (self $c) => $c->align = $align);
    }

    /**
     * Mark the column as sortable. The sorted database column differs from the
     * value key when a relation is involved, e.g. Column::make('product.name')->sortable('products.name').
     */
    public function sortable(?string $column = null): static
    {
        $column ??= str_contains($this->key, '.') ? null : $this->key;

        return $this->tap(fn (self $c) => $c->sort = $column);
    }

    public function format(string $format): static
    {
        $this->guard('format', $format, self::FORMATS);

        return $this->tap(fn (self $c) => $c->format = $format);
    }

    private function whenFormat(?string $format): static
    {
        return $format === null ? $this : $this->format($format);
    }

    private function whenSortable(?string $sort): static
    {
        return $sort === null ? $this : $this->sortable($sort);
    }

    public function render(Closure $render): static
    {
        return $this->tap(fn (self $c) => $c->render = $render);
    }

    /**
     * Resolve the cell value from the row, for derived columns such as a status
     * that depends on a flag. Keeps the value usable by format, component and
     * export alike instead of re-implementing it inside a render closure.
     */
    public function value(Closure $value): static
    {
        return $this->tap(fn (self $c) => $c->value = $value);
    }

    public function resolvedValue(object $row): mixed
    {
        $value = $this->value instanceof Closure
            ? ($this->value)($row, $this)
            : data_get($row, $this->key);

        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    /**
     * Delegate the cell to an anonymous component, e.g. ->component('ui.row-actions').
     * The component receives :row, :column, :value and :data, so it must declare
     *
     * @props(['row' => null, 'column' => null, 'value' => null, 'data' => []]).
     */
    public function component(string $component, array $data = []): static
    {
        return $this->tap(function (self $c) use ($component, $data) {
            $c->component = $component;
            $c->componentData = $data;
        });
    }

    /**
     * The component name when it can actually be rendered, so a typo degrades to
     * the plain cell instead of taking the whole page down.
     */
    public function resolvedComponent(): ?string
    {
        if (blank($this->component)) {
            return null;
        }

        if (class_exists('App\\View\\Components\\'.Str::studly($this->component))
            || View::exists('components.'.$this->component)) {
            return $this->component;
        }

        foreach (Blade::getAnonymousComponentPaths() as $path) {
            $name = Str::startsWith($this->component, $path['prefix'].'::')
                ? Str::after($this->component, '::')
                : $this->component;

            if (View::exists($path['prefixHash'].'::'.$name)) {
                return $this->component;
            }
        }

        return null;
    }

    /**
     * Hide the column unless the callback returns true. Enforced server side so
     * sensitive columns cannot leak through the view or a future export.
     */
    public function visible(?Closure $visible): static
    {
        return $this->tap(fn (self $c) => $c->visible = $visible);
    }

    public function card(string $card): static
    {
        $this->guard('card', $card, self::CARDS);

        return $this->tap(fn (self $c) => $c->card = $card);
    }

    /**
     * Defer this column until the viewport is wide enough to show it properly.
     *
     * Rendered as CSS rather than filtered server side: the server cannot know
     * the viewport, and hiding the cell in PHP would also drop it out of the
     * sort headers, the aria-sort wiring and any future export.
     *
     * This is a presentation concern only. Sensitive columns must still use
     * ->visible(), which is enforced server side.
     */
    public function priority(int $level): static
    {
        $this->guard('priority', $level, self::PRIORITIES);

        return $this->tap(fn (self $c) => $c->priority = $level);
    }

    /** The class that hides this cell until its breakpoint is reached. */
    public function responsiveClass(): string
    {
        return match ($this->priority) {
            2 => 'hidden xl:table-cell',
            3 => 'hidden 2xl:table-cell',
            default => '',
        };
    }

    /** Where this column lands in the mobile card, defaulting to a label/value pair. */
    public function cardRole(): string
    {
        return $this->card ?? 'meta';
    }

    /** Columns ordered for the card: title, subtitle, badge, price, then meta, then footer. */
    public static function cardOrder(string $role): int
    {
        $order = ['title', 'badge', 'price', 'subtitle', 'meta', 'footer'];

        $index = array_search($role, $order, true);

        return $index === false ? count($order) : $index;
    }

    public function class(?string $class): static
    {
        return $this->tap(fn (self $c) => $c->class = $class);
    }

    public function headClass(?string $headClass): static
    {
        return $this->tap(fn (self $c) => $c->headClass = $headClass);
    }

    public function mono(bool $mono = true): static
    {
        return $this->tap(fn (self $c) => $c->mono = $mono);
    }

    public function notExportable(): static
    {
        return $this->tap(fn (self $c) => $c->exportable = false);
    }

    public function isSortable(): bool
    {
        return $this->sort !== null;
    }

    public function isVisibleFor(?object $user): bool
    {
        return $this->visible === null || (bool) ($this->visible)($user);
    }

    public function cellClass(): string
    {
        return trim(implode(' ', array_filter([
            self::CELL_PADDING,
            'text-body-sm',
            'align-middle',
            $this->align === 'left' ? 'text-left' : 'text-'.$this->align,
            $this->align === 'right' ? 'tabular-nums' : null,
            $this->mono ? 'font-mono text-sku' : null,
            $this->class,
            $this->responsiveClass(),
        ])));
    }

    public function headClassResolved(): string
    {
        return trim(implode(' ', array_filter([
            self::CELL_PADDING,
            'font-semibold whitespace-nowrap',
            $this->align === 'left' ? 'text-left' : 'text-'.$this->align,
            $this->headClass,
            $this->responsiveClass(),
        ])));
    }

    /**
     * @param  array<int|string, int|string>  $allowed
     */
    private function guard(string $attribute, string|int|null $value, array $allowed): void
    {
        if ($value === null || in_array($value, $allowed, true)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Column [%s] has invalid %s [%s]. Allowed: %s.',
            $this->key,
            $attribute,
            $value,
            implode(', ', $allowed),
        ));
    }

    private function tap(Closure $callback): static
    {
        $column = clone $this;
        $callback($column);

        return $column;
    }
}
