<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '../api/client'
import {
  UiPageHeader,
  UiAlert,
  UiLoading,
  UiCard,
  UiSelect,
  UiMultiSelect,
  UiTable,
  UiInput,
  UiButton,
  UiMuted,
} from '../components/ui'

const { embedded } = defineProps({ embedded: { type: Boolean, default: false } })

const groupOptions = computed(() =>
  (taskGroups.value || []).map((g) => ({
    value: g.bitrix24_group_id,
    label: g.name || String(g.bitrix24_group_id),
  }))
)

const defaultDepartmentId = computed({
  get: () => settings.value.planning_default_department_id ?? '',
  set: (v) => {
    settings.value.planning_default_department_id = v === '' ? null : v
  },
})

const settings = ref({
  planning_default_department_id: null,
  planning_default_group_ids: [],
})
const departments = ref([])
const taskGroups = ref([])
const taskUfCatalog = ref([])
const loading = ref(true)
const error = ref(null)
const saving = ref(false)
const ufLabelSaving = ref(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    const [res, depRes, catalogRes, groupsRes] = await Promise.all([
      api.integrationSettings.get(),
      api.departments.list(),
      api.taskUfCatalog.list().catch(() => ({ items: [] })),
      api.taskGroups.list().catch(() => ({ items: [] })),
    ])
    taskUfCatalog.value = (catalogRes.items || []).map((it) => ({ ...it, label_edit: it.label ?? '' }))
    taskGroups.value = groupsRes.items ?? []
    departments.value = depRes.items ?? []
    settings.value = {
      planning_default_department_id: res.planning_default_department_id ?? null,
      planning_default_group_ids: Array.isArray(res.planning_default_group_ids) ? res.planning_default_group_ids : [],
    }
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function saveUfLabel(item) {
  ufLabelSaving.value = item.field_code
  try {
    await api.taskUfCatalog.updateLabel({ field_code: item.field_code, label: (item.label_edit || '').trim() || null })
    item.label = (item.label_edit || '').trim() || null
  } catch (e) {
    error.value = e.message
  } finally {
    ufLabelSaving.value = null
  }
}

async function saveFilters() {
  saving.value = true
  error.value = null
  try {
    await api.integrationSettings.save({
      planning_default_department_id: settings.value.planning_default_department_id ?? null,
      planning_default_group_ids: settings.value.planning_default_group_ids ?? [],
    })
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <UiPageHeader
      title="Фильтры"
      :description="
        embedded
          ? 'Отдел и группы по умолчанию, подписи полей для фильтров в планировании.'
          : 'Отдел и группы по умолчанию для планирования, подписи пользовательских полей.'
      "
      :size="embedded ? 'md' : 'lg'"
    />

    <UiAlert v-if="error" variant="error">{{ error }}</UiAlert>
    <UiLoading v-if="loading" />
    <template v-else>
      <UiCard tag="section" class="filters-form">
        <form class="filters-form__form" @submit.prevent="saveFilters">
          <div class="filters-form__block">
            <span class="filters-form__block-title">Отдел по умолчанию в планировании</span>
            <UiMuted tag="small">При открытии страницы «Планирование» будет выбран этот отдел и подгружены данные.</UiMuted>
            <label class="filters-form__label">
              <UiSelect v-model="defaultDepartmentId" style="max-width: 320px">
                <option value="">— не задан —</option>
                <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
              </UiSelect>
            </label>
          </div>

          <div v-if="groupOptions.length" class="filters-form__block">
            <span class="filters-form__block-title">Группы по умолчанию для плана</span>
            <UiMuted tag="small">В сетке планирования по умолчанию показываются только задачи из выбранных групп (проектов). Пусто — все группы.</UiMuted>
            <UiMultiSelect
              v-model="settings.planning_default_group_ids"
              :options="groupOptions"
              placeholder="Поиск и выбор групп…"
              style="max-width: 320px"
            />
          </div>

          <div class="filters-form__actions">
            <UiButton type="submit" variant="primary" :disabled="saving">
              {{ saving ? 'Сохранение…' : 'Сохранить' }}
            </UiButton>
          </div>
        </form>
      </UiCard>

      <UiCard v-if="taskUfCatalog.length" tag="section" class="filters-uf">
        <h2 class="filters-uf__title">Подписи пользовательских полей задач</h2>
        <UiMuted tag="p" class="filters-uf__hint">
          Подписи используются в фильтрах (например, в сетке планирования). Заполненные значения не перезаписываются при синхронизации.
        </UiMuted>
        <UiTable class="filters-uf__table">
          <thead>
            <tr>
              <th>Код поля (B24)</th>
              <th>Подпись</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in taskUfCatalog" :key="item.field_code">
              <td><code>{{ item.field_code }}</code></td>
              <td>
                <UiInput v-model="item.label_edit" placeholder="Например: Флайт" class="filters-uf__input" />
              </td>
              <td>
                <UiButton
                  size="sm"
                  variant="outline"
                  :disabled="ufLabelSaving === item.field_code"
                  @click="saveUfLabel(item)"
                >
                  {{ ufLabelSaving === item.field_code ? '…' : 'Сохранить' }}
                </UiButton>
              </td>
            </tr>
          </tbody>
        </UiTable>
      </UiCard>
    </template>
  </div>
</template>

<style lang="scss" scoped>
.filters-form {
  margin-bottom: 1.5rem;
}
.filters-form__form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 560px;
}
.filters-form__block {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.filters-form__block-title {
  font-weight: 600;
  font-size: 0.9rem;
}
.filters-form__actions {
  margin-top: 0.5rem;
}
.filters-uf__title {
  margin: 0 0 0.5rem;
  font-size: 1.1rem;
}
.filters-uf__hint {
  margin-bottom: 0.5rem;
}
.filters-uf__table {
  max-width: 560px;
  margin-top: 0.5rem;
}
.filters-uf__table :deep(th) {
  color: #64748b;
}
.filters-uf__table :deep(code) {
  font-size: 0.85rem;
  background: #f1f5f9;
  padding: 0.2rem 0.4rem;
  border-radius: 4px;
}
.filters-uf__input {
  max-width: 200px;
}
</style>
