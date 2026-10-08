<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

/**
 * The models list entries this client has read, by model name, so the local
 * checks can use the limits only the list states. Empty until `models()` is
 * called; a limit the client does not hold is left to the server.
 *
 * @internal
 */
final class Held
{
    /** @var array<string, array<string, mixed>> */
    private array $models = [];

    /**
     * @param list<array<array-key, mixed>> $entries
     */
    public function keep(array $entries): void
    {
        $models = [];
        foreach ($entries as $entry) {
            if (is_string($entry['name'] ?? null)) {
                /** @var array<string, mixed> $entry */
                $models[$entry['name']] = $entry;
            }
        }
        $this->models = $models;
    }

    /**
     * The held entry of a model, with its name under `model`, or [].
     *
     * @return array<string, mixed>
     */
    public function limits(string $model): array
    {
        $entry = $this->models[$model] ?? null;

        return $entry === null ? [] : ['model' => $model] + $entry;
    }
}
