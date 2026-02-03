<script setup>
import { ref, onMounted, computed } from 'vue'
import { api } from '../api/client'

const items = ref([])
const specialists = ref([])
const workTypes = ref([{ id: 1, code: 'regular', name: 'Регулярные' }, { id: 2, code: 'flight', name: 'Флайт' }])
const loading = ref(true)
const error = ref(null)
const form = ref({
  id: null,
  specialist_id: '',
  date_from: '',
  date_to: '',
  hours: '',
  work_type_id: 1,
  note: '',
})
const filter = ref({ specialist_id: '', date_from: '', date_to: '' })
const saving = ref(false)

async function load() {
  loading.value = true
  error.value = null
  try {
    const params = {}
    if (filter.value.specialist_id) params.specialist_id = filter.value.specialist_id
    if (filter.value.date_from) params.date_from = filter.value.date_from
    if (filter.value.date_to) params.date_to = filter.value.date_to
    const [entriesRes, specRes] = await Promise.all([
      api.planEntries.list(params),
      api.specialists.list(),
    ])
    items.value = entriesRes.items || []
    specialists.value = specRes.items || []
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

function openEdit(row) {
  form.value = {
    id: row.id,
    specialist_id: row.specialist_id,
    date_from: row.date_from,
    date_to: row.date_to,
    hours: row.hours,
    work_type_id: row.work_type_id ?? 1,
    note: row.note || '',
  }
}

function clearForm() {
  form.value = { id: null, specialist_id: '', date_from: '', date_to: '', hours: '', work_type_id: 1, note: '' }
}

async function save() {
  if (!form.value.specialist_id || !form.value.date_from || !form.value.date_to) {
    error.value = 'Специалист, дата начала и дата окончания обязательны'
    return
  }
  const hours = Number(form.value.hours)
  if (isNaN(hours) || hours < 0) {
    error.value = 'Часы должны быть числом >= 0'
    return
  }
  saving.value = true
  error.value = null
  try {
    if (form.value.id) {
      await api.planEntries.update({
        id: form.value.id,
        specialist_id: form.value.specialist_id,
        date_from: form.value.date_from,
        date_to: form.value.date_to,
        hours,
        work_type_id: form.value.work_type_id,
        note: form.value.note || null,
      })
    } else {
      await api.planEntries.create({
        specialist_id: form.value.specialist_id,
        date_from: form.value.date_from,
        date_to: form.value.date_to,
        hours,
        work_type_id: form.value.work_type_id,
        note: form.value.note || null,
      })
    }
    clearForm()
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

async function remove(id) {
  if (!confirm('Удалить запись?')) return
  error.value = null
  try {
    await api.planEntries.delete(id)
    await load()
  } catch (e) {
    error.value = e.message
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <h1 class="page__title">Плановые записи</h1>
    <p class="page__desc">Часы по специалистам за период (регулярка / флайт). Учитываются в расчёте загрузки вместе с задачами из Bitrix24.</p>

    <p v-if="error" class="error">{{ error }}</p>

    <section class="filter-section">
      <label>Специалист <select v-model="filter.specialist_id" class="input" @change="load"><option value="">— все —</option><option v-for="s in specialists" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
      <label>С <input v-model="filter.date_from" type="date" class="input" @change="load" /></label>
      <label>По <input v-model="filter.date_to" type="date" class="input" @change="load" /></label>
    </section>

    <div v-if="loading" class="loading">Загрузка…</div>
    <template v-else>
      <table class="table">
        <thead>
          <tr>
            <th>Специалист</th>
            <th>Период</th>
            <th>Часы</th>
            <th>Тип</th>
            <th>Примечание</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in items" :key="row.id">
            <td>{{ row.specialist_name }}</td>
            <td>{{ row.date_from }} — {{ row.date_to }}</td>
            <td>{{ row.hours }}</td>
            <td>{{ row.work_type_name }}</td>
            <td>{{ row.note || '—' }}</td>
            <td>
              <button type="button" class="btn btn--sm" @click="openEdit(row)">Изменить</button>
              <button type="button" class="btn btn--sm btn--danger" @click="remove(row.id)">Удалить</button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="items.length === 0" class="muted">Нет записей. Добавьте ниже.</p>

      <section class="form-section">
        <h2>{{ form.id ? 'Редактирование' : 'Новая запись' }}</h2>
        <form class="form form--grid" @submit.prevent="save">
          <label>
            <span>Специалист *</span>
            <select v-model="form.specialist_id" class="input" required>
              <option value="">— выберите —</option>
              <option v-for="s in specialists" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
          </label>
          <label>
            <span>Дата начала *</span>
            <input v-model="form.date_from" type="date" class="input" required />
          </label>
          <label>
            <span>Дата окончания *</span>
            <input v-model="form.date_to" type="date" class="input" required />
          </label>
          <label>
            <span>Часы *</span>
            <input v-model.number="form.hours" type="number" step="0.5" min="0" class="input" required />
          </label>
          <label>
            <span>Тип работ</span>
            <select v-model.number="form.work_type_id" class="input">
              <option v-for="wt in workTypes" :key="wt.id" :value="wt.id">{{ wt.name }}</option>
            </select>
          </label>
          <label>
            <span>Примечание</span>
            <input v-model="form.note" type="text" class="input" />
          </label>
          <div class="form__actions">
            <button type="submit" class="btn btn--primary" :disabled="saving">{{ saving ? 'Сохранение…' : (form.id ? 'Сохранить' : 'Добавить') }}</button>
            <button v-if="form.id" type="button" class="btn" @click="clearForm">Отмена</button>
          </div>
        </form>
      </section>
    </template>
  </div>
</template>

<style lang="scss" scoped>
.page__title { margin: 0 0 0.25rem; font-size: 1.5rem; }
.page__desc { margin: 0 0 1rem; color: #64748b; font-size: 0.9rem; }
.error { color: var(--color-error); margin-bottom: 1rem; }
.filter-section { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; margin-bottom: 1rem; }
.filter-section label { display: flex; align-items: center; gap: 0.35rem; font-size: 0.9rem; }
.loading { color: #64748b; }
.table { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
.table th, .table td { padding: 0.5rem 0.75rem; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 0.9rem; }
.table th { background: #f8fafc; font-weight: 600; }
.muted { color: #64748b; margin-bottom: 1.5rem; }
.form-section { background: #fff; padding: 1rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
.form-section h2 { margin: 0 0 0.75rem; font-size: 1.1rem; }
.form--grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 0.75rem; align-items: end; }
.form--grid label { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.85rem; }
.form__actions { grid-column: 1 / -1; display: flex; gap: 0.5rem; }
.input { padding: 0.4rem 0.6rem; border: 1px solid #cbd5e1; border-radius: 4px; }
.btn { padding: 0.4rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer; font-size: 0.9rem; }
.btn--sm { padding: 0.25rem 0.5rem; font-size: 0.85rem; }
.btn--primary { background: var(--color-primary); color: #fff; border-color: var(--color-primary); }
.btn--danger { border-color: #e53e3e; color: #e53e3e; }
.btn:disabled { opacity: 0.7; cursor: not-allowed; }
</style>
