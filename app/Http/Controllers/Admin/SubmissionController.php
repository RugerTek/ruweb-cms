<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Forms\FormRegistry;
use App\Services\Forms\SubmissionNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        $workflow = in_array($request->query('workflow'), ['new', 'in_progress', 'resolved'], true) ? $request->query('workflow') : null;
        $assignee = $request->query('assignee');
        $notification = in_array($request->query('notification'), ['queued', 'retrying', 'sending', 'failed', 'sent', 'skipped'], true) ? $request->query('notification') : null;
        if ($workflow) {
            $query->where('workflow_status', $workflow);
        }
        if ($assignee === 'unassigned') {
            $query->whereNull('assigned_to');
        } elseif (ctype_digit((string) $assignee)) {
            $query->where('assigned_to', (int) $assignee);
        }
        if ($notification) {
            $query->where('notification_status', $notification);
        }
        $query->with('assignee');

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
            'filters' => ['search' => $search, 'from' => $from, 'to' => $to, 'workflow' => $workflow, 'assignee' => $assignee, 'notification' => $notification],
            'assignees' => User::whereIn('role', ['admin', 'editor'])->orderBy('name')->get(),
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
            'submission' => $record->load(['assignee', 'notes.author']),
            'assignees' => User::whereIn('role', ['admin', 'editor'])->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, string $form, int $submission): RedirectResponse
    {
        $record = $this->forms->query($form)->findOrFail($submission);
        $data = $request->validate([
            'workflow_status' => ['required', Rule::in(['new', 'in_progress', 'resolved'])],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->whereIn('role', ['admin', 'editor'])],
        ]);
        $record->update($data);

        return back()->with('success', __('submissions.saved'));
    }

    public function note(Request $request, string $form, int $submission): RedirectResponse
    {
        $record = $this->forms->query($form)->findOrFail($submission);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $record->notes()->create(['body' => $data['body'], 'user_id' => $request->user()->id]);

        return back()->with('success', __('submissions.note_added'));
    }

    public function resend(string $form, int $submission, SubmissionNotifier $notifier): RedirectResponse
    {
        $record = $this->forms->query($form)->findOrFail($submission);
        $queued = $notifier->notify($record, $record->page, $this->forms->get($form)['section'] ?? 'form');

        return back()->with($queued ? 'success' : 'error', __('submissions.'.($queued ? 'notification_queued' : 'notification_unavailable')));
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
