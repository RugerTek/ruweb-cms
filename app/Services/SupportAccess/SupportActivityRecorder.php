<?php

namespace App\Services\SupportAccess;

use App\Models\SupportAudit;
use App\Models\SupportSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Writes support_audit rows (docs/operations/support-access.md#what-is-recorded). What is kept: method, route name,
 * path WITHOUT the query string, the subject the route points at (type, id, a readable label) and the NAMES of the
 * submitted fields. No submitted value is ever stored, sensitive or not, so a password, token or card number typed
 * during support cannot end up in this table.
 */
class SupportActivityRecorder
{
    public const MAX_FIELDS = 60;

    private const MAX_DEPTH = 3;

    /** Framework fields that say nothing about what changed. */
    private const IGNORED_FIELDS = ['_token', '_method'];

    /** Scalar route parameters that qualify a route instead of naming its subject. */
    private const QUALIFIERS = ['locale', 'version', 'review', 'link', 'token'];

    public function record(Request $request, SupportSession $support, ?int $status): void
    {
        $write = ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
        [$type, $id, $label] = $this->subject($request);

        SupportAudit::create([
            'session_id' => $support->id,
            'at' => Carbon::now(),
            'method' => $request->method(),
            'route_name' => $request->route()?->getName(),
            'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 500),
            'subject_type' => $type,
            'subject_id' => $id,
            'subject_label' => $label,
            'changed_fields' => $write ? $this->fieldNames($request) : null,
            'status' => $status,
        ]);
    }

    /**
     * Names of the submitted fields in dot notation (lists collapse to `*`), files included; never values.
     *
     * @return list<string>
     */
    public function fieldNames(Request $request): array
    {
        $names = [];
        $this->collect($request->isJson() ? $request->json()->all() : $request->request->all(), '', 1, $names);
        $this->collect($request->allFiles(), '', 1, $names);
        $names = array_values(array_unique($names));

        return array_slice($names, 0, self::MAX_FIELDS);
    }

    /** @param  list<string>  $names */
    private function collect(array $input, string $prefix, int $depth, array &$names): void
    {
        foreach ($input as $key => $value) {
            if ($prefix === '' && in_array($key, self::IGNORED_FIELDS, true)) {
                continue;
            }
            $segment = is_int($key) ? '*' : Str::limit((string) $key, 60, '');
            $name = $prefix === '' ? $segment : $prefix.'.'.$segment;
            if (is_array($value) && $value !== [] && $depth < self::MAX_DEPTH) {
                $this->collect($value, $name, $depth + 1, $names);
            } else {
                $names[] = mb_substr($name, 0, 120);
            }
            if (count($names) >= self::MAX_FIELDS * 2) {
                return;
            }
        }
    }

    /**
     * What the route points at: the first bound model (its readable name as label), else a `{type}/{id}` pair or a
     * connector key. Captured before the action runs, so a deleted record keeps its name.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function subject(Request $request): array
    {
        $parameters = $request->route()?->parameters() ?? [];
        foreach ($parameters as $value) {
            if ($value instanceof Model) {
                return [Str::snake(class_basename($value)), (string) $value->getKey(), $this->label($value)];
            }
        }
        if (isset($parameters['type'], $parameters['id']) && is_scalar($parameters['type']) && is_scalar($parameters['id'])) {
            return [Str::limit((string) $parameters['type'], 64, ''), Str::limit((string) $parameters['id'], 64, ''), null];
        }
        if (isset($parameters['connector']) && is_string($parameters['connector'])) {
            return ['connector', null, Str::limit($parameters['connector'], 64, '')];
        }
        if (isset($parameters['form'], $parameters['submission']) && is_scalar($parameters['submission'])) {
            return ['submission', (string) $parameters['submission'], null];
        }
        foreach ($parameters as $name => $value) {
            if (is_scalar($value) && ! in_array($name, self::QUALIFIERS, true) && preg_match('/^[0-9]{1,20}$/D', (string) $value) === 1) {
                // `custom-code/{id}`: the resource is the route's segment (admin.custom-code.update → custom_code).
                $type = $name === 'id' ? str_replace('-', '_', (string) (explode('.', (string) $request->route()?->getName())[1] ?? 'item')) : Str::snake((string) $name);

                return [Str::limit($type, 64, ''), (string) $value, null];
            }
        }

        return [null, null, null];
    }

    private function label(Model $model): ?string
    {
        $attributes = $model->getAttributes();
        foreach (['title', 'name', 'label', 'from_path', 'key', 'slug'] as $attribute) {
            if (! array_key_exists($attribute, $attributes)) {
                continue;
            }
            $value = $model->getAttribute($attribute);
            if (is_array($value)) {
                $value = collect($value)->first(fn ($item) => is_string($item) && trim($item) !== '');
            }
            if (is_string($value) && trim($value) !== '') {
                return Str::limit(trim(strip_tags($value)), 120);
            }
        }

        return null;
    }
}
