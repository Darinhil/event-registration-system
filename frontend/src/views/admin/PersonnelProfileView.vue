<script setup>
import { computed, ref } from 'vue'
import AdminLayout from '../../layouts/AdminLayout.vue'
import AppIcon from '../../components/AppIcon.vue'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const activeSection = ref('my-profile')
const personnelOpen = ref(true)

const profileLinks = [
  { id: 'my-profile', label: 'My Profile (5 steps)', icon: 'user' },
  { id: 'compliances', label: 'Compliances', icon: 'lock' },
  { id: 'contract-history', label: 'Contract History', icon: 'calendar' },
  { id: 'training', label: 'Staff Online Training', icon: 'check-circle' },
]

const workspaceLinks = [
  { id: 'time-management', label: 'Time Management', icon: 'clock' },
  { id: 'other-requests', label: 'Other Requests', icon: 'inbox' },
  { id: 'finance-management', label: 'Finance Management', icon: 'ticket' },
  { id: 'my-requests', label: 'My Requests', icon: 'inbox' },
]

const profileSteps = [
  'Personal information',
  'Contact details',
  'Employment details',
  'Emergency contact',
  'Review profile',
]

const sectionDetails = {
  'my-profile': {
    title: 'My Profile',
    description: 'Your personnel information and account details.',
    emptyTitle: 'Your profile is ready',
    emptyDescription: 'Review the account information currently available to you.',
  },
  compliances: {
    title: 'Compliances',
    description: 'Compliance documents and requirements.',
    emptyTitle: 'No compliance records yet',
    emptyDescription: 'Compliance items will appear here when they are available.',
  },
  'contract-history': {
    title: 'Contract History',
    description: 'A record of your employment contracts.',
    emptyTitle: 'No contract history yet',
    emptyDescription: 'Contract records will appear here when they are available.',
  },
  training: {
    title: 'Staff Online Training',
    description: 'Training information for staff.',
    emptyTitle: 'No training records yet',
    emptyDescription: 'Assigned courses and training progress will appear here.',
  },
  'time-management': {
    title: 'Time Management',
    description: 'Your time and attendance workspace.',
    emptyTitle: 'No time records yet',
    emptyDescription: 'Time-management records will appear here when they are available.',
  },
  'other-requests': {
    title: 'Other Requests',
    description: 'Additional staff requests.',
    emptyTitle: 'No other requests yet',
    emptyDescription: 'Requests you submit will appear here.',
  },
  'finance-management': {
    title: 'Finance Management',
    description: 'Your finance-related staff information.',
    emptyTitle: 'No finance records yet',
    emptyDescription: 'Finance information will appear here when it is available.',
  },
  'my-requests': {
    title: 'My Requests',
    description: 'Track requests associated with your account.',
    emptyTitle: 'No requests yet',
    emptyDescription: 'Requests associated with your account will appear here.',
  },
}

const currentSection = computed(() => sectionDetails[activeSection.value])

const selectSection = (section) => {
  activeSection.value = section
  if (profileLinks.some((item) => item.id === section)) {
    personnelOpen.value = true
  }
}

const joinedDate = computed(() => {
  if (!auth.user?.created_at) return '—'

  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(auth.user.created_at))
})
</script>

