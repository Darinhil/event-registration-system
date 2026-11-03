<script setup>
import { computed, reactive } from 'vue'
import AppIcon from '../AppIcon.vue'
import { getMyCompliances } from '../../services/complianceService'
import { getMyContracts } from '../../services/contractService'
import { getPersonnelProfile } from '../../services/staffRequestService'
import { getMyTraining } from '../../services/trainingService'

const describe = (error, fallback) => (!error.response ? 'We couldn’t reach the server. Check your connection and try again.'
  : error.response.status >= 500 ? 'Something went wrong on our side. Please try again in a moment.'
  : error.response.data?.message || fallback)

/* ---------- Data: each panel loads on its own, so one failure leaves the others working ---------- */
const source = (request, fallback) => {
  const state = reactive({ body: null, loading: true, error: '' })
  request()
    .then(({ data }) => { state.body = data })
    .catch((error) => { state.error = describe(error, fallback) })
    .finally(() => { state.loading = false })
  return state
}
const profile = source(getPersonnelProfile, 'Your profile couldn’t be loaded.')
const compliance = source(getMyCompliances, 'Your compliance items couldn’t be loaded.')
const contractSource = source(getMyContracts, 'Contracts couldn’t be loaded.')
const training = source(getMyTraining, 'Your training couldn’t be loaded.')

