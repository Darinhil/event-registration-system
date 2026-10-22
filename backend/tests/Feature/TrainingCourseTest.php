<?php

namespace Tests\Feature;

use App\Models\TrainingCourse;
use App\Models\TrainingCourseAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrainingCourseTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_admin_can_create_a_course_for_selected_staff_and_staff_can_pass_quiz(): void
    {
        $admin = User::factory()->create(['role' => 'event_admin']);
        $staff = User::factory()->create(['role' => 'officer_1']);
        $otherStaff = User::factory()->create(['role' => 'officer_2']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/training/courses', $this->courseData([$staff->id]));
        $response->assertCreated()
            ->assertJsonPath('data.staff_ids.0', $staff->id);

        $courseId = $response->json('data.id');
        $this->assertDatabaseHas('training_course_assignments', [
            'training_course_id' => $courseId,
            'user_id' => $staff->id,
        ]);
        $this->assertDatabaseMissing('training_course_assignments', [
            'training_course_id' => $courseId,
            'user_id' => $otherStaff->id,
        ]);

        Sanctum::actingAs($staff);
        $this->getJson('/api/training/courses')
            ->assertOk()
            ->assertJsonMissingPath('data.0.course.quiz.0.answer')
            ->assertJsonMissingPath('data.0.course.quiz.1.answer');

        $assignmentId = TrainingCourseAssignment::query()->where('user_id', $staff->id)->value('id');
        $this->postJson("/api/training/assignments/$assignmentId/start")
            ->assertOk()
            ->assertJsonMissingPath('data.course.quiz.0.answer');

        $this->postJson("/api/training/assignments/$assignmentId/quiz", ['answers' => [1, 0]])
            ->assertOk()
            ->assertJsonPath('passed', true)
            ->assertJsonPath('score', 100)
            ->assertJsonPath('data.status', 'Completed');
    }

    public function test_staff_cannot_access_another_staff_members_assignment(): void
    {
        $course = TrainingCourse::query()->create($this->courseAttributes());
        $owner = User::factory()->create(['role' => 'officer_1']);
        $otherStaff = User::factory()->create(['role' => 'officer_2']);
        $assignment = TrainingCourseAssignment::query()->create([
            'training_course_id' => $course->id,
            'user_id' => $owner->id,
            'status' => 'Pending',
        ]);
        Sanctum::actingAs($otherStaff);

        $this->postJson("/api/training/assignments/$assignment->id/start")->assertNotFound();
        $this->postJson("/api/training/assignments/$assignment->id/quiz", ['answers' => [1, 0]])->assertNotFound();
        $this->assertDatabaseHas('training_course_assignments', ['id' => $assignment->id, 'status' => 'Pending']);
    }

    public function test_only_admins_and_event_admins_can_manage_courses_and_only_staff_can_be_assigned(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        Sanctum::actingAs($manager);
        $this->getJson('/api/admin/training/courses')->assertForbidden();
        $this->postJson('/api/admin/training/courses', $this->courseData([]))->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $attendee = User::factory()->create(['role' => 'attendee']);
        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/training/courses', $this->courseData([$attendee->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('staff_ids');
    }

    public function test_admin_can_save_a_course_draft_before_assigning_staff(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->postJson('/api/admin/training/courses', $this->courseData([]));

        $response->assertCreated()
            ->assertJsonPath('data.staff_ids', []);
        $this->assertDatabaseCount('training_course_assignments', 0);
    }

    public function test_event_admin_can_assign_and_unassign_staff_from_an_existing_course(): void
    {
        $admin = User::factory()->create(['role' => 'event_admin']);
        $staff = User::factory()->create(['role' => 'officer_1']);
        $otherStaff = User::factory()->create(['role' => 'officer_2']);
        $course = TrainingCourse::query()->create($this->courseAttributes());
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/training/courses/$course->id/assignments", [
            'staff_ids' => [$staff->id, $otherStaff->id],
        ])->assertOk()
            ->assertJsonCount(2, 'data.staff_ids');

        $this->assertDatabaseHas('training_course_assignments', [
            'training_course_id' => $course->id,
            'user_id' => $staff->id,
            'assigned_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('training_course_assignments', [
            'training_course_id' => $course->id,
            'user_id' => $otherStaff->id,
        ]);

        $this->patchJson("/api/admin/training/courses/$course->id/assignments", [
            'staff_ids' => [],
        ])->assertOk()->assertJsonCount(0, 'data.staff_ids');
        $this->assertDatabaseMissing('training_course_assignments', ['training_course_id' => $course->id]);
    }

    public function test_assignment_endpoint_rejects_non_staff_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $attendee = User::factory()->create(['role' => 'attendee']);
        $course = TrainingCourse::query()->create($this->courseAttributes());
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/training/courses/$course->id/assignments", [
            'staff_ids' => [$attendee->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_ids');

        $this->assertDatabaseMissing('training_course_assignments', [
            'training_course_id' => $course->id,
            'user_id' => $attendee->id,
        ]);
    }

    public function test_failed_quiz_can_be_retried_and_invalid_answer_indexes_are_rejected(): void
    {
        $course = TrainingCourse::query()->create($this->courseAttributes());
        $staff = User::factory()->create(['role' => 'officer_1']);
        $assignment = TrainingCourseAssignment::query()->create([
            'training_course_id' => $course->id,
            'user_id' => $staff->id,
            'status' => 'In Progress',
        ]);
        Sanctum::actingAs($staff);

        $this->postJson("/api/training/assignments/$assignment->id/quiz", ['answers' => [0, 1]])
            ->assertOk()
            ->assertJsonPath('passed', false)
            ->assertJsonPath('score', 0)
            ->assertJsonPath('data.status', 'In Progress');
        $this->postJson("/api/training/assignments/$assignment->id/quiz", ['answers' => [10, 0]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('answers.0');
    }

    private function courseData(array $staffIds): array
    {
        return [
            'title' => 'Child Safeguarding',
            'category' => 'Child Safeguarding',
            'description' => 'Learn the core safeguarding responsibilities.',
            'content' => 'Read and follow the safeguarding policy.',
            'quiz' => [
                ['question' => 'Who should a concern be reported to?', 'options' => ['No one', 'The safeguarding lead'], 'answer' => 1],
                ['question' => 'When should a concern be reported?', 'options' => ['Promptly', 'Never'], 'answer' => 0],
            ],
            'pass_percent' => 80,
            'staff_ids' => $staffIds,
        ];
    }

    private function courseAttributes(): array
    {
        $data = $this->courseData([]);
        unset($data['staff_ids']);
        $data['created_by'] = User::factory()->create(['role' => 'admin'])->id;

        return $data;
    }
}
