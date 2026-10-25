<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Pet;
use App\Services\ActivityRecorder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActivityLogController extends Controller
{
    public function page()
    {
        $html = file_get_contents(base_path('resources/views/admin/activity-log.html'));
        return response($html)->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'no-store, private');
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:150', 'area' => ['nullable', Rule::in(['all', 'clinic', 'grooming', 'system'])],
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'staff' => 'nullable|integer|min:1', 'type' => ['nullable', Rule::in(array_keys(ActivityRecorder::LABELS))],
            'page' => 'nullable|integer|min:1', 'per_page' => ['nullable', Rule::in([10, 25, 50])],
        ]);
        $from = Carbon::parse($filters['from'] ?? now()->subDays(29)->toDateString())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now()->toDateString())->endOfDay();
        $query = ActivityLog::select(['id', 'action', 'context', 'actor_display', 'area', 'occurred_at', 'pet_id'])
            ->with('pet:pet_id,pet_name')->whereBetween('occurred_at', [$from, $to]);
        if (($filters['area'] ?? 'all') !== 'all') $query->where('area', $filters['area']);
        if (! empty($filters['staff'])) $query->where('actor_id', $filters['staff']);
        if (! empty($filters['type'])) $query->where('action', $filters['type']);
        if ($search = trim($filters['search'] ?? '')) {
            $actions = array_keys(array_filter(ActivityRecorder::LABELS, fn ($label) => mb_stripos($label, $search) !== false));
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function ($q) use ($pattern, $actions) {
                $q->whereIn('action', $actions);
                foreach (['context', 'subject_id', 'actor_display', 'actor_identity'] as $column) {
                    $q->orWhereRaw("{$column} LIKE ? ESCAPE '!'", [$pattern]);
                }
                $q->orWhereHas('pet', fn ($pets) => $pets->whereRaw("pet_name LIKE ? ESCAPE '!'", [$pattern]));
            });
        }
        $page = $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 25);
        $page->getCollection()->transform(fn ($row) => $this->summary($row));
        return response()->json($page)->header('Cache-Control', 'no-store, private');
    }

    public function show(ActivityLog $activity)
    {
        $activity->load('pet:pet_id,pet_name');
        return response()->json($this->summary($activity) + [
            'actor_id' => $activity->actor_id, 'actor_role' => $activity->actor_role,
            'actor_identity' => $activity->actor_identity, 'subject_type' => $activity->subject_type,
            'subject_id' => $activity->subject_id, 'result' => $activity->result, 'changes' => $activity->changes,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function options()
    {
        // Historical identities, including deactivated/renamed actors. No user-table joins.
        $latest = ActivityLog::whereNotNull('actor_id')->whereIn('actor_role', ['Admin', 'Grooming Receptionist', 'Clinic Receptionist', 'Veterinarian', 'Staff'])
            ->selectRaw('MAX(id) as id')->groupBy('actor_id');
        $actors = ActivityLog::whereIn('id', $latest)->select('actor_id', 'actor_display')->orderBy('actor_display')->get();
        return response()->json(['staff' => $actors, 'types' => ActivityRecorder::LABELS])->header('Cache-Control', 'no-store, private');
    }

    private function summary(ActivityLog $row): array
    {
        $petName = $row->pet?->pet_name;
        $context = $petName && $row->context !== $petName ? $petName.' · '.$row->context : $row->context;
        return ['id' => $row->id, 'title' => ActivityRecorder::LABELS[$row->action], 'context' => $context,
            'actor_display' => $row->actor_display, 'area' => $row->area,
            'occurred_at' => $row->occurred_at->toIso8601String()];
    }

    public function recent(Request $request, ActivityRecorder $recorder)
    {
        $request->validate(['area' => ['nullable', Rule::in(['all', 'clinic', 'grooming'])]]);
        $user = $request->user();
        $default = $user->role === 'admin' ? 'all' : ($user->staff_type === 'grooming' ? 'grooming' : 'clinic');
        $area = $request->query('area', $default);
        abort_if($user->isVeterinarian() && $area !== 'clinic', 403);
        $rows = $recorder->available() ? ActivityLog::select(['id', 'action', 'context', 'area', 'occurred_at', 'pet_id'])
            ->where('operational', true)->whereIn('area', ['clinic', 'grooming'])
            ->whereIn('action', ActivityRecorder::OPERATIONAL_ACTIONS)
            ->when($area !== 'all', fn ($q) => $q->where('area', $area))
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(6)->get() : collect();
        // Batch pet context; never load clinical records, actor data or financial metadata for a feed.
        $petIds = $rows->pluck('pet_id')->filter()->unique();
        $pets = $petIds->isNotEmpty() ? Pet::whereIn('pet_id', $petIds)->pluck('pet_name', 'pet_id') : collect();
        $data = $rows->map(fn ($row) => [
            'id' => 'activity-'.$row->id,
            'type' => match ($row->action) { 'checked_in' => 'queued', 'grooming_started', 'consultation_started' => 'in_progress',
                'grooming_completed', 'consultation_completed' => 'completed', default => 'activity' },
            'title' => $row->action === 'checked_in' && $row->area === 'clinic' ? 'Clinic check-in' : ActivityRecorder::LABELS[$row->action],
            'subtitle' => isset($pets[$row->pet_id]) ? $pets[$row->pet_id].' · '.ucfirst($row->area) : $row->context,
            'createdAt' => $row->occurred_at->toIso8601String(),
        ])->all();
        return response()->json(['area' => $area, 'canFilter' => ! $user->isVeterinarian(), 'recentActivity' => $data])->header('Cache-Control', 'no-store, private');
    }
}
