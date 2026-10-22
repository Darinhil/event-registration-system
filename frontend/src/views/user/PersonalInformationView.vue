<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRouter } from 'vue-router'
import UserLayout from '../../layouts/UserLayout.vue'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const router = useRouter()
const saving = ref(false)
const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const isHydrated = ref(false)

const blankInformation = () => ({
  first_name: '',
  legal_name: '',
  surname: '',
  family_name: '',
  gender: '',
  date_of_birth: '',
  place_of_birth: '',
  citizenship: '',
  id_passport_number: '',
  primary_phone: '',
  alternate_phone: '',
  primary_email: '',
  alternate_email: '',
  address: {
    house_number: '',
    street_number: '',
    village: '',
    commune: '',
    district: '',
    province: '',
    postal_code: '',
  },
})

const form = reactive(blankInformation())
const snapshot = ref('')
const formSnapshot = () => JSON.stringify(form)
const isDirty = computed(() => isHydrated.value && snapshot.value !== formSnapshot())
const staffId = computed(() => auth.user?.id ? `STAFF-${String(auth.user.id).padStart(6, '0')}` : 'Generated when saved')
const countryCodes = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW'.split(' ')
const displayNames = new Intl.DisplayNames(['en'], { type: 'region' })
const countries = countryCodes
  .map((code) => ({ code, name: displayNames.of(code) || code }))
  .sort((a, b) => a.name.localeCompare(b.name))

const setFormValues = (user) => {
  const stored = user?.personal_information || {}
  const defaults = blankInformation()
  const nameParts = (user?.name || '').trim().split(/\s+/).filter(Boolean)
  Object.assign(form, defaults, stored, {
    first_name: stored.first_name || nameParts[0] || '',
    surname: stored.surname || nameParts.slice(1).join(' '),
    primary_phone: stored.primary_phone || user?.phone || '',
    primary_email: stored.primary_email || user?.email || '',
    address: { ...defaults.address, ...(stored.address || {}) },
  })
  snapshot.value = formSnapshot()
  isHydrated.value = true
}

onMounted(async () => {
  setFormValues(auth.user)
  loading.value = true
  try {
    await auth.fetchMe()
    if (!isDirty.value) setFormValues(auth.user)
  } catch {
    errorMessage.value = 'Unable to refresh your saved information. You can still edit and save your changes.'
  } finally {
    loading.value = false
  }
})

watch(form, () => {
  if (isHydrated.value) {
    errorMessage.value = ''
    successMessage.value = ''
  }
}, { deep: true })

const saveInformation = async (complete = false) => {
  errorMessage.value = ''
  successMessage.value = ''
  if (complete && (!form.first_name.trim() || !form.surname.trim())) {
    errorMessage.value = 'Enter your first name and surname before continuing.'
    await goToSection('personal')
    return
  }

  saving.value = true
  try {
    await auth.updateProfile({
      personal_information: JSON.parse(formSnapshot()),
      personal_information_complete: complete,
    })
    snapshot.value = formSnapshot()
    successMessage.value = complete
      ? 'Your personal information is complete and saved.'
      : 'Your personal information has been saved as a draft.'
    if (complete) await router.push('/me/professional-qualifications')
  } catch (error) {
    const validationMessage = Object.values(error.response?.data?.errors || {}).flat()[0]
    errorMessage.value = validationMessage || error.response?.data?.message || 'Unable to save your information. Please try again.'
  } finally {
    saving.value = false
  }
}

const confirmExit = () => !isDirty.value || window.confirm('You have unsaved changes. Leave without saving?')

onBeforeRouteLeave(() => {
  if (confirmExit()) return true
  return false
})
</script>

