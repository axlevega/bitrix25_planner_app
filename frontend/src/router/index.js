import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  {
    path: '/',
    name: 'Home',
    component: () => import('../views/HomeView.vue'),
    meta: { title: 'Bitrix25 Planner' },
  },
  {
    path: '/settings',
    name: 'Settings',
    component: () => import('../views/SettingsView.vue'),
    meta: { title: 'Настройки' },
  },
  {
    path: '/departments',
    redirect: () => ({ path: '/settings', query: { tab: 'departments' } }),
  },
  {
    path: '/specialists',
    redirect: () => ({ path: '/settings', query: { tab: 'specialists' } }),
  },
  {
    path: '/integration-settings',
    redirect: () => ({ path: '/settings', query: { tab: 'sync' } }),
  },
  {
    path: '/dashboard',
    name: 'Dashboard',
    component: () => import('../views/DashboardView.vue'),
    meta: { title: 'Загрузка' },
  },
  {
    path: '/planning-grid',
    name: 'PlanningGrid',
    component: () => import('../views/PlanningGridView.vue'),
    meta: { title: 'Планирование (сетка)' },
  },
  {
    path: '/ui-showcase',
    name: 'UiShowcase',
    component: () => import('../views/UiShowcaseView.vue'),
    meta: { title: 'UI Showcase' },
  },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

router.afterEach((to) => {
  const title = to.meta?.title
  if (title) document.title = `${title} — Bitrix25 Planner`
})

export default router
