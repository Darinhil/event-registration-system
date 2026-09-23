import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './assets/styles/main.css'
import './assets/styles/ui-overrides.css'
import './assets/styles/admin-topbar.css'
import './assets/styles/admin-dashboard.css'
import './assets/styles/event-workspace.css'
import './assets/styles/form-builder.css'
import './assets/styles/form-renderer.css'
import './assets/styles/registration-edit.css'

createApp(App).use(createPinia()).use(router).mount('#app')
