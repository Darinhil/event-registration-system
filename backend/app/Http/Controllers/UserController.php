<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\CompliancePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function show(Request $request): UserResource
    {
        // A registration must not remain visible if its event was removed.
        // The event delete flow removes these rows, while this constraint also
        // protects users from seeing legacy orphaned registrations.
        $user = $request->user()->load([
            'registrations' => fn ($query) => $query->whereHas('event')->with('checkIn', 'event'),
        ]);
        $fields = $user->registrations->isNotEmpty()
            ? \App\Models\FormField::whereIn('event_id', $user->registrations->pluck('event_id'))->orderBy('sort_order')->get()
            : collect();
        $user->registrations->each(fn (\App\Models\Registration $registration) => $registration->append_form_values($fields->where('event_id', $registration->event_id)));

        return new UserResource($user);
    }

    public function updateProfile(Request $request): UserResource
    {
        $user = $request->user();
        abort_if($request->exists('compliance_acknowledgements') && ! $user->can('manage-events'), 403);
        $data = $request->validate([
            'profile_photo' => ['sometimes', 'required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'personal_information' => ['sometimes', 'required', 'array'],
            'personal_information_complete' => ['sometimes', 'boolean'],
            'personal_information.first_name' => ['required_if:personal_information_complete,true', 'nullable', 'string', 'max:100'],
            'personal_information.legal_name' => ['nullable', 'string', 'max:200'],
            'personal_information.surname' => ['required_if:personal_information_complete,true', 'nullable', 'string', 'max:100'],
            'personal_information.family_name' => ['nullable', 'string', 'max:100'],
            'personal_information.gender' => ['required_if:personal_information_complete,true', 'nullable', 'in:Male,Female,Non-binary,Prefer not to say'],
            'personal_information.date_of_birth' => ['required_if:personal_information_complete,true', 'nullable', 'date', 'before_or_equal:today'],
            'personal_information.place_of_birth' => ['nullable', 'string', 'max:120'],
            'personal_information.citizenship' => ['nullable', 'string', 'max:100'],
            'personal_information.id_passport_number' => ['required_if:personal_information_complete,true', 'nullable', 'string', 'max:100'],
            'personal_information.primary_phone' => ['required_if:personal_information_complete,true', 'nullable', 'string', 'max:30'],
            'personal_information.alternate_phone' => ['nullable', 'string', 'max:30'],
            'personal_information.primary_email' => ['required_if:personal_information_complete,true', 'nullable', 'email', 'max:255'],
            'personal_information.alternate_email' => ['nullable', 'email', 'max:255'],
            'personal_information.address' => ['nullable', 'array'],
            'personal_information.address.house_number' => ['required_if:personal_information_complete,true', 'nullable', 'string', 'max:20'],
            'personal_information.address.street_number' => ['required_if:personal_information_complete,true', 'nullable', 'string', 'max:120'],
            'personal_information.address.village' => ['nullable', 'string', 'max:120'],
            'personal_information.address.commune' => ['nullable', 'string', 'max:120'],
            'personal_information.address.district' => ['required_if:personal_information_complete,true', 'nullable', 'string', 'max:120'],
            'personal_information.address.province' => ['required_if:personal_information_complete,true', 'nullable', 'string', 'max:120'],
            'personal_information.address.postal_code' => ['nullable', 'string', 'max:20'],
            'professional_qualifications' => ['sometimes', 'required', 'array'],
            'professional_qualifications_complete' => ['sometimes', 'boolean'],
            'professional_qualifications.education_history' => ['sometimes', 'array', 'max:50'],
            'professional_qualifications.education_history.*.start_date' => ['required_if:professional_qualifications_complete,true', 'nullable', 'date'],
            'professional_qualifications.education_history.*.end_date' => ['required_if:professional_qualifications_complete,true', 'nullable', 'date', 'after_or_equal:professional_qualifications.education_history.*.start_date'],
            'professional_qualifications.education_history.*.institution' => ['required_if:professional_qualifications_complete,true', 'nullable', 'string', 'max:200'],
            'professional_qualifications.education_history.*.certificate' => ['required_if:professional_qualifications_complete,true', 'nullable', 'string', 'max:200'],
            'professional_qualifications.education_history.*.major' => ['required_if:professional_qualifications_complete,true', 'nullable', 'string', 'max:150'],
            'professional_qualifications.training_courses' => ['sometimes', 'array', 'max:50'],
            'professional_qualifications.training_courses.*.course_title' => ['required_if:professional_qualifications_complete,true', 'nullable', 'string', 'max:200'],
            'professional_qualifications.training_courses.*.start_date' => ['required_if:professional_qualifications_complete,true', 'nullable', 'date'],
            'professional_qualifications.training_courses.*.end_date' => ['required_if:professional_qualifications_complete,true', 'nullable', 'date', 'after_or_equal:professional_qualifications.training_courses.*.start_date'],
            'professional_qualifications.training_courses.*.provider' => ['required_if:professional_qualifications_complete,true', 'nullable', 'string', 'max:200'],
            'professional_qualifications.expertise' => ['sometimes', 'array', 'max:50'],
            'professional_qualifications.expertise.*' => ['required_if:professional_qualifications_complete,true', 'nullable', 'string', 'max:150'],
            'professional_qualifications.employment_history' => ['sometimes', 'array', 'max:50'],
            'professional_qualifications.employment_history.*.start_date' => ['required_if:professional_qualifications_complete,true', 'nullable', 'date'],
            'professional_qualifications.employment_history.*.end_date' => ['nullable', 'date', 'after_or_equal:professional_qualifications.employment_history.*.start_date'],
            'professional_qualifications.employment_history.*.position' => ['required_if:professional_qualifications_complete,true', 'nullable', 'string', 'max:150'],
            'professional_qualifications.employment_history.*.employer' => ['required_if:professional_qualifications_complete,true', 'nullable', 'string', 'max:200'],
            'professional_qualifications.employment_history.*.project_title' => ['nullable', 'string', 'max:200'],
            'professional_qualifications.employment_history.*.job_description' => ['nullable', 'string', 'max:2000'],
            'professional_qualifications.mother_tongue' => ['required_if:professional_qualifications_complete,true', 'nullable', 'string', 'max:100'],
            'professional_qualifications.other_mother_tongue' => [
                Rule::requiredIf(fn (): bool => $request->boolean('professional_qualifications_complete')
                    && $request->input('professional_qualifications.mother_tongue') === 'Other'),
                'nullable',
                'string',
                'max:100',
            ],
            'professional_qualifications.languages' => ['sometimes', 'array', 'max:30'],
            'professional_qualifications.languages.*.language' => ['required_if:professional_qualifications_complete,true', 'nullable', 'string', 'max:100'],
            'professional_qualifications.languages.*.reading' => ['required_if:professional_qualifications_complete,true', 'nullable', 'in:Fluent,Good,Fair,Basic'],
            'professional_qualifications.languages.*.writing' => ['required_if:professional_qualifications_complete,true', 'nullable', 'in:Fluent,Good,Fair,Basic'],
            'professional_qualifications.languages.*.speaking' => ['required_if:professional_qualifications_complete,true', 'nullable', 'in:Fluent,Good,Fair,Basic'],
            'professional_qualifications.languages.*.understanding' => ['required_if:professional_qualifications_complete,true', 'nullable', 'in:Fluent,Good,Fair,Basic'],
            'professional_qualifications.geographic_experience' => ['sometimes', 'array', 'max:50'],
            'professional_qualifications.geographic_experience.*.country' => ['required_if:professional_qualifications_complete,true', 'nullable', 'string', 'max:120'],
            'professional_qualifications.geographic_experience.*.province' => ['required_if:professional_qualifications_complete,true', 'nullable', 'string', 'max:120'],
            'professional_qualifications.memberships' => ['sometimes', 'array', 'max:50'],
            'professional_qualifications.memberships.*.start_date' => ['required_if:professional_qualifications_complete,true', 'nullable', 'date'],
            'professional_qualifications.memberships.*.end_date' => ['nullable', 'date', 'after_or_equal:professional_qualifications.memberships.*.start_date'],
            'professional_qualifications.memberships.*.institution' => ['required_if:professional_qualifications_complete,true', 'nullable', 'string', 'max:200'],
            'professional_qualifications.memberships.*.role' => ['required_if:professional_qualifications_complete,true', 'nullable', 'string', 'max:150'],
            'professional_qualifications.memberships.*.description' => ['nullable', 'string', 'max:2000'],
            'family_information' => ['sometimes', 'required', 'array'],
            'family_information_complete' => ['sometimes', 'boolean'],
            'family_information.marital_status' => ['required_if:family_information_complete,true', 'nullable', Rule::in(['Married', 'Single', 'Divorced', 'Widowed', 'Partnered'])],
            'family_information.spouse_partner_name' => ['nullable', 'string', 'max:200'],
            'family_information.children' => ['sometimes', 'array', 'max:50'],
            'family_information.children.*.name' => ['nullable', 'string', 'max:200'],
            'family_information.children.*.date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'family_information.children.*.sex' => ['nullable', Rule::in(['Male', 'Female', 'Other', 'Prefer not to say'])],
            'family_information.emergency_contacts' => ['sometimes', 'array', 'max:20'],
            'family_information.emergency_contacts.*.name' => ['nullable', 'string', 'max:200'],
            'family_information.emergency_contacts.*.relationship' => ['nullable', 'string', 'max:100'],
            'family_information.emergency_contacts.*.phone' => ['nullable', 'string', 'max:30'],
            'family_information.emergency_contacts.*.email' => ['nullable', 'email', 'max:255'],
            'family_information.emergency_contacts.*.current_address' => ['nullable', 'string', 'max:500'],
            'family_information.beneficiaries' => ['sometimes', 'array', 'max:50'],
            'family_information.beneficiaries.*.full_name' => ['nullable', 'string', 'max:200'],
            'family_information.beneficiaries.*.date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'family_information.beneficiaries.*.identity_number' => ['nullable', 'string', 'max:100'],
            'family_information.beneficiaries.*.relationship' => ['nullable', 'string', 'max:100'],
            'family_information.beneficiaries.*.contact_number' => ['nullable', 'string', 'max:30'],
            'family_information.beneficiaries.*.address' => ['nullable', 'string', 'max:500'],
            'family_information.beneficiaries.*.share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'compliance_acknowledgements' => ['sometimes', 'array'],
            'compliance_acknowledgements.*' => ['boolean'],
        ]);

        if (array_key_exists('compliance_acknowledgements', $data)) {
            $unknownPolicies = array_diff(
                array_keys($data['compliance_acknowledgements']),
                CompliancePolicy::query()->pluck('key')->all(),
            );
            if ($unknownPolicies !== []) {
                throw ValidationException::withMessages([
                    'compliance_acknowledgements' => 'One or more compliance policies are no longer available.',
                ]);
            }
        }

        if ($request->boolean('family_information_complete')) {
            foreach ($data['family_information']['children'] ?? [] as $index => $child) {
                $hasChildDetails = collect($child)->contains(fn ($value): bool => filled($value));
                if (!$hasChildDetails) {
                    continue;
                }

                $errors = [];
                if (!filled($child['name'] ?? null)) {
                    $errors["family_information.children.$index.name"] = 'Enter the child’s name or remove the empty child entry.';
                }
                if (!filled($child['date_of_birth'] ?? null)) {
                    $errors["family_information.children.$index.date_of_birth"] = 'Enter the child’s date of birth or remove the incomplete child entry.';
                }
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }

            foreach ($data['family_information']['emergency_contacts'] ?? [] as $index => $contact) {
                if (!collect($contact)->contains(fn ($value): bool => filled($value))) {
                    continue;
                }

                $errors = [];
                foreach (['name' => 'name', 'relationship' => 'relationship', 'phone' => 'phone'] as $field => $label) {
                    if (!filled($contact[$field] ?? null)) {
                        $errors["family_information.emergency_contacts.$index.$field"] = "Enter the emergency contact $label or remove the incomplete entry.";
                    }
                }
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }

            foreach ($data['family_information']['beneficiaries'] ?? [] as $index => $beneficiary) {
                if (!collect($beneficiary)->contains(fn ($value): bool => filled($value))) {
                    continue;
                }

                $errors = [];
                foreach ([
                    'full_name' => 'full name',
                    'date_of_birth' => 'date of birth',
                    'relationship' => 'relationship',
                    'contact_number' => 'contact number',
                    'address' => 'address',
                    'share_percent' => 'share of benefits',
                ] as $field => $label) {
                    if (!filled($beneficiary[$field] ?? null)) {
                        $errors["family_information.beneficiaries.$index.$field"] = "Enter the beneficiary $label or remove the incomplete entry.";
                    }
                }
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }
        }

        $beneficiaries = $data['family_information']['beneficiaries'] ?? [];
        $beneficiaries = array_values(array_filter(
            $beneficiaries,
            fn (array $beneficiary): bool => collect($beneficiary)->contains(fn ($value): bool => filled($value)),
        ));
        if ($request->boolean('family_information_complete') && $beneficiaries !== []) {
            $totalShares = array_sum(array_map(
                fn (array $beneficiary): float => (float) $beneficiary['share_percent'],
                $beneficiaries,
            ));
            if (abs($totalShares - 100) > 0.01) {
                throw ValidationException::withMessages([
                    'family_information.beneficiaries' => 'Beneficiary shares must add up to 100%.',
                ]);
            }
        }

        if (array_key_exists('personal_information', $data)) {
            $user->personal_information = $data['personal_information'];
        }

        if (array_key_exists('professional_qualifications', $data)) {
            $user->professional_qualifications = $data['professional_qualifications'];
        }

        if (array_key_exists('family_information', $data)) {
            $data['family_information']['children'] = array_values(array_filter(
                $data['family_information']['children'] ?? [],
                fn (array $child): bool => collect($child)->contains(fn ($value): bool => filled($value)),
            ));
            $data['family_information']['emergency_contacts'] = array_values(array_filter(
                $data['family_information']['emergency_contacts'] ?? [],
                fn (array $contact): bool => collect($contact)->contains(fn ($value): bool => filled($value)),
            ));
            $data['family_information']['beneficiaries'] = $beneficiaries;
            $user->family_information = $data['family_information'];
        }

        if (array_key_exists('compliance_acknowledgements', $data)) {
            $acknowledgements = $user->compliance_acknowledgements ?? [];
            foreach ($data['compliance_acknowledgements'] as $policy => $accepted) {
                if ($accepted) {
                    $acknowledgements[$policy] ??= now()->toIso8601String();
                }
            }
            $user->compliance_acknowledgements = $acknowledgements;
        }

        if ($request->hasFile('profile_photo')) {
            $newPath = $request->file('profile_photo')->store('profile-photos', 'public');
            $oldPath = $user->profile_photo;
            $user->profile_photo = $newPath;
            if ($oldPath && $oldPath !== $newPath) Storage::disk('public')->delete($oldPath);
        }

        $user->save();

        return new UserResource($user->fresh());
    }

    public function updatePassword(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (!Hash::check($data['current_password'], $request->user()->password)) {
            return response()->json(['message' => 'The current password is incorrect.'], 422);
        }

        $request->user()->update(['password' => $data['new_password']]);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function profilePhoto(Request $request)
    {
        $path = $request->user()->profile_photo;
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return response()->file(Storage::disk('public')->path($path), [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
