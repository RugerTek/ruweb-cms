<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Forms\FormRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin "Submissions": one tab per form registered in `config('cms.forms')`.
 * The controller is shared; each form brings its own column/row/detail
 * partials under `resources/views/admin/submissions/{form}/`. See docs/content/forms.md.
 */
class SubmissionController extends Controller
{
    public function __construct(
        protected FormRegistry $forms
    ) {}

    public function index(Request $request, string $form): View
    {
        $config = $this->forms->get($form);
        $query = $this->forms->query($form)->latest('id');

        $status = in_array($request->query('status'), ['new', 'read'], true) ? $request->query('status') : null;
        $search = trim((string) $request->query('search', ''));
        $from = $request->query('from');
        $to = $request->query('to');

        if ($status === 'new') {
            $query->whereNull('read_at');
        } elseif ($status === 'read') {
            $query->whereNotNull('read_at');
        }

        if ($search !== '') {
            $query->where(function ($q) use ($config, $search) {
                foreach ((array) ($config['searchable'] ?? []) as $column) {
                    $q->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        if ($from && strtotime($from)) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to && strtotime($to)) {
            $query->whereDate('created_at', '<=', $to);
        }

        return view('admin.submissions.index', [
            'form' => $form,
            'forms' => $this->forms->all(),
            'unread' => $this->forms->unreadCounts(),
            'totalCount' => $this->forms->query($form)->count(),
            'submissions' => $query->paginate(20)->withQueryString(),
            'currentStatus' => $status,
            'filters' => ['search' => $search, 'from' => $from, 'to' => $to],
        ]);
    }

    public function show(string $form, int $submission): View
    {
        $this->forms->get($form);
        $record = $this->forms->query($form)->findOrFail($submission);

        if (method_exists($record, 'markRead')) {
            $record->markRead();
        }

        return view('admin.submissions.show', [
            'form' => $form,
            'submission' => $record,
        ]);
    }

    public function destroy(string $form, int $submission): RedirectResponse
    {
        $this->forms->get($form);
        $this->forms->query($form)->findOrFail($submission)->delete();

        return redirect()
            ->route('admin.submissions.index', ['form' => $form])
            ->with('success', __('admin.submission_deleted'));
    }
}