<template>
  <AdminLayout>
    <section class="personnel-portal-page">
      <header class="personnel-portal-heading">
        <div>
          <p class="personnel-portal-kicker">STAFF WORKSPACE</p>
          <h1>{{ currentSection.title }}</h1>
          <p>{{ currentSection.description }}</p>
        </div>
        <span class="personnel-portal-user"><span>{{ (auth.user?.name || 'S').trim().charAt(0).toUpperCase() }}</span>{{ auth.user?.name || 'Staff member' }}</span>
      </header>

      <div class="personnel-portal-layout">
        <nav class="personnel-portal-nav" aria-label="Personnel workspace">
          <p class="personnel-portal-nav-label">WORKSPACE</p>
          <button class="personnel-portal-nav-parent" type="button" :aria-expanded="personnelOpen" @click="personnelOpen = !personnelOpen">
            <span class="personnel-portal-nav-icon" aria-hidden="true"><AppIcon name="user" :size="17" /></span>
            <span>Personnel Profile</span>
            <svg class="personnel-portal-chevron" :class="{ 'is-open': personnelOpen }" viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5" /></svg>
          </button>
          <div v-if="personnelOpen" class="personnel-portal-subnav">
            <button
              v-for="item in profileLinks"
              :key="item.id"
              type="button"
              :class="{ 'is-active': activeSection === item.id }"
              :aria-current="activeSection === item.id ? 'page' : undefined"
              @click="selectSection(item.id)"
            >
              <AppIcon :name="item.icon" :size="16" />
              <span>{{ item.label }}</span>
            </button>
          </div>

          <button
            v-for="item in workspaceLinks"
            :key="item.id"
            class="personnel-portal-nav-link"
            :class="{ 'is-active': activeSection === item.id }"
            type="button"
            :aria-current="activeSection === item.id ? 'page' : undefined"
            @click="selectSection(item.id)"
          >
            <span class="personnel-portal-nav-icon" aria-hidden="true"><AppIcon :name="item.icon" :size="17" /></span>
            <span>{{ item.label }}</span>
          </button>
        </nav>

        <article class="personnel-portal-content">
          <template v-if="activeSection === 'my-profile'">
            <div class="personnel-portal-card-heading">
              <div class="personnel-portal-avatar">{{ (auth.user?.name || 'U').trim().charAt(0).toUpperCase() }}</div>
              <div>
                <span class="personnel-portal-step-label">PERSONNEL PROFILE</span>
                <h2>{{ auth.user?.name || 'Your profile' }}</h2>
                <p>{{ auth.roleLabel || 'Staff member' }}<span class="personnel-profile-divider">·</span>Joined {{ joinedDate }}</p>
              </div>
              <span class="personnel-profile-static-pill">Overview</span>
            </div>

            <section class="personnel-profile-steps-panel" aria-labelledby="profile-progress-title">
              <div class="personnel-profile-steps-heading">
                <div><h3 id="profile-progress-title">Profile completion</h3><p>Five steps to complete your personnel profile.</p></div>
                <span>Step 1 of 5</span>
              </div>
              <ol class="personnel-portal-progress" aria-label="Profile steps">
                <li v-for="(step, index) in profileSteps" :key="step" :class="{ 'is-current': index === 0 }">
                  <span>{{ String(index + 1).padStart(2, '0') }}</span>
                  <small>{{ step }}</small>
                </li>
              </ol>
            </section>

            <dl class="personnel-portal-details">
              <div><dt>Full name</dt><dd><AppIcon name="user" :size="16" />{{ auth.user?.name || 'Not provided' }}</dd></div>
              <div><dt>Email address</dt><dd><AppIcon name="mail" :size="16" />{{ auth.user?.email || 'Not provided' }}</dd></div>
              <div><dt>Phone number</dt><dd><AppIcon name="users" :size="16" />{{ auth.user?.phone || 'Not provided' }}</dd></div>
              <div><dt>Username</dt><dd><AppIcon name="user" :size="16" />{{ auth.user?.username || 'Not provided' }}</dd></div>
              <div><dt>Role</dt><dd><AppIcon name="lock" :size="16" />{{ auth.roleLabel || 'Staff member' }}</dd></div>
              <div><dt>Joined</dt><dd><AppIcon name="calendar" :size="16" />{{ joinedDate }}</dd></div>
            </dl>
            <p class="personnel-portal-static-note"><AppIcon name="info" :size="17" />This is a static profile preview. Editing and workflow actions are not enabled.</p>
          </template>
          <div v-else class="personnel-portal-empty">
            <span class="personnel-portal-empty-icon" aria-hidden="true">▤</span>
            <h2>{{ currentSection.emptyTitle }}</h2>
            <p>{{ currentSection.emptyDescription }}</p>
            <span class="personnel-portal-static-badge">STATIC PREVIEW</span>
          </div>
        </article>
      </div>
    </section>
  </AdminLayout>
</template>

<style scoped>
.personnel-profile-page {
  width: min(100%, 1120px);
  margin: 0 auto;
  padding: 28px 0 56px;
  color: #172b3b;
}

.personnel-profile-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  margin-bottom: 24px;
}

.personnel-profile-heading h1 {
  margin: 6px 0;
  color: #142b3c;
  font-size: clamp(1.8rem, 3vw, 2.35rem);
  font-weight: 750;
  letter-spacing: -.045em;
}

.personnel-profile-heading > div > p:last-child,
.personnel-card-heading > div > p:last-child {
  margin: 0;
  color: #73869a;
  font-size: .9rem;
}

.personnel-profile-status {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 9px 13px;
  border: 1px solid #cce9df;
  border-radius: 999px;
  background: #effaf5;
  color: #167653;
  font-size: .78rem;
  font-weight: 650;
  white-space: nowrap;
}

.personnel-profile-status span {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #22a879;
}

.personnel-profile-layout {
  display: grid;
  grid-template-columns: minmax(250px, .72fr) minmax(0, 1.6fr);
  align-items: start;
  gap: 20px;
}

.personnel-profile-steps,
.personnel-profile-card {
  overflow: hidden;
  border: 1px solid #e1e9ee;
  border-radius: 16px;
  background: #fff;
  box-shadow: 0 8px 28px rgba(20, 43, 60, .045);
}

