<?php

namespace Tests\Feature;

use App\Models\TrainingCourse;
use App\Models\TrainingProgress;
use App\Models\TrainingQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrainingTest extends TestCase
{
    use RefreshDatabase;

    private function course(): TrainingCourse { return TrainingCourse::where('title', 'Child Safeguarding')->firstOrFail(); }

    private function staff(): User
    {
        $user = User::factory()->create(['name' => 'Sokha Leng', 'role' => 'officer_1']);
        Sanctum::actingAs($user);
        return $user;
    }

    private function completeAllLessons(TrainingCourse $course): void
    {
        foreach ($course->lessons()->get() as $lesson) {
            $this->postJson("/api/me/training/{$course->id}/lessons/{$lesson->id}/complete")->assertOk();
        }
    }

    /** @return array<int, int> question id => answer index */
    private function answers(TrainingCourse $course, int $wrong = 0): array
    {
        return $course->questions()->get()->mapWithKeys(fn (TrainingQuestion $question, int $index) => [
            $question->id => $index < $wrong ? ($question->correct_option + 1) % count($question->options) : $question->correct_option,
        ])->all();
    }

    public function test_only_staff_can_use_training(): void
    {
        $this->getJson('/api/me/training')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'attendee']));
        $this->getJson('/api/me/training')->assertForbidden();
    }

    public function test_new_staff_member_sees_the_course_not_started(): void
    {
        $this->staff();
        $response = $this->getJson('/api/me/training')->assertOk();

        $response->assertJsonPath('data.0.title', 'Child Safeguarding')
            ->assertJsonPath('data.0.status', 'not_started')
            ->assertJsonPath('data.0.lesson_count', 4)
            ->assertJsonPath('data.0.percent', 0);
        $this->assertSame(['assigned' => 8, 'completed' => 0, 'in_progress' => 0, 'not_started' => 8, 'overdue' => 0, 'percent' => 0], $response->json('summary'));
    }

    public function test_default_training_catalog_includes_all_requested_topics_with_lessons_and_quizzes(): void
    {
        $titles = [
            'Child Safeguarding',
            'Adult Safeguarding (PSEAH)',
            'Staff Safeguarding',
            'Conflict of Interest',
            'Anti-Corruption and Anti-Money Laundering',
            'Anti-Fraud',
            'GEDSI Policy',
            'Environmental Safeguarding Policy',
        ];

        $courses = TrainingCourse::withCount(['lessons', 'questions'])->orderBy('sort_order')->get();

        $this->assertSame($titles, $courses->pluck('title')->all());
        $this->assertTrue($courses->every(fn (TrainingCourse $course) => $course->is_active && $course->assign_all_staff && $course->lessons_count >= 1 && $course->questions_count >= 1));
        $this->assertTrue($courses->skip(1)->every(fn (TrainingCourse $course) => $course->questions()->pluck('correct_option')->unique()->count() > 1));
    }

    public function test_quiz_questions_never_include_the_answer_key(): void
    {
        $this->staff();
        $course = $this->course();
        $this->completeAllLessons($course);

        $questions = $this->getJson("/api/me/training/{$course->id}/quiz")->assertOk()->json('data');
        $this->assertCount(5, $questions);
        $this->assertSame(['id', 'prompt', 'options'], array_keys($questions[0]));
    }

    public function test_lessons_must_be_completed_in_order_and_unlock_the_quiz(): void
    {
        $this->staff();
        $course = $this->course();
        $lessons = $course->lessons()->get();

        $this->postJson("/api/me/training/{$course->id}/lessons/{$lessons[2]->id}/complete")->assertStatus(422)->assertJsonValidationErrors('lesson');
        $this->getJson("/api/me/training/{$course->id}/quiz")->assertStatus(422)->assertJsonValidationErrors('quiz');

        $detail = $this->postJson("/api/me/training/{$course->id}/lessons/{$lessons[0]->id}/complete")->assertOk()->json('data');
        $this->assertSame([true, false, true, true], [$detail['lessons'][0]['completed'], $detail['lessons'][1]['locked'], $detail['lessons'][2]['locked'], $detail['lessons'][3]['locked']]);
        $this->assertSame('in_progress', $detail['status']);

        $this->completeAllLessons($course);
        $this->getJson("/api/me/training/{$course->id}")->assertJsonPath('data.status', 'quiz_ready')->assertJsonPath('data.percent', 80);
    }

    public function test_a_lesson_from_another_course_is_rejected(): void
    {
        $this->staff();
        $other = TrainingCourse::create(['title' => 'Other', 'summary' => '-']);
        $lesson = $other->lessons()->create(['title' => 'Elsewhere', 'body' => '-']);

        $this->postJson("/api/me/training/{$this->course()->id}/lessons/{$lesson->id}/complete")->assertNotFound();
    }

    public function test_failing_the_quiz_records_the_attempt_and_allows_a_retake(): void
    {
        $this->staff();
        $course = $this->course();
        $this->completeAllLessons($course);

        $result = $this->postJson("/api/me/training/{$course->id}/quiz", ['answers' => $this->answers($course, wrong: 2)])->assertOk()->json('data.result');

        $this->assertSame([60, false, 3, 5], [$result['score'], $result['passed'], $result['correct'], $result['total']]);
        $this->assertCount(2, $result['incorrect']);
        $this->getJson("/api/me/training/{$course->id}")->assertJsonPath('data.status', 'quiz_ready')->assertJsonPath('data.certificate', null)->assertJsonPath('data.attempts', 1);
    }

    public function test_passing_the_quiz_completes_the_course_and_issues_a_certificate(): void
    {
        $user = $this->staff();
        $course = $this->course();
        $this->completeAllLessons($course);

        $data = $this->postJson("/api/me/training/{$course->id}/quiz", ['answers' => $this->answers($course, wrong: 1)])->assertOk()->json('data');

        $this->assertTrue($data['result']['passed']);
        $this->assertSame(80, $data['result']['score']);
        $this->assertSame('completed', $data['course']['status']);
        $this->assertSame(100, $data['course']['percent']);
        $this->assertSame('Sokha Leng', $data['course']['certificate']['name']);
        $this->assertMatchesRegularExpression('/^LLC-[A-Z0-9]{8}$/', $data['course']['certificate']['code']);
        $this->assertSame(1, TrainingProgress::where('user_id', $user->id)->whereNotNull('certificate_code')->count());

        $this->postJson("/api/me/training/{$course->id}/quiz", ['answers' => $this->answers($course)])->assertStatus(422)->assertJsonValidationErrors('quiz');
        $this->getJson('/api/me/training')->assertJsonPath('summary.completed', 1)->assertJsonPath('summary.percent', 13);
    }

    public function test_quiz_requires_every_question_answered_with_a_valid_option(): void
    {
        $this->staff();
        $course = $this->course();
        $this->completeAllLessons($course);
        $answers = $this->answers($course);

        $this->postJson("/api/me/training/{$course->id}/quiz", ['answers' => array_slice($answers, 0, 3, true)])->assertStatus(422)->assertJsonValidationErrors('answers');
        $answers[array_key_first($answers)] = 9;
        $this->postJson("/api/me/training/{$course->id}/quiz", ['answers' => $answers])->assertStatus(422)->assertJsonValidationErrors('answers');
        $this->assertDatabaseCount('training_quiz_attempts', 0);
    }

    public function test_due_dates_flag_overdue_and_due_soon_courses(): void
    {
        $this->staff();
        $this->course()->update(['due_date' => now()->subDay()->toDateString()]);
        $this->getJson('/api/me/training')->assertJsonPath('data.0.overdue', true)->assertJsonPath('summary.overdue', 1);

        $this->course()->update(['due_date' => now()->addDays(3)->toDateString()]);
        $this->getJson('/api/me/training')->assertJsonPath('data.0.overdue', false)->assertJsonPath('data.0.due_soon', true);
    }

    public function test_progress_is_personal(): void
    {
        $this->staff();
        $this->completeAllLessons($this->course());

        $this->staff();
        $this->getJson('/api/me/training')->assertJsonPath('data.0.status', 'not_started');
    }

    public function test_only_master_admins_manage_courses_and_assignments_are_staff_scoped(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));
        $this->getJson('/api/admin/training-courses')->assertForbidden();

        $staff = User::factory()->create(['role' => 'officer_1']);
        $otherStaff = User::factory()->create(['role' => 'officer_2']);
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $payload = [
            'title' => 'New Staff Orientation',
            'category' => 'Onboarding',
            'summary' => 'An introduction for new team members.',
            'estimated_minutes' => 15,
            'pass_mark' => 80,
            'due_date' => now()->addMonth()->toDateString(),
            'lessons' => [['title' => 'Welcome', 'body' => 'Welcome to the team.']],
            'questions' => [['prompt' => 'Ready?', 'options' => ['Yes', 'No', 'Maybe', 'Later'], 'correct_option' => 0]],
        ];
        $course = $this->postJson('/api/admin/training-courses', $payload)
            ->assertCreated()
            ->assertJsonPath('data.assigned_count', 0)
            ->json('data');

        Sanctum::actingAs($staff);
        $this->getJson('/api/me/training')->assertJsonMissing(['title' => 'New Staff Orientation']);
        $this->getJson("/api/me/training/{$course['id']}")->assertNotFound();

        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/training-courses/{$course['id']}/assignments", ['user_ids' => [$staff->id]])
            ->assertOk()
            ->assertJsonPath('data.assigned_count', 1);

        Sanctum::actingAs($staff);
        $this->getJson('/api/me/training')->assertJsonFragment(['title' => 'New Staff Orientation']);
        $this->getJson("/api/me/training/{$course['id']}")->assertOk();

        Sanctum::actingAs($otherStaff);
        $this->getJson('/api/me/training')->assertJsonMissing(['title' => 'New Staff Orientation']);
        $this->getJson("/api/me/training/{$course['id']}")->assertNotFound();
    }
}
