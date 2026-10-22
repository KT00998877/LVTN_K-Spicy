import './assets/main.css'
import './assets/foundation.css'
import './assets/auth.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { useAuthStore } from './stores/auth'

const app = createApp(App)

app.use(createPinia())
window.addEventListener('customer-session-expired', () => useAuthStore().clearSession())
window.addEventListener('storage', (event) => {
    if (event.key === null || ['auth_token', 'auth_user', 'staff_token', 'staff_session'].includes(event.key)) {
        // Local component forms also belong to the previous account.
        useAuthStore().resetCustomerData()
        window.location.reload()
    }
})
app.use(router)

app.mount('#app')