.personnel-profile-steps {
  padding: 20px 14px 16px;
}

.personnel-profile-section-label {
  margin: 0;
  color: #8293a1;
  font-size: .68rem;
  font-weight: 750;
  letter-spacing: .12em;
}

.personnel-profile-steps > .personnel-profile-section-label {
  padding: 0 10px 12px;
}

.personnel-profile-steps ol {
  display: grid;
  gap: 5px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.personnel-profile-steps li {
  display: grid;
  grid-template-columns: 32px minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
  min-height: 62px;
  padding: 9px 10px;
  border: 1px solid transparent;
  border-radius: 11px;
  color: #52697a;
}

.personnel-profile-steps li.is-current {
  border-color: #d4e8f4;
  background: #f0f7fb;
  color: #183d58;
}

.personnel-step-number {
  display: grid;
  width: 30px;
  height: 30px;
  place-items: center;
  border-radius: 9px;
  background: #f1f5f7;
  color: #617889;
  font-size: .7rem;
  font-weight: 750;
}

.is-current .personnel-step-number {
  background: #0e5963;
  color: white;
}

.personnel-step-copy {
  display: grid;
  gap: 3px;
  min-width: 0;
}

.personnel-step-copy strong {
  font-size: .81rem;
  font-weight: 700;
}

.personnel-step-copy small {
  color: #8999a6;
  font-size: .69rem;
}

.personnel-step-current {
  color: #16836d;
  font-size: .65rem;
  font-weight: 700;
}

.personnel-profile-progress {
  display: grid;
  gap: 10px;
  margin: 18px 5px 0;
  padding: 16px 5px 0;
  border-top: 1px solid #edf1f3;
}

.personnel-profile-progress > div {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  color: #73869a;
  font-size: .72rem;
}

.personnel-profile-progress strong {
  color: #344e60;
}

.personnel-progress-track {
  height: 6px;
  overflow: hidden;
  border-radius: 99px;
  background: #eaf0f2;
}

.personnel-progress-track > span {
  display: block;
  width: 20%;
  height: 100%;
  border-radius: inherit;
  background: #16836d;
}

.personnel-profile-card {
  padding: clamp(20px, 3vw, 32px);
}

.personnel-card-heading {
  display: flex;
  align-items: center;
  gap: 15px;
  padding-bottom: 23px;
  border-bottom: 1px solid #edf1f3;
}

.personnel-card-heading h2 {
  margin: 5px 0 6px;
  color: #172f42;
  font-size: 1.25rem;
  font-weight: 750;
  letter-spacing: -.025em;
}

.personnel-avatar {
  display: grid;
  flex: 0 0 54px;
  width: 54px;
  height: 54px;
  place-items: center;
  border-radius: 16px;
  background: #e8f3f2;
  color: #12695e;
  font-size: 1.25rem;
  font-weight: 750;
}

.personnel-profile-details {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0 18px;
  margin: 0;
}

.personnel-profile-details > div {
  display: grid;
  gap: 7px;
  min-width: 0;
  padding: 17px 0;
  border-bottom: 1px solid #edf1f3;
}

.personnel-profile-details dt {
  color: #81919e;
  font-size: .72rem;
  font-weight: 650;
}

.personnel-profile-details dd {
  overflow-wrap: anywhere;
  margin: 0;
  color: #263f51;
  font-size: .88rem;
  font-weight: 650;
}

.personnel-profile-note {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin-top: 20px;
  padding: 13px 14px;
  border: 1px solid #e2edf0;
  border-radius: 11px;
  background: #f7fafb;
}

.personnel-profile-note > span {
  display: grid;
  flex: 0 0 19px;
  width: 19px;
  height: 19px;
  place-items: center;
  border-radius: 50%;
  background: #e2f1ef;
  color: #176f68;
  font-size: .72rem;
  font-weight: 750;
}

.personnel-profile-note p {
  margin: 1px 0 0;
  color: #687f8d;
  font-size: .76rem;
  line-height: 1.5;
}

@media (max-width: 760px) {
  .personnel-profile-layout {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 520px) {
  .personnel-profile-heading {
    align-items: flex-start;
    flex-direction: column;
  }

  .personnel-profile-details {
    grid-template-columns: 1fr;
  }

  .personnel-step-copy small {
    display: none;
  }
}

.personnel-portal-page {
  width: min(100%, 1120px);
  margin: 0 auto;
  padding: 28px 0 56px;
  color: #152f40;
}

.personnel-portal-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  margin-bottom: 22px;
}

.personnel-portal-kicker {
  margin: 0;
  color: #118b7e;
  font-size: .67rem;
  font-weight: 800;
  letter-spacing: .13em;
}

.personnel-portal-heading h1 {
  margin: 7px 0 5px;
  color: #142d3e;
  font-size: clamp(1.8rem, 3vw, 2.35rem);
  font-weight: 750;
  letter-spacing: -.045em;
}

.personnel-portal-heading > div > p:last-child,
.personnel-portal-card-heading > div > p {
  margin: 0;
  color: #788b99;
  font-size: .9rem;
}

.personnel-portal-user {
  padding: 9px 14px;
  border: 1px solid #e2e9eb;
  border-radius: 999px;
  background: #fff;
  color: #445e6f;
  font-size: .78rem;
  font-weight: 650;
}

.personnel-portal-layout {
  display: grid;
  grid-template-columns: minmax(235px, 280px) minmax(0, 1fr);
  gap: 18px;
}

.personnel-portal-nav,
.personnel-portal-content {
  overflow: hidden;
  border: 1px solid #e0e8eb;
  border-radius: 15px;
  background: #fff;
  box-shadow: 0 8px 28px rgba(20, 43, 60, .045);
}

.personnel-portal-nav {
  display: grid;
  align-content: start;
  gap: 5px;
  padding: 12px;
}

.personnel-portal-nav button {
  display: flex;
  align-items: center;
  width: 100%;
  min-height: 46px;
  gap: 11px;
  margin: 0;
  padding: 10px 12px;
  border: 0;
  border-radius: 10px;
  background: transparent;
  color: #667c8a;
  cursor: pointer;
  font-family: inherit;
  font-size: .86rem;
  font-weight: 600;
  line-height: 1.3;
  text-align: left;
  transition: color .15s ease, background-color .15s ease;
}

.personnel-portal-nav button:hover {
  background: #f2f7f7;
  color: #173d4a;
}

.personnel-portal-nav .personnel-portal-nav-parent {
  color: #174b4e;
  font-weight: 750;
}

.personnel-portal-nav-icon {
  display: grid;
  flex: 0 0 28px;
  width: 28px;
  height: 28px;
  place-items: center;
  border-radius: 8px;
  background: #edf5f4;
  color: #397d78;
  font-size: 1rem;
}

.personnel-portal-chevron {
  margin-left: auto;
  color: #82959e;
  font-size: 1rem;
  transform: rotate(-90deg);
  transition: transform .16s ease;
}

.personnel-portal-chevron.is-open {
  transform: rotate(0);
}

.personnel-portal-subnav {
  display: grid;
  gap: 4px;
  margin: -1px 0 6px 39px;
}

.personnel-portal-nav .personnel-portal-subnav button {
  min-height: 39px;
  padding: 8px 11px;
  border-radius: 9px;
  font-size: .76rem;
  font-weight: 600;
}

.personnel-portal-nav .personnel-portal-subnav button.is-active,
.personnel-portal-nav .personnel-portal-nav-link.is-active {
  background: #e8f3f2;
  color: #125e59;
  font-weight: 750;
}

.personnel-portal-content {
  min-height: 410px;
  padding: clamp(20px, 3.2vw, 34px);
}

.personnel-portal-card-heading {
  display: flex;
  align-items: center;
  gap: 15px;
  padding-bottom: 20px;
  border-bottom: 1px solid #edf1f3;
}

.personnel-portal-avatar {
  display: grid;
  flex: 0 0 52px;
  width: 52px;
  height: 52px;
  place-items: center;
  border-radius: 15px;
  background: #e8f3f2;
  color: #12695e;
  font-size: 1.25rem;
  font-weight: 750;
}

.personnel-portal-step-label {
  color: #118b7e;
  font-size: .65rem;
  font-weight: 800;
  letter-spacing: .11em;
}

.personnel-portal-card-heading h2 {
  margin: 5px 0 6px;
  color: #172f42;
  font-size: 1.2rem;
  font-weight: 750;
  letter-spacing: -.025em;
}

.personnel-portal-progress {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  margin: 20px 0 4px;
  padding: 0 0 14px;
  border-bottom: 1px solid #edf1f3;
  list-style: none;
}

.personnel-portal-progress li {
  display: flex;
  flex: 1 0 auto;
  align-items: center;
  gap: 7px;
  color: #8796a1;
  font-size: .69rem;
  font-weight: 650;
}

.personnel-portal-progress li span {
  display: grid;
  flex: 0 0 22px;
  width: 22px;
  height: 22px;
  place-items: center;
  border-radius: 50%;
  background: #f0f3f4;
  color: #70818b;
  font-size: .67rem;
}

.personnel-portal-progress li.is-current {
  color: #176b62;
}

.personnel-portal-progress li.is-current span {
  background: #138777;
  color: #fff;
}

.personnel-portal-details {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0 18px;
  margin: 0;
}

.personnel-portal-details > div {
  display: grid;
  gap: 7px;
  min-width: 0;
  padding: 17px 0;
  border-bottom: 1px solid #edf1f3;
}

.personnel-portal-details dt {
  color: #81919e;
  font-size: .72rem;
  font-weight: 650;
}

.personnel-portal-details dd {
  overflow-wrap: anywhere;
  margin: 0;
  color: #263f51;
  font-size: .88rem;
  font-weight: 650;
}

.personnel-portal-static-note {
  margin: 18px 0 0;
  color: #85949d;
  font-size: .74rem;
}

.personnel-portal-empty {
  display: grid;
  justify-items: center;
  align-content: center;
  min-height: 340px;
  padding: 24px;
  text-align: center;
}

.personnel-portal-empty-icon {
  display: grid;
  width: 54px;
  height: 54px;
  place-items: center;
  border-radius: 16px;
  background: #edf5f4;
  color: #27867c;
  font-size: 1.35rem;
}

.personnel-portal-empty h2 {
  margin: 17px 0 7px;
  color: #203b4d;
  font-size: 1.15rem;
}

.personnel-portal-empty p {
  max-width: 390px;
  margin: 0;
  color: #788b99;
  font-size: .86rem;
  line-height: 1.55;
}

.personnel-portal-static-badge {
  margin-top: 18px;
  padding: 6px 9px;
  border-radius: 999px;
  background: #f1f5f5;
  color: #758992;
  font-size: .62rem;
  font-weight: 750;
  letter-spacing: .08em;
}

@media (max-width: 760px) {
  .personnel-portal-layout {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 520px) {
  .personnel-portal-heading {
    align-items: flex-start;
    flex-direction: column;
  }

  .personnel-portal-details {
    grid-template-columns: 1fr;
  }

  .personnel-portal-progress li {
    max-width: 120px;
  }
}

.personnel-portal-page {
  width: min(100%, 1240px);
  padding: 32px 0 60px;
}

.personnel-portal-heading {
  margin-bottom: 28px;
}

.personnel-portal-heading h1 {
  font-size: clamp(2rem, 3.2vw, 2.65rem);
  letter-spacing: -.055em;
}

.personnel-portal-heading > div > p:last-child {
  font-size: .94rem;
}

.personnel-portal-user {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  max-width: 240px;
  padding: 7px 14px 7px 8px;
  box-shadow: 0 2px 8px rgba(17, 45, 57, .04);
}

.personnel-portal-user > span {
  display: grid;
  width: 30px;
  height: 30px;
  flex: 0 0 30px;
  place-items: center;
  border-radius: 50%;
  background: #dff1ed;
  color: #176d61;
  font-size: .8rem;
  font-weight: 750;
}

.personnel-portal-layout {
  grid-template-columns: minmax(248px, 286px) minmax(0, 1fr);
  align-items: stretch;
  gap: 22px;
}

.personnel-portal-nav {
  align-content: start;
  gap: 6px;
  padding: 18px 14px;
  border-color: #0d4643;
  background: linear-gradient(165deg, #104d49 0%, #0a3938 100%);
  box-shadow: 0 16px 36px rgba(8, 57, 54, .13);
}

.personnel-portal-nav-label {
  margin: 0 10px 5px;
  color: #91b7af;
  font-size: .64rem;
  font-weight: 800;
  letter-spacing: .13em;
}

.personnel-portal-nav button,
.personnel-portal-nav button:hover {
  color: #c4d8d5;
}

.personnel-portal-nav button {
  min-height: 48px;
  gap: 12px;
  padding: 9px 11px;
  border-radius: 10px;
  font-size: .84rem;
}

.personnel-portal-nav button:hover {
  background: rgba(255, 255, 255, .07);
}

.personnel-portal-nav .personnel-portal-nav-parent {
  color: #fff;
}

.personnel-portal-nav-icon {
  background: rgba(198, 235, 226, .12);
  color: #a9d8ce;
}

.personnel-portal-chevron {
  width: 16px;
  height: 16px;
  fill: none;
  stroke: #9abbb6;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 2;
}

.personnel-portal-subnav {
  gap: 5px;
  margin: 0 0 8px 23px;
  padding-left: 13px;
  border-left: 1px solid rgba(188, 222, 216, .2);
}

.personnel-portal-nav .personnel-portal-subnav button {
  min-height: 41px;
  gap: 9px;
  padding: 8px 10px;
  color: #bdd1ce;
  font-size: .75rem;
}

.personnel-portal-nav .personnel-portal-subnav button .app-icon {
  flex: 0 0 auto;
}

.personnel-portal-nav .personnel-portal-subnav button.is-active,
.personnel-portal-nav .personnel-portal-nav-link.is-active {
  background: #148f83;
  box-shadow: 0 7px 18px rgba(4, 30, 29, .16);
  color: #fff;
}

.personnel-portal-nav .personnel-portal-subnav button.is-active {
  background: #2470cb;
}

.personnel-portal-nav .personnel-portal-subnav button.is-active:hover,
.personnel-portal-nav .personnel-portal-nav-link.is-active:hover {
  background: #1b9b8d;
  color: #fff;
}

.personnel-portal-content {
  min-height: 560px;
  padding: clamp(24px, 4vw, 40px);
  border-color: #e3eaed;
  box-shadow: 0 12px 38px rgba(24, 53, 68, .055);
}

.personnel-portal-card-heading {
  gap: 17px;
  padding-bottom: 25px;
}

.personnel-portal-avatar {
  width: 62px;
  height: 62px;
  flex-basis: 62px;
  border: 4px solid #f0f7f5;
  border-radius: 19px;
  background: linear-gradient(145deg, #d7f0e9, #eaf4ff);
  color: #14685e;
  font-size: 1.45rem;
}

.personnel-portal-step-label {
  display: inline-block;
  margin-bottom: 2px;
}

.personnel-portal-card-heading h2 {
  font-size: clamp(1.25rem, 2vw, 1.55rem);
}

.personnel-profile-divider {
  margin-inline: 8px;
  color: #b0bec5;
}

.personnel-profile-static-pill {
  margin-left: auto;
  padding: 7px 11px;
  border: 1px solid #d7ece5;
  border-radius: 999px;
  background: #f0faf6;
  color: #237b61;
  font-size: .7rem;
  font-weight: 750;
}

.personnel-profile-steps-panel {
  margin-top: 24px;
  padding: 18px 18px 15px;
  border: 1px solid #e7edef;
  border-radius: 14px;
  background: linear-gradient(145deg, #fbfdfd, #f7fafb);
}

.personnel-profile-steps-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.personnel-profile-steps-heading h3 {
  margin: 0;
  color: #233f50;
  font-size: .9rem;
  font-weight: 750;
}

.personnel-profile-steps-heading p {
  margin: 5px 0 0;
  color: #83939c;
  font-size: .74rem;
}

.personnel-profile-steps-heading > span {
  padding: 6px 9px;
  border-radius: 8px;
  background: #e9f5f1;
  color: #26745f;
  font-size: .68rem;
  font-weight: 750;
  white-space: nowrap;
}

.personnel-portal-progress {
  justify-content: space-between;
  gap: 0;
  overflow: visible;
  margin: 19px 0 0;
  padding: 0;
  border: 0;
}

.personnel-portal-progress li {
  position: relative;
  z-index: 0;
  display: grid;
  justify-items: center;
  flex: 1 1 0;
  gap: 8px;
  color: #8998a0;
  font-size: .67rem;
  text-align: center;
}

.personnel-portal-progress li::before {
  position: absolute;
  z-index: -1;
  top: 15px;
  left: calc(-50% + 15px);
  width: calc(100% - 30px);
  height: 2px;
  background: #e3e9eb;
  content: '';
}

.personnel-portal-progress li:first-child::before {
  display: none;
}

.personnel-portal-progress li > span {
  width: 31px;
  height: 31px;
  flex-basis: 31px;
  border: 3px solid #fff;
  background: #e8edef;
  box-shadow: 0 0 0 1px #e4eaec;
  color: #788990;
  font-size: .65rem;
}

.personnel-portal-progress li.is-current > span {
  background: #148b7d;
  box-shadow: 0 0 0 4px #e2f3ee;
  color: #fff;
}

.personnel-portal-progress li small {
  max-width: 110px;
  color: inherit;
  font-size: .65rem;
  line-height: 1.35;
}

.personnel-portal-details {
  gap: 12px;
  margin-top: 24px;
}

.personnel-portal-details > div {
  gap: 10px;
  min-height: 82px;
  padding: 14px 15px;
  border: 1px solid #e9eef0;
  border-radius: 12px;
  background: #fff;
  transition: border-color .16s ease, background-color .16s ease, transform .16s ease;
}

.personnel-portal-details > div:hover {
  transform: translateY(-1px);
  border-color: #cfe3df;
  background: #fcfefe;
}

.personnel-portal-details dt {
  font-size: .68rem;
  letter-spacing: .025em;
}

.personnel-portal-details dd {
  display: flex;
  align-items: center;
  gap: 9px;
  font-size: .82rem;
}

.personnel-portal-details dd :deep(svg) {
  flex: 0 0 auto;
  color: #6f9a91;
}

.personnel-portal-static-note {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 19px;
  padding: 11px 13px;
  border: 1px solid #e7edef;
  border-radius: 10px;
  background: #f8fafb;
  color: #748791;
  line-height: 1.45;
}

.personnel-portal-static-note :deep(svg) {
  flex: 0 0 auto;
  color: #6c928c;
}

.personnel-portal-empty {
  min-height: 500px;
}

.personnel-portal-empty-icon {
  border: 1px solid #dfece9;
  background: linear-gradient(145deg, #eff8f6, #f2f6fb);
  color: #2d8278;
}

.personnel-portal-static-badge {
  border: 1px solid #e5ecec;
}

.personnel-portal-nav button:focus-visible {
  outline: 3px solid rgba(112, 222, 206, .7);
  outline-offset: 2px;
}

@media (max-width: 760px) {
  .personnel-portal-layout {
    grid-template-columns: minmax(0, 1fr);
  }

  .personnel-portal-nav {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .personnel-portal-nav-label,
  .personnel-portal-nav-parent,
  .personnel-portal-subnav {
    grid-column: 1 / -1;
  }

  .personnel-portal-nav .personnel-portal-nav-link {
    min-height: 48px;
  }
}

@media (max-width: 520px) {
  .personnel-portal-page {
    padding-top: 20px;
  }

  .personnel-portal-content {
    min-height: 0;
    padding: 19px 16px;
  }

  .personnel-portal-nav {
    grid-template-columns: 1fr;
  }

  .personnel-portal-nav-label,
  .personnel-portal-nav-parent,
  .personnel-portal-subnav {
    grid-column: auto;
  }

  .personnel-portal-card-heading {
    align-items: flex-start;
    flex-wrap: wrap;
  }

  .personnel-profile-static-pill {
    margin-left: 0;
  }

  .personnel-profile-steps-panel {
    padding: 15px 12px;
  }

  .personnel-profile-steps-heading {
    flex-direction: column;
  }

  .personnel-portal-progress li {
    min-width: 62px;
    max-width: none;
    font-size: .6rem;
  }

  .personnel-portal-progress li small {
    max-width: 66px;
    font-size: .58rem;
  }
}

/* Keep the personnel workspace calm, readable, and consistent with SaaS tools. */
.personnel-portal-page {
  color: #1e293b;
}

.personnel-portal-heading {
  margin-bottom: 24px;
}

.personnel-portal-kicker,
.personnel-portal-step-label {
  color: #64748b;
  font-size: .68rem;
  letter-spacing: .09em;
}

.personnel-portal-heading h1 {
  color: #172b4d;
  font-size: clamp(1.8rem, 2.7vw, 2.2rem);
  font-weight: 700;
  letter-spacing: -.035em;
}

.personnel-portal-heading > div > p:last-child {
  color: #64748b;
}

.personnel-portal-user {
  border-color: #e2e8f0;
  border-radius: 10px;
  color: #334155;
  box-shadow: none;
}

.personnel-portal-user > span {
  background: #eff6ff;
  color: #2563eb;
}

.personnel-portal-layout {
  grid-template-columns: minmax(245px, 275px) minmax(0, 1fr);
  gap: 20px;
}

.personnel-portal-nav,
.personnel-portal-content {
  border-color: #e2e8f0;
  border-radius: 12px;
  box-shadow: none;
}

.personnel-portal-nav {
  gap: 4px;
  padding: 16px 12px;
  background: #fff;
}

.personnel-portal-nav-label {
  margin: 2px 10px 8px;
  color: #94a3b8;
  font-size: .65rem;
  letter-spacing: .1em;
}

.personnel-portal-nav button,
.personnel-portal-nav button:hover {
  color: #475569;
}

.personnel-portal-nav button {
  min-height: 43px;
  border-radius: 8px;
  font-size: .82rem;
  transition: background-color .14s ease, color .14s ease;
}

.personnel-portal-nav button:hover {
  background: #f8fafc;
  color: #1e293b;
}

.personnel-portal-nav .personnel-portal-nav-parent {
  color: #334155;
}

.personnel-portal-nav-icon {
  width: 27px;
  height: 27px;
  flex-basis: 27px;
  border-radius: 7px;
  background: transparent;
  color: #64748b;
}

.personnel-portal-chevron {
  stroke: #94a3b8;
}

.personnel-portal-subnav {
  gap: 3px;
  margin: 0 0 7px 18px;
  padding-left: 10px;
  border-color: #e2e8f0;
}

.personnel-portal-nav .personnel-portal-subnav button {
  min-height: 37px;
  color: #64748b;
  font-size: .75rem;
}

.personnel-portal-nav .personnel-portal-subnav button .app-icon {
  color: #7c8da3;
}

.personnel-portal-nav .personnel-portal-subnav button.is-active,
.personnel-portal-nav .personnel-portal-nav-link.is-active {
  background: #eff6ff;
  box-shadow: inset 2px 0 #3b82f6;
  color: #1d4ed8;
}

.personnel-portal-nav .personnel-portal-subnav button.is-active:hover,
.personnel-portal-nav .personnel-portal-nav-link.is-active:hover {
  background: #eaf2ff;
  color: #1d4ed8;
}

.personnel-portal-nav .personnel-portal-nav-link.is-active .personnel-portal-nav-icon {
  color: #2563eb;
}

.personnel-portal-content {
  min-height: 0;
  padding: clamp(22px, 3vw, 32px);
}

.personnel-portal-card-heading {
  padding-bottom: 22px;
}

.personnel-portal-avatar {
  width: 56px;
  height: 56px;
  flex-basis: 56px;
  border: 0;
  border-radius: 14px;
  background: #eff6ff;
  color: #2563eb;
  font-size: 1.3rem;
}

.personnel-portal-step-label {
  color: #64748b;
}

.personnel-portal-card-heading h2 {
  margin-top: 6px;
  color: #172b4d;
  font-size: 1.25rem;
  font-weight: 700;
}

.personnel-portal-card-heading > div > p {
  color: #64748b;
  font-size: .84rem;
}

.personnel-profile-static-pill {
  border-color: #dbeafe;
  border-radius: 8px;
  background: #eff6ff;
  color: #1d4ed8;
  font-weight: 650;
}

.personnel-profile-steps-panel {
  margin-top: 20px;
  padding: 17px;
  border-color: #e2e8f0;
  border-radius: 10px;
  background: #fff;
}

.personnel-profile-steps-heading h3 {
  color: #334155;
  font-size: .86rem;
}

.personnel-profile-steps-heading p {
  color: #64748b;
  font-size: .73rem;
}

.personnel-profile-steps-heading > span {
  border: 1px solid #e2e8f0;
  border-radius: 7px;
  background: #f8fafc;
  color: #475569;
  font-weight: 650;
}

.personnel-portal-progress li {
  color: #94a3b8;
  font-weight: 550;
}

.personnel-portal-progress li::before {
  height: 1px;
  background: #e2e8f0;
}

.personnel-portal-progress li > span {
  border-width: 2px;
  background: #f1f5f9;
  box-shadow: 0 0 0 1px #e2e8f0;
  color: #64748b;
}

.personnel-portal-progress li.is-current {
  color: #1d4ed8;
}

.personnel-portal-progress li.is-current > span {
  background: #2563eb;
  box-shadow: 0 0 0 3px #dbeafe;
}

.personnel-portal-progress li small {
  font-weight: 550;
}

.personnel-portal-details {
  gap: 10px;
  margin-top: 20px;
}

.personnel-portal-details > div {
  min-height: 76px;
  gap: 8px;
  padding: 13px 14px;
  border-color: #e8edf2;
  border-radius: 9px;
  background: #fff;
  transition: border-color .14s ease, background-color .14s ease;
}

.personnel-portal-details > div:hover {
  transform: none;
  border-color: #cbd5e1;
  background: #fbfdff;
}

.personnel-portal-details dt {
  color: #64748b;
  font-size: .68rem;
  font-weight: 600;
}

.personnel-portal-details dd {
  color: #334155;
  font-size: .82rem;
  font-weight: 600;
}

.personnel-portal-details dd :deep(svg) {
  color: #7c8da3;
}

.personnel-portal-static-note {
  margin-top: 18px;
  padding: 0;
  border: 0;
  background: transparent;
  color: #64748b;
  font-size: .74rem;
}

.personnel-portal-static-note :deep(svg) {
  color: #64748b;
}

.personnel-portal-empty-icon {
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  background: #f8fafc;
  color: #64748b;
}

.personnel-portal-empty h2 {
  color: #1e293b;
  font-weight: 700;
}

.personnel-portal-empty p {
  color: #64748b;
}

.personnel-portal-static-badge {
  border: 1px solid #e2e8f0;
  border-radius: 7px;
  background: #f8fafc;
  color: #64748b;
  letter-spacing: .06em;
}

.personnel-portal-nav button:focus-visible {
  outline: 2px solid #93c5fd;
  outline-offset: 2px;
}

@media (max-width: 760px) {
  .personnel-portal-nav {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 520px) {
  .personnel-portal-nav {
    grid-template-columns: 1fr;
  }

  .personnel-portal-progress li small {
    max-width: 66px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .personnel-portal-nav button,
  .personnel-portal-details > div,
  .personnel-portal-chevron {
    transition: none;
  }
}
</style>
