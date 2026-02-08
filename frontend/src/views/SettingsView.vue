<script setup>
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { UiTabs, UiPageHeader } from '../components/ui'
import DepartmentsView from './DepartmentsView.vue'
import SpecialistsView from './SpecialistsView.vue'
import IntegrationSettingsView from './IntegrationSettingsView.vue'
import FiltersView from './FiltersView.vue'

const route = useRoute()
const router = useRouter()

const TABS = [
  { id: 'departments', label: 'Отделы' },
  { id: 'specialists', label: 'Специалисты' },
  { id: 'sync', label: 'Синхронизация' },
  { id: 'filters', label: 'Фильтры' },
]

const activeTab = ref(route.query.tab || 'departments')

watch(
  () => route.query.tab,
  (tab) => {
    if (tab && TABS.some((t) => t.id === tab)) activeTab.value = tab
  },
  { immediate: true }
)

watch(activeTab, (id) => {
  if (route.query.tab !== id) {
    router.replace({ path: '/settings', query: { tab: id } })
  }
})

const currentComponent = computed(() => {
  switch (activeTab.value) {
    case 'departments':
      return DepartmentsView
    case 'specialists':
      return SpecialistsView
    case 'sync':
      return IntegrationSettingsView
    case 'filters':
      return FiltersView
    default:
      return DepartmentsView
  }
})
</script>

<template>
  <div class="settings-page">
    <UiPageHeader title="Настройки" />
    <div class="settings-page__tabs">
      <UiTabs v-model="activeTab" :tabs="TABS" />
    </div>
    <div class="settings-page__content">
      <component :is="currentComponent" embedded />
    </div>
  </div>
</template>

<style lang="scss" scoped>
.settings-page__tabs {
  margin-bottom: 1rem;
}
.settings-page__content {
  padding-top: 0;
}
</style>
