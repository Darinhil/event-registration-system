<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import AppIcon from '../AppIcon.vue'
import { getStaffAccounts } from '../../services/contractService'
import { assignTrainingCourse, createTrainingCourse, deleteTrainingCourse, getMyTraining, getTrainingCoursesForAdmin, updateTrainingCourse } from '../../services/trainingService'

const courses = ref([])
const myCourses = ref([])
const staff = ref([])
const loading = ref(true)
const loadError = ref('')
const toast = ref('')
const formDialog = ref(null)
const assignDialog = ref(null)
const deleteDialog = ref(null)
const editing = ref(null)
const assigning = ref(null)
const deleting = ref(null)
const saving = ref(false)
const assigningBusy = ref(false)
const deletingBusy = ref(false)
const formError = ref('')
const assignError = ref('')
const deleteError = ref('')
const fieldErrors = ref({})
const contentLocked = ref(false)
const assignmentIds = ref([])

const blankQuestion = () => ({ prompt: '', options: ['', '', '', ''], correct_option: 0 })
const form = reactive({
  title: '',
  category: '',
  summary: '',
  estimated_minutes: 20,
  pass_mark: 80,
  due_date: '',
  is_active: true,
  lessons: [{ title: '', body: '' }],
  questions: [blankQuestion()],
})

const describeError = (error, fallback) => (!error.response
  ? 'We couldn’t reach the server. Check your connection and try again.'
  : error.response.status >= 500
    ? 'Something went wrong on our side. Please try again in a moment.'
    : error.response.data?.message || fallback)

const load = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const [courseResponse, staffResponse, myTrainingResponse] = await Promise.all([getTrainingCoursesForAdmin(), getStaffAccounts(), getMyTraining()])
    courses.value = courseResponse.data.data
    staff.value = staffResponse.data.data.filter((person) => person.role !== 'admin')
    myCourses.value = myTrainingResponse.data.data
  } catch (error) {
    loadError.value = describeError(error, 'Training management couldn’t be loaded.')
  } finally {
    loading.value = false
  }
}
onMounted(load)

