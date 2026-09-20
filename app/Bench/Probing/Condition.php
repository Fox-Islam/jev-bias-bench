<?php

declare(strict_types=1);

namespace App\Bench\Probing;

/**
 * How much of the person the model is allowed to see.
 *
 * Running the same case under all four separates the two channels a real
 * integration leaks through. `NameOnly` minus `Blind` is what a name alone does —
 * the situation of anyone who passes a customer record to a model. `AttributesOnly`
 * minus `Blind` is what stating the attributes does, which is what a form that
 * collects diversity data produces. `Blind` is the control: the same case with
 * nobody attached, which also gives the noise floor every other number is judged
 * against.
 */
enum Condition: string
{
    case Blind = 'blind';
    case NameOnly = 'name_only';
    case AttributesOnly = 'attributes_only';
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::Blind => 'No person attached',
            self::NameOnly => 'Name only',
            self::AttributesOnly => 'Attributes, no name',
            self::Full => 'Name and attributes',
        };
    }

    public function showsName(): bool
    {
        return $this === self::NameOnly || $this === self::Full;
    }

    public function showsAttributes(): bool
    {
        return $this === self::AttributesOnly || $this === self::Full;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
