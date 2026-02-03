import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  {
    path: '/',
    name: 'Home',
    component: () => import('../views/HomeView.vue'),
    meta: { title: 'Bitrix25 Planner' },
  },
  {
    path: '/departments',
    name: 'Departments',
    component: () => import('../views/DepartmentsView.vue'),
    meta: { title: 'Отделы' },
  },
  {
    path: '/specialists',
    name: 'Specialists',
    component: () => import('../views/SpecialistsView.vue'),
    meta: { title: 'Специалисты' },
  },
  {
    path: '/integration-settings',
    name: 'IntegrationSettings',
    component: () => import('../views/IntegrationSettingsView.vue'),
    meta: { title: 'Настройки интеграции' },
  },
  {
    path: '/project-work-types',
    name: 'ProjectWorkTypes',
    component: () => import('../views/ProjectWorkTypesView.vue'),
    meta: { title: 'Типы проектов B24' },
  },
  {
    path: '/plan-entries',
    name: 'PlanEntries',
    component: () => import('../views/PlanEntriesView.vue'),
    meta: { title: 'Плановые записи' },
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