const to = (section, extra = {}) => ({ path: '/admin/personnel-profile', query: { section, ...extra } })
const formatDate = (iso) => (iso ? new Date(iso.length === 10 ? `${iso}T00:00:00` : iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '')
const plural = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`

/* ---------- Profile: the same required fields as the steps in PersonnelProfileForm ---------- */
const filled = (value) => String(value ?? '').trim() !== ''
const rowsFilled = (rows, keys) => (rows || []).every((row) => keys.every((key) => filled(row[key])))
const profileSteps = computed(() => {
  const saved = profile.body?.data.profile || {}
  const documents = profile.body?.data.documents || {}
  const declarations = saved.declarations || {}
  return [
    { title: 'Personal info', complete: ['gender', 'dob', 'birthPlace', 'idNumber', 'phone', 'email'].every((key) => filled(saved[key])) },
    { title: 'Qualifications', complete: Boolean(saved.education?.length) && rowsFilled(saved.education, ['degree', 'institution']) && rowsFilled(saved.experience, ['organisation', 'position']) },
    { title: 'Family details', complete: Boolean(saved.contacts?.length) && rowsFilled(saved.contacts, ['name', 'relationship', 'phone']) && rowsFilled(saved.children, ['name']) && rowsFilled(saved.beneficiaries, ['name', 'share']) && (saved.maritalStatus !== 'Married' || filled(saved.spouse)) },
    { title: 'Documents', complete: Boolean(documents.id && documents.cv) },
    { title: 'Declarations', complete: Boolean(declarations.accurate && declarations.updates && declarations.consent) && filled(saved.signature) },
  ]
})
const stepsDone = computed(() => profileSteps.value.filter((step) => step.complete).length)
const profilePercent = computed(() => Math.round(stepsDone.value / profileSteps.value.length * 100))
const stepsLeft = computed(() => profileSteps.value.length - stepsDone.value)

/* ---------- Compliance ---------- */
const complianceSummary = computed(() => compliance.body?.summary || null)
const complianceAttention = computed(() => (complianceSummary.value ? complianceSummary.value.expired + complianceSummary.value.pending + complianceSummary.value.renew_soon : 0))
const COMPLIANCE_STATUS = {
  expired: { label: 'Expired', tone: 'danger' },
  pending: { label: 'Action needed', tone: 'warning' },
  renew: { label: 'Renew soon', tone: 'warning' },
  completed: { label: 'Up to date', tone: 'success' },
}
const complianceStatus = (item) => COMPLIANCE_STATUS[item.status === 'completed' && item.renew_soon ? 'renew' : item.status]
const complianceRank = (item) => (item.status === 'expired' ? 0 : item.status === 'pending' ? 1 : item.renew_soon ? 2 : 3)
const topCompliances = computed(() => [...(compliance.body?.data || [])].sort((a, b) => complianceRank(a) - complianceRank(b)).slice(0, 4))

/* ---------- Contracts ---------- */
const CONTRACT_STATUS = {
  active: { label: 'Active', tone: 'success' },
  ending: { label: 'Ending soon', tone: 'warning' },
  upcoming: { label: 'Upcoming', tone: 'info' },
  ended: { label: 'Ended', tone: 'muted' },
}
const contractStatus = (contract) => CONTRACT_STATUS[contract.ending_soon ? 'ending' : contract.status]
const contracts = computed(() => contractSource.body?.data || [])
const activeContracts = computed(() => contracts.value.filter((contract) => contract.status === 'active'))
const featuredContract = computed(() => activeContracts.value[0] || contracts.value[0] || null)

/* ---------- Training ---------- */
const TRAINING_STATUS = {
  not_started: { label: 'Not started', tone: 'muted', action: 'Start course' },
  in_progress: { label: 'In progress', tone: 'info', action: 'Continue' },
  quiz_ready: { label: 'Quiz ready', tone: 'info', action: 'Take the quiz' },
  completed: { label: 'Completed', tone: 'success', action: 'View certificate' },
}
const courses = computed(() => training.body?.data || [])
const trainingSummary = computed(() => training.body?.summary || null)
const trainingLeft = computed(() => (trainingSummary.value ? trainingSummary.value.assigned - trainingSummary.value.completed : 0))
const featuredCourse = computed(() => courses.value.find((course) => course.status !== 'completed') || courses.value[0] || null)

/* ---------- Recent activity ---------- */
const recentActivity = computed(() => [
  ...contracts.value.map((contract) => ({
    id: `contract-${contract.id}`,
    title: `Contract ${contractStatus(contract).label.toLowerCase()}`,
    detail: `${contract.position} · ${contract.type_label}`,
    date: contract.start_date,
    section: 'contract-history',
  })),
  ...courses.value.filter((course) => course.completed_at).map((course) => ({
    id: `training-${course.id}`,
    title: 'Training completed',
    detail: course.title,
    date: course.completed_at,
    section: 'training',
  })),
].sort((a, b) => new Date(b.date) - new Date(a.date)).slice(0, 4))

const printSummary = () => window.print()
</script>

<template>
  <section class="pd" aria-labelledby="pd-title">
    <header class="pd-head">
      <div>
        <h1 id="pd-title">Personnel Dashboard</h1>
        <p>Overview of your profile, compliance status, contracts and training.</p>
      </div>
      <div class="pd-head__actions">
        <button type="button" class="pd-btn" @click="printSummary"><AppIcon name="download" :size="16" />Download summary PDF</button>
        <RouterLink class="pd-btn pd-btn--primary" :to="to('my-profile')"><AppIcon name="arrow-up-right" :size="16" />Update profile</RouterLink>
      </div>
    </header>

    <div class="pd-metrics">
      <RouterLink class="pd-metric" :to="to('my-profile')">
        <span class="pd-metric__top"><span class="pd-icon pd-icon--teal"><AppIcon name="user" /></span><span v-if="!profile.loading && !profile.error" class="pd-pill" :class="stepsLeft ? 'is-warning' : 'is-success'">{{ stepsLeft ? `${plural(stepsLeft, 'step')} to finish` : 'Complete' }}</span></span>
        <small>Profile completion</small>
        <strong>{{ profile.loading || profile.error ? '—' : `${profilePercent}%` }}</strong>
        <span class="pd-metric__note">{{ profile.body ? `${stepsDone} of ${profileSteps.length} steps completed` : 'Not available yet' }}</span>
        <span class="pd-track"><i :style="{ width: `${profilePercent}%` }"></i></span>
      </RouterLink>
      <RouterLink class="pd-metric" :to="to('compliances')">
        <span class="pd-metric__top"><span class="pd-icon pd-icon--blue"><AppIcon name="shield-check" /></span><span v-if="complianceSummary" class="pd-pill" :class="complianceAttention ? 'is-warning' : 'is-success'">{{ complianceAttention ? `${complianceAttention} need${complianceAttention === 1 ? 's' : ''} action` : 'Up to date' }}</span></span>
        <small>Compliance status</small>
        <strong>{{ complianceSummary ? `${complianceSummary.percent}%` : '—' }}</strong>
        <span class="pd-metric__note">{{ complianceSummary ? `${complianceSummary.completed} of ${complianceSummary.required} completed` : 'Not available yet' }}</span>
        <span class="pd-track"><i :style="{ width: `${complianceSummary?.percent || 0}%` }"></i></span>
      </RouterLink>
      <RouterLink class="pd-metric" :to="to('contract-history')">
        <span class="pd-metric__top"><span class="pd-icon pd-icon--violet"><AppIcon name="file-text" /></span><span v-if="contractSource.body" class="pd-pill" :class="activeContracts.length ? 'is-success' : 'is-muted'">{{ activeContracts.length ? 'Active' : 'No active contract' }}</span></span>
        <small>Active contracts</small>
        <strong>{{ contractSource.body ? activeContracts.length : '—' }}</strong>
        <span class="pd-metric__note">{{ featuredContract ? `${featuredContract.position}${featuredContract.department ? ` · ${featuredContract.department}` : ''}` : 'No contracts on record' }}</span>
      </RouterLink>
      <RouterLink class="pd-metric" :to="to('training')">
        <span class="pd-metric__top"><span class="pd-icon pd-icon--amber"><AppIcon name="graduation" /></span><span v-if="trainingSummary" class="pd-pill" :class="trainingLeft ? 'is-info' : 'is-success'">{{ trainingLeft ? `${trainingLeft} to complete` : 'All complete' }}</span></span>
        <small>Staff online training</small>
        <strong>{{ trainingSummary ? `${trainingSummary.completed} of ${trainingSummary.assigned}` : '—' }}</strong>
        <span class="pd-metric__note">assigned courses completed</span>
        <span class="pd-track"><i :style="{ width: `${trainingSummary?.percent || 0}%` }"></i></span>
      </RouterLink>
    </div>

    <div class="pd-columns">
      <div class="pd-stack">
        <section class="pd-panel">
          <header class="pd-panel__head">
            <span class="pd-icon pd-icon--teal"><AppIcon name="user" /></span>
            <div><h2>My Profile (5 steps)</h2><p>Review and finalize your personal records, qualifications and declaration.</p></div>
            <RouterLink :to="to('my-profile')">Open full profile <AppIcon name="arrow-right" :size="14" /></RouterLink>
          </header>
          <p v-if="profile.error" class="pd-error" role="alert">{{ profile.error }}</p>
          <p v-else-if="profile.loading" class="pd-empty">Loading your profile…</p>
          <div v-else class="pd-steps">
            <RouterLink v-for="(step, index) in profileSteps" :key="step.title" class="pd-step" :class="{ 'is-complete': step.complete }" :to="to('my-profile')">
              <span class="pd-step__top"><small>Step {{ index + 1 }}</small><AppIcon :name="step.complete ? 'check-circle' : 'alert-circle'" :size="16" /></span>
              <strong>{{ step.title }}</strong>
              <small>{{ step.complete ? 'Complete' : 'Action needed' }}</small>
            </RouterLink>
          </div>
        </section>

        <section class="pd-panel">
          <header class="pd-panel__head">
            <span class="pd-icon pd-icon--violet"><AppIcon name="file-text" /></span>
            <div><h2>Contract History &amp; Assignments</h2><p>Recorded contracts, positions, dates and employment status.</p></div>
            <RouterLink :to="to('contract-history')">View all contracts <AppIcon name="arrow-right" :size="14" /></RouterLink>
          </header>
          <p v-if="contractSource.error" class="pd-error" role="alert">{{ contractSource.error }}</p>
          <div v-else class="pd-table-wrap">
            <table class="pd-table">
              <thead><tr><th scope="col">Role / designation</th><th scope="col">Department</th><th scope="col">Duration</th><th scope="col">Status</th></tr></thead>
              <tbody>
                <tr v-if="contractSource.loading"><td colspan="4" class="pd-table__empty">Loading contract history…</td></tr>
                <tr v-else-if="!contracts.length"><td colspan="4" class="pd-table__empty">No contracts have been recorded yet.</td></tr>
                <tr v-for="contract in contracts.slice(0, 3)" :key="contract.id">
                  <td><strong>{{ contract.position }}</strong><small>{{ contract.type_label }}</small></td>
                  <td>{{ contract.department || '—' }}</td>
                  <td>{{ formatDate(contract.start_date) }} – {{ contract.end_date ? formatDate(contract.end_date) : 'Open-ended' }}</td>
                  <td><span class="pd-pill" :class="`is-${contractStatus(contract).tone}`">{{ contractStatus(contract).label }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="pd-panel">
          <header class="pd-panel__head">
            <span class="pd-icon pd-icon--amber"><AppIcon name="graduation" /></span>
            <div><h2>Staff Online Training</h2><p>Open assigned courses, track progress and view certificates.</p></div>
            <RouterLink :to="to('training')">Certificates ({{ trainingSummary?.completed || 0 }}) <AppIcon name="arrow-right" :size="14" /></RouterLink>
          </header>
          <p v-if="training.error" class="pd-error" role="alert">{{ training.error }}</p>
          <p v-else-if="training.loading" class="pd-empty">Loading assigned training…</p>
          <div v-else-if="featuredCourse" class="pd-course">
            <div class="pd-course__top">
              <span class="pd-pill" :class="`is-${TRAINING_STATUS[featuredCourse.status].tone}`">{{ TRAINING_STATUS[featuredCourse.status].label }}</span>
              <small :class="{ 'is-overdue': featuredCourse.overdue }">{{ featuredCourse.due_date ? `${featuredCourse.overdue ? 'Overdue · was due' : 'Due'} ${formatDate(featuredCourse.due_date)}` : 'No deadline' }}</small>
            </div>
            <div class="pd-course__body">
              <div><strong>{{ featuredCourse.title }}</strong><p v-if="featuredCourse.summary">{{ featuredCourse.summary }}</p></div>
              <RouterLink class="pd-btn pd-btn--primary" :to="to('training', { course: featuredCourse.id })">{{ TRAINING_STATUS[featuredCourse.status].action }}<AppIcon name="chevron-right" :size="14" /></RouterLink>
            </div>
            <div class="pd-course__progress"><span class="pd-track"><i :style="{ width: `${featuredCourse.percent}%` }"></i></span><small>{{ featuredCourse.percent }}% complete</small></div>
          </div>
          <p v-else class="pd-empty">No training courses have been assigned yet.</p>
        </section>
      </div>

      <aside class="pd-stack">
        <section class="pd-panel">
          <header class="pd-panel__head">
            <span class="pd-icon pd-icon--blue"><AppIcon name="shield-check" /></span>
            <div><h2>Required Compliances</h2><p>Policy and legal clearances</p></div>
            <RouterLink :to="to('compliances')">View all</RouterLink>
          </header>
          <p v-if="compliance.error" class="pd-error" role="alert">{{ compliance.error }}</p>
          <p v-else-if="compliance.loading" class="pd-empty">Loading compliance records…</p>
          <div v-else-if="topCompliances.length" class="pd-compliances">
            <RouterLink v-for="item in topCompliances" :key="item.id" class="pd-compliance" :to="to('compliances')">
              <span><strong>{{ item.title }}</strong><small>{{ item.category }}</small></span>
              <span class="pd-pill" :class="`is-${complianceStatus(item).tone}`">{{ complianceStatus(item).label }}</span>
            </RouterLink>
          </div>
          <p v-else class="pd-empty">No compliance policies are available.</p>
        </section>

        <section class="pd-panel">
          <header class="pd-panel__head">
            <span class="pd-icon pd-icon--teal"><AppIcon name="activity" /></span>
            <div><h2>Recent Activity</h2><p>Latest personnel updates</p></div>
          </header>
          <ol v-if="recentActivity.length" class="pd-activity">
            <li v-for="activity in recentActivity" :key="activity.id">
              <RouterLink :to="to(activity.section)"><strong>{{ activity.title }}</strong><small>{{ activity.detail }}</small><time :datetime="activity.date">{{ formatDate(activity.date) }}</time></RouterLink>
            </li>
          </ol>
          <p v-else class="pd-empty">Your recent contract and training activity will appear here.</p>
        </section>

        <section class="pd-support">
          <h2>Need personnel support?</h2>
          <p>Questions about your profile, contracts or compliance documents? Contact your People &amp; Operations team.</p>
          <RouterLink :to="to('my-profile')">Review your profile <AppIcon name="arrow-right" :size="14" /></RouterLink>
        </section>
      </aside>
    </div>
  </section>
</template>

<style>
.pd { --pd-ink: #17365f; --pd-text: #3d5470; --pd-muted: #6b8099; --pd-line: #dfe7ef; --pd-soft: #f6f9fc; --pd-brand: #0b7f62; --pd-brand-dark: #086a51; --pd-brand-soft: #e8f5f0; --pd-success: #14804a; --pd-success-soft: #e7f6ee; --pd-warning: #a35f00; --pd-warning-soft: #fff4dc; --pd-info: #2b5ea8; --pd-info-soft: #e9f1fd; --pd-danger: #c0392b; --pd-danger-soft: #fdf1f0; display: grid; gap: 16px; color: var(--pd-text); font-size: 14px; line-height: 1.5; }
.pd h1, .pd h2 { color: var(--pd-ink); letter-spacing: -.01em; }
.pd small { font-size: .78rem; }

.pd-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px; }
.pd-head h1 { margin: 0 0 4px; font-size: 1.6rem; font-weight: 700; }
.pd-head p { margin: 0; color: var(--pd-muted); }
.pd-head__actions { display: flex; flex-wrap: wrap; gap: 10px; }
.pd-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 42px; padding: 0 16px; border: 1px solid var(--pd-line); border-radius: 10px; background: #fff; color: var(--pd-ink); font: inherit; font-size: .9rem; font-weight: 600; text-decoration: none; white-space: nowrap; cursor: pointer; transition: background .15s, border-color .15s; }
.pd-btn:hover { background: var(--pd-soft); }
.pd-btn--primary { border-color: var(--pd-brand); background: var(--pd-brand); color: #fff; }
.pd-btn--primary:hover { border-color: var(--pd-brand-dark); background: var(--pd-brand-dark); }

.pd-icon { display: grid; flex: none; place-items: center; width: 34px; height: 34px; border-radius: 10px; }
.pd-icon--teal { background: var(--pd-brand-soft); color: var(--pd-brand); }
.pd-icon--blue { background: var(--pd-info-soft); color: var(--pd-info); }
.pd-icon--violet { background: #f1ecfd; color: #6f47c4; }
.pd-icon--amber { background: var(--pd-warning-soft); color: var(--pd-warning); }
.pd-pill { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 999px; font-size: .75rem; font-weight: 600; white-space: nowrap; }
.pd-pill.is-success { background: var(--pd-success-soft); color: var(--pd-success); }
.pd-pill.is-warning { background: var(--pd-warning-soft); color: var(--pd-warning); }
.pd-pill.is-info { background: var(--pd-info-soft); color: var(--pd-info); }
.pd-pill.is-danger { background: var(--pd-danger-soft); color: var(--pd-danger); }
.pd-pill.is-muted { background: #edf1f5; color: var(--pd-muted); }
.pd-track { display: block; height: 6px; overflow: hidden; border-radius: 999px; background: #edf1f5; }
.pd-track i { display: block; height: 100%; border-radius: inherit; background: var(--pd-brand); transition: width .2s; }

.pd-metrics { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
.pd-metric { display: flex; flex-direction: column; gap: 4px; min-width: 0; padding: 16px; border: 1px solid var(--pd-line); border-radius: 14px; background: #fff; color: inherit; text-decoration: none; transition: border-color .15s, box-shadow .15s; }
.pd-metric:hover { border-color: #bcd3cb; box-shadow: 0 6px 18px rgba(23, 54, 95, .06); }
.pd-metric__top { display: flex; align-items: center; justify-content: space-between; gap: 8px; min-height: 34px; margin-bottom: 8px; }
.pd-metric > small { color: var(--pd-muted); font-weight: 600; }
.pd-metric > strong { color: var(--pd-ink); font-size: 1.6rem; font-weight: 700; line-height: 1.2; }
.pd-metric__note { overflow: hidden; color: var(--pd-muted); font-size: .82rem; text-overflow: ellipsis; white-space: nowrap; }
.pd-metric .pd-track { margin-top: auto; }
.pd-metric__note + .pd-track { margin-top: 10px; }

.pd-columns { display: grid; grid-template-columns: minmax(0, 1.9fr) minmax(280px, 1fr); align-items: start; gap: 16px; }
.pd-stack { display: grid; gap: 16px; min-width: 0; }
.pd-panel { min-width: 0; padding: 18px; border: 1px solid var(--pd-line); border-radius: 14px; background: #fff; }
.pd-panel__head { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 14px; }
.pd-panel__head > div { flex: 1 1 200px; min-width: 0; }
.pd-panel__head h2 { margin: 0; font-size: 1.02rem; font-weight: 700; }
.pd-panel__head p { margin: 2px 0 0; color: var(--pd-muted); font-size: .85rem; }
.pd-panel__head > a { display: inline-flex; flex: none; align-items: center; gap: 5px; color: var(--pd-brand); font-size: .85rem; font-weight: 600; text-decoration: none; }
.pd-panel__head > a:hover { color: var(--pd-brand-dark); text-decoration: underline; }
.pd-error { margin: 0; padding: 10px 14px; border: 1px solid #f2c4bf; border-radius: 10px; background: var(--pd-danger-soft); color: #9b2c22; font-size: .9rem; font-weight: 600; }
.pd-empty { margin: 0; padding: 18px 10px; color: var(--pd-muted); font-size: .9rem; text-align: center; }

.pd-steps { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
.pd-step { display: flex; flex-direction: column; gap: 4px; min-width: 0; padding: 10px 12px; border: 1px solid #f1dfb4; border-radius: 10px; background: #fffbf2; color: var(--pd-warning); text-decoration: none; transition: border-color .15s; }
.pd-step.is-complete { border-color: #bfe3d6; background: #f2faf6; color: var(--pd-success); }
.pd-step:hover { border-color: currentColor; }
.pd-step__top { display: flex; align-items: center; justify-content: space-between; gap: 6px; }
.pd-step__top small { color: var(--pd-muted); font-weight: 600; }
.pd-step strong { color: var(--pd-ink); font-size: .88rem; font-weight: 600; line-height: 1.3; overflow-wrap: anywhere; }

.pd-table-wrap { overflow-x: auto; }
.pd-table { width: 100%; border-collapse: collapse; text-align: left; }
.pd-table th { padding: 8px 10px; border-bottom: 1px solid var(--pd-line); color: var(--pd-muted); font-size: .78rem; font-weight: 600; white-space: nowrap; }
.pd-table td { padding: 10px; border-bottom: 1px solid #eef2f6; font-size: .88rem; white-space: nowrap; }
.pd-table tr:last-child td { border-bottom: 0; }
.pd-table td strong { display: block; color: var(--pd-ink); font-weight: 600; }
.pd-table td small { display: block; color: var(--pd-muted); }
.pd-table td.pd-table__empty { padding: 18px 10px; color: var(--pd-muted); text-align: center; white-space: normal; }

.pd-course { padding: 14px; border: 1px solid var(--pd-line); border-radius: 12px; background: var(--pd-soft); }
.pd-course__top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.pd-course__top small { color: var(--pd-muted); }
.pd-course__top small.is-overdue { color: var(--pd-danger); font-weight: 600; }
.pd-course__body { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin: 10px 0; }
.pd-course__body > div { min-width: 0; }
.pd-course__body strong { color: var(--pd-ink); font-size: .98rem; font-weight: 600; }
.pd-course__body p { display: -webkit-box; margin: 4px 0 0; overflow: hidden; color: var(--pd-muted); font-size: .85rem; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
.pd-course__progress { display: flex; align-items: center; gap: 10px; }
.pd-course__progress .pd-track { flex: 1; }
.pd-course__progress small { color: var(--pd-muted); font-weight: 600; white-space: nowrap; }

.pd-compliances { display: grid; gap: 8px; }
.pd-compliance { display: flex; align-items: center; justify-content: space-between; gap: 10px; min-width: 0; padding: 10px 12px; border: 1px solid var(--pd-line); border-radius: 10px; color: inherit; text-decoration: none; transition: background .15s; }
.pd-compliance:hover { background: var(--pd-soft); }
.pd-compliance > span:first-child { display: grid; min-width: 0; }
.pd-compliance strong { overflow: hidden; color: var(--pd-ink); font-size: .88rem; font-weight: 600; text-overflow: ellipsis; white-space: nowrap; }
.pd-compliance small { color: var(--pd-muted); }

.pd-activity { display: grid; gap: 4px; margin: 0; padding: 0; list-style: none; }
.pd-activity a { display: grid; padding: 8px 10px 8px 14px; border-left: 2px solid var(--pd-line); color: inherit; text-decoration: none; }
.pd-activity a:hover { border-left-color: var(--pd-brand); background: var(--pd-soft); }
.pd-activity strong { color: var(--pd-ink); font-size: .88rem; font-weight: 600; }
.pd-activity small, .pd-activity time { color: var(--pd-muted); font-size: .78rem; }

.pd-support { padding: 18px; border-radius: 14px; background: var(--pd-ink); color: #d5e0ec; }
.pd-support h2 { margin: 0 0 6px; color: #fff; font-size: 1rem; font-weight: 700; }
.pd-support p { margin: 0 0 12px; font-size: .88rem; }
.pd-support a { display: inline-flex; align-items: center; gap: 6px; color: #fff; font-size: .88rem; font-weight: 600; text-decoration: none; }
.pd-support a:hover { text-decoration: underline; }

.pd a:focus-visible, .pd button:focus-visible { outline: 3px solid rgba(11, 127, 98, .35); outline-offset: 2px; }

@media (max-width: 1000px) {
  .pd-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .pd-columns { grid-template-columns: minmax(0, 1fr); }
  .pd-steps { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 560px) {
  .pd-metrics, .pd-steps { grid-template-columns: minmax(0, 1fr); }
  .pd-head__actions, .pd-head__actions .pd-btn { width: 100%; }
  .pd-course__body { flex-direction: column; align-items: stretch; }
}

/* "Download summary PDF" prints this page; :has keeps these rules off every other page. */
@media print {
  body:has(.pd) :is(.admin-sidebar, .admin-topbar, .admin-mobile-menu-toggle, .admin-mobile-menu-backdrop) { display: none !important; }
  body:has(.pd) .admin-main-shell { margin: 0 !important; padding: 0 !important; }
  .pd-head__actions { display: none; }
  .pd-panel, .pd-metric, .pd-support { break-inside: avoid; box-shadow: none; }
}
</style>
