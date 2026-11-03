<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A staff member's own HR record: saved as a draft while it's filled in, then submitted
 * to HR. Only the owner and Master Admins (HR) can read it; documents stay on the private disk.
 */
class PersonnelProfileController extends Controller
{
    private const DOCUMENT_SLOTS = ['id', 'cv', 'certificate', 'bank'];
    private const REQUIRED_DOCUMENTS = ['id', 'cv'];
    private const SECTIONS = ['education', 'experience', 'children', 'contacts', 'beneficiaries'];

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request->user())]);
    }

    /** HR's read-only view of one staff member. */
    public function showFor(User $user): JsonResponse
    {
        abort_unless($user->isStaff(), 404);

        return response()->json(['data' => $this->present($user)]);
    }

    public function save(Request $request): JsonResponse
    {
        $user = $request->user();
        $submit = $request->boolean('submit');
        $required = $submit ? 'required' : 'nullable';
        $data = $request->validate([
            'profile' => ['required', 'array'],
            'profile.khmerName' => ['nullable', 'string', 'max:200'],
            'profile.gender' => [$required, 'nullable', 'in:Female,Male,Non-binary,Prefer not to say'],
            'profile.dob' => [$required, 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'profile.birthPlace' => [$required, 'nullable', 'string', 'max:120'],
            'profile.citizenship' => ['nullable', 'string', 'max:100'],
            'profile.idNumber' => [$required, 'nullable', 'string', 'max:100'],
            'profile.phone' => [$required, 'nullable', 'string', 'max:30'],
            'profile.altPhone' => ['nullable', 'string', 'max:30'],
            'profile.email' => [$required, 'nullable', 'email', 'max:255'],
            'profile.altEmail' => ['nullable', 'email', 'max:255'],
            'profile.address' => ['nullable', 'string', 'max:500'],
            'profile.houseNumber' => ['nullable', 'string', 'max:20'],
            'profile.streetNumber' => ['nullable', 'string', 'max:120'],
            'profile.village' => ['nullable', 'string', 'max:120'],
            'profile.commune' => ['nullable', 'string', 'max:120'],
            'profile.district' => ['nullable', 'string', 'max:120'],
            'profile.province' => ['nullable', 'string', 'max:120'],
            'profile.postalCode' => ['nullable', 'string', 'max:20'],
            'profile.education' => [$submit ? 'required' : 'nullable', 'array', 'max:50'],
            'profile.education.*.degree' => [$required, 'nullable', 'string', 'max:200'],
            'profile.education.*.institution' => [$required, 'nullable', 'string', 'max:200'],
            'profile.education.*.start_date' => ['nullable', 'date_format:Y-m-d'],
            'profile.education.*.end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:profile.education.*.start_date'],
            'profile.education.*.major' => ['nullable', 'string', 'max:150'],
            'profile.education.*.year' => ['nullable', 'integer', 'between:1950,2100'],
            'profile.trainingCourses' => ['nullable', 'array', 'max:50'],
            'profile.trainingCourses.*.course_title' => [$required, 'nullable', 'string', 'max:200'],
            'profile.trainingCourses.*.start_date' => [$required, 'nullable', 'date_format:Y-m-d'],
            'profile.trainingCourses.*.end_date' => [$required, 'nullable', 'date_format:Y-m-d', 'after_or_equal:profile.trainingCourses.*.start_date'],
            'profile.trainingCourses.*.provider' => [$required, 'nullable', 'string', 'max:200'],
            'profile.expertise' => ['nullable', 'array', 'max:50'],
            'profile.expertise.*' => [$required, 'nullable', 'string', 'max:150'],
            'profile.experience' => ['nullable', 'array', 'max:50'],
            'profile.experience.*.organisation' => [$required, 'nullable', 'string', 'max:200'],
            'profile.experience.*.position' => [$required, 'nullable', 'string', 'max:150'],
            'profile.experience.*.from' => ['nullable', 'date_format:Y-m-d'],
            'profile.experience.*.to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:profile.experience.*.from'],
            'profile.experience.*.project_title' => ['nullable', 'string', 'max:200'],
            'profile.experience.*.job_description' => ['nullable', 'string', 'max:2000'],
            'profile.motherTongue' => ['nullable', 'string', 'max:100'],
            'profile.otherMotherTongue' => [
                Rule::requiredIf(fn (): bool => $submit && $request->input('profile.motherTongue') === 'Other'),
                'nullable',
                'string',
                'max:100',
            ],
            'profile.languages' => ['nullable', 'array', 'max:30'],
            'profile.languages.*.language' => [$required, 'nullable', 'string', 'max:100'],
            'profile.languages.*.reading' => [$required, 'nullable', 'in:Fluent,Good,Fair,Basic'],
            'profile.languages.*.writing' => [$required, 'nullable', 'in:Fluent,Good,Fair,Basic'],
            'profile.languages.*.speaking' => [$required, 'nullable', 'in:Fluent,Good,Fair,Basic'],
            'profile.languages.*.understanding' => [$required, 'nullable', 'in:Fluent,Good,Fair,Basic'],
            'profile.geographicExperience' => ['nullable', 'array', 'max:50'],
            'profile.geographicExperience.*.country' => [$required, 'nullable', 'string', 'max:120'],
            'profile.geographicExperience.*.province' => [$required, 'nullable', 'string', 'max:120'],
            'profile.memberships' => ['nullable', 'array', 'max:50'],
            'profile.memberships.*.start_date' => [$required, 'nullable', 'date_format:Y-m-d'],
            'profile.memberships.*.end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:profile.memberships.*.start_date'],
            'profile.memberships.*.institution' => [$required, 'nullable', 'string', 'max:200'],
            'profile.memberships.*.role' => [$required, 'nullable', 'string', 'max:150'],
            'profile.memberships.*.description' => ['nullable', 'string', 'max:2000'],
            'profile.maritalStatus' => ['nullable', 'string', 'max:30'],
            'profile.spouse' => ['nullable', 'string', 'max:200'],
            'profile.notes' => ['nullable', 'string', 'max:5000'],
            'profile.extraDocuments' => ['nullable', 'array', 'max:50'],
            'profile.extraDocuments.*.key' => ['required', 'string', 'regex:/^attachment-[0-9a-f-]{36}$/i'],
            'profile.extraDocuments.*.title' => [$submit ? 'required' : 'nullable', 'string', 'max:150'],
            'profile.extraDocuments.*.description' => ['nullable', 'string', 'max:500'],
            'profile.extraDocuments.*.remark' => ['nullable', 'string', 'max:500'],
            'profile.children' => ['nullable', 'array', 'max:50'],
            'profile.children.*.name' => [$required, 'nullable', 'string', 'max:200'],
            'profile.children.*.dob' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'profile.children.*.sex' => ['nullable', 'in:Female,Male'],
            'profile.contacts' => [$submit ? 'required' : 'nullable', 'array', 'max:20'],
            'profile.contacts.*.name' => [$required, 'nullable', 'string', 'max:200'],
            'profile.contacts.*.relationship' => [$required, 'nullable', 'string', 'max:100'],
            'profile.contacts.*.phone' => [$required, 'nullable', 'string', 'max:30'],
            'profile.contacts.*.email' => ['nullable', 'email', 'max:255'],
            'profile.contacts.*.address' => ['nullable', 'string', 'max:500'],
            'profile.beneficiaries' => ['nullable', 'array', 'max:50'],
            'profile.beneficiaries.*.name' => [$required, 'nullable', 'string', 'max:200'],
            'profile.beneficiaries.*.dob' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'profile.beneficiaries.*.id_number' => ['nullable', 'string', 'max:100'],
            'profile.beneficiaries.*.relationship' => ['nullable', 'string', 'max:100'],
            'profile.beneficiaries.*.phone' => ['nullable', 'string', 'max:30'],
            'profile.beneficiaries.*.address' => ['nullable', 'string', 'max:500'],
            'profile.beneficiaries.*.share' => [$required, 'nullable', 'numeric', 'min:0', 'max:100'],
            'profile.declarations' => ['nullable', 'array'],
            'profile.declarations.accurate' => [$submit ? 'accepted' : 'nullable', 'boolean'],
            'profile.declarations.updates' => [$submit ? 'accepted' : 'nullable', 'boolean'],
            'profile.declarations.consent' => [$submit ? 'accepted' : 'nullable', 'boolean'],
            'profile.signature' => [$required, 'nullable', 'string', 'max:150'],
        ]);

        // Under the same lock as uploads, so a save can't drop a file that was just added.
        $user = DB::transaction(function () use ($user, $data, $submit): User {
            $fresh = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $documents = $fresh->personnel_profile['documents'] ?? [];
            if ($submit) {
                if (array_diff(self::REQUIRED_DOCUMENTS, array_keys($documents))) throw ValidationException::withMessages(['documents' => 'Upload your ID and CV before submitting.']);
                $shares = array_column($data['profile']['beneficiaries'] ?? [], 'share');
                if ($shares && abs(array_sum($shares) - 100) > 0.01) throw ValidationException::withMessages(['profile.beneficiaries' => 'Beneficiary shares must add up to 100%.']);
            }

            $profile = $data['profile'];
            foreach (self::SECTIONS as $section) $profile[$section] = array_values($profile[$section] ?? []);
            $profile['documents'] = $documents;
            $fresh->personnel_profile = $profile;
            // An edit after submitting stays in the record but has to be submitted again.
            $fresh->personnel_profile_submitted_at = $submit ? now() : null;
            $fresh->save();

            return $fresh;
        });

        return response()->json(['data' => $this->present($user)]);
    }

    public function uploadDocument(Request $request, string $slot): JsonResponse
    {
        abort_unless($this->validDocumentSlot($slot), 404);
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'title' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'remark' => ['nullable', 'string', 'max:500'],
        ], ['file.mimes' => 'Use a PDF, JPG or PNG file.', 'file.max' => 'The file can be at most 5 MB.']);
        $file = $request->file('file');
        $stored = [
            'path' => $file->store("personnel-documents/{$request->user()->id}", 'local'),
            'name' => mb_substr($file->getClientOriginalName(), 0, 150),
            'size' => $file->getSize(),
            'title' => $request->input('title') ?: mb_substr($file->getClientOriginalName(), 0, 150),
            'description' => $request->input('description'),
            'remark' => $request->input('remark'),
            'uploaded_at' => now()->toIso8601String(),
        ];
        $user = $this->changeDocuments($request->user(), function (array &$documents) use ($slot, $stored): void {
            if ($old = $documents[$slot]['path'] ?? null) Storage::disk('local')->delete($old);
            $documents[$slot] = $stored;
        });

        return response()->json(['data' => $this->present($user)], 201);
    }

    public function deleteDocument(Request $request, string $slot): JsonResponse
    {
        abort_unless($this->validDocumentSlot($slot), 404);
        $user = $this->changeDocuments($request->user(), function (array &$documents) use ($slot): void {
            abort_unless(isset($documents[$slot]), 404);
            Storage::disk('local')->delete($documents[$slot]['path']);
            unset($documents[$slot]);
        });

        return response()->json(['data' => $this->present($user)]);
    }

    /** Re-reads the record under a lock, so uploading two files at once can't lose one. */
    private function changeDocuments(User $user, callable $change): User
    {
        return DB::transaction(function () use ($user, $change): User {
            $fresh = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $profile = $fresh->personnel_profile ?? [];
            $documents = $profile['documents'] ?? [];
            $change($documents);
            $profile['documents'] = $documents;
            $fresh->personnel_profile = $profile;
            $fresh->save();

            return $fresh;
        });
    }

    public function document(Request $request, User $user, string $slot)
    {
        abort_unless($request->user()->is($user) || $request->user()->isMasterAdmin(), 403);
        $document = $user->personnel_profile['documents'][$slot] ?? null;
        abort_unless($document && Storage::disk('local')->exists($document['path']), 404);

        return Storage::disk('local')->download($document['path'], $document['name'], ['Cache-Control' => 'private, no-store']);
    }

    private function present(User $user): array
    {
        $profile = $user->personnel_profile ?? [];
        $documents = [];
        foreach ($profile['documents'] ?? [] as $slot => $document) $documents[$slot] = [
            'name' => $document['name'],
            'size' => $document['size'] ?? null,
            'title' => $document['title'] ?? null,
            'description' => $document['description'] ?? null,
            'remark' => $document['remark'] ?? null,
            'uploaded_at' => $document['uploaded_at'] ?? null,
        ];
        unset($profile['documents']);

        return [
            'user_id' => $user->id,
            'name' => $user->name,
            'profile' => $profile === [] ? null : $profile,
            'documents' => (object) $documents,
            'submitted_at' => $user->personnel_profile_submitted_at?->toIso8601String(),
        ];
    }

    private function validDocumentSlot(string $slot): bool
    {
        return in_array($slot, self::DOCUMENT_SLOTS, true)
            || preg_match('/^attachment-[0-9a-f-]{36}$/i', $slot) === 1;
    }
}
