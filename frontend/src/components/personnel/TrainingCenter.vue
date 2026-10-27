<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../AppIcon.vue'
import logo from '../../assets/LLEE_Cambodia_Inverse_L.png'
import { completeTrainingLesson, getMyTraining, getTrainingCourse, getTrainingQuiz, submitTrainingQuiz } from '../../services/trainingService'

const route = useRoute()
const router = useRouter()
const describe = (error, fallback) => (!error.response ? 'We couldn’t reach the server. Check your connection and try again.'
  : error.response.status >= 500 ? 'Something went wrong on our side. Please try again in a moment.'
  : [error.response.data?.errors && Object.values(error.response.data.errors).flat()[0]].find(Boolean) || error.response.data?.message || fallback)

/* ---------- Shared presentation ---------- */
const STATUS = {
  not_started: { label: 'Not started', icon: 'circle-dot', tone: 'muted', action: 'Start course' },
  in_progress: { label: 'In progress', icon: 'clock', tone: 'info', action: 'Continue' },
  quiz_ready: { label: 'Quiz ready', icon: 'square-check', tone: 'info', action: 'Take the quiz' },
  completed: { label: 'Completed', icon: 'check-circle', tone: 'success', action: 'View certificate' },
}
const formatDate = (iso) => (iso ? new Date(iso.length === 10 ? `${iso}T00:00:00` : iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '')
const dueText = (course) => {
  if (!course.due_date) return course.status === 'completed' ? `Completed ${formatDate(course.completed_at)}` : 'No deadline'
  if (course.status === 'completed') return `Completed ${formatDate(course.completed_at)}`
  const days = Math.round((new Date(`${course.due_date}T00:00:00`) - new Date(new Date().toDateString())) / 864e5)
  if (days < 0) return `Overdue · was due ${formatDate(course.due_date)}`
  if (days === 0) return 'Due today'
  return `Due ${formatDate(course.due_date)}${days <= 7 ? ` · ${days} day${days === 1 ? '' : 's'} left` : ''}`
}
const dueTone = (course) => (course.overdue ? 'danger' : course.due_soon ? 'warning' : '')
/** Lesson text: blank lines separate paragraphs; "- " lines become a bullet list. Rendered as text, never HTML. */
const blocks = (body) => body.split(/\n\s*\n/).map((chunk) => {
  const lines = chunk.split('\n').map((line) => line.trim()).filter(Boolean)
  return lines.every((line) => line.startsWith('- ')) ? { list: lines.map((line) => line.slice(2)) } : { text: lines.join(' ') }
})

/* ---------- Course list ---------- */
const courses = ref([])
const summary = ref(null)
const listLoading = ref(true)
const listError = ref('')
const loadList = async () => {
  listLoading.value = true
  listError.value = ''
  try {
    const { data } = await getMyTraining()
    courses.value = data.data
    summary.value = data.summary
  } catch (error) {
    listError.value = describe(error, 'Your training couldn’t be loaded.')
  } finally {
    listLoading.value = false
  }
}
const rank = (course) => (course.overdue ? 0 : course.status === 'completed' ? 3 : course.status === 'not_started' ? 2 : 1)
const sortedCourses = computed(() => [...courses.value].sort((a, b) => rank(a) - rank(b)))
const dueAlert = computed(() => {
  const overdue = courses.value.filter((course) => course.overdue)
  const soon = courses.value.filter((course) => course.due_soon)
  if (overdue.length) return { tone: 'danger', text: `${overdue.length === 1 ? `“${overdue[0].title}” is` : `${overdue.length} courses are`} overdue. Please complete ${overdue.length === 1 ? 'it' : 'them'} as soon as possible.` }
  if (soon.length) return { tone: 'warning', text: `${soon.length === 1 ? `“${soon[0].title}” is` : `${soon.length} courses are`} due within a week.` }
  return null
})

/* ---------- Course player ---------- */
const courseId = computed(() => (route.query.course ? Number(route.query.course) : null))
const course = ref(null)
const courseLoading = ref(false)
const courseError = ref('')
const courseMissing = ref(false)
const step = ref(null) // a lesson id, 'quiz' or 'certificate'
const busy = ref(false)
const actionError = ref('')
const stepHeading = ref(null)

const openCourse = (id) => router.push({ query: { ...route.query, course: id } })
const backToList = () => { const { course: _, ...rest } = route.query; router.push({ query: rest }) }
const defaultStep = (detail) => (detail.status === 'completed' ? 'certificate' : detail.status === 'quiz_ready' ? 'quiz' : (detail.lessons.find((lesson) => !lesson.completed) || detail.lessons[0])?.id ?? 'quiz')
const loadCourse = async () => {
  courseLoading.value = true
  courseError.value = ''
  courseMissing.value = false
  course.value = null
  resetQuiz()
  try {
    course.value = (await getTrainingCourse(courseId.value)).data.data
    step.value = defaultStep(course.value)
  } catch (error) {
    courseMissing.value = error.response?.status === 404
    courseError.value = courseMissing.value ? 'This course isn’t available any more.' : describe(error, 'The course couldn’t be loaded.')
  } finally {
    courseLoading.value = false
  }
  await nextTick() // the outline only exists once loading has finished
  revealCurrentStep()
}
// On narrow screens the outline is a horizontal strip; keep the current step visible in it.
const revealCurrentStep = () => { const strip = document.querySelector('.tr-outline ol'); const item = strip?.querySelector('.is-current'); if (strip && item && strip.scrollWidth > strip.clientWidth) strip.scrollLeft = item.offsetLeft - (strip.clientWidth - item.offsetWidth) / 2 }
const goTo = async (target) => {
  actionError.value = ''
  step.value = target
  await nextTick()
  revealCurrentStep()
  stepHeading.value?.focus()
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

const lessonIndex = computed(() => course.value?.lessons.findIndex((lesson) => lesson.id === step.value) ?? -1)
const lesson = computed(() => course.value?.lessons[lessonIndex.value] || null)
const quizUnlocked = computed(() => course.value && course.value.lessons.every((item) => item.completed))
const outline = computed(() => course.value ? [
  ...course.value.lessons.map((item, index) => ({ key: item.id, label: item.title, kind: `Lesson ${index + 1}`, done: item.completed, locked: item.locked })),
  { key: 'quiz', label: 'Quiz', kind: `${course.value.question_count} questions · pass ${course.value.pass_mark}%`, done: course.value.status === 'completed', locked: !quizUnlocked.value },
  { key: 'certificate', label: 'Certificate', kind: course.value.status === 'completed' ? 'Ready' : 'After you pass', done: course.value.status === 'completed', locked: course.value.status !== 'completed' },
] : [])
const lockedReason = (item) => (item.key === 'quiz' ? 'Complete every lesson to unlock the quiz' : item.key === 'certificate' ? 'Pass the quiz to get your certificate' : 'Finish the previous lesson first')

const completeLesson = async () => {
  busy.value = true
  actionError.value = ''
  try {
    const current = lesson.value
    course.value = (await completeTrainingLesson(course.value.id, current.id)).data.data
    const next = course.value.lessons[lessonIndex.value + 1]
    await goTo(next ? next.id : 'quiz')
  } catch (error) {
    actionError.value = describe(error, 'Your progress wasn’t saved. Please try again.')
  } finally {
    busy.value = false
  }
}

/* ---------- Quiz ---------- */
const questions = ref([])
const answers = reactive({})
const quizStarted = ref(false)
const quizResult = ref(null)
const showMissing = ref(false)
const answeredCount = computed(() => questions.value.filter((question) => answers[question.id] !== undefined).length)
function resetQuiz () { questions.value = []; Object.keys(answers).forEach((key) => delete answers[key]); quizStarted.value = false; quizResult.value = null; showMissing.value = false }
const startQuiz = async () => {
  busy.value = true
  actionError.value = ''
  try {
    questions.value = (await getTrainingQuiz(course.value.id)).data.data
    Object.keys(answers).forEach((key) => delete answers[key])
    quizResult.value = null
    showMissing.value = false
    quizStarted.value = true
    await nextTick()
    document.querySelector('.tr-question input')?.focus()
  } catch (error) {
    actionError.value = describe(error, 'The quiz couldn’t be loaded.')
  } finally {
    busy.value = false
  }
}
const submitQuiz = async () => {
  actionError.value = ''
  if (answeredCount.value < questions.value.length) {
    showMissing.value = true
    await nextTick()
    document.querySelector('.tr-question.is-missing input')?.focus()
    return
  }
  busy.value = true
  try {
    const { data } = await submitTrainingQuiz(course.value.id, { ...answers })
    quizResult.value = data.data.result
    course.value = data.data.course
    quizStarted.value = false
    await nextTick()
    stepHeading.value?.focus()
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } catch (error) {
    actionError.value = describe(error, 'Your answers weren’t submitted. Please try again.')
  } finally {
    busy.value = false
  }
}
const missedPrompts = computed(() => (quizResult.value ? questions.value.filter((question) => quizResult.value.incorrect.includes(question.id)).map((question) => question.prompt) : []))
const printCertificate = () => window.print()

watch(courseId, (id) => { if (id) loadCourse(); else { course.value = null; loadList() } })
onMounted(() => (courseId.value ? loadCourse() : loadList()))
</script>

<template>
  <div class="tr">
    <!-- ================= Course list ================= -->
    <template v-if="!courseId">
      <header class="tr-head">
        <h1>Staff Online Training</h1>
        <p>Work through each course’s lessons, pass the short quiz, and download your certificate.</p>
      </header>

      <div v-if="listLoading" class="tr-skeleton" aria-busy="true" aria-label="Loading your training"><span v-for="n in 4" :key="n"></span></div>

      <section v-else-if="listError" class="tr-state tr-state--error" role="alert">
        <AppIcon name="alert-circle" :size="28" /><h2>Training didn’t load</h2><p>{{ listError }}</p>
        <button type="button" class="tr-btn tr-btn--primary" @click="loadList"><AppIcon name="refresh" :size="16" />Try again</button>
      </section>

      <section v-else-if="!courses.length" class="tr-state">
        <AppIcon name="graduation" :size="28" /><h2>No courses assigned</h2><p>HR hasn’t assigned any online training to you yet.</p>
      </section>

      <template v-else>
        <section class="tr-summary" aria-label="Training summary">
          <article class="tr-progress-card">
            <div class="tr-progress-card__top"><span>Overall progress</span><strong>{{ summary.percent }}%</strong></div>
            <div class="tr-track" role="progressbar" :aria-valuenow="summary.percent" aria-valuemin="0" aria-valuemax="100" aria-label="Courses completed"><i :style="{ width: `${summary.percent}%` }"></i></div>
            <p>{{ summary.completed }} of {{ summary.assigned }} course{{ summary.assigned === 1 ? '' : 's' }} completed</p>
          </article>
          <div v-for="tile in [{ key: 'not_started', label: 'Not started', icon: 'circle-dot', tone: 'muted' }, { key: 'in_progress', label: 'In progress', icon: 'clock', tone: 'info' }, { key: 'completed', label: 'Completed', icon: 'check-circle', tone: 'success' }, { key: 'overdue', label: 'Overdue', icon: 'alert-circle', tone: 'danger' }]" :key="tile.key" class="tr-stat" :class="[`is-${tile.tone}`, { 'is-zero': !summary[tile.key] }]">
            <span class="tr-stat__icon"><AppIcon :name="tile.icon" :size="18" /></span>
            <span><strong>{{ summary[tile.key] }}</strong><small>{{ tile.label }}</small></span>
          </div>
        </section>

        <p v-if="dueAlert" class="tr-banner" :class="`is-${dueAlert.tone}`" role="status"><AppIcon :name="dueAlert.tone === 'danger' ? 'alert-circle' : 'clock'" :size="18" /><span>{{ dueAlert.text }}</span></p>

        <section class="tr-grid" aria-label="Your courses">
          <article v-for="item in sortedCourses" :key="item.id" class="tr-course" :class="{ 'is-overdue': item.overdue }">
            <header class="tr-course__top">
              <span v-if="item.category" class="tr-chip">{{ item.category }}</span>
              <span class="tr-badge" :class="`is-${STATUS[item.status].tone}`"><AppIcon :name="STATUS[item.status].icon" :size="14" />{{ STATUS[item.status].label }}</span>
            </header>
            <h2>{{ item.title }}</h2>
            <p class="tr-course__summary">{{ item.summary }}</p>
            <ul class="tr-meta">
              <li><AppIcon name="file-text" :size="15" />{{ item.lesson_count }} lesson{{ item.lesson_count === 1 ? '' : 's' }} + quiz</li>
              <li><AppIcon name="clock" :size="15" />About {{ item.estimated_minutes }} min</li>
              <li><AppIcon name="square-check" :size="15" />Pass mark {{ item.pass_mark }}%</li>
            </ul>
            <div class="tr-course__progress">
              <div class="tr-course__progress-label"><span>{{ item.status === 'completed' ? `Passed with ${item.best_score}%` : item.status === 'quiz_ready' ? 'All lessons done — quiz next' : `${item.lessons_done} of ${item.lesson_count} lesson${item.lesson_count === 1 ? '' : 's'}` }}</span><strong>{{ item.percent }}%</strong></div>
              <div class="tr-track tr-track--sm" role="progressbar" :aria-valuenow="item.percent" aria-valuemin="0" aria-valuemax="100" :aria-label="`${item.title} progress`"><i :style="{ width: `${item.percent}%` }"></i></div>
            </div>
            <p class="tr-due" :class="dueTone(item) && `is-${dueTone(item)}`"><AppIcon :name="item.status === 'completed' ? 'check-circle' : 'calendar'" :size="15" />{{ dueText(item) }}<template v-if="item.attempts && item.status !== 'completed'"> · best quiz score {{ item.best_score }}%</template></p>
            <button type="button" class="tr-btn" :class="item.status === 'completed' ? 'tr-btn--ghost' : 'tr-btn--primary'" @click="openCourse(item.id)">{{ STATUS[item.status].action }}<AppIcon :name="item.status === 'completed' ? 'eye' : 'arrow-right'" :size="16" /></button>
          </article>
        </section>
      </template>
    </template>

    <!-- ================= Course player ================= -->
    <template v-else>
      <button type="button" class="tr-back" @click="backToList"><AppIcon name="arrow-left" :size="16" />All courses</button>

      <div v-if="courseLoading" class="tr-skeleton" aria-busy="true" aria-label="Loading course"><span v-for="n in 3" :key="n"></span></div>
      <section v-else-if="courseError" class="tr-state tr-state--error" role="alert">
        <AppIcon name="alert-circle" :size="28" /><h2>Course didn’t load</h2><p>{{ courseError }}</p>
        <div class="tr-state__actions"><button type="button" class="tr-btn tr-btn--ghost" @click="backToList">Back to courses</button><button v-if="!courseMissing" type="button" class="tr-btn tr-btn--primary" @click="loadCourse"><AppIcon name="refresh" :size="16" />Try again</button></div>
      </section>

      <template v-else-if="course">
        <header class="tr-course-head">
          <div>
            <p class="tr-eyebrow">{{ course.category || 'Course' }}</p>
            <h1>{{ course.title }}</h1>
            <p class="tr-due" :class="dueTone(course) && `is-${dueTone(course)}`"><AppIcon name="calendar" :size="15" />{{ dueText(course) }}</p>
          </div>
          <div class="tr-course-head__progress">
            <div class="tr-course__progress-label"><span>{{ course.lessons_done }} of {{ course.lesson_count }} lesson{{ course.lesson_count === 1 ? '' : 's' }}{{ course.status === 'completed' ? ' · quiz passed' : '' }}</span><strong>{{ course.percent }}%</strong></div>
            <div class="tr-track" role="progressbar" :aria-valuenow="course.percent" aria-valuemin="0" aria-valuemax="100" aria-label="Course progress"><i :style="{ width: `${course.percent}%` }"></i></div>
          </div>
        </header>

        <div class="tr-player">
          <nav class="tr-outline" aria-label="Course outline">
            <ol>
              <li v-for="item in outline" :key="item.key">
                <button type="button" class="tr-outline__item" :class="{ 'is-current': step === item.key, 'is-done': item.done, 'is-locked': item.locked }" :aria-current="step === item.key ? 'step' : undefined" :aria-disabled="item.locked ? 'true' : undefined" :title="item.locked ? lockedReason(item) : undefined" @click="!item.locked && goTo(item.key)">
                  <span class="tr-outline__dot"><AppIcon :name="item.locked ? 'lock' : item.done ? 'check' : item.key === 'quiz' ? 'square-check' : item.key === 'certificate' ? 'graduation' : 'file-text'" :size="14" /></span>
                  <span class="tr-outline__text"><small>{{ item.kind }}</small><strong>{{ item.label }}</strong></span>
                </button>
              </li>
            </ol>
          </nav>

          <section class="tr-panel" aria-live="polite">
            <p v-if="actionError" class="tr-alert" role="alert"><AppIcon name="alert-circle" :size="18" /><span>{{ actionError }}</span></p>

            <!-- Lesson -->
            <article v-if="lesson">
              <p class="tr-eyebrow">Lesson {{ lessonIndex + 1 }} of {{ course.lessons.length }}<span v-if="lesson.completed" class="tr-badge is-success"><AppIcon name="check" :size="13" />Completed</span></p>
              <h2 ref="stepHeading" tabindex="-1">{{ lesson.title }}</h2>
              <div class="tr-lesson">
                <template v-for="(block, index) in blocks(lesson.body)" :key="index">
                  <ul v-if="block.list"><li v-for="line in block.list" :key="line">{{ line }}</li></ul>
                  <p v-else>{{ block.text }}</p>
                </template>
              </div>
              <footer class="tr-panel__foot">
                <button v-if="lessonIndex > 0" type="button" class="tr-btn tr-btn--ghost" @click="goTo(course.lessons[lessonIndex - 1].id)"><AppIcon name="arrow-left" :size="16" />Previous</button>
                <span class="tr-spacer"></span>
                <button v-if="!lesson.completed" type="button" class="tr-btn tr-btn--primary" :disabled="busy" @click="completeLesson"><AppIcon :name="busy ? 'refresh' : 'check'" :size="16" :class="{ 'tr-spin': busy }" />{{ busy ? 'Saving…' : lessonIndex === course.lessons.length - 1 ? 'Complete lesson and go to quiz' : 'Complete lesson and continue' }}</button>
                <button v-else type="button" class="tr-btn tr-btn--primary" @click="goTo(course.lessons[lessonIndex + 1]?.id ?? 'quiz')">{{ course.lessons[lessonIndex + 1] ? 'Next lesson' : course.status === 'completed' ? 'View quiz result' : 'Go to quiz' }}<AppIcon name="arrow-right" :size="16" /></button>
              </footer>
            </article>

            <!-- Quiz -->
            <article v-else-if="step === 'quiz'">
              <p class="tr-eyebrow">Quiz</p>

              <template v-if="quizResult">
                <div class="tr-result" :class="quizResult.passed ? 'is-pass' : 'is-fail'">
                  <span class="tr-result__icon"><AppIcon :name="quizResult.passed ? 'check' : 'refresh'" :size="28" /></span>
                  <h2 ref="stepHeading" tabindex="-1">{{ quizResult.passed ? 'You passed!' : 'Not quite — try again' }}</h2>
                  <p class="tr-result__score"><strong>{{ quizResult.score }}%</strong> · {{ quizResult.correct }} of {{ quizResult.total }} correct · pass mark {{ quizResult.pass_mark }}%</p>
                  <template v-if="!quizResult.passed">
                    <p>Review these questions, then retake the quiz. You can go back to any lesson first.</p>
                    <ul class="tr-missed"><li v-for="prompt in missedPrompts" :key="prompt"><AppIcon name="x" :size="14" />{{ prompt }}</li></ul>
                  </template>
                  <p v-else>Your certificate is ready.</p>
                </div>
                <footer class="tr-panel__foot">
                  <template v-if="quizResult.passed"><span class="tr-spacer"></span><button type="button" class="tr-btn tr-btn--primary" @click="goTo('certificate')"><AppIcon name="graduation" :size="16" />View certificate</button></template>
                  <template v-else><button type="button" class="tr-btn tr-btn--ghost" @click="goTo(course.lessons[0].id)"><AppIcon name="file-text" :size="16" />Review lessons</button><span class="tr-spacer"></span><button type="button" class="tr-btn tr-btn--primary" :disabled="busy" @click="startQuiz"><AppIcon name="refresh" :size="16" />Retake quiz</button></template>
                </footer>
              </template>

              <template v-else-if="course.status === 'completed'">
                <h2 ref="stepHeading" tabindex="-1">Quiz passed</h2>
                <p class="tr-muted">You passed with {{ course.best_score }}% on {{ formatDate(course.completed_at) }}.</p>
                <footer class="tr-panel__foot"><span class="tr-spacer"></span><button type="button" class="tr-btn tr-btn--primary" @click="goTo('certificate')"><AppIcon name="graduation" :size="16" />View certificate</button></footer>
              </template>

              <form v-else-if="quizStarted" novalidate @submit.prevent="submitQuiz">
                <h2 ref="stepHeading" tabindex="-1">{{ course.title }} quiz</h2>
                <p class="tr-muted">Answer every question. You need {{ course.pass_mark }}% to pass. <strong>{{ answeredCount }} of {{ questions.length }} answered.</strong></p>
                <p v-if="showMissing && answeredCount < questions.length" class="tr-alert" role="alert"><AppIcon name="alert-circle" :size="18" /><span>Answer the {{ questions.length - answeredCount === 1 ? 'question' : `${questions.length - answeredCount} questions` }} marked below.</span></p>
                <fieldset v-for="(question, index) in questions" :key="question.id" class="tr-question" :class="{ 'is-missing': showMissing && answers[question.id] === undefined }">
                  <legend><span>Question {{ index + 1 }}</span>{{ question.prompt }}</legend>
                  <label v-for="(option, optionIndex) in question.options" :key="optionIndex" class="tr-option" :class="{ 'is-checked': answers[question.id] === optionIndex }">
                    <input v-model="answers[question.id]" type="radio" :name="`q-${question.id}`" :value="optionIndex" />
                    <span>{{ option }}</span>
                  </label>
                </fieldset>
                <footer class="tr-panel__foot"><span class="tr-spacer"></span><button type="submit" class="tr-btn tr-btn--primary" :disabled="busy"><AppIcon :name="busy ? 'refresh' : 'send'" :size="16" :class="{ 'tr-spin': busy }" />{{ busy ? 'Submitting…' : 'Submit answers' }}</button></footer>
              </form>

              <template v-else>
                <h2 ref="stepHeading" tabindex="-1">Ready for the quiz?</h2>
                <ul class="tr-quiz-facts">
                  <li><AppIcon name="list" :size="16" />{{ course.question_count }} multiple-choice questions</li>
                  <li><AppIcon name="square-check" :size="16" />Pass mark {{ course.pass_mark }}%</li>
                  <li><AppIcon name="refresh" :size="16" />{{ course.attempts ? `Attempts so far: ${course.attempts} · best score ${course.best_score}%` : 'You can retake it if you don’t pass' }}</li>
                </ul>
                <footer class="tr-panel__foot"><button type="button" class="tr-btn tr-btn--ghost" @click="goTo(course.lessons[course.lessons.length - 1]?.id)"><AppIcon name="arrow-left" :size="16" />Back to lessons</button><span class="tr-spacer"></span><button type="button" class="tr-btn tr-btn--primary" :disabled="busy" @click="startQuiz"><AppIcon :name="busy ? 'refresh' : 'arrow-right'" :size="16" :class="{ 'tr-spin': busy }" />{{ busy ? 'Loading…' : 'Start quiz' }}</button></footer>
              </template>
            </article>

            <!-- Certificate -->
            <article v-else-if="step === 'certificate' && course.certificate">
              <h2 ref="stepHeading" tabindex="-1" class="sr-only">Certificate</h2>
              <div class="tr-cert">
                <img :src="logo" alt="Live & Learn Cambodia" class="tr-cert__logo" />
                <p class="tr-cert__kicker">Certificate of completion</p>
                <p class="tr-cert__line">This certifies that</p>
                <p class="tr-cert__name">{{ course.certificate.name }}</p>
                <p class="tr-cert__line">has successfully completed the online course</p>
                <p class="tr-cert__course">{{ course.title }}</p>
                <p class="tr-cert__line">with a score of {{ course.certificate.score }}%</p>
                <dl class="tr-cert__meta">
                  <div><dt>Date</dt><dd>{{ formatDate(course.certificate.issued_at) }}</dd></div>
                  <div><dt>Certificate no.</dt><dd>{{ course.certificate.code }}</dd></div>
                </dl>
              </div>
              <footer class="tr-panel__foot">
                <button type="button" class="tr-btn tr-btn--ghost" @click="backToList"><AppIcon name="arrow-left" :size="16" />All courses</button>
                <span class="tr-spacer"></span>
                <button type="button" class="tr-btn tr-btn--primary" @click="printCertificate"><AppIcon name="download" :size="16" />Print or save as PDF</button>
              </footer>
            </article>
          </section>
        </div>
      </template>
    </template>
  </div>
</template>

<style>
.tr { --tr-ink: #17365f; --tr-text: #3d5470; --tr-muted: #6b8099; --tr-line: #dfe7ef; --tr-soft: #f6f9fc; --tr-brand: #0b7f62; --tr-brand-dark: #086a51; --tr-brand-soft: #e8f5f0; --tr-success: #14804a; --tr-success-soft: #e7f6ee; --tr-warning: #a35f00; --tr-warning-soft: #fff4dc; --tr-info: #2b5ea8; --tr-info-soft: #e9f1fd; --tr-danger: #c0392b; --tr-danger-soft: #fdf1f0; width: min(100%, 1180px); margin: 0 auto; padding: 24px 0 48px; color: var(--tr-text); font-size: 14px; line-height: 1.55; }
.tr button { margin: 0; } /* older global styles give every button a top margin and large padding */
.tr h1, .tr h2, .tr h3 { color: var(--tr-ink); letter-spacing: -.01em; }
.tr-head { margin-bottom: 18px; }
.tr-head h1, .tr-course-head h1 { margin: 0 0 4px; font-size: 1.6rem; font-weight: 700; }
.tr-head p { margin: 0; color: var(--tr-muted); }
.tr-eyebrow { display: flex; align-items: center; gap: 10px; margin: 0 0 4px; color: var(--tr-brand); font-size: .75rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
.tr-muted { color: var(--tr-muted); }

/* Summary */
.tr-summary { display: grid; grid-template-columns: minmax(240px, 1.5fr) repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
.tr-progress-card, .tr-stat, .tr-course, .tr-panel, .tr-outline ol, .tr-course-head { border: 1px solid var(--tr-line); border-radius: 14px; background: #fff; box-shadow: 0 4px 14px rgba(23, 54, 95, .04); }
.tr-progress-card { padding: 16px 18px; }
.tr-progress-card__top { display: flex; align-items: baseline; justify-content: space-between; color: var(--tr-muted); font-weight: 600; }
.tr-progress-card__top strong { color: var(--tr-ink); font-size: 1.6rem; line-height: 1.1; }
.tr-progress-card p { margin: 0; color: var(--tr-muted); font-size: .86rem; }
.tr-track { height: 8px; margin: 10px 0 8px; overflow: hidden; border-radius: 99px; background: #e6edf3; }
.tr-track--sm { height: 6px; margin: 6px 0 0; }
.tr-track i { display: block; height: 100%; border-radius: inherit; background: var(--tr-brand); transition: width .4s ease; }
.tr-stat { display: flex; align-items: center; gap: 12px; padding: 14px 16px; color: var(--tr-ink); }
.tr-stat > span:last-child { display: grid; }
.tr-stat strong { font-size: 1.45rem; line-height: 1.1; }
.tr-stat small { color: var(--tr-muted); font-size: .84rem; font-weight: 600; }
.tr-stat__icon { display: grid; width: 38px; height: 38px; flex: none; place-items: center; border-radius: 10px; }
.tr-stat.is-muted .tr-stat__icon { background: #eef2f6; color: #4f6278; }
.tr-stat.is-info .tr-stat__icon { background: var(--tr-info-soft); color: var(--tr-info); }
.tr-stat.is-success .tr-stat__icon { background: var(--tr-success-soft); color: var(--tr-success); }
.tr-stat.is-danger .tr-stat__icon { background: var(--tr-danger-soft); color: var(--tr-danger); }
.tr-stat.is-zero .tr-stat__icon { background: var(--tr-soft); color: #9aabbd; }

.tr-banner, .tr-alert { display: flex; align-items: center; gap: 10px; margin: 0 0 16px; padding: 10px 14px; border: 1px solid; border-radius: 10px; font-size: .9rem; }
.tr-banner svg, .tr-alert svg { flex: none; }
.tr-banner.is-danger, .tr-alert { border-color: #f2c4bf; background: var(--tr-danger-soft); color: #9b2c22; font-weight: 600; }
.tr-banner.is-warning { border-color: #f1dfae; background: #fffaeb; color: #7a5a12; font-weight: 600; }

/* Course cards */
.tr-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 340px), 1fr)); gap: 14px; }
.tr-course { display: flex; flex-direction: column; padding: 18px; }
.tr-course.is-overdue { border-color: #f2c9c4; }
.tr-course__top { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px; }
.tr-course h2 { margin: 0 0 4px; font-size: 1.1rem; font-weight: 700; }
.tr-course__summary { margin: 0 0 12px; }
.tr-meta { display: flex; flex-wrap: wrap; gap: 6px 14px; margin: 0 0 14px; padding: 0; list-style: none; color: var(--tr-muted); font-size: .84rem; }
.tr-meta li, .tr-quiz-facts li { display: flex; align-items: center; gap: 6px; }
.tr-course__progress { margin-bottom: 10px; }
.tr-course__progress-label { display: flex; justify-content: space-between; gap: 10px; color: var(--tr-muted); font-size: .84rem; }
.tr-course__progress-label strong { color: var(--tr-ink); }
.tr-due { display: flex; align-items: center; gap: 6px; margin: 0 0 16px; color: var(--tr-muted); font-size: .86rem; }
.tr-due.is-danger { color: var(--tr-danger); font-weight: 600; }
.tr-due.is-warning { color: var(--tr-warning); font-weight: 600; }
.tr-course > .tr-btn { margin-top: auto; width: 100%; }

.tr-chip { display: inline-flex; padding: 3px 10px; border: 1px solid var(--tr-line); border-radius: 99px; background: var(--tr-soft); color: #44586f; font-size: .76rem; font-weight: 600; }
.tr-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 99px; font-size: .76rem; font-weight: 700; letter-spacing: 0; text-transform: none; white-space: nowrap; }
.tr-badge.is-success { background: var(--tr-success-soft); color: var(--tr-success); }
.tr-badge.is-info { background: var(--tr-info-soft); color: var(--tr-info); }
.tr-badge.is-muted { background: #eef2f6; color: #4f6278; }

/* Buttons */
.tr-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 42px; padding: 0 18px; border: 1px solid transparent; border-radius: 10px; font: inherit; font-size: .92rem; font-weight: 600; white-space: nowrap; cursor: pointer; }
.tr-btn svg { flex: none; }
.tr-btn--primary { background: var(--tr-brand); color: #fff; }
.tr-btn--primary:hover:not(:disabled) { background: var(--tr-brand-dark); }
.tr-btn--ghost { border-color: #cfdbe7; background: #fff; color: var(--tr-ink); }
.tr-btn--ghost:hover:not(:disabled) { border-color: #aebfd1; background: var(--tr-soft); }
.tr-btn:disabled { opacity: .65; cursor: progress; }
.tr-back { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 14px !important; padding: 6px 2px; border: 0; background: none; color: var(--tr-brand-dark); font: inherit; font-weight: 600; cursor: pointer; }
.tr-back:hover { text-decoration: underline; transform: none !important; }
.tr-spin { animation: tr-spin 1s linear infinite; }
@keyframes tr-spin { to { transform: rotate(360deg); } }

/* States */
.tr-state { display: grid; justify-items: center; gap: 6px; padding: 44px 24px; border: 1px dashed #d6e0ea; border-radius: 14px; background: #fff; color: var(--tr-muted); text-align: center; }
.tr-state h2 { margin: 6px 0 0; font-size: 1.1rem; }
.tr-state p { margin: 0 0 8px; }
.tr-state--error { border-style: solid; border-color: #f2c4bf; color: var(--tr-danger); }
.tr-state__actions { display: flex; gap: 10px; }
.tr-skeleton { display: grid; gap: 12px; }
.tr-skeleton span { display: block; height: 96px; border-radius: 14px; background: linear-gradient(90deg, #eef2f6 25%, #f6f9fc 50%, #eef2f6 75%); background-size: 200% 100%; animation: tr-shimmer 1.4s ease infinite; }
@keyframes tr-shimmer { to { background-position: -200% 0; } }

/* Player */
.tr-course-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px 32px; margin-bottom: 16px; padding: 20px 24px; }
.tr-course-head .tr-due { margin: 4px 0 0; }
.tr-course-head__progress { width: min(100%, 320px); }
.tr-player { display: grid; grid-template-columns: 280px minmax(0, 1fr); align-items: start; gap: 20px; }
.tr-player > * { min-width: 0; }
.tr-outline { position: sticky; top: 96px; }
.tr-outline ol { display: grid; gap: 2px; margin: 0; padding: 8px; list-style: none; }
.tr-outline__item { display: flex; width: 100%; align-items: center; gap: 12px; padding: 10px 12px; border: 0; border-radius: 10px; background: transparent; color: var(--tr-text); text-align: left; cursor: pointer; }
.tr-outline__item:hover:not(.is-locked) { background: var(--tr-soft); transform: none !important; }
.tr-outline__item.is-locked { cursor: not-allowed; opacity: .6; }
.tr-outline__dot { display: grid; width: 28px; height: 28px; flex: none; place-items: center; border: 1.5px solid #cdd9e5; border-radius: 50%; background: #fff; color: var(--tr-muted); }
.tr-outline__text { display: grid; min-width: 0; }
.tr-outline__text small { color: var(--tr-muted); font-size: .74rem; }
.tr-outline__text strong { overflow: hidden; color: var(--tr-ink); font-size: .88rem; font-weight: 600; text-overflow: ellipsis; white-space: nowrap; }
.tr-outline__item.is-done .tr-outline__dot { border-color: #bfe3d6; background: var(--tr-brand-soft); color: var(--tr-brand); }
.tr-outline__item.is-current { background: var(--tr-brand-soft); }
.tr-outline__item.is-current .tr-outline__dot { border-color: var(--tr-brand); background: var(--tr-brand); color: #fff; }
.tr-outline__item.is-current strong { color: var(--tr-brand-dark); font-weight: 700; }
.tr-panel { padding: 26px 30px; }
.tr-panel h2 { margin: 0 0 14px; font-size: 1.35rem; font-weight: 700; outline: 0; }
.tr-lesson { max-width: 68ch; color: var(--tr-ink); font-size: 1rem; line-height: 1.7; }
.tr-lesson p { margin: 0 0 14px; }
.tr-lesson ul { margin: 0 0 16px; padding-left: 22px; list-style: disc; } /* global styles strip list markers */
.tr-lesson li { margin-bottom: 6px; }
.tr-lesson li::marker { color: var(--tr-brand); }
.tr-panel__foot { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--tr-line); }
.tr-spacer { flex: 1; }

/* Quiz */
.tr-quiz-facts { display: grid; gap: 10px; margin: 0; padding: 16px 18px; border-radius: 12px; background: var(--tr-soft); list-style: none; color: var(--tr-ink); }
.tr-question { margin: 0 0 16px; padding: 16px 18px; border: 1px solid var(--tr-line); border-radius: 12px; }
.tr-question.is-missing { border-color: #f2c4bf; background: #fffbfa; }
.tr-question legend { display: grid; gap: 2px; padding: 0 4px; color: var(--tr-ink); font-weight: 600; }
.tr-question legend span { color: var(--tr-muted); font-size: .76rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }
.tr-option { display: flex; align-items: flex-start; gap: 12px; margin-top: 8px; padding: 11px 14px; border: 1px solid var(--tr-line); border-radius: 10px; color: var(--tr-ink); cursor: pointer; }
.tr-option:hover { border-color: #aebfd1; }
.tr-option.is-checked { border-color: #9fd6c3; background: #f6fbf9; }
.tr-option input { width: 18px; height: 18px; flex: none; margin: 2px 0 0; accent-color: var(--tr-brand); cursor: pointer; }
.tr-result { display: grid; justify-items: center; padding: 18px 10px 0; text-align: center; }
.tr-result__icon { display: grid; width: 64px; height: 64px; place-items: center; margin-bottom: 12px; border-radius: 50%; }
.tr-result.is-pass .tr-result__icon { background: var(--tr-success-soft); color: var(--tr-success); }
.tr-result.is-fail .tr-result__icon { background: var(--tr-warning-soft); color: var(--tr-warning); }
.tr-result h2 { margin-bottom: 4px; }
.tr-result__score { margin: 0 0 10px; color: var(--tr-muted); }
.tr-result__score strong { color: var(--tr-ink); font-size: 1.2rem; }
.tr-result p { margin: 0 0 10px; }
.tr-missed { display: grid; gap: 6px; max-width: 560px; margin: 0; padding: 0; list-style: none; text-align: left; }
.tr-missed li { display: flex; gap: 8px; padding: 10px 12px; border-radius: 10px; background: var(--tr-danger-soft); color: #7c2a22; }
.tr-missed svg { flex: none; margin-top: 4px; }

/* Certificate */
.tr-cert { position: relative; display: grid; justify-items: center; gap: 4px; padding: 40px 32px; border: 1px solid #cfe5dc; border-radius: 14px; background: linear-gradient(180deg, #fbfefd, #f3faf7); text-align: center; box-shadow: inset 0 0 0 8px #fff, inset 0 0 0 9px #cfe5dc; }
.tr-cert__logo { width: 180px; height: auto; margin-bottom: 14px; }
.tr-cert__kicker { margin: 0 0 12px; color: var(--tr-brand); font-size: .82rem; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; }
.tr-cert__line { margin: 0; color: var(--tr-muted); }
.tr-cert__name { margin: 4px 0 8px; color: var(--tr-ink); font-size: 2rem; font-weight: 700; }
.tr-cert__course { margin: 4px 0; color: var(--tr-ink); font-size: 1.3rem; font-weight: 700; }
.tr-cert__meta { display: flex; gap: 48px; margin: 22px 0 0; padding-top: 16px; border-top: 1px solid #cfe5dc; }
.tr-cert__meta dt { color: var(--tr-muted); font-size: .76rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
.tr-cert__meta dd { margin: 2px 0 0; color: var(--tr-ink); font-weight: 600; }

@media (max-width: 1080px) {
  .tr-summary { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  .tr-progress-card { grid-column: 1 / -1; }
  .tr-player { grid-template-columns: 1fr; }
  .tr-outline { position: static; }
  .tr-outline ol { display: flex; overflow-x: auto; scrollbar-width: none; }
  .tr-outline li { flex: 0 0 auto; }
  .tr-outline__text { max-width: 180px; }
}
@media (max-width: 640px) {
  .tr { padding-top: 16px; }
  .tr-head h1, .tr-course-head h1 { font-size: 1.35rem; }
  .tr-summary { grid-template-columns: 1fr 1fr; gap: 8px; }
  .tr-stat { padding: 12px; }
  .tr-course-head, .tr-panel { padding: 18px 16px; }
  .tr-outline__text { display: none; }
  .tr-outline__item.is-current .tr-outline__text { display: grid; }
  .tr-panel__foot .tr-btn--primary { flex: 1 1 100%; order: 3; }
  .tr-cert { padding: 28px 16px; }
  .tr-cert__name { font-size: 1.5rem; }
  .tr-cert__meta { gap: 24px; }
}
@media (prefers-reduced-motion: reduce) { .tr-track i, .tr-skeleton span, .tr-spin { transition: none; animation: none; } }

/* Printing the certificate: only the certificate, on its own page. */
@media print {
  body * { visibility: hidden !important; }
  .tr-cert, .tr-cert * { visibility: visible !important; }
  .tr-cert { position: fixed; inset: 40px; box-shadow: none; border: 2px solid #0b7f62; }
}
</style>
