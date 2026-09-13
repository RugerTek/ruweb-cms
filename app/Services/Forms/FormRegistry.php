<?php

namespace App\Services\Forms;

use Illuminate\Database\Eloquent\Model;

/**
 * The code-owned list of public forms, read from `config('cms.forms')`.
 *
 * Every form that stores submissions registers here so the admin can show
 * one tab per form under "Submissions". See docs/forms.md.
 */
class FormRegistry
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return (array) config('cms.forms', []);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /** @return array<string, mixed> */
    public function get(string $key): array
    {
        return $this->all()[$key] ?? abort(404);
    }

    /** @return class-string<Model> */
    public function modelClass(string $key): string
    {
        return $this->get($key)['model'];
    }

    public function query(string $key)
    {
        return $this->modelClass($key)::query();
    }

    /** @return array<string, int> form key => unread submissions */
    public function unreadCounts(): array
    {
        $counts = [];

        foreach (array_keys($this->all()) as $key) {
            $counts[$key] = $this->query($key)->whereNull('read_at')->count();
        }

        return $counts;
    }

    public function unreadTotal(): int
    {
        return array_sum($this->unreadCounts());
    }

    public function firstKey(): ?string
    {
        return array_key_first($this->all());
    }
}
