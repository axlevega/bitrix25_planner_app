<script setup>
import { ref, onMounted, computed } from 'vue'
import { api } from '../api/client'

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
    <h1 class="page__title">Специалисты</h1>
    <p class="page__desc">Справочник специалистов для планирования: отдел, нормы часов, лимиты по флайтам. Создаётся вручную. Укажите <strong>Bitrix24 User ID</strong> (из синхронизации), чтобы задачи из Bitrix24 учитывались по этому специалисту при расчёте загрузки.</p>

    <p v-if="error" class="error">{{ error }}</p>

    <div v-if="loading" class="loading">Загрузка…</div>
    <template v-else>
      <div class="table-wrap">
        <table class="table">
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
                <button type="button" class="btn btn--sm" @click="openEdit(row)">Изменить</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="items.length === 0" class="muted">Нет специалистов. Добавьте первого ниже.</p>

      <section class="form-section">
        <h2>{{ form.id ? 'Редактирование' : 'Новый специалист' }}</h2>
        <form class="form form--grid" @submit.prevent="save">
          <label>
            <span>Имя *</span>
            <input v-model="form.name" type="text" class="input" required />
          </label>
          <label>
            <span>Отдел</span>
            <select v-model="form.department_id" class="input">
              <option value="">— не выбран —</option>
              <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
            </select>
          </label>
          <label>
            <span>Bitrix24 User ID</span>
            <input v-model="form.bitrix24_user_id" type="text" class="input" placeholder="из B24" />
          </label>
          <label>
            <span>Норма ч/день</span>
            <input v-model.number="form.norm_hours_per_day" type="number" step="0.5" min="0" class="input" />
          </label>
          <label>
            <span>Норма ч/неделя</span>
            <input v-model.number="form.norm_hours_per_week" type="number" step="0.5" min="0" class="input" />
          </label>
          <label>
            <span>Лимит флайт ч/день</span>
            <input v-model.number="form.flight_hours_limit_per_day" type="number" step="0.5" min="0" class="input" />
          </label>
          <label>
            <span>Лимит флайт ч/неделя</span>
            <input v-model.number="form.flight_hours_limit_per_week" type="number" step="0.5" min="0" class="input" />
          </label>
          <label class="form__checkbox">
            <input v-model="form.is_active" type="checkbox" :true-value="1" :false-value="0" />
            <span>Активен</span>
          </label>
          <div class="form__actions">
            <button type="submit" class="btn btn--primary" :disabled="saving">
              {{ saving ? 'Сохранение…' : (form.id ? 'Сохранить' : 'Добавить') }}
            </button>
            <button v-if="form.id" type="button" class="btn" @click="clearForm">Отмена</button>
          </div>
        </form>
      </section>
    </template>
  </div>
</template>

<style lang="scss" scoped>
.page__title {
  margin: 0 0 0.25rem;
  font-size: 1.5rem;
}
.page__desc {
  margin: 0 0 1rem;
  color: #64748b;
  font-size: 0.9rem;
}
.error {
  color: var(--color-error);
  margin-bottom: 1rem;
}
.loading {
  color: #64748b;
}
.table-wrap {
  overflow-x: auto;
  margin-bottom: 1.5rem;
}
.table {
  width: 100%;
  min-width: 800px;
  border-collapse: collapse;
  background: #fff;
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}
.table th,
.table td {
  padding: 0.5rem 0.6rem;
  text-align: left;
  border-bottom: 1px solid #e2e8f0;
  font-size: 0.85rem;
}
.table th {
  background: #f8fafc;
  font-weight: 600;
}
.muted {
  color: #64748b;
  margin-bottom: 1.5rem;
}
.form-section {
  background: #fff;
  padding: 1rem;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}
.form-section h2 {
  margin: 0 0 0.75rem;
  font-size: 1.1rem;
}
.form--grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 0.75rem;
  align-items: end;
}
.form--grid label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.85rem;
}
.form__checkbox {
  flex-direction: row;
  align-items: center;
}
.form__actions {
  grid-column: 1 / -1;
  display: flex;
  gap: 0.5rem;
}
.input {
  padding: 0.4rem 0.6rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
}
.btn {
  padding: 0.4rem 0.75rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  background: #fff;
  cursor: pointer;
  font-size: 0.9rem;
}
.btn--sm {
  padding: 0.25rem 0.5rem;
  font-size: 0.85rem;
}
.btn--primary {
  background: var(--color-primary);
  color: #fff;
  border-color: var(--color-primary);
}
.btn:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}
</style>
