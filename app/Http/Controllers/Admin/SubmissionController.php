<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Forms\FormRegistry;
use App\Services\Forms\SubmissionOperations;
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
        protected FormRegistry $forms,
        protected SubmissionOperations $operations
    ) {}

    public function index(Request $request, string $form): View
    {
        $query = $this->operations->query($request->user(), $form, $request->query());
        $status = $request->query('status');
        $search = trim((string) $request->query('search', ''));
        $from = $request->query('from');
        $to = $request->query('to');
        $workflow = $request->query('workflow');
        $assignee = $request->query('assignee');
        $notification = $request->query('notification');

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

    public function show(Request $request, string $form, int $submission): View
    {
        $this->operations->write($request->user(), $form, $submission, 'mark_read', []);
        $record = $this->operations->record($request->user(), $form, $submission);

        return view('admin.submissions.show', [
            'form' => $form,
            'submission' => $record->load(['assignee', 'notes.author']),
            'assignees' => User::whereIn('role', ['admin', 'editor'])->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, string $form, int $submission): RedirectResponse
    {
        $this->operations->write($request->user(), $form, $submission, 'update_workflow', $request->all());

        return back()->with('success', __('submissions.saved'));
    }

    public function note(Request $request, string $form, int $submission): RedirectResponse
    {
        $this->operations->write($request->user(), $form, $submission, 'add_note', $request->all());

        return back()->with('success', __('submissions.note_added'));
    }

    public function resend(Request $request, string $form, int $submission): RedirectResponse
    {
        $queued = $this->operations->write($request->user(), $form, $submission, 'retry_notification', $request->all())['queued'];

        return back()->with($queued ? 'success' : 'error', __('submissions.'.($queued ? 'notification_queued' : 'notification_unavailable')));
    }

    public function destroy(Request $request, string $form, int $submission): RedirectResponse
    {
        $this->operations->write($request->user(), $form, $submission, 'delete', $request->all());

        return redirect()
            ->route('admin.submissions.index', ['form' => $form])
            ->with('success', __('admin.submission_deleted'));
    }
}
