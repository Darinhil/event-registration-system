<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PersonalInformationTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendee_can_save_and_reload_personal_information(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $information = [
            'first_name' => 'Dara',
            'legal_name' => 'Dara Chenda',
            'surname' => 'Sok',
            'family_name' => 'Sok',
            'gender' => 'Non-binary',
            'date_of_birth' => '1994-06-12',
            'id_passport_number' => 'A12345678',
            'primary_phone' => '+855 12 345 678',
            'primary_email' => 'dara@example.com',
            'address' => [
                'house_number' => '18',
                'street_number' => 'Street 240',
                'district' => 'Chamkarmon',
                'province' => 'Phnom Penh',
                'postal_code' => '12000',
            ],
        ];

        $this->postJson('/api/me/profile', [
            'personal_information' => $information,
            'personal_information_complete' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.personal_information.first_name', 'Dara')
            ->assertJsonPath('data.personal_information.legal_name', 'Dara Chenda')
            ->assertJsonPath('data.personal_information.family_name', 'Sok')
            ->assertJsonPath('data.personal_information.address.postal_code', '12000');

        $this->assertSame($information, $user->fresh()->personal_information);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.personal_information.surname', 'Sok');
    }

    public function test_personal_information_rejects_invalid_email_and_gender(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/me/profile', [
            'personal_information' => [
                'first_name' => 'Dara',
                'surname' => 'Sok',
                'gender' => 'Unknown',
                'primary_email' => 'not-an-email',
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'personal_information.gender',
                'personal_information.primary_email',
            ]);
    }

    public function test_incomplete_information_can_be_saved_as_a_draft(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/me/profile', [
            'personal_information' => [
                'first_name' => 'Dara',
                'surname' => '',
                'gender' => '',
                'date_of_birth' => '',
                'id_passport_number' => '',
                'primary_phone' => '',
                'primary_email' => '',
                'address' => [
                    'house_number' => '',
                    'street_number' => '',
                    'district' => '',
                    'province' => '',
                ],
            ],
            'personal_information_complete' => false,
        ])->assertOk()
            ->assertJsonPath('data.personal_information.first_name', 'Dara');

        $this->assertSame('Dara', $user->fresh()->personal_information['first_name']);
    }

    public function test_completing_information_requires_all_required_details(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/me/profile', [
            'personal_information' => ['first_name' => 'Dara'],
            'personal_information_complete' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'personal_information.surname',
                'personal_information.gender',
                'personal_information.date_of_birth',
                'personal_information.id_passport_number',
                'personal_information.primary_phone',
                'personal_information.primary_email',
                'personal_information.address.house_number',
                'personal_information.address.street_number',
                'personal_information.address.district',
                'personal_information.address.province',
            ]);
    }

    public function test_professional_qualifications_can_be_saved_and_reloaded(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $qualifications = [
            'education_history' => [[
                'start_date' => '2012-09-01',
                'end_date' => '2016-06-30',
                'institution' => 'Royal University of Phnom Penh',
                'certificate' => 'Bachelor of Arts',
                'major' => 'Development Studies',
            ]],
            'training_courses' => [[
                'course_title' => 'Project Management',
                'start_date' => '2023-02-01',
                'end_date' => '2023-02-05',
                'provider' => 'Training Institute',
            ]],
            'expertise' => ['Community development'],
            'employment_history' => [[
                'start_date' => '2018-01-01',
                'end_date' => '2024-12-31',
                'position' => 'Program Officer',
                'employer' => 'Community Organization',
                'project_title' => 'Rural Development',
                'job_description' => 'Led community programs.',
            ]],
            'mother_tongue' => 'Khmer',
            'other_mother_tongue' => null,
            'languages' => [[
                'language' => 'English',
                'reading' => 'Fluent',
                'writing' => 'Good',
                'speaking' => 'Good',
                'understanding' => 'Fluent',
            ]],
            'geographic_experience' => [['country' => 'Cambodia', 'province' => 'Phnom Penh']],
            'memberships' => [[
                'start_date' => '2020-01-01',
                'end_date' => null,
                'institution' => 'Professional Association',
                'role' => 'Member',
                'description' => 'Participates in professional activities.',
            ]],
        ];

        $this->postJson('/api/me/profile', [
            'professional_qualifications' => $qualifications,
            'professional_qualifications_complete' => true,
        ])->assertOk()
            ->assertJsonPath('data.professional_qualifications.mother_tongue', 'Khmer')
            ->assertJsonPath('data.professional_qualifications.education_history.0.major', 'Development Studies')
            ->assertJsonPath('data.professional_qualifications.languages.0.reading', 'Fluent');

        $savedQualifications = $user->fresh()->professional_qualifications;
        $this->assertSame('Khmer', $savedQualifications['mother_tongue']);
        $this->assertSame($qualifications['education_history'], $savedQualifications['education_history']);
        $this->assertSame($qualifications['languages'], $savedQualifications['languages']);
        $this->assertSame($qualifications['memberships'], $savedQualifications['memberships']);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.professional_qualifications.memberships.0.institution', 'Professional Association');
    }

    public function test_professional_qualifications_can_be_saved_as_a_draft(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/me/profile', [
            'professional_qualifications' => [
                'education_history' => [['institution' => 'In progress']],
                'mother_tongue' => 'Other',
                'other_mother_tongue' => '',
            ],
            'professional_qualifications_complete' => false,
        ])->assertOk()
            ->assertJsonPath('data.professional_qualifications.education_history.0.institution', 'In progress');
    }

    public function test_other_mother_tongue_is_required_when_completing_qualifications(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/me/profile', [
            'professional_qualifications' => [
                'mother_tongue' => 'Other',
                'other_mother_tongue' => '',
            ],
            'professional_qualifications_complete' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['professional_qualifications.other_mother_tongue']);
    }

    public function test_completing_professional_qualifications_validates_language_proficiency(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/me/profile', [
            'professional_qualifications' => [
                'mother_tongue' => 'Khmer',
                'languages' => [['language' => 'English', 'reading' => 'Excellent']],
            ],
            'professional_qualifications_complete' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'professional_qualifications.languages.0.reading',
                'professional_qualifications.languages.0.writing',
                'professional_qualifications.languages.0.speaking',
                'professional_qualifications.languages.0.understanding',
            ]);
    }

    public function test_family_information_can_be_saved_and_reloaded(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $familyInformation = [
            'marital_status' => 'Married',
            'spouse_partner_name' => 'Sok Dara',
            'children' => [[
                'name' => 'Sok Lina',
                'date_of_birth' => '2018-04-12',
                'sex' => 'Female',
            ]],
            'emergency_contacts' => [[
                'name' => 'Sok Vannak',
                'relationship' => 'Brother',
                'phone' => '+85512345678',
                'email' => 'vannak@example.com',
                'current_address' => 'Phnom Penh',
            ]],
            'beneficiaries' => [[
                'full_name' => 'Sok Lina',
                'date_of_birth' => '2018-04-12',
                'identity_number' => 'ID-123456',
                'relationship' => 'Child',
                'contact_number' => '+85512345678',
                'address' => 'Phnom Penh',
                'share_percent' => 100,
            ]],
        ];

        $this->postJson('/api/me/profile', [
            'family_information' => $familyInformation,
            'family_information_complete' => true,
        ])->assertOk()
            ->assertJsonPath('data.family_information.marital_status', 'Married')
            ->assertJsonPath('data.family_information.children.0.name', 'Sok Lina')
            ->assertJsonPath('data.family_information.emergency_contacts.0.email', 'vannak@example.com')
            ->assertJsonPath('data.family_information.beneficiaries.0.share_percent', 100);

        $this->assertSame($familyInformation, $user->fresh()->family_information);
        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.family_information.beneficiaries.0.full_name', 'Sok Lina');
    }

    public function test_family_information_draft_accepts_incomplete_entries(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/me/profile', [
            'family_information' => [
                'marital_status' => '',
                'children' => [['name' => 'In progress']],
                'emergency_contacts' => [['name' => '']],
            ],
            'family_information_complete' => false,
        ])->assertOk()
            ->assertJsonPath('data.family_information.children.0.name', 'In progress');
    }

    public function test_single_family_information_does_not_require_a_partner_or_child(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/me/profile', [
            'family_information' => [
                'marital_status' => 'Single',
                'spouse_partner_name' => '',
                'children' => [['name' => '', 'date_of_birth' => '', 'sex' => '']],
            ],
            'family_information_complete' => true,
        ])->assertOk()
            ->assertJsonPath('data.family_information.marital_status', 'Single')
            ->assertJsonPath('data.family_information.spouse_partner_name', null)
            ->assertJsonPath('data.family_information.children', []);

        $this->assertSame([], $user->fresh()->family_information['children']);
    }

    public function test_family_completion_ignores_and_removes_blank_beneficiary_rows(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $beneficiary = [
            'full_name' => 'Sok Lina',
            'date_of_birth' => '2018-04-12',
            'identity_number' => null,
            'relationship' => 'Child',
            'contact_number' => '+85512345678',
            'address' => 'Phnom Penh',
            'share_percent' => 100,
        ];

        $this->postJson('/api/me/profile', [
            'family_information' => [
                'marital_status' => 'Single',
                'beneficiaries' => [$beneficiary, [
                    'full_name' => '',
                    'date_of_birth' => '',
                    'identity_number' => '',
                    'relationship' => '',
                    'contact_number' => '',
                    'address' => '',
                    'share_percent' => '',
                ]],
            ],
            'family_information_complete' => true,
        ])->assertOk()
            ->assertJsonPath('data.family_information.beneficiaries', [$beneficiary]);

        $this->assertSame([$beneficiary], $user->fresh()->family_information['beneficiaries']);
    }

    public function test_family_completion_rejects_incomplete_nonempty_beneficiary(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/me/profile', [
            'family_information' => [
                'marital_status' => 'Single',
                'beneficiaries' => [[
                    'full_name' => 'Sok Lina',
                    'date_of_birth' => '',
                    'identity_number' => '',
                    'relationship' => '',
                    'contact_number' => '',
                    'address' => '',
                    'share_percent' => '',
                ]],
            ],
            'family_information_complete' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'family_information.beneficiaries.0.date_of_birth',
                'family_information.beneficiaries.0.relationship',
                'family_information.beneficiaries.0.contact_number',
                'family_information.beneficiaries.0.address',
                'family_information.beneficiaries.0.share_percent',
            ]);
    }

    public function test_family_information_completion_validates_status_and_beneficiary_share(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/me/profile', [
            'family_information' => [
                'marital_status' => '',
                'beneficiaries' => [[
                    'full_name' => 'Sok Lina',
                    'date_of_birth' => '2018-04-12',
                    'identity_number' => '',
                    'relationship' => 'Child',
                    'contact_number' => '+85512345678',
                    'address' => 'Phnom Penh',
                    'share_percent' => 120,
                ]],
            ],
            'family_information_complete' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'family_information.marital_status',
                'family_information.beneficiaries.0.share_percent',
            ]);
    }

    public function test_family_information_beneficiary_shares_must_total_one_hundred_percent(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $beneficiary = [
        'full_name' => 'Sok Lina',
        'date_of_birth' => '2018-04-12',
        'identity_number' => 'ID-123456',
        'relationship' => 'Child',
        'contact_number' => '+85512345678',
        'address' => 'Phnom Penh',
        'share_percent' => 60,
        ];

        $this->postJson('/api/me/profile', [
        'family_information' => [
            'marital_status' => 'Married',
            'beneficiaries' => [$beneficiary, [...$beneficiary, 'full_name' => 'Sok Dara']],
        ],
        'family_information_complete' => true,
        ])->assertUnprocessable()
        ->assertJsonValidationErrors(['family_information.beneficiaries']);
    }







}
