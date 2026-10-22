<script setup>
import UserLayout from '../../layouts/UserLayout.vue'
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

      <div class="supporting-documents-shell">
        <nav class="onboarding-steps" aria-label="Onboarding steps">
          <RouterLink class="onboarding-step is-complete" to="/me/personal-information"><span>✓</span> Personal Information</RouterLink>
          <RouterLink class="onboarding-step is-complete" to="/me/professional-qualifications"><span>✓</span> Professional Qualifications</RouterLink>
          <RouterLink class="onboarding-step is-complete" to="/me/family-information"><span>✓</span> Family Information</RouterLink>
          <span class="onboarding-step is-active" aria-current="step"><span>4</span> Supporting Documents</span>
          <span class="onboarding-step is-upcoming"><span>5</span> Declaration</span>
        </nav>

        <header class="supporting-documents-heading">
          <div>
            <p class="supporting-documents-kicker">STEP 4 OF 5 <span>NEW HIRE PROFILE</span></p>
            <h1>Supporting Documents</h1>
            <p>Your Family Information has been saved successfully.</p>
          </div>
          <div class="supporting-documents-progress">
            <div><span>Overall Completion</span><strong>80%</strong></div>
            <span><i></i></span>
            <small>Next: Declaration</small>
          </div>
        </header>

        <section class="supporting-documents-card">
          <span class="supporting-documents-icon" aria-hidden="true">✓</span>
          <div>
            <h2>Family Information saved</h2>
            <p>You have completed this section. Supporting document uploads are not available yet. You can return to your profile at any time.</p>
          </div>
        </section>

        <footer class="supporting-documents-actions">
          <RouterLink class="supporting-documents-button is-quiet" to="/">⌂ Home</RouterLink>
          <div>
            <RouterLink class="supporting-documents-button is-quiet" to="/me/family-information">← Previous</RouterLink>
            <RouterLink class="supporting-documents-button is-primary" to="/me">Save &amp; Exit</RouterLink>
          </div>
        </footer>
      </div>
    </div>
  </UserLayout>
</template>

<style scoped>
:global(.shell.onboarding-shell > main){padding:0}
.supporting-documents-shell{width:min(100% - 36px,1200px);margin:0 auto}
.onboarding-steps{display:flex;flex-wrap:wrap;align-items:stretch;margin:8px 0 16px;border-bottom:1px solid #dfe6f2;background:#fff}
.onboarding-step{display:flex;flex:1 1 145px;align-items:center;gap:6px;min-height:34px;padding:9px 12px;border-bottom:2px solid transparent;color:#6e7d97;font-size:9px;text-decoration:none}
.onboarding-step>span{display:grid;width:14px;height:14px;flex:none;place-items:center;border-radius:50%;background:#e7edf7;color:#687994;font-size:8px;font-weight:700}
.onboarding-step.is-active{border-color:#2949d0;color:#243fb1}
.onboarding-step.is-active>span{background:#dfe6ff;color:#304bc4}
.onboarding-step.is-complete{color:#2949c7;text-decoration:none}
.onboarding-step.is-complete>span{background:#2949c7;color:#fff}
.supporting-documents-kicker{display:flex;width:fit-content;align-items:center;gap:7px;margin:0 0 5px;padding:3px 6px;border-radius:4px;background:#304bc9;color:#fff;font-size:8px;font-weight:800}
.supporting-documents-kicker span{color:#56698b;background:#fff}
.supporting-documents-heading{display:flex;align-items:center;justify-content:space-between;gap:20px;min-height:80px;margin:0 0 12px;padding:8px 12px;border-radius:8px;background:#fff}
.supporting-documents-heading h1{margin:0;color:#152945;font-size:21px;letter-spacing:-.03em}
.supporting-documents-heading>div:first-child>p:last-child{margin:5px 0 0;color:#6e7c95;font-size:10px}
.supporting-documents-progress{width:200px;flex:none}
.supporting-documents-progress>div{display:flex;justify-content:space-between;color:#687993;font-size:9px}
.supporting-documents-progress strong{color:#304bc9;font-size:12px}
.supporting-documents-progress>span{display:block;height:5px;margin-top:6px;overflow:hidden;border-radius:99px;background:#e8edf7}
.supporting-documents-progress i{display:block;width:80%;height:100%;border-radius:inherit;background:#304bc9}
.supporting-documents-progress small{display:block;margin-top:4px;color:#71809b;font-size:8px}
.supporting-documents-card{display:flex;align-items:flex-start;gap:12px;padding:18px;border:1px solid #e7ecf5;border-radius:8px;background:#fff}
.supporting-documents-icon{display:grid;width:30px;height:30px;flex:none;place-items:center;border-radius:8px;background:#e8f6ee;color:#21834b;font-weight:800}
.supporting-documents-card h2{margin:1px 0 5px;color:#1c3152;font-size:13px}
.supporting-documents-card p{margin:0;color:#71809b;font-size:11px;line-height:1.6}
.supporting-documents-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:14px}
.supporting-documents-actions>div{display:flex;gap:8px}
.supporting-documents-button{display:inline-flex;min-height:34px;align-items:center;justify-content:center;padding:0 11px;border:1px solid #dfe5ef;border-radius:5px;background:#fff;color:#455671;font:inherit;font-size:10px;font-weight:700;text-decoration:none;white-space:nowrap}
.supporting-documents-button.is-primary{border-color:#2f49c4;background:#2f49c4;color:#fff}
@media(max-width:700px){.supporting-documents-shell{width:calc(100% - 20px)}.supporting-documents-heading{align-items:flex-start;flex-direction:column}.supporting-documents-progress{width:100%}.supporting-documents-actions{align-items:stretch;flex-direction:column}.supporting-documents-actions>div{justify-content:flex-start}}
</style>
