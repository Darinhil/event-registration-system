<?php

namespace App\Http\Controllers;

use App\Models\TrainingCourse;
use App\Models\TrainingLesson;
use App\Models\TrainingProgress;
use App\Models\TrainingQuizAttempt;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Staff online training for the signed-in user: lessons in order, then a quiz
 * (answer key kept on the server), then a certificate once the pass mark is met.
 */
class TrainingController extends Controller
{
    private const DUE_SOON_DAYS = 7;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $courses = TrainingCourse::where('is_active', true)->withCount(['lessons', 'questions'])->orderBy('sort_order')->orderBy('id')->get();
        $progress = TrainingProgress::where('user_id', $user->id)->whereIn('training_course_id', $courses->pluck('id'))->get()->keyBy('training_course_id');
        $items = $courses->map(fn (TrainingCourse $course) => $this->overview($course, $progress->get($course->id), $course->lessons_count));

        return response()->json(['data' => $items->values(), 'summary' => [
            'assigned' => $items->count(),
            'completed' => $items->where('status', 'completed')->count(),
            'in_progress' => $items->whereIn('status', ['in_progress', 'quiz_ready'])->count(),
            'not_started' => $items->where('status', 'not_started')->count(),
            'overdue' => $items->where('overdue', true)->count(),
            'percent' => $items->count() ? (int) round($items->where('status', 'completed')->count() / $items->count() * 100) : 0,
        ]]);
    }

    public function show(Request $request, TrainingCourse $course): JsonResponse
    {
        abort_unless($course->is_active, 404);
        return response()->json(['data' => $this->detail($course, $request->user())]);
    }

    public function completeLesson(Request $request, TrainingCourse $course, TrainingLesson $lesson): JsonResponse
    {
        abort_unless($course->is_active && $lesson->training_course_id === $course->id, 404);
        $user = $request->user();
        $lessonIds = $course->lessons()->pluck('id')->all();
        $progress = $this->progressFor($user, $course);
        $done = $progress->completed_lessons ?? [];

        // Lessons build on each other, so they're completed in order.
        $previous = array_slice($lessonIds, 0, array_search($lesson->id, $lessonIds, true));
        if (array_diff($previous, $done)) throw ValidationException::withMessages(['lesson' => 'Finish the previous lesson first.']);

        if (! in_array($lesson->id, $done, true)) {
            $progress->completed_lessons = array_values(array_intersect($lessonIds, [...$done, $lesson->id]));
            $progress->started_at ??= now();
            $progress->save();
        }

        return response()->json(['data' => $this->detail($course, $user)]);
    }

    public function quiz(Request $request, TrainingCourse $course): JsonResponse
    {
        abort_unless($course->is_active, 404);
        $this->assertQuizUnlocked($course, $request->user());

        return response()->json(['data' => $course->questions()->get()->map(fn ($question) => ['id' => $question->id, 'prompt' => $question->prompt, 'options' => $question->options])->values()]);
    }

    public function submitQuiz(Request $request, TrainingCourse $course): JsonResponse
    {
        abort_unless($course->is_active, 404);
        $user = $request->user();
        $progress = $this->assertQuizUnlocked($course, $user);
        if ($progress->completed_at) throw ValidationException::withMessages(['quiz' => 'You’ve already passed this course.']);

        $questions = $course->questions()->get();
        $answers = $request->validate(['answers' => ['required', 'array']])['answers'];
        $unanswered = $questions->filter(fn ($question) => ! isset($answers[$question->id]) || ! is_numeric($answers[$question->id])
            || (int) $answers[$question->id] < 0 || (int) $answers[$question->id] >= count($question->options));
        if ($unanswered->isNotEmpty()) {
            throw ValidationException::withMessages(['answers' => 'Answer every question before submitting.']);
        }

        $incorrect = $questions->filter(fn ($question) => (int) $answers[$question->id] !== $question->correct_option)->pluck('id')->values();
        $total = $questions->count();
        $score = $total ? (int) round(($total - $incorrect->count()) / $total * 100) : 0;
        $passed = $score >= $course->pass_mark;

        DB::transaction(function () use ($user, $course, $questions, $answers, $score, $passed, $progress): void {
            TrainingQuizAttempt::create(['user_id' => $user->id, 'training_course_id' => $course->id, 'answers' => $questions->mapWithKeys(fn ($question) => [$question->id => (int) $answers[$question->id]])->all(), 'score' => $score, 'passed' => $passed]);
            $progress->attempts += 1;
            $progress->best_score = max($progress->best_score ?? 0, $score);
            if ($passed) {
                $progress->completed_at = now();
                do { $code = 'LLC-'.strtoupper(Str::random(8)); } while (TrainingProgress::where('certificate_code', $code)->exists());
                $progress->certificate_code = $code;
            }
            $progress->save();
        });

        return response()->json(['data' => [
            'result' => ['score' => $score, 'passed' => $passed, 'pass_mark' => $course->pass_mark, 'correct' => $total - $incorrect->count(), 'total' => $total, 'incorrect' => $incorrect],
            'course' => $this->detail($course, $user),
        ]]);
    }

    private function progressFor(User $user, TrainingCourse $course): TrainingProgress
    {
        return TrainingProgress::firstOrNew(['user_id' => $user->id, 'training_course_id' => $course->id]);
    }

    private function assertQuizUnlocked(TrainingCourse $course, User $user): TrainingProgress
    {
        $progress = $this->progressFor($user, $course);
        $lessonIds = $course->lessons()->pluck('id')->all();
        if (array_diff($lessonIds, $progress->completed_lessons ?? [])) {
            throw ValidationException::withMessages(['quiz' => 'Complete every lesson to unlock the quiz.']);
        }
        if (! $course->questions()->exists()) throw ValidationException::withMessages(['quiz' => 'This course has no quiz yet.']);
        return $progress;
    }

    /** List-level view of a course for one person. */
    private function overview(TrainingCourse $course, ?TrainingProgress $progress, int $lessonCount): array
    {
        $done = count($progress?->completed_lessons ?? []);
        $completed = (bool) $progress?->completed_at;
        $status = $completed ? 'completed' : ($lessonCount > 0 && $done >= $lessonCount ? 'quiz_ready' : ($done > 0 ? 'in_progress' : 'not_started'));
        $today = now()->startOfDay();
        $steps = $lessonCount + 1; // every lesson, plus the quiz

        return [
            'id' => $course->id,
            'title' => $course->title,
            'category' => $course->category,
            'summary' => $course->summary,
            'estimated_minutes' => $course->estimated_minutes,
            'pass_mark' => $course->pass_mark,
            'lesson_count' => $lessonCount,
            'lessons_done' => min($done, $lessonCount),
            'status' => $status,
            'percent' => (int) round((min($done, $lessonCount) + ($completed ? 1 : 0)) / $steps * 100),
            'best_score' => $progress?->best_score,
            'attempts' => $progress?->attempts ?? 0,
            'due_date' => $course->due_date?->toDateString(),
            'overdue' => ! $completed && $course->due_date !== null && $course->due_date->lt($today),
            'due_soon' => ! $completed && $course->due_date !== null && $course->due_date->gte($today) && $course->due_date->lte($today->copy()->addDays(self::DUE_SOON_DAYS)),
            'completed_at' => $progress?->completed_at?->toIso8601String(),
        ];
    }

    private function detail(TrainingCourse $course, User $user): array
    {
        $progress = TrainingProgress::where('user_id', $user->id)->where('training_course_id', $course->id)->first();
        $lessons = $course->lessons()->get();
        $done = $progress?->completed_lessons ?? [];
        $nextOpen = true; // the first unfinished lesson is open; the ones after it are locked

        return [
            ...$this->overview($course, $progress, $lessons->count()),
            'lessons' => $lessons->map(function (TrainingLesson $lesson) use ($done, &$nextOpen) {
                $completed = in_array($lesson->id, $done, true);
                $locked = ! $completed && ! $nextOpen;
                if (! $completed) $nextOpen = false;
                return ['id' => $lesson->id, 'title' => $lesson->title, 'body' => $lesson->body, 'completed' => $completed, 'locked' => $locked];
            })->values(),
            'question_count' => $course->questions()->count(),
            'certificate' => $progress?->completed_at ? [
                'code' => $progress->certificate_code,
                'name' => $user->name,
                'issued_at' => $progress->completed_at->toIso8601String(),
                'score' => $progress->best_score,
            ] : null,
        ];
    }
}
