<?php

namespace App\Http\Controllers;

use App\Models\TrainingCourse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminTrainingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => TrainingCourse::withCount(['lessons', 'questions', 'assignedUsers', 'progress'])
                ->with('lessons', 'questions', 'assignedUsers:id')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (TrainingCourse $course) => $this->present($course)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $course = DB::transaction(function () use ($data): TrainingCourse {
            $course = TrainingCourse::create(collect($data)->except(['lessons', 'questions'])->all() + ['assign_all_staff' => false]);
            $this->replaceContent($course, $data);
            return $course;
        });

        return response()->json(['data' => $this->present($course->load('lessons', 'questions', 'assignedUsers:id')->loadCount(['lessons', 'questions', 'assignedUsers', 'progress']))], 201);
    }

    public function update(Request $request, TrainingCourse $course): JsonResponse
    {
        $data = $request->validate($this->rules());
        if (($request->exists('lessons') || $request->exists('questions')) && $course->progress()->exists()) {
            throw ValidationException::withMessages([
                'content' => 'Course lessons and quiz cannot be changed after staff have started the course.',
            ]);
        }

        DB::transaction(function () use ($course, $data): void {
            $course->update(collect($data)->except(['lessons', 'questions'])->all());
            if (array_key_exists('lessons', $data) || array_key_exists('questions', $data)) {
                $this->replaceContent($course, $data);
            }
        });

        return response()->json(['data' => $this->present($course->fresh()->load('lessons', 'questions', 'assignedUsers:id')->loadCount(['lessons', 'questions', 'assignedUsers', 'progress']))]);
    }

    public function destroy(TrainingCourse $course): JsonResponse
    {
        if ($course->progress()->exists()) {
            return response()->json(['message' => 'This course has staff learning records and cannot be deleted. Deactivate it to keep their history.'], 409);
        }

        $course->delete();

        return response()->json(['message' => 'Course deleted.']);
    }

    public function assign(Request $request, TrainingCourse $course): JsonResponse
    {
        $data = $request->validate(['user_ids' => ['required', 'array', 'max:500'], 'user_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')]]);
        $users = User::whereIn('id', $data['user_ids'])->get();
        if ($users->contains(fn (User $user) => ! $user->isStaff())) {
            throw ValidationException::withMessages(['user_ids' => 'Choose staff accounts only.']);
        }

        DB::transaction(function () use ($course, $users, $request): void {
            $course->assignedUsers()->sync($users->mapWithKeys(fn (User $user) => [
                $user->id => ['assigned_by' => $request->user()->id],
            ])->all());
            $course->update(['assign_all_staff' => false]);
        });

        return response()->json(['data' => $this->present($course->fresh()->load('lessons', 'questions', 'assignedUsers:id')->loadCount(['lessons', 'questions', 'assignedUsers', 'progress']))]);
    }

    private function rules(bool $contentRequired = false): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:80'],
            'summary' => ['required', 'string', 'max:5000'],
            'estimated_minutes' => ['required', 'integer', 'between:1,65535'],
            'pass_mark' => ['required', 'integer', 'between:1,100'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,65535'],
            'lessons' => [$contentRequired ? 'required' : 'sometimes', 'array', 'min:1', 'max:100'],
            'lessons.*.title' => ['required', 'string', 'max:150'],
            'lessons.*.body' => ['required', 'string', 'max:20000'],
            'questions' => [$contentRequired ? 'required' : 'sometimes', 'array', 'min:1', 'max:100'],
            'questions.*.prompt' => ['required', 'string', 'max:2000'],
            'questions.*.options' => ['required', 'array', 'size:4'],
            'questions.*.options.*' => ['required', 'string', 'max:500'],
            'questions.*.correct_option' => ['required', 'integer', 'between:0,3'],
        ];
    }

    private function replaceContent(TrainingCourse $course, array $data): void
    {
        if (array_key_exists('lessons', $data)) {
            $course->lessons()->delete();
            foreach ($data['lessons'] as $index => $lesson) {
                $course->lessons()->create([...$lesson, 'sort_order' => $index]);
            }
        }
        if (array_key_exists('questions', $data)) {
            $course->questions()->delete();
            foreach ($data['questions'] as $index => $question) {
                $course->questions()->create([...$question, 'sort_order' => $index]);
            }
        }
    }

    private function present(TrainingCourse $course): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'category' => $course->category,
            'summary' => $course->summary,
            'estimated_minutes' => $course->estimated_minutes,
            'pass_mark' => $course->pass_mark,
            'due_date' => $course->due_date?->toDateString(),
            'is_active' => $course->is_active,
            'sort_order' => $course->sort_order,
            'assign_all_staff' => $course->assign_all_staff,
            'assigned_count' => $course->assigned_users_count,
            'progress_count' => $course->progress_count,
            'assigned_ids' => $course->assignedUsers->pluck('id')->values(),
            'lesson_count' => $course->lessons_count,
            'lessons' => $course->lessons->map(fn ($lesson) => ['title' => $lesson->title, 'body' => $lesson->body])->values(),
            'question_count' => $course->questions_count,
            'questions' => $course->questions->map(fn ($question) => [
                'prompt' => $question->prompt,
                'options' => $question->options,
                'correct_option' => $question->correct_option,
            ])->values(),
        ];
    }
}