<template>
  <UserLayout onboarding>
    <div class="personal-info-page">
      <header class="onboarding-topbar">
        <RouterLink class="onboarding-brand" to="/" aria-label="StaffHub home">
          <span class="onboarding-brand-mark">S</span>
          <span><strong>StaffHub</strong><small>ONBOARDING PORTAL</small></span>
        </RouterLink>
        <div class="onboarding-breadcrumb"><span>Workforce</span><span aria-hidden="true">›</span><strong>New Hire Onboarding</strong></div>
        <div class="onboarding-top-actions">
          <span class="onboarding-status"><span></span> IN PROGRESS</span>
          <span class="onboarding-support">ⓘ <span>Support &amp; Help</span></span>
          <RouterLink class="onboarding-avatar" to="/profile" aria-label="Account profile">U</RouterLink>
        </div>
      </header>

      <div class="personal-info-shell">
        <nav class="onboarding-steps" aria-label="Onboarding steps">
          <span class="onboarding-step is-active" aria-current="step"><span>1</span> Personal Information</span>
          <span class="onboarding-step is-upcoming"><span>2</span> Professional Qualifications</span>
          <span class="onboarding-step is-upcoming"><span>3</span> Family Information</span>
          <span class="onboarding-step is-upcoming"><span>4</span> Supporting Documents</span>
          <span class="onboarding-step is-upcoming"><span>5</span> Declaration</span>
        </nav>
        <header class="personal-info-heading">
          <div>
            <p class="personal-info-eyebrow"><span>STEP 1 OF 5</span> NEW HIRE PROFILE</p>
            <h1>Personal information</h1>
            <p class="personal-info-intro">Please provide accurate personal and contact details for official records and employee registration.</p>
          </div>
          <div class="onboarding-completion">
            <div><span>Overall Completion</span><strong>20%</strong></div>
            <span class="onboarding-progress"><i></i></span>
            <small>Next: Professional Qualifications</small>
          </div>
        </header>

        <div v-if="loading" class="personal-info-loading" role="status">Refreshing your saved information…</div>
        <div v-if="errorMessage" class="personal-info-alert is-error" role="alert">{{ errorMessage }}</div>
        <div v-if="successMessage" class="personal-info-alert is-success" role="status">{{ successMessage }}</div>

        <form class="personal-info-form" @submit.prevent="saveInformation(true)">
          <section class="personal-info-card" aria-labelledby="personal-details-title">
            <div class="personal-info-card-heading">
              <span class="personal-info-card-icon" aria-hidden="true">♙</span>
              <div><h2 id="personal-details-title">Section 1: Basic Information</h2><p>Core identity metrics registered to your employee ledger</p></div>
              <div class="personal-info-id"><span><small>STAFF ID</small><strong>{{ staffId }}</strong></span><span aria-hidden="true">♙</span></div>
            </div>
            <div class="personal-info-grid">
              <label class="personal-info-field">First name <span>*</span>
                <input v-model.trim="form.first_name" name="first_name" autocomplete="given-name" placeholder="e.g. Dara" required maxlength="100" />
              </label>
              <label class="personal-info-field">Legal name
                <input v-model.trim="form.legal_name" name="legal_name" autocomplete="additional-name" placeholder="As shown on official documents" maxlength="200" />
              </label>
              <label class="personal-info-field">Surname <span>*</span>
                <input v-model.trim="form.surname" name="surname" autocomplete="family-name" placeholder="e.g. Sok" required maxlength="100" />
              </label>
              <label class="personal-info-field">Family name
                <input v-model.trim="form.family_name" name="family_name" placeholder="Family name" maxlength="100" />
              </label>
              <label class="personal-info-field">Gender <span>*</span>
                <select v-model="form.gender" name="gender" required>
                  <option value="">Select gender</option>
                  <option>Male</option><option>Female</option><option>Non-binary</option><option>Prefer not to say</option>
                </select>
              </label>
              <label class="personal-info-field">Date of birth <span>*</span>
                <input v-model="form.date_of_birth" name="date_of_birth" type="date" autocomplete="bday" required :max="new Date().toISOString().slice(0, 10)" />
              </label>
              <label class="personal-info-field">Place of birth
                <input v-model.trim="form.place_of_birth" name="place_of_birth" placeholder="City or province" maxlength="120" />
              </label>
              <label class="personal-info-field">Citizenship
                <select v-model="form.citizenship" name="citizenship">
                  <option value="">Select a country</option>
                  <option v-for="country in countries" :key="country.code" :value="country.name">{{ country.name }}</option>
                </select>
              </label>
              <label class="personal-info-field">National ID or Passport Number <span>*</span>
                <input v-model.trim="form.id_passport_number" name="id_passport_number" autocomplete="off" placeholder="e.g., A12345678 or 089123456" required maxlength="100" />
              </label>
            </div>
          </section>

          <section class="personal-info-card" aria-labelledby="contact-details-title">
            <div class="personal-info-card-heading">
              <span class="personal-info-card-icon is-contact" aria-hidden="true">▣</span>
              <div><h2 id="contact-details-title">Section 2: Contact Information</h2><p>Channels for dispatch, digital access, and official correspondence</p></div>
            </div>

            <div class="personal-info-group-heading"><span>GROUP A — TELEPHONE &amp; EMAIL</span></div>
            <div class="personal-info-grid personal-info-contact-grid">
              <label class="personal-info-field">Primary Phone Number <span>*</span>
                <input v-model.trim="form.primary_phone" name="primary_phone" type="tel" autocomplete="tel" inputmode="tel" placeholder="(555) 012-3456" required maxlength="30" />
              </label>
              <label class="personal-info-field">Alternate Phone Number <small>(Optional)</small>
                <input v-model.trim="form.alternate_phone" name="alternate_phone" type="tel" autocomplete="tel-national" inputmode="tel" placeholder="(555) 098-7654" maxlength="30" />
              </label>
              <label class="personal-info-field">Primary Email Address <span>*</span>
                <input v-model.trim="form.primary_email" name="primary_email" type="email" autocomplete="email" placeholder="alex.henderson@email.com" required maxlength="255" />
              </label>
              <label class="personal-info-field">Alternate Email Address <small>(Optional)</small>
                <input v-model.trim="form.alternate_email" name="alternate_email" type="email" autocomplete="email" placeholder="alex.personal@gmail.com" maxlength="255" />
              </label>
            </div>

            <div class="personal-info-address-panel">
              <div class="personal-info-address-heading">
                <div><h3>GROUP B — CURRENT ADDRESS</h3></div>
                <span>⌖ Physical Residence</span>
              </div>
              <div class="personal-info-grid personal-info-address-grid">
                <label class="personal-info-field">House Number <span>*</span>
                  <input v-model.trim="form.address.house_number" name="house_number" inputmode="numeric" placeholder="e.g., 428" required maxlength="20" />
                </label>
                <label class="personal-info-field personal-info-field-wide">Street Number / Name <span>*</span>
                  <input v-model.trim="form.address.street_number" name="street_number" placeholder="e.g., 315 West St. or St. 2004" required maxlength="120" />
                </label>
                <label class="personal-info-field">Village / Locality
                  <input v-model.trim="form.address.village" name="village" placeholder="e.g., Village 4 / Phum 2" maxlength="120" />
                </label>
                <label class="personal-info-field">Commune / Sub-district
                  <input v-model.trim="form.address.commune" name="commune" placeholder="e.g., Sangkat Teuk Thla" maxlength="120" />
                </label>
                <label class="personal-info-field">District <span>*</span>
                  <input v-model.trim="form.address.district" name="district" placeholder="e.g., Khan Sen Sok" required maxlength="120" />
                </label>
                <label class="personal-info-field">Province / State <span>*</span>
                  <input v-model.trim="form.address.province" name="province" placeholder="e.g., Phnom Penh" required maxlength="120" />
                </label>
                <label class="personal-info-field">Postal / ZIP Code
                  <input v-model.trim="form.address.postal_code" name="postal_code" inputmode="numeric" autocomplete="postal-code" placeholder="e.g., 12101" maxlength="20" />
                </label>
              </div>
            </div>
          </section>

          <footer class="personal-info-actions">
            <div class="personal-info-action-group">
              <RouterLink class="personal-info-button is-quiet" to="/">⌂ Home</RouterLink>
              <RouterLink class="personal-info-button is-exit" to="/me">Exit</RouterLink>
            </div>
            <div class="personal-info-action-group">
              <button class="personal-info-button is-draft" type="button" :disabled="saving" @click="saveInformation(false)">
                ▣ {{ saving ? 'Saving…' : 'Save as Draft' }}
              </button>
              <button class="personal-info-button is-next" type="submit" :disabled="saving">
                Next: Professional Qualifications <span aria-hidden="true">→</span>
              </button>
            </div>
          </footer>
        </form>
      </div>
      <footer class="onboarding-footer">
        <span><strong>StaffHub Enterprise</strong> · © 2025 StaffHub Human Resources Portal. All rights reserved.</span>
        <nav aria-label="Footer links"><span>Support Desk</span><span>Security &amp; Compliance</span><span>Privacy Notice</span></nav>
      </footer>
    </div>
  </UserLayout>
