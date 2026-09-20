<?php

declare(strict_types=1);

namespace App\Bench\Probing;

use App\Bench\Personas\AttributeCatalogue;
use App\Bench\Personas\Persona;
use App\Bench\Scenarios\Scenario;

/**
 * Assembles the state posted to Jev.
 *
 * The shape is identical in every condition — same keys, same order, same nesting —
 * so a difference between conditions cannot be an artefact of the request looking
 * structurally different. Only the contents of the subject block change.
 */
final class StateBuilder
{
    /** @return array<string, mixed> */
    public function build(Scenario $scenario, Persona $persona, Condition $condition, string $variant): array
    {
        [$case, $subject] = $this->parts($scenario, $persona, $condition, $variant);

        return [$scenario->stateKey => $case, $scenario->subjectRole => $subject];
    }

    /**
     * The same state split at its only seam.
     *
     * Everyone facing a scenario faces the identical case; the person is the
     * only thing that changes. Drivers that can cache a prompt prefix need those
     * two apart, because the first half is worth caching across a whole scenario
     * and the second half is the entire experiment.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function parts(Scenario $scenario, Persona $persona, Condition $condition, string $variant): array
    {
        return [
            $scenario->facts($variant, $persona),
            $this->subject($persona, $condition, $scenario->subjectRole),
        ];
    }

    /** @return array<string, mixed> */
    private function subject(Persona $persona, Condition $condition, string $role): array
    {
        $subject = [];

        $subject['reference'] = $condition->showsName()
            ? $persona->fullName()
            : 'The '.$role;

        if ($condition->showsAttributes()) {
            $subject += $this->attributes($persona);
        }

        return $subject;
    }

    /**
     * The attributes as a dossier would list them.
     *
     * A level whose phrase is empty means "not stated" and is left out entirely
     * rather than sent as a blank, because an explicit blank is itself a signal.
     * The three continuous attributes carry both their number and the phrase for
     * it, because Jev's documentation is explicit that it reads numeric formats
     * worse than semantic ones.
     *
     * @return array<string, mixed>
     */
    private function attributes(Persona $persona): array
    {
        $out = [
            'age' => $persona->age.', '.AttributeCatalogue::agePhrase($persona->age),
            'pronouns' => $persona->pronouns(),
            'height' => $persona->heightCm.'cm, '.AttributeCatalogue::heightPhrase($persona->heightCm),
            'build' => $persona->weightKg.'kg, '.AttributeCatalogue::buildPhrase($persona->bmi()),
        ];

        foreach (AttributeCatalogue::ATTRIBUTES as $key => $spec) {
            if ($key === 'name_culture') {
                continue;   // carried by the name, never stated outright
            }

            $phrase = AttributeCatalogue::phrase($key, $persona->get($key), $persona->get('gender'));
            if ($phrase === '') {
                continue;
            }

            $out[$key] = $phrase;
        }

        return $out;
    }
}
