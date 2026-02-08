<script setup>
import { ref, onMounted, computed } from 'vue'
import { api } from '../api/client'
import {
  UiPageHeader,
  UiAlert,
  UiLoading,
  UiTable,
  UiCard,
  UiInput,
  UiSelect,
  UiCheckbox,
  UiButton,
  UiMuted,
} from '../components/ui'

const { embedded } = defineProps({ embedded: { type: Boolean, default: false } })

const items = ref([])
const departments = ref([])
const loading = ref(true)
const error = ref(null)
const form = ref({
  id: null,
  name: '',
  department_id: '',
  bitrix24_user_id: '',
  norm_hours_per_day: '',
  norm_hours_per_week: '',
  flight_hours_limit_per_day: '',
  flight_hours_limit_per_week: '',
  is_active: 1,
})
const saving = ref(false)

const departmentMap = computed(() => {
  const m = {}
  departments.value.forEach((d) => { m[d.id] = d.name })
  return m
})

async function load() {
  loading.value = true
  error.value = null
  try {
    const [specRes, depRes] = await Promise.all([
      api.specialists.list(),
      api.departments.list(),
    ])
    items.value = specRes.items || []
    departments.value = depRes.items || []
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

function openEdit(row) {
  form.value = {
    id: row.id,
    name: row.name || '',
    department_id: row.department_id ?? '',
    bitrix24_user_id: row.bitrix24_user_id || '',
    norm_hours_per_day: row.norm_hours_per_day ?? '',
    norm_hours_per_week: row.norm_hours_per_week ?? '',
    flight_hours_limit_per_day: row.flight_hours_limit_per_day ?? '',
    flight_hours_limit_per_week: row.flight_hours_limit_per_week ?? '',
    is_active: row.is_active !== undefined ? row.is_active : 1,
  }
}

function clearForm() {
  form.value = {
    id: null,
    name: '',
    department_id: '',
    bitrix24_user_id: '',
    norm_hours_per_day: '',
    norm_hours_per_week: '',
    flight_hours_limit_per_day: '',
    flight_hours_limit_per_week: '',
    is_active: 1,
  }
}

async function save() {
  if (!form.value.name.trim()) {
    error.value = 'Введите имя специалиста'
    return
  }
  saving.value = true
  error.value = null
  try {
    const payload = {
      ...form.value,
      department_id: form.value.department_id ? Number(form.value.department_id) : null,
      norm_hours_per_day: form.value.norm_hours_per_day !== '' ? Number(form.value.norm_hours_per_day) : null,
      norm_hours_per_week: form.value.norm_hours_per_week !== '' ? Number(form.value.norm_hours_per_week) : null,
      flight_hours_limit_per_day: form.value.flight_hours_limit_per_day !== '' ? Number(form.value.flight_hours_limit_per_day) : null,
      flight_hours_limit_per_week: form.value.flight_hours_limit_per_week !== '' ? Number(form.value.flight_hours_limit_per_week) : null,
      is_active: form.value.is_active ? 1 : 0,
    }
    if (form.value.id) {
      await api.specialists.update(payload)
    } else {
      await api.specialists.create(payload)
    }
    clearForm()
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
      title="Специалисты"
      :description="
        embedded
          ? 'Справочник специалистов: отдел, нормы часов, лимиты. Укажите Bitrix24 User ID для учёта задач при расчёте загрузки.'
          : 'Справочник специалистов для планирования: отдел, нормы часов, лимиты по флайтам. Создаётся вручную. Укажите Bitrix24 User ID (из синхронизации), чтобы задачи из Bitrix24 учитывались по этому специалисту при расчёте загрузки.'
      "
      :size="embedded ? 'md' : 'lg'"
    />

    <UiAlert v-if="error" variant="error">{{ error }}</UiAlert>

    <UiLoading v-if="loading" />
    <template v-else>
      <UiTable class="specialists__table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Имя</th>
            <th>Отдел</th>
            <th>B24 User ID</th>
            <th>Норма ч/день</th>
            <th>Норма ч/нед</th>
            <th>Лимит флайт ч/день</th>
            <th>Лимит флайт ч/нед</th>
            <th>Активен</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in items" :key="row.id">
            <td>{{ row.id }}</td>
            <td>{{ row.name }}</td>
            <td>{{ departmentMap[row.department_id] || '—' }}</td>
            <td>{{ row.bitrix24_user_id || '—' }}</td>
            <td>{{ row.norm_hours_per_day ?? '—' }}</td>
            <td>{{ row.norm_hours_per_week ?? '—' }}</td>
            <td>{{ row.flight_hours_limit_per_day ?? '—' }}</td>
            <td>{{ row.flight_hours_limit_per_week ?? '—' }}</td>
            <td>{{ row.is_active ? 'Да' : 'Нет' }}</td>
            <td>
              <UiButton size="sm" @click="openEdit(row)">Изменить</UiButton>
            </td>
          </tr>
        </tbody>
      </UiTable>
      <UiMuted v-if="items.length === 0" tag="p" class="specialists__empty">Нет специалистов. Добавьте первого ниже.</UiMuted>

      <UiCard tag="section" class="specialists__form-card">
        <h2 class="specialists__form-title">{{ form.id ? 'Редактирование' : 'Новый специалист' }}</h2>
        <form class="specialists__form" @submit.prevent="save">
          <label class="specialists__field">
            <span>Имя *</span>
            <UiInput v-model="form.name" required />
          </label>
          <label class="specialists__field">
            <span>Отдел</span>
            <UiSelect v-model="form.department_id">
              <option value="">— не выбран —</option>
              <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
            </UiSelect>
          </label>
          <label class="specialists__field">
            <span>Bitrix24 User ID</span>
            <UiInput v-model="form.bitrix24_user_id" placeholder="из B24" />
          </label>
          <label class="specialists__field">
            <span>Норма ч/день</span>
            <UiInput v-model="form.norm_hours_per_day" type="number" step="0.5" min="0" />
          </label>
          <label class="specialists__field">
            <span>Норма ч/неделя</span>
            <UiInput v-model="form.norm_hours_per_week" type="number" step="0.5" min="0" />
          </label>
          <label class="specialists__field">
            <span>Лимит флайт ч/день</span>
            <UiInput v-model="form.flight_hours_limit_per_day" type="number" step="0.5" min="0" />
          </label>
          <label class="specialists__field">
            <span>Лимит флайт ч/неделя</span>
            <UiInput v-model="form.flight_hours_limit_per_week" type="number" step="0.5" min="0" />
          </label>
          <div class="specialists__checkbox-wrap">
            <UiCheckbox
              :model-value="!!form.is_active"
              @update:model-value="form.is_active = $event ? 1 : 0"
            >
              Активен
            </UiCheckbox>
          </div>
          <div class="specialists__actions">
            <UiButton type="submit" variant="primary" :disabled="saving">
              {{ saving ? 'Сохранение…' : (form.id ? 'Сохранить' : 'Добавить') }}
            </UiButton>
            <UiButton v-if="form.id" type="button" @click="clearForm">Отмена</UiButton>
          </div>
        </form>
      </UiCard>
    </template>
  </div>
</template>

<style lang="scss" scoped>
.specialists__table :deep(.ui-table) {
  min-width: 800px;
  font-size: 0.85rem;
}
.specialists__table :deep(th),
.specialists__table :deep(td) {
  padding: 0.5rem 0.6rem;
}
.specialists__empty {
  margin-bottom: 1.5rem;
}
.specialists__form-card :deep(h2) {
  margin: 0 0 0.75rem;
  font-size: 1.1rem;
}
.specialists__form {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 0.75rem;
  align-items: end;
}
.specialists__field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.85rem;
}
.specialists__checkbox-wrap {
  align-self: center;
}
.specialists__actions {
  grid-column: 1 / -1;
  display: flex;
  gap: 0.5rem;
}
</style>