</template>

<style scoped>
.personal-info-page { --pi-ink: #18312b; --pi-muted: #728078; --pi-green: #16846e; --pi-line: #e6ede9; width: min(100%, 1080px); margin: 0 auto; padding: 42px 0 64px; color: var(--pi-ink); }
.personal-info-shell { max-width: 920px; margin: 0 auto; }
.personal-info-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 24px; margin-bottom: 28px; }
.personal-info-eyebrow { display: flex; align-items: center; gap: 8px; margin: 0 0 10px; color: var(--pi-green); font-size: 11px; font-weight: 800; letter-spacing: .13em; }
.personal-info-eyebrow span { width: 7px; height: 7px; border-radius: 50%; background: #35b996; box-shadow: 0 0 0 4px #e4f7f1; }
.personal-info-heading h1 { margin: 0; color: #18312b; font-size: clamp(28px, 4vw, 38px); font-weight: 750; letter-spacing: -.04em; line-height: 1.15; }
.personal-info-intro { margin: 10px 0 0; color: var(--pi-muted); font-size: 14px; }
.personal-info-id { display: flex; align-items: center; gap: 12px; padding: 12px 15px; border: 1px solid var(--pi-line); border-radius: 14px; background: #fff; box-shadow: 0 5px 18px rgba(30, 70, 55, .04); }
.personal-info-id-icon { display: grid; width: 38px; height: 38px; place-items: center; border-radius: 11px; background: #e8f6f1; color: var(--pi-green); font-size: 12px; font-weight: 800; }
.personal-info-id small, .personal-info-id strong { display: block; }
.personal-info-id small { color: #8b9991; font-size: 9px; font-weight: 800; letter-spacing: .1em; }
.personal-info-id strong { margin-top: 3px; font-size: 13px; letter-spacing: .03em; }
.personal-info-auto { align-self: flex-start; padding: 3px 6px; border-radius: 5px; background: #f1f5f3; color: #688077; font-size: 8px; font-weight: 800; letter-spacing: .06em; }
.personal-info-stepper { display: flex; align-items: center; gap: 16px; margin-bottom: 22px; padding: 14px 20px; border: 1px solid var(--pi-line); border-radius: 15px; background: #fff; }
.personal-info-stepper button { display: flex; min-width: 0; align-items: center; gap: 11px; padding: 0; border: 0; background: transparent; color: var(--pi-muted); text-align: left; cursor: pointer; }
.personal-info-stepper button > span:last-child { display: grid; gap: 2px; }
.personal-info-stepper strong { color: #64746c; font-size: 12px; font-weight: 700; }
.personal-info-stepper small { font-size: 11px; }
.personal-info-step-number { display: grid; width: 32px; height: 32px; flex: 0 0 32px; place-items: center; border: 1px solid #dce6e1; border-radius: 50%; color: #75857d; font-size: 12px; font-weight: 800; }
.personal-info-stepper button.is-active .personal-info-step-number { border-color: #16846e; background: #16846e; color: #fff; box-shadow: 0 0 0 4px #e6f5f0; }
.personal-info-stepper button.is-active strong { color: #18312b; }
.personal-info-step-line { width: 54px; height: 1px; flex: 0 0 54px; background: #dce6e1; }
.personal-info-loading { margin: 0 0 12px; color: var(--pi-muted); font-size: 12px; }
.personal-info-alert { margin-bottom: 14px; padding: 12px 15px; border: 1px solid transparent; border-radius: 10px; font-size: 13px; }
.personal-info-alert.is-error { border-color: #f3d2ce; background: #fff6f5; color: #a33a2f; }
.personal-info-alert.is-success { border-color: #cbe9dd; background: #f1fbf6; color: #167357; }
.personal-info-form { display: grid; gap: 16px; }
.personal-info-card { scroll-margin-top: 24px; padding: 24px 26px 26px; border: 1px solid var(--pi-line); border-radius: 16px; background: #fff; box-shadow: 0 8px 26px rgba(30, 70, 55, .045); }
.personal-info-card-heading { display: flex; align-items: center; gap: 13px; margin-bottom: 23px; padding-bottom: 18px; border-bottom: 1px solid #eef2ef; }
.personal-info-card-icon { display: grid; width: 40px; height: 40px; flex: 0 0 40px; place-items: center; border-radius: 12px; background: #e9f6f1; color: #16846e; font-size: 11px; font-weight: 800; }
.personal-info-card-icon.is-contact { background: #edf3fb; color: #5278ac; }
.personal-info-card-heading h2 { margin: 0; color: #1b342d; font-size: 16px; font-weight: 750; letter-spacing: -.01em; }
.personal-info-card-heading p { margin: 4px 0 0; color: var(--pi-muted); font-size: 12px; }
.personal-info-card-heading p span, .personal-info-field > span { color: #cf5546; }
.personal-info-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 17px 20px; }
.personal-info-field { display: grid; min-width: 0; gap: 7px; color: #364a42; font-size: 12px; font-weight: 700; }
.personal-info-field input, .personal-info-field select { box-sizing: border-box; width: 100%; min-width: 0; height: 43px; padding: 0 12px; border: 1px solid #dce6e1; border-radius: 9px; outline: none; background: #fff; color: #20352e; font: inherit; font-size: 13px; font-weight: 450; transition: border-color .16s, box-shadow .16s; }
.personal-info-field input::placeholder { color: #a0aca5; }
.personal-info-field input:focus, .personal-info-field select:focus { border-color: #39a58a; box-shadow: 0 0 0 3px rgba(22, 132, 110, .12); }
.personal-info-field select:invalid, .personal-info-field select option[value=""] { color: #8f9c95; }
.personal-info-field-wide { grid-column: 1 / -1; }
.personal-info-address-heading { display: flex; align-items: center; justify-content: space-between; margin: 25px 0 16px; padding-top: 20px; border-top: 1px solid #eef2ef; }
.personal-info-address-heading h3 { margin: 0; color: #253b33; font-size: 14px; font-weight: 750; }
.personal-info-address-heading p { margin: 4px 0 0; color: var(--pi-muted); font-size: 11px; }
.personal-info-address-heading > span { display: grid; width: 34px; height: 34px; place-items: center; border-radius: 10px; background: #f0f5f2; color: #16846e; font-size: 18px; }
.personal-info-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 0 0; }
.personal-info-action-group { display: flex; align-items: center; gap: 9px; }
.personal-info-button { display: inline-flex; min-height: 42px; align-items: center; justify-content: center; gap: 8px; padding: 0 16px; border: 1px solid transparent; border-radius: 9px; font: inherit; font-size: 12px; font-weight: 750; text-decoration: none; cursor: pointer; transition: background .15s, transform .15s, border-color .15s; }
.personal-info-button:hover:not(:disabled) { transform: translateY(-1px); }
.personal-info-button.is-quiet { border-color: #dce6e1; background: #fff; color: #42564d; }
.personal-info-button.is-next { border-color: #dce6e1; background: #fff; color: #344a41; }
.personal-info-button.is-save { background: #16846e; color: #fff; box-shadow: 0 5px 12px rgba(22, 132, 110, .18); }
.personal-info-button.is-save:hover:not(:disabled) { background: #116f5d; }
.personal-info-button.is-save:disabled { background: #9dbdb3; box-shadow: none; cursor: not-allowed; }
.personal-info-button.is-exit { color: #8a9690; }
.personal-info-privacy { margin: 14px 0 0; color: #89968f; font-size: 11px; text-align: center; }
.personal-info-privacy span { margin-right: 5px; color: #16846e; }
@media (max-width: 650px) {
  .personal-info-page { padding: 25px 0 40px; }
  .personal-info-heading { align-items: flex-start; flex-direction: column; gap: 16px; margin-bottom: 20px; }
  .personal-info-id { width: 100%; box-sizing: border-box; }
  .personal-info-stepper { gap: 10px; padding: 12px; }
  .personal-info-stepper button { gap: 8px; }
  .personal-info-stepper strong { font-size: 10px; }
  .personal-info-stepper small { font-size: 9px; }
  .personal-info-step-line { width: 18px; flex-basis: 18px; }
  .personal-info-card { padding: 19px 16px 20px; }
  .personal-info-grid { grid-template-columns: 1fr; gap: 14px; }
  .personal-info-field-wide { grid-column: auto; }
  .personal-info-actions, .personal-info-action-group { align-items: stretch; }
  .personal-info-actions { flex-direction: column; }
  .personal-info-action-group { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .personal-info-action-group .is-save { grid-column: 1 / -1; grid-row: 1; }
  .personal-info-action-group .is-next, .personal-info-action-group .is-exit { grid-row: 2; }
}
</style>

<style scoped>
:global(.shell.onboarding-shell > main) { padding: 0; }
.personal-info-page {
  --pi-ink: #142746;
  --pi-muted: #6d7c98;
  --pi-line: #e3e9f4;
  width: 100%;
  min-height: 100vh;
  margin: 0;
  padding: 0 0 104px;
  background: #f5f7fc;
  color: var(--pi-ink);
  font-family: Inter, "Segoe UI", sans-serif;
}
.onboarding-topbar {
  display: flex;
  min-height: 52px;
  align-items: center;
  gap: 24px;
  padding: 0 max(18px, calc((100vw - 1240px) / 2));
  border-bottom: 1px solid #e8edf6;
  background: #fff;
}
.onboarding-brand { display: flex; flex: 0 0 auto; align-items: center; gap: 8px; color: #17335e; text-decoration: none; }
.onboarding-brand-mark { display: grid; width: 24px; height: 24px; place-items: center; border-radius: 4px; background: #2446c6; color: #fff; font-size: 13px; font-weight: 800; }
.onboarding-brand > span:last-child { display: grid; gap: 1px; }
.onboarding-brand strong { font-size: 11px; line-height: 1; }
.onboarding-brand small { color: #7886a1; font-size: 7px; font-weight: 700; letter-spacing: .07em; }
.onboarding-breadcrumb { display: flex; align-items: center; gap: 9px; color: #77849b; font-size: 10px; }
.onboarding-breadcrumb strong { color: #253958; font-weight: 650; }
.onboarding-top-actions { display: flex; align-items: center; gap: 14px; margin-left: auto; }
.onboarding-status { display: inline-flex; align-items: center; gap: 5px; padding: 4px 8px; border-radius: 999px; background: #edf1ff; color: #344ec0; font-size: 8px; font-weight: 750; letter-spacing: .04em; }
.onboarding-status > span { width: 5px; height: 5px; border-radius: 50%; background: #526be7; }
.onboarding-support { color: #556580; font-size: 10px; white-space: nowrap; }
.onboarding-avatar { display: grid; width: 25px; height: 25px; place-items: center; border-radius: 8px; background: #2446c6; color: white; font-size: 10px; font-weight: 700; text-decoration: none; }
.personal-info-shell { width: min(100% - 36px, 1200px); max-width: 1200px; margin: 0 auto; }
.onboarding-steps { display: flex; min-width: 0; align-items: stretch; margin: 8px 0 16px; overflow-x: auto; border-bottom: 1px solid #dfe6f2; background: #fff; }
.onboarding-step { display: flex; flex: 0 0 auto; align-items: center; gap: 6px; padding: 9px 12px; border-bottom: 2px solid transparent; color: #6e7d97; font-size: 9px; white-space: nowrap; }
.onboarding-step > span { display: grid; width: 14px; height: 14px; place-items: center; border-radius: 50%; background: #e7edf7; color: #687994; font-size: 8px; font-weight: 700; }
.onboarding-step.is-active { border-color: #2949d0; color: #243fb1; }
.onboarding-step.is-active > span { background: #dfe6ff; color: #304bc4; }
.personal-info-heading { min-height: 78px; align-items: center; gap: 20px; margin-bottom: 14px; padding: 8px 12px; border-radius: 8px; background: #fff; }
.personal-info-eyebrow { gap: 6px; margin-bottom: 4px; color: #56698b; font-size: 8px; letter-spacing: .06em; }
.personal-info-eyebrow span { padding: 3px 6px; border-radius: 4px; background: #314dc9; box-shadow: none; color: #fff; font-size: 8px; letter-spacing: 0; }
.personal-info-heading h1 { color: #152945; font-size: 21px; font-weight: 700; letter-spacing: -.03em; }
.personal-info-intro { margin-top: 5px; color: #6e7c95; font-size: 10px; }
.onboarding-completion { width: 132px; flex: 0 0 auto; padding: 9px 10px; border-radius: 6px; background: #f3f6ff; color: #667694; font-size: 8px; }
.onboarding-completion > div { display: flex; justify-content: space-between; gap: 8px; }
.onboarding-completion strong { color: #344dca; font-size: 8px; }
.onboarding-progress { display: block; height: 4px; margin: 5px 0; overflow: hidden; border-radius: 99px; background: #dce4f5; }
.onboarding-progress i { display: block; width: 20%; height: 100%; border-radius: inherit; background: #304bc9; }
.onboarding-completion small { font-size: 7px; }
.personal-info-loading { color: var(--pi-muted); }
.personal-info-form { gap: 12px; }
.personal-info-card { padding: 13px 16px 15px; border: 1px solid #e8edf6; border-radius: 7px; box-shadow: none; }
.personal-info-card-heading { min-height: 36px; gap: 9px; margin: -13px -16px 12px; padding: 7px 12px; border-bottom: 1px solid #e5ebf6; border-radius: 7px 7px 0 0; background: #edf2ff; }
.personal-info-card-icon { width: 20px; height: 20px; flex-basis: 20px; border-radius: 4px; background: #dce6ff; color: #2948ce; font-size: 10px; }
.personal-info-card-icon.is-contact { background: #dce6ff; color: #2948ce; }
.personal-info-card-heading h2 { color: #1c3152; font-size: 10px; }
.personal-info-card-heading p { margin-top: 2px; color: #697a98; font-size: 8px; }
.personal-info-card-heading .personal-info-id { gap: 7px; margin-left: auto; padding: 4px 8px; border: 0; border-radius: 4px; background: #fff; box-shadow: none; }
.personal-info-card-heading .personal-info-id small { color: #7f8ca4; font-size: 6px; }
.personal-info-card-heading .personal-info-id strong { margin-top: 2px; color: #2847c8; font-size: 8px; }
.personal-info-card-heading .personal-info-id > span:last-child { color: #687997; font-size: 10px; }
.personal-info-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 11px 14px; }
.personal-info-field { display: block; color: #263b5e; font-size: 8px; font-weight: 650; }
.personal-info-field > span { color: #d84d54; }
.personal-info-field > small { display: inline; color: #8995a9; font-size: 7px; font-weight: 450; }
.personal-info-field input, .personal-info-field select { display: block; height: 24px; margin-top: 3px; padding: 0 8px; border-color: #edf0f6; border-radius: 4px; color: #354764; font-size: 8px; }
.personal-info-field input::placeholder { color: #a1abc0; }
.personal-info-field input:focus, .personal-info-field select:focus { border-color: #7189ed; box-shadow: 0 0 0 2px #e8edff; }
.personal-info-field-wide { grid-column: auto; }
.personal-info-group-heading { margin: 0 0 8px; color: #304b80; font-size: 8px; font-weight: 800; letter-spacing: .04em; }
.personal-info-contact-grid { margin-bottom: 10px; }
.personal-info-address-panel { padding: 10px; border-radius: 5px; background: #f7f9fe; }
.personal-info-address-heading { margin: 0 0 8px; padding: 0; border: 0; }
.personal-info-address-heading h3 { color: #2b4268; font-size: 8px; font-weight: 800; letter-spacing: .04em; }
.personal-info-address-heading > span { display: inline; width: auto; height: auto; border-radius: 0; background: transparent; color: #556681; font-size: 7px; }
.personal-info-address-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 9px 10px; }
.personal-info-address-grid .personal-info-field-wide { grid-column: span 3; }
.personal-info-alert { padding: 8px 11px; border-radius: 5px; font-size: 10px; }
.personal-info-actions { position: fixed; z-index: 5; right: 0; bottom: 0; left: 0; min-height: 54px; justify-content: space-between; padding: 7px max(18px, calc((100vw - 1200px) / 2)); border-top: 1px solid #e5eaf3; background: rgba(255,255,255,.97); box-shadow: 0 -5px 14px rgba(22, 43, 80, .06); }
.personal-info-action-group { gap: 7px; }
.personal-info-button { min-height: 32px; padding: 0 11px; border-radius: 4px; font-size: 8px; }
.personal-info-button.is-quiet { border-color: #e2e7ef; background: #fff; color: #4c5b73; }
.personal-info-button.is-exit { border: 1px solid #e2e7ef; color: #4c5b73; }
.personal-info-button.is-draft { border-color: #d9e1ef; background: #f8faff; color: #405372; }
.personal-info-button.is-next { border-color: #2f49c4; background: #2f49c4; color: #fff; }
.personal-info-button:disabled { opacity: .6; cursor: not-allowed; }
.onboarding-footer { display: flex; justify-content: space-between; gap: 12px; padding: 8px max(18px, calc((100vw - 1200px) / 2)); color: #6e7a90; font-size: 7px; }
.onboarding-footer strong { color: #42516a; }
.onboarding-footer nav { display: flex; gap: 13px; white-space: nowrap; }

@media (max-width: 700px) {
  .onboarding-topbar { gap: 12px; padding-inline: 12px; }
  .onboarding-breadcrumb { font-size: 8px; }
  .onboarding-support span { display: none; }
  .personal-info-shell { width: calc(100% - 20px); }
  .personal-info-heading { align-items: center; flex-direction: row; }
  .onboarding-completion { width: 132px; }
  .personal-info-actions { padding-inline: 10px; }
  .personal-info-actions { flex-direction: row; align-items: center; }
  .personal-info-action-group { display: flex; align-items: center; }
  .personal-info-button { padding-inline: 8px; font-size: 7px; }
  .onboarding-footer { flex-direction: column; padding-inline: 10px; }
}
@media (max-width: 440px) {
  .onboarding-topbar { gap: 8px; }
  .onboarding-breadcrumb { display: none; }
  .personal-info-heading { flex-direction: column; }
  .onboarding-completion { width: 100%; box-sizing: border-box; }
  .personal-info-grid, .personal-info-address-grid { grid-template-columns: 1fr; }
  .personal-info-address-grid .personal-info-field-wide { grid-column: auto; }
  .personal-info-actions { align-items: stretch; }
  .personal-info-actions .personal-info-action-group { gap: 5px; }
  .onboarding-footer nav { flex-wrap: wrap; }
}
@media (min-width: 441px) and (max-width: 550px) {
  .personal-info-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .personal-info-address-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .personal-info-address-grid .personal-info-field-wide { grid-column: span 1; }
}
</style>
