<script setup>
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
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

function setTab(id) {
  activeTab.value = id
}

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
    <h1 class="settings-page__title">Настройки</h1>
    <div class="tabs">
      <button
        v-for="t in TABS"
        :key="t.id"
        type="button"
        class="tab"
        :class="{ 'tab--active': activeTab === t.id }"
        @click="setTab(t.id)"
      >
        {{ t.label }}
      </button>
    </div>
    <div class="settings-page__content">
      <component :is="currentComponent" embedded />
    </div>
  </div>
</template>

<style lang="scss" scoped>
.settings-page__title {
  margin: 0 0 1rem;
  font-size: 1.5rem;
}
.tabs {
  display: flex;
  gap: 0.25rem;
  margin-bottom: 1.5rem;
  border-bottom: 1px solid #e2e8f0;
  padding-bottom: 0;
}
.tab {
  padding: 0.5rem 1rem;
  border: none;
  background: none;
  font-size: 0.95rem;
  font-weight: 500;
  color: #64748b;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  border-radius: 4px 4px 0 0;
  &:hover {
    color: var(--color-primary);
  }
  &.tab--active {
    color: var(--color-primary);
    border-bottom-color: var(--color-primary);
  }
}
.settings-page__content {
  padding-top: 0;
}
</style>
