<?php

namespace App\Http\Controllers;

use App\Models\TrainingCourse;
use App\Models\TrainingCourseAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TrainingCourseController extends Controller
{
    private const STAFF_ROLES = ['admin', 'event_admin', 'manager', 'officer_3', 'officer_2', 'officer_1', 'officer_0'];

    public function staff(Request $request): JsonResponse
    {
        $staff = User::query()
            ->whereIn('role', self::STAFF_ROLES)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        return response()->json(['data' => $staff]);
    }

    public function index(): JsonResponse
    {
        $courses = TrainingCourse::query()
            ->withCount('assignments')
            ->with('assignments:id,training_course_id,user_id,status,score')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TrainingCourse $course) => $this->adminCourse($course));

        return response()->json(['data' => $courses]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedCourse($request);
        $course = DB::transaction(function () use ($request, $data): TrainingCourse {
            $staffIds = $data['staff_ids'];
            unset($data['staff_ids']);
            $course = TrainingCourse::query()->create([...$data, 'created_by' => $request->user()->id]);
            $this->syncAssignments($course, $staffIds, $request->user()->id);

            return $course;
        });

        return response()->json(['data' => $this->adminCourse($course->load('assignments'))], 201);
    }

    public function update(Request $request, TrainingCourse $trainingCourse): JsonResponse
    {
        $data = $this->validatedCourse($request);
        DB::transaction(function () use ($request, $trainingCourse, $data): void {
            $staffIds = $data['staff_ids'];
            unset($data['staff_ids']);
            $trainingCourse->update($data);
            $this->syncAssignments($trainingCourse, $staffIds, $request->user()->id);
        });

        return response()->json(['data' => $this->adminCourse($trainingCourse->fresh()->load('assignments'))]);
    }

    public function assign(Request $request, TrainingCourse $trainingCourse): JsonResponse
    {
        $data = $request->validate([
            'staff_ids' => ['present', 'array', 'max:500'],
            'staff_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
        ]);
        $staffIds = $data['staff_ids'];
        $validStaffCount = User::query()
            ->whereIn('id', $staffIds)
            ->whereIn('role', self::STAFF_ROLES)
            ->count();
        if ($validStaffCount !== count($staffIds)) {
            throw ValidationException::withMessages(['staff_ids' => 'Courses can only be assigned to staff accounts.']);
        }

        DB::transaction(fn () => $this->syncAssignments($trainingCourse, $staffIds, $request->user()->id));

        return response()->json([
            'data' => $this->adminCourse($trainingCourse->fresh()->load('assignments')),
        ]);
    }

    public function destroy(TrainingCourse $trainingCourse): JsonResponse
    {
        $trainingCourse->delete();

        return response()->json(['message' => 'Training course deleted.']);
    }

    public function assigned(Request $request): JsonResponse
    {
        $assignments = TrainingCourseAssignment::query()
            ->where('user_id', $request->user()->id)
            ->with('course:id,title,category,description,content,quiz,due_date,pass_percent')
            ->latest()
            ->get()
            ->map(function (TrainingCourseAssignment $assignment): array {
                $data = $assignment->toArray();
                $course = $assignment->course->toArray();
                $course['quiz'] = array_map(
                    fn (array $question): array => [
                        'question' => $question['question'],
                        'options' => $question['options'],
                    ],
                    $course['quiz'] ?? [],
                );
                $data['course'] = $course;

                return $data;
            });

        return response()->json(['data' => $assignments]);
    }

    public function start(Request $request, TrainingCourseAssignment $assignment): JsonResponse
    {
        abort_unless($assignment->user_id === $request->user()->id, 404);
        if ($assignment->status === 'Pending') {
            $assignment->status = 'In Progress';
            $assignment->started_at = now();
            $assignment->progress = 25;
            $assignment->save();
        }

        return response()->json(['data' => $this->learnerAssignment($assignment->fresh()->load('course'))]);
    }

    public function submitQuiz(Request $request, TrainingCourseAssignment $assignment): JsonResponse
    {
        abort_unless($assignment->user_id === $request->user()->id, 404);
        abort_if($assignment->status === 'Completed', 409, 'This course is already completed.');
        $quiz = $assignment->course->quiz;
        $data = $request->validate([
            'answers' => ['required', 'array', 'size:' . count($quiz)],
            'answers.*' => ['required', 'integer', 'min:0'],
        ]);
        $data['answers'] = array_values($data['answers']);
        foreach ($quiz as $index => $question) {
            if ($data['answers'][$index] >= count($question['options'])) {
                throw ValidationException::withMessages(["answers.$index" => 'Choose one of the listed answers.']);
            }
        }

        $correct = 0;
        foreach ($quiz as $index => $question) {
            if (($data['answers'][$index] ?? null) === $question['answer']) {
                $correct++;
            }
        }
        $score = count($quiz) > 0 ? (int) round($correct / count($quiz) * 100) : 0;
        $passed = $score >= $assignment->course->pass_percent;
        $assignment->score = $score;
        $assignment->progress = $passed ? 100 : 75;
        $assignment->status = $passed ? 'Completed' : 'In Progress';
        $assignment->completed_at = $passed ? now() : null;
        $assignment->save();

        return response()->json([
            'data' => $this->learnerAssignment($assignment->fresh()->load('course')),
            'passed' => $passed,
            'score' => $score,
            'pass_percent' => $assignment->course->pass_percent,
        ]);
    }

    private function validatedCourse(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'category' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:1000'],
            'content' => ['required', 'string', 'max:30000'],
            'quiz' => ['required', 'array', 'min:1', 'max:30'],
            'quiz.*.question' => ['required', 'string', 'max:1000'],
            'quiz.*.options' => ['required', 'array', 'min:2', 'max:6'],
            'quiz.*.options.*' => ['required', 'string', 'max:300'],
            'quiz.*.answer' => ['required', 'integer', 'min:0'],
            'due_date' => ['nullable', 'date'],
            'pass_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'staff_ids' => ['present', 'array', 'max:500'],
            'staff_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
        ]);

        $staffIds = $data['staff_ids'];
        $validStaffCount = User::query()->whereIn('id', $staffIds)->whereIn('role', self::STAFF_ROLES)->count();
        if ($validStaffCount !== count($staffIds)) {
            throw ValidationException::withMessages(['staff_ids' => 'Courses can only be assigned to staff accounts.']);
        }
        foreach ($data['quiz'] as $index => $question) {
            if ($question['answer'] >= count($question['options'])) {
                throw ValidationException::withMessages(["quiz.$index.answer" => 'Choose a valid correct answer for each question.']);
            }
        }

        return $data;
    }

    private function syncAssignments(TrainingCourse $course, array $staffIds, int $assignedBy): void
    {
        if ($staffIds === []) {
            $course->assignments()->delete();

            return;
        }

        $course->assignments()->whereNotIn('user_id', $staffIds)->delete();
        foreach ($staffIds as $staffId) {
            TrainingCourseAssignment::query()->firstOrCreate(
                ['training_course_id' => $course->id, 'user_id' => $staffId],
                ['assigned_by' => $assignedBy],
            );
        }
    }

    private function adminCourse(TrainingCourse $course): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'category' => $course->category,
            'description' => $course->description,
            'content' => $course->content,
            'quiz' => $course->quiz,
            'due_date' => $course->due_date?->toDateString(),
            'pass_percent' => $course->pass_percent,
            'staff_ids' => $course->assignments->pluck('user_id')->map(fn ($id) => (int) $id)->values(),
            'assignments' => $course->assignments->map(fn (TrainingCourseAssignment $assignment) => [
                'user_id' => $assignment->user_id,
                'status' => $assignment->status,
                'score' => $assignment->score,
            ])->values(),
        ];
    }

    private function learnerAssignment(TrainingCourseAssignment $assignment): array
    {
        $course = $assignment->course;
        return [
            'id' => $assignment->id,
            'status' => $assignment->status,
            'progress' => $assignment->progress,
            'score' => $assignment->score,
            'started_at' => $assignment->started_at?->toIso8601String(),
            'completed_at' => $assignment->completed_at?->toIso8601String(),
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'category' => $course->category,
                'description' => $course->description,
                'content' => $course->content,
                'quiz' => array_map(fn (array $question) => [
                    'question' => $question['question'],
                    'options' => $question['options'],
                ], $course->quiz ?? []),
                'due_date' => $course->due_date?->toDateString(),
                'pass_percent' => $course->pass_percent,
            ],
        ];
    }
}
