<?php

declare(strict_types=1);

namespace App\Bench\Personas;

/**
 * One generated person.
 *
 * In a counterfactual cohort a person is also a position in a design: which base
 * they were built from, and which single attribute was swapped away from that
 * base. An anchor is the base itself, and every swapped person is compared with
 * their own anchor rather than with the cohort average.
 */
final readonly class Persona
{
    /** @param array<string, string> $attributes */
    public function __construct(
        public int $idx,
        public string $firstName,
        public string $lastName,
        public array $attributes,
        public int $age,
        public int $heightCm,
        public int $weightKg,
        public int $baseIndex = 0,
        public bool $isAnchor = false,
        public ?string $swappedAttribute = null,
        public ?string $swappedLevel = null,
    ) {}

    public function fullName(): string
    {
        return $this->firstName.' '.$this->lastName;
    }

    public function get(string $attribute): string
    {
        return $this->attributes[$attribute] ?? '';
    }

    public function pronouns(): string
    {
        return NamePools::PRONOUNS[$this->get('gender')] ?? 'they/them';
    }

    public function bmi(): float
    {
        return round($this->weightKg / (($this->heightCm / 100) ** 2), 1);
    }

    /**
     * Everything the analysis can group by: the drawn attributes plus the bands
     * derived from the continuous ones.
     *
     * @return array<string, string>
     */
    public function factors(): array
    {
        return $this->attributes + [
            'age_band' => AttributeCatalogue::ageBand($this->age),
            'height_band' => AttributeCatalogue::heightBand($this->heightCm),
            'bmi_band' => AttributeCatalogue::bmiBand($this->bmi()),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'idx' => $this->idx,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'full_name' => $this->fullName(),
            'pronouns' => $this->pronouns(),
            'age' => $this->age,
            'height_cm' => $this->heightCm,
            'weight_kg' => $this->weightKg,
            'bmi' => $this->bmi(),
            'base_index' => $this->baseIndex,
            'is_anchor' => $this->isAnchor,
            'swapped_attribute' => $this->swappedAttribute,
            'swapped_level' => $this->swappedLevel,
            'attributes' => $this->attributes,
            'factors' => $this->factors(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            idx: (int) $data['idx'],
            firstName: (string) $data['first_name'],
            lastName: (string) $data['last_name'],
            attributes: (array) $data['attributes'],
            age: (int) $data['age'],
            heightCm: (int) $data['height_cm'],
            weightKg: (int) $data['weight_kg'],
            baseIndex: (int) ($data['base_index'] ?? 0),
            isAnchor: (bool) ($data['is_anchor'] ?? false),
            swappedAttribute: $data['swapped_attribute'] ?? null,
            swappedLevel: $data['swapped_level'] ?? null,
        );
    }

    /** A copy with one attribute moved to another level, and the name redrawn if it has to be. */
    public function withAttribute(int $idx, string $attribute, string $level, Randomiser $names): self
    {
        if (AttributeCatalogue::isNumeric($attribute)) {
            return $this->withNumeric($idx, $attribute, $level);
        }

        $attributes = $this->attributes;
        $attributes[$attribute] = $level;

        $first = $this->firstName;
        $last = $this->lastName;

        // The name is the carrier for culture and for gender, so those two swaps
        // have to redraw it. Every other swap keeps the name byte for byte, which
        // is what makes the pair a clean comparison.
        if ($attribute === 'name_culture' || $attribute === 'gender') {
            [$first, $last] = $names->name($attributes['name_culture'], $attributes['gender']);
        }

        return new self(
            idx: $idx,
            firstName: $first,
            lastName: $last,
            attributes: $attributes,
            age: $this->age,
            heightCm: $this->heightCm,
            weightKg: $this->weightKg,
            baseIndex: $this->baseIndex,
            isAnchor: false,
            swappedAttribute: $attribute,
            swappedLevel: $level,
        );
    }

    /**
     * A copy at a different age, height or build.
     *
     * A build swap keeps the person's own height and moves the weight to hit the
     * target BMI, so the pair differs in build alone rather than in build and
     * height at once.
     */
    private function withNumeric(int $idx, string $attribute, string $level): self
    {
        $target = AttributeCatalogue::NUMERIC_SWEEP[$attribute]['values'][$level];

        $age = $this->age;
        $height = $this->heightCm;
        $weight = $this->weightKg;

        match ($attribute) {
            'age_band' => $age = (int) $target,
            'height_band' => [$height, $weight] = [(int) $target, (int) round($this->bmi() * (((int) $target) / 100) ** 2)],
            'bmi_band' => $weight = (int) round(((float) $target) * ($this->heightCm / 100) ** 2),
            default => null,
        };

        return new self(
            idx: $idx,
            firstName: $this->firstName,
            lastName: $this->lastName,
            attributes: $this->attributes,
            age: $age,
            heightCm: $height,
            weightKg: $weight,
            baseIndex: $this->baseIndex,
            isAnchor: false,
            swappedAttribute: $attribute,
            swappedLevel: $level,
        );
    }
}
