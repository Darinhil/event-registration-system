<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AdminLayout from '../../layouts/AdminLayout.vue'
import { useAuthStore } from '../../stores/auth'
import PersonnelDashboard from '../../components/personnel/PersonnelDashboard.vue'
import PersonnelProfileForm from '../../components/personnel/PersonnelProfileForm.vue'
import ComplianceCenter from '../../components/personnel/ComplianceCenter.vue'
import CompliancePolicyManagement from '../../components/personnel/CompliancePolicyManagement.vue'
import TrainingManagement from '../../components/personnel/TrainingManagement.vue'
import FinanceManagement from '../../components/FinanceManagement.vue'
import ContractHistory from '../../components/personnel/ContractHistory.vue'
import TrainingCenter from '../../components/personnel/TrainingCenter.vue'
import LeaveApplication from '../../components/personnel/LeaveApplication.vue'
import OvertimeRequest from '../../components/personnel/OvertimeRequest.vue'
import MonthlyTimesheet from '../../components/personnel/MonthlyTimesheet.vue'
import TravelApplication from '../../components/personnel/TravelApplication.vue'
import FuelLogSheet from '../../components/personnel/FuelLogSheet.vue'
import PurchaseRequest from '../../components/personnel/PurchaseRequest.vue'
import MyRequests from '../../components/personnel/MyRequests.vue'
import TimeManagement from '../../components/personnel/TimeManagement.vue'
import OtherRequests from '../../components/personnel/OtherRequests.vue'

const route = useRoute()
const auth = useAuthStore()
// Group headings in the sidebar open their first page.
const sections = {
  'my-profile': PersonnelProfileForm,
  compliances: ComplianceCenter,
  'contract-history': ContractHistory,
  training: TrainingCenter,
  'time-management': TimeManagement,
  'leave-application': LeaveApplication,
  'overtime-request': OvertimeRequest,
  'monthly-timesheet': MonthlyTimesheet,
  'other-requests': OtherRequests,
  'travel-application': TravelApplication,
  'fuel-log-sheet': FuelLogSheet,
  'purchase-request': PurchaseRequest,
  'finance-management': FinanceManagement,
  'my-requests': MyRequests,
}
// No section is the dashboard; an unknown one still falls back to the profile form.
const page = computed(() => {
  if (!route.query.section) return PersonnelDashboard
  if (route.query.section === 'compliances' && auth.isAdmin) return CompliancePolicyManagement
  if (route.query.section === 'training' && auth.isAdmin && !route.query.course) return TrainingManagement
  return sections[route.query.section] || PersonnelProfileForm
})
</script>

<template>
  <AdminLayout>
    <div class="profile-workspace" :class="{ 'is-profile-form': page === PersonnelProfileForm, 'is-wide': page === TimeManagement || page === OtherRequests }">
      <component :is="page" />
    </div>
  </AdminLayout>
</template>

<style scoped>
.profile-workspace { width: min(100%, 1180px); margin: 0 auto; padding: 12px 0 42px; color: #172b4d; }
.profile-workspace.is-profile-form { padding: 0; }
.profile-workspace.is-wide { width: 100%; max-width: none; padding: 12px 24px 42px; }
@media (max-width: 700px) { .profile-workspace:not(.is-profile-form) { width: 100%; padding: 14px 16px 16px; } }
</style>