const staffById = computed(() => new Map(staff.value.map((person) => [person.id, person])))
const formatDate = (date) => (date ? new Date(`${date}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : 'No deadline')
const normalizeCourse = (course) => ({
  ...course,
  lessons: course.lessons?.map((lesson) => ({ title: lesson.title, body: lesson.body })) || [{ title: '', body: '' }],
  questions: course.questions?.map((question) => ({
    prompt: question.prompt,
    options: [...question.options],
    correct_option: question.correct_option,
  })) || [blankQuestion()],
})
const resetForm = () => {
  Object.assign(form, {
    title: '',
    category: '',
    summary: '',
    estimated_minutes: 20,
    pass_mark: 80,
    due_date: '',
    is_active: true,
    lessons: [{ title: '', body: '' }],
    questions: [blankQuestion()],
  })
  formError.value = ''
  fieldErrors.value = {}
  contentLocked.value = false
}
const openCreate = async () => {
  editing.value = null
  resetForm()
  await nextTick()
  formDialog.value?.showModal()
  formDialog.value?.querySelector('input')?.focus()
}
const openEdit = async (course) => {
  editing.value = course
  contentLocked.value = course.progress_count > 0
  Object.assign(form, normalizeCourse(course))
  formError.value = ''
  fieldErrors.value = {}
  await nextTick()
  formDialog.value?.showModal()
}
const addLesson = () => form.lessons.push({ title: '', body: '' })
const removeLesson = (index) => { if (form.lessons.length > 1) form.lessons.splice(index, 1) }
const addQuestion = () => form.questions.push(blankQuestion())
const removeQuestion = (index) => { if (form.questions.length > 1) form.questions.splice(index, 1) }
const fieldError = (key) => fieldErrors.value[key]?.[0] || ''

const saveCourse = async () => {
  formError.value = ''
  fieldErrors.value = {}
  saving.value = true
  const payload = {
    title: form.title,
    category: form.category || null,
    summary: form.summary,
    estimated_minutes: Number(form.estimated_minutes),
    pass_mark: Number(form.pass_mark),
    due_date: form.due_date || null,
    is_active: form.is_active,
  }
  if (!contentLocked.value) {
    payload.lessons = form.lessons
    payload.questions = form.questions.map((question) => ({ ...question, correct_option: Number(question.correct_option) }))
  }
  try {
    const response = editing.value
      ? await updateTrainingCourse(editing.value.id, payload)
      : await createTrainingCourse(payload)
    const saved = response.data.data
    formDialog.value?.close()
    toast.value = `${saved.title} ${editing.value ? 'updated' : 'created'}.`
    await load()
  } catch (error) {
    fieldErrors.value = error.response?.data?.errors || {}
    formError.value = Object.keys(fieldErrors.value).length ? '' : describeError(error, 'The course couldn’t be saved.')
  } finally {
    saving.value = false
  }
}

const openAssign = async (course) => {
  assigning.value = course
  assignmentIds.value = [...(course.assigned_ids || [])]
  assignError.value = ''
  await nextTick()
  assignDialog.value?.showModal()
}
const saveAssignments = async () => {
  if (!assigning.value) return
  assigningBusy.value = true
  assignError.value = ''
  try {
    const response = await assignTrainingCourse(assigning.value.id, assignmentIds.value)
    const updated = response.data.data
    courses.value = courses.value.map((course) => course.id === updated.id ? updated : course)
    assignDialog.value?.close()
    toast.value = `${updated.title} assigned to ${updated.assigned_count} staff member${updated.assigned_count === 1 ? '' : 's'}.`
  } catch (error) {
    assignError.value = describeError(error, error.response?.data?.errors?.user_ids?.[0] || 'Assignments couldn’t be saved.')
  } finally {
    assigningBusy.value = false
  }
}
const askDelete = async (course) => {
  deleting.value = course
  deleteError.value = ''
  await nextTick()
  deleteDialog.value?.showModal()
}
const confirmDelete = async () => {
  if (!deleting.value) return
  deletingBusy.value = true
  deleteError.value = ''
  try {
    await deleteTrainingCourse(deleting.value.id)
    deleteDialog.value?.close()
    toast.value = `${deleting.value.title} deleted.`
    await load()
  } catch (error) {
    deleteError.value = describeError(error, 'The course couldn’t be deleted.')
  } finally {
    deletingBusy.value = false
  }
}
const closeDialog = (dialog) => dialog?.close()
const onBackdrop = (event, dialog) => { if (event.target === dialog) dialog.close() }
</script>

<template>
  <main class="tm">
    <header class="tm-hero">
      <div>
        <p class="tm-eyebrow">Learning &amp; Development</p>
        <h1>Staff Online Training</h1>
        <p>Assigned courses, practical learning, and certificates — all in one place.</p>
      </div>
      <button type="button" class="tm-btn tm-btn--primary" :disabled="loading || !!loadError" @click="openCreate"><AppIcon name="plus" :size="16" />Create course</button>
    </header>

    <p v-if="toast" class="tm-toast" role="status"><AppIcon name="check-circle" :size="17" /><span>{{ toast }}</span><button type="button" aria-label="Dismiss" @click="toast = ''"><AppIcon name="x" :size="15" /></button></p>

    <section class="tm-panel" aria-labelledby="tm-manage-title">
      <header class="tm-panel__head">
        <span class="tm-panel__icon"><AppIcon name="list" :size="17" /></span>
        <div><h2 id="tm-manage-title">Manage courses</h2><p>Create, assign, and track staff learning.</p></div>
        <span class="tm-count">{{ courses.length }} course{{ courses.length === 1 ? '' : 's' }}</span>
      </header>
      <div v-if="loading" class="tm-loading" aria-busy="true">Loading courses…</div>
      <div v-else-if="loadError" class="tm-state" role="alert">
        <p>{{ loadError }}</p><button type="button" class="tm-btn" @click="load"><AppIcon name="refresh" :size="15" />Try again</button>
      </div>
      <div v-else class="tm-table-scroll">
        <table class="tm-table">
          <thead><tr><th>Course</th><th>Topic</th><th>Assigned</th><th>Due date</th><th>Pass score</th><th>Actions</th></tr></thead>
          <tbody>
            <tr v-if="!courses.length"><td colspan="6" class="tm-empty">No training courses have been created yet.</td></tr>
            <tr v-for="course in courses" :key="course.id">
              <td data-label="Course"><div class="tm-course"><strong>{{ course.title }}</strong><small>{{ course.summary }}</small></div></td>
              <td data-label="Topic"><span class="tm-topic">{{ course.category || 'General' }}</span></td>
              <td data-label="Assigned"><span class="tm-assigned">{{ course.assigned_count }}</span> staff</td>
              <td data-label="Due date">{{ formatDate(course.due_date) }}</td>
              <td data-label="Pass score"><strong>{{ course.pass_mark }}%</strong></td>
              <td data-label="Actions"><div class="tm-actions">
                <button type="button" class="tm-btn tm-btn--assign" :disabled="!staff.length" @click="openAssign(course)">Assign staff</button>
                <button type="button" class="tm-btn tm-btn--small" @click="openEdit(course)">Edit</button>
                <button type="button" class="tm-btn tm-btn--small tm-btn--danger" @click="askDelete(course)">Delete</button>
              </div></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <aside v-if="!loading && !loadError && !staff.length" class="tm-notice">
      <span class="tm-notice__icon"><AppIcon name="info" :size="17" /></span>
      <div><strong>No staff accounts are available to assign yet</strong><p>Create an account with a staff role (Manager or Officer) first. Then return here and use Assign staff on the course.</p></div>
      <RouterLink to="/admin/admin-accounts">Open Admin Accounts <span aria-hidden="true">→</span></RouterLink>
    </aside>

    <section class="tm-panel tm-learning" aria-labelledby="tm-learning-title">
      <header class="tm-panel__head">
        <span class="tm-panel__icon tm-panel__icon--blue"><AppIcon name="layout-grid" :size="17" /></span>
        <div><h2 id="tm-learning-title">My learning</h2><p>Courses assigned to you and your results.</p></div>
      </header>
      <div v-if="!myCourses.length" class="tm-caught-up">
        <span class="tm-caught-up__icon"><AppIcon name="check" :size="20" /></span>
        <div><strong>You’re all caught up</strong><p>No courses have been assigned to you yet. Check back here when a new course is available.</p></div>
        <small>Your assigned courses will appear here</small>
      </div>
      <div v-else class="tm-my-courses">
        <RouterLink v-for="course in myCourses" :key="course.id" class="tm-my-course" :to="{ path: '/admin/personnel-profile', query: { section: 'training', course: course.id } }">
          <span><strong>{{ course.title }}</strong><small>{{ course.lesson_count }} lessons · {{ course.status.replaceAll('_', ' ') }}</small></span>
          <span class="tm-my-course__progress">{{ course.percent }}%</span>
        </RouterLink>
      </div>
    </section>

    <dialog ref="formDialog" class="tm-dialog tm-dialog--form" aria-labelledby="tm-form-title" @click="onBackdrop($event, formDialog)">
      <form class="tm-dialog__inner" novalidate @submit.prevent="saveCourse">
        <header class="tm-dialog__head">
          <div><p class="tm-eyebrow">Learning &amp; Development</p><h2 id="tm-form-title">{{ editing ? 'Edit course' : 'Create course' }}</h2></div>
          <button type="button" class="tm-icon-btn" aria-label="Close" @click="closeDialog(formDialog)"><AppIcon name="x" :size="19" /></button>
        </header>
        <div class="tm-dialog__body">
          <p v-if="formError" class="tm-error-box" role="alert">{{ formError }}</p>
          <div class="tm-course-fields">
            <label class="tm-field tm-field--title">Course title<input v-model.trim="form.title" required maxlength="150" /><small v-if="fieldError('title')">{{ fieldError('title') }}</small></label>
            <label class="tm-field">Topic<input v-model.trim="form.category" maxlength="80" placeholder="e.g. Safeguarding" /><small v-if="fieldError('category')">{{ fieldError('category') }}</small></label>
            <label class="tm-field tm-field--wide">Description<textarea v-model.trim="form.summary" required rows="3" maxlength="5000"></textarea><small v-if="fieldError('summary')">{{ fieldError('summary') }}</small></label>
            <label class="tm-field">Estimated minutes<input v-model="form.estimated_minutes" type="number" min="1" max="65535" required /><small v-if="fieldError('estimated_minutes')">{{ fieldError('estimated_minutes') }}</small></label>
            <label class="tm-field">Pass score (%)<input v-model="form.pass_mark" type="number" min="1" max="100" required /><small v-if="fieldError('pass_mark')">{{ fieldError('pass_mark') }}</small></label>
            <label class="tm-field">Due date<input v-model="form.due_date" type="date" /><small v-if="fieldError('due_date')">{{ fieldError('due_date') }}</small></label>
          </div>
          <label class="tm-toggle"><input v-model="form.is_active" type="checkbox" /><span><strong>Course is active</strong><small>Active courses are available to assigned staff.</small></span></label>

          <section class="tm-editor">
            <header><div><h3>Lessons</h3><p>Staff complete lessons in order before taking the quiz.</p></div><button type="button" class="tm-btn tm-btn--small" :disabled="contentLocked" @click="addLesson"><AppIcon name="plus" :size="14" />Add lesson</button></header>
            <p v-if="contentLocked" class="tm-lock-note">Lessons and quiz are locked because staff have started this course. Course details can still be updated.</p>
            <article v-for="(lesson, index) in form.lessons" :key="index" class="tm-repeat">
              <header><strong>Lesson {{ index + 1 }}</strong><button type="button" class="tm-icon-btn" :disabled="contentLocked || form.lessons.length < 2" :aria-label="`Remove lesson ${index + 1}`" @click="removeLesson(index)"><AppIcon name="trash" :size="15" /></button></header>
              <label class="tm-field">Title<input v-model.trim="lesson.title" :disabled="contentLocked" maxlength="150" /><small v-if="fieldError(`lessons.${index}.title`)">{{ fieldError(`lessons.${index}.title`) }}</small></label>
              <label class="tm-field">Lesson content<textarea v-model.trim="lesson.body" :disabled="contentLocked" rows="4" placeholder="Separate paragraphs with a blank line. Start lines with - for bullet points."></textarea><small v-if="fieldError(`lessons.${index}.body`)">{{ fieldError(`lessons.${index}.body`) }}</small></label>
            </article>
          </section>

          <section class="tm-editor">
            <header><div><h3>Quiz questions</h3><p>Each question has four answer choices.</p></div><button type="button" class="tm-btn tm-btn--small" :disabled="contentLocked" @click="addQuestion"><AppIcon name="plus" :size="14" />Add question</button></header>
            <article v-for="(question, index) in form.questions" :key="index" class="tm-repeat">
              <header><strong>Question {{ index + 1 }}</strong><button type="button" class="tm-icon-btn" :disabled="contentLocked || form.questions.length < 2" :aria-label="`Remove question ${index + 1}`" @click="removeQuestion(index)"><AppIcon name="trash" :size="15" /></button></header>
              <label class="tm-field">Question prompt<textarea v-model.trim="question.prompt" :disabled="contentLocked" rows="2"></textarea><small v-if="fieldError(`questions.${index}.prompt`)">{{ fieldError(`questions.${index}.prompt`) }}</small></label>
              <div class="tm-fields-row">
                <label v-for="(option, optionIndex) in question.options" :key="optionIndex" class="tm-field">Option {{ optionIndex + 1 }}<input v-model.trim="question.options[optionIndex]" :disabled="contentLocked" /><small v-if="fieldError(`questions.${index}.options.${optionIndex}`)">{{ fieldError(`questions.${index}.options.${optionIndex}`) }}</small></label>
              </div>
              <label class="tm-field">Correct answer<select v-model="question.correct_option" :disabled="contentLocked"><option v-for="(_, optionIndex) in question.options" :key="optionIndex" :value="optionIndex">Option {{ optionIndex + 1 }}</option></select></label>
            </article>
          </section>
        </div>
        <footer class="tm-dialog__foot"><button type="button" class="tm-btn" @click="closeDialog(formDialog)">Cancel</button><button type="submit" class="tm-btn tm-btn--primary" :disabled="saving"><AppIcon :name="saving ? 'refresh' : 'save'" :size="15" />{{ saving ? 'Saving…' : editing ? 'Save changes' : 'Create course' }}</button></footer>
      </form>
    </dialog>

    <dialog ref="assignDialog" class="tm-dialog tm-dialog--small" aria-labelledby="tm-assign-title" @click="onBackdrop($event, assignDialog)">
      <div v-if="assigning" class="tm-dialog__inner">
        <header class="tm-dialog__head"><div><p class="tm-eyebrow">Course assignment</p><h2 id="tm-assign-title">Assign staff</h2><p>{{ assigning.title }}</p></div><button type="button" class="tm-icon-btn" aria-label="Close" @click="closeDialog(assignDialog)"><AppIcon name="x" :size="19" /></button></header>
        <div class="tm-dialog__body">
          <p v-if="assignError" class="tm-error-box" role="alert">{{ assignError }}</p>
          <div class="tm-staff-list">
            <label v-for="person in staff" :key="person.id" class="tm-staff-option">
              <input v-model="assignmentIds" type="checkbox" :value="person.id" />
              <span><strong>{{ person.name }}</strong><small>{{ person.role.replaceAll('_', ' ') }} · {{ person.email }}</small></span>
            </label>
          </div>
        </div>
        <footer class="tm-dialog__foot"><button type="button" class="tm-btn" @click="closeDialog(assignDialog)">Cancel</button><button type="button" class="tm-btn tm-btn--primary" :disabled="assigningBusy" @click="saveAssignments">{{ assigningBusy ? 'Saving…' : 'Save assignments' }}</button></footer>
      </div>
    </dialog>

    <dialog ref="deleteDialog" class="tm-dialog tm-dialog--small" aria-labelledby="tm-delete-title" @click="onBackdrop($event, deleteDialog)">
      <div v-if="deleting" class="tm-dialog__inner">
        <header class="tm-dialog__head"><div><p class="tm-eyebrow">Permanent action</p><h2 id="tm-delete-title">Delete this course?</h2></div></header>
        <div class="tm-dialog__body"><p><strong>{{ deleting.title }}</strong> will be permanently deleted. Courses with staff learning records can’t be deleted; deactivate them to preserve records.</p><p v-if="deleteError" class="tm-error-box" role="alert">{{ deleteError }}</p></div>
        <footer class="tm-dialog__foot"><button type="button" class="tm-btn" @click="closeDialog(deleteDialog)">Cancel</button><button type="button" class="tm-btn tm-btn--danger" :disabled="deletingBusy" @click="confirmDelete"><AppIcon name="trash" :size="15" />{{ deletingBusy ? 'Deleting…' : 'Delete course' }}</button></footer>
      </div>
    </dialog>
  </main>
</template>

<style>
.tm { --tm-ink: #17365f; --tm-text: #3d5470; --tm-muted: #8293a7; --tm-line: #e1e8ef; --tm-soft: #f6f9fc; --tm-brand: #087f70; width: min(100%, 1240px); margin: 0 auto; padding: 4px 0 42px; color: var(--tm-text); font-size: 13px; line-height: 1.5; }
.tm button { margin: 0; }
.tm h1, .tm h2, .tm h3 { color: var(--tm-ink); }
.tm-hero { display: flex; min-height: 102px; align-items: center; justify-content: space-between; gap: 20px; margin: 0 0 16px; padding: 18px 24px; border: 1px solid #e1e8ef; border-radius: 14px; background: linear-gradient(110deg, #f0f8f6, #fff 62%); }
.tm-eyebrow { margin: 0 0 3px; color: var(--tm-brand); font-size: 10px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.tm-hero h1 { margin: 0; font-size: 1.45rem; line-height: 1.25; }
.tm-hero > div > p:last-child { margin: 2px 0 0; color: var(--tm-muted); font-size: 12px; }
.tm-panel { margin-bottom: 16px; padding: 16px 18px; border: 1px solid var(--tm-line); border-radius: 14px; background: #fff; box-shadow: 0 4px 14px rgba(23, 54, 95, .04); }
.tm-panel__head { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
.tm-panel__icon { display: grid; width: 34px; height: 34px; flex: none; place-items: center; border-radius: 10px; background: #edf7f4; color: var(--tm-brand); }
.tm-panel__icon--blue { background: #eef2ff; color: #536db2; }
.tm-panel__head h2 { margin: 0; font-size: 14px; }
.tm-panel__head p { margin: 1px 0 0; color: var(--tm-muted); font-size: 11px; }
.tm-count { margin-left: auto; padding: 4px 9px; border: 1px solid var(--tm-line); border-radius: 20px; color: var(--tm-muted); font-size: 10px; font-weight: 600; }
.tm-table-scroll { overflow-x: auto; border: 1px solid var(--tm-line); border-radius: 9px; }
.tm-table { width: 100%; min-width: 930px; border-collapse: collapse; text-align: left; }
.tm-table th { padding: 11px 12px; background: #f8fafc; color: var(--tm-muted); font-size: 9px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; white-space: nowrap; }
.tm-table td { padding: 12px; border-top: 1px solid var(--tm-line); color: var(--tm-text); font-size: 11px; vertical-align: middle; }
.tm-course { display: grid; max-width: 390px; gap: 4px; }
.tm-course strong { color: var(--tm-ink); font-size: 11px; }
.tm-course small { color: var(--tm-muted); font-size: 10px; }
.tm-topic { display: inline-block; padding: 4px 9px; border: 1px solid #e6ecef; border-radius: 20px; background: #f6f9f8; color: #63796f; font-size: 9px; white-space: nowrap; }
.tm-assigned { display: inline-grid; min-width: 23px; height: 23px; margin-right: 5px; place-items: center; border-radius: 7px; background: #eef3fb; color: #4b6f9f; font-weight: 700; }
.tm-actions { display: flex; align-items: center; gap: 7px; }
.tm-btn { display: inline-flex; min-height: 34px; align-items: center; justify-content: center; gap: 6px; padding: 0 11px; border: 1px solid #dfe6ee; border-radius: 7px; background: #fff; color: var(--tm-ink); font: inherit; font-size: 10px; font-weight: 700; white-space: nowrap; cursor: pointer; }
.tm-btn:hover:not(:disabled) { background: #f7f9fb; border-color: #bdcbd9; }
.tm-btn--primary { border-color: var(--tm-brand); background: var(--tm-brand); color: #fff; }
.tm-btn--primary:hover:not(:disabled) { border-color: #06695d; background: #06695d; }
.tm-btn--assign { border-color: #d3e8e1; background: #f2f9f6; color: #267865; }
.tm-btn--small { min-height: 32px; padding: 0 9px; color: #4269a5; }
.tm-btn--danger { border-color: #f0d7d5; color: #bd4d46; }
.tm-btn--danger:hover:not(:disabled) { background: #fff8f7; border-color: #e8b8b4; }
.tm-btn:disabled { opacity: .6; cursor: not-allowed; }
.tm-empty { height: 64px; color: var(--tm-muted) !important; text-align: center; }
.tm-notice { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; padding: 14px 16px; border: 1px solid #f0e2bf; border-radius: 12px; background: #fffbef; color: #765d2a; }
.tm-notice__icon { display: grid; width: 28px; height: 28px; flex: none; place-items: center; border-radius: 50%; background: #fff1ca; color: #bd8a21; }
.tm-notice div { flex: 1; }
.tm-notice strong { font-size: 11px; }
.tm-notice p { margin: 2px 0 0; color: #917d56; font-size: 10px; }
.tm-notice a { color: var(--tm-brand); font-size: 10px; font-weight: 700; text-decoration: none; white-space: nowrap; }
.tm-caught-up { display: flex; min-height: 74px; align-items: center; gap: 12px; padding: 13px 15px; border: 1px dashed var(--tm-line); border-radius: 10px; background: #fbfcfd; }
.tm-caught-up__icon { display: grid; width: 34px; height: 34px; flex: none; place-items: center; border-radius: 50%; background: #edf8f2; color: #54a878; }
.tm-caught-up div { flex: 1; }
.tm-caught-up strong { color: var(--tm-ink); font-size: 11px; }
.tm-caught-up p, .tm-learning-note { margin: 2px 0 0; color: var(--tm-muted); font-size: 10px; }
.tm-my-courses { display: grid; gap: 7px; }
.tm-my-course { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px; border: 1px solid var(--tm-line); border-radius: 9px; color: inherit; text-decoration: none; }
.tm-my-course:hover { background: var(--tm-soft); }
.tm-my-course > span:first-child { display: grid; gap: 2px; }
.tm-my-course strong { color: var(--tm-ink); font-size: 11px; }
.tm-my-course small { color: var(--tm-muted); font-size: 10px; text-transform: capitalize; }
.tm-my-course__progress { color: var(--tm-brand); font-weight: 700; }
.tm-caught-up small { color: #a0adba; font-size: 9px; }
.tm-toast { display: flex; align-items: center; gap: 9px; margin: 0 0 14px; padding: 10px 12px; border: 1px solid #bfe3d6; border-radius: 9px; background: #e8f6f1; color: #086a51; font-weight: 600; }
.tm-toast span { flex: 1; }
.tm-toast button, .tm-icon-btn { display: grid; width: 32px; height: 32px; place-items: center; border: 0; border-radius: 7px; background: transparent; color: inherit; cursor: pointer; }
.tm-state, .tm-loading { display: grid; justify-items: center; gap: 8px; padding: 24px; color: var(--tm-muted); text-align: center; }
.tm-state p { margin: 0; }
.tm-dialog { position: fixed; inset: 50% auto auto 50%; width: min(560px, calc(100vw - 32px)); max-width: calc(100vw - 32px); max-height: min(84dvh, 760px); margin: 0; padding: 0; transform: translate(-50%, -50%); overflow: hidden; border: 1px solid var(--tm-line); border-radius: 16px; background: #fff; color: var(--tm-text); box-shadow: 0 24px 80px rgba(15, 35, 60, .3); }
.tm-dialog--form { width: min(840px, calc(100vw - 40px)); max-height: min(88dvh, 900px); }
.tm-dialog--small { width: min(520px, calc(100vw - 32px)); }
.tm-dialog::backdrop { background: rgba(15, 30, 48, .52); backdrop-filter: blur(3px); }
.tm-dialog[open] { display: flex; flex-direction: column; }
.tm-dialog__inner { display: flex; min-height: 0; flex: 1 1 auto; flex-direction: column; }
.tm-dialog__head { display: flex; flex: none; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 18px 24px; border-bottom: 1px solid var(--tm-line); background: #fff; }
.tm-dialog__head h2 { margin: 0; font-size: 21px; }
.tm-dialog__head > div > p:last-child:not(.tm-eyebrow) { margin: 3px 0 0; color: var(--tm-muted); font-size: 11px; }
.tm-dialog__body { display: grid; min-height: 0; flex: 1 1 auto; gap: 16px; padding: 20px 24px; overflow-y: auto; overscroll-behavior: contain; }
.tm-dialog__foot { display: flex; flex: none; justify-content: flex-end; gap: 8px; padding: 13px 24px; border-top: 1px solid var(--tm-line); background: #f8fafb; }
.tm-field { display: grid; gap: 5px; color: var(--tm-ink); font-size: 11px; font-weight: 700; }
.tm-field input, .tm-field select, .tm-field textarea { box-sizing: border-box; width: 100%; min-height: 36px; padding: 8px 10px; border: 1px solid #ced9e4; border-radius: 7px; background: #fff; color: var(--tm-ink); font: inherit; font-size: 12px; }
.tm-field textarea { resize: vertical; }
.tm-field input:focus, .tm-field select:focus, .tm-field textarea:focus { border-color: var(--tm-brand); outline: 0; box-shadow: 0 0 0 3px rgba(8, 127, 112, .12); }
.tm-field small { color: #b3322a; font-size: 10px; font-weight: 500; }
.tm-fields-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.tm-course-fields { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 13px 15px; }
.tm-field--title { grid-column: span 2; }
.tm-field--wide { grid-column: 1 / -1; }
.tm-toggle { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border: 1px solid var(--tm-line); border-radius: 9px; background: #fbfcfd; color: var(--tm-ink); }
.tm-toggle input { width: 16px; height: 16px; margin: 1px 0 0; accent-color: var(--tm-brand); }
.tm-toggle span { display: grid; gap: 2px; }
.tm-toggle strong { font-size: 11px; }
.tm-toggle small { color: var(--tm-muted); font-size: 10px; font-weight: 400; }
.tm-editor { display: grid; gap: 10px; padding-top: 13px; border-top: 1px solid var(--tm-line); }
.tm-editor > header, .tm-repeat > header { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.tm-editor h3 { margin: 0; font-size: 13px; }
.tm-editor > header p { margin: 2px 0 0; color: var(--tm-muted); font-size: 10px; }
.tm-repeat { display: grid; gap: 9px; padding: 12px; border: 1px solid var(--tm-line); border-radius: 9px; background: #fbfcfd; }
.tm-repeat > header strong { color: var(--tm-ink); font-size: 11px; }
.tm-repeat > header .tm-icon-btn { color: #9c5b55; }
.tm-repeat > header .tm-icon-btn:disabled { opacity: .45; cursor: not-allowed; }
.tm-lock-note, .tm-error-box { margin: 0; padding: 9px 11px; border-radius: 7px; font-size: 11px; }
.tm-lock-note { border: 1px solid #f0e2bf; background: #fffbef; color: #765d2a; }
.tm-error-box { border: 1px solid #f0c7c3; background: #fff7f6; color: #a5342e; }
.tm-staff-list { display: grid; max-height: 45vh; gap: 7px; overflow-y: auto; }
.tm-staff-option { display: flex; align-items: center; gap: 10px; padding: 10px; border: 1px solid var(--tm-line); border-radius: 8px; cursor: pointer; }
.tm-staff-option input { accent-color: var(--tm-brand); }
.tm-staff-option span { display: grid; gap: 1px; }
.tm-staff-option strong { color: var(--tm-ink); font-size: 11px; }
.tm-staff-option small { color: var(--tm-muted); font-size: 10px; text-transform: capitalize; }
@media (max-width: 700px) {
  .tm { padding-top: 14px; }
  .tm-hero { align-items: flex-start; flex-direction: column; padding: 16px; }
  .tm-hero h1 { font-size: 1.3rem; }
  .tm-hero > .tm-btn { width: 100%; }
  .tm-panel { padding: 12px; }
  .tm-notice { align-items: flex-start; flex-wrap: wrap; }
  .tm-notice a { margin-left: 40px; }
  .tm-caught-up { align-items: flex-start; }
  .tm-caught-up small { display: none; }
  .tm-dialog { max-height: 90dvh; }
  .tm-dialog--form { width: calc(100vw - 24px); max-width: calc(100vw - 24px); }
  .tm-course-fields, .tm-fields-row { grid-template-columns: 1fr; }
  .tm-field--title, .tm-field--wide { grid-column: auto; }
  .tm-dialog__head, .tm-dialog__body, .tm-dialog__foot { padding-inline: 15px; }
  .tm-dialog__foot .tm-btn { flex: 1; }
}
</style>
