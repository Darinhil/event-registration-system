<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AdminLayout from '../../layouts/AdminLayout.vue'
import PersonnelProfileForm from '../../components/personnel/PersonnelProfileForm.vue'
import ComplianceCenter from '../../components/personnel/ComplianceCenter.vue'
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

const route = useRoute()
// Group headings in the sidebar open their first page.
const sections = {
  'my-profile': PersonnelProfileForm,
  compliances: ComplianceCenter,
  'contract-history': ContractHistory,
  training: TrainingCenter,
  'time-management': LeaveApplication,
  'leave-application': LeaveApplication,
  'overtime-request': OvertimeRequest,
  'monthly-timesheet': MonthlyTimesheet,
  'other-requests': TravelApplication,
  'travel-application': TravelApplication,
  'fuel-log-sheet': FuelLogSheet,
  'purchase-request': PurchaseRequest,
  'finance-management': FinanceManagement,
  'my-requests': MyRequests,
}
const page = computed(() => sections[route.query.section] || PersonnelProfileForm)
</script>

<template>
  <AdminLayout>
    <div class="profile-workspace" :class="{ 'is-profile-form': page === PersonnelProfileForm }">
      <component :is="page" />
    </div>
  </AdminLayout>
</template>

<style scoped>
.profile-workspace { width: min(100%, 1180px); margin: 0 auto; padding: 12px 0 42px; color: #172b4d; }
.profile-workspace.is-profile-form { padding: 0; }
@media (max-width: 700px) { .profile-workspace:not(.is-profile-form) { width: 100%; padding: 14px 16px 16px; } }
</style>
