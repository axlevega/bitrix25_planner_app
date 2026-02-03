<script setup>
import { ref, onMounted } from 'vue'
import { api } from '../api/client'

const items = ref([])
const workTypes = ref([{ id: 1, name: 'Регулярные' }, { id: 2, name: 'Флайт' }])
const loading = ref(true)
const error = ref(null)
const form = ref({ id: null, bitrix24_group_id: '', work_type_id: 1 })
const saving = ref(false)

async function load() {
  loading.value = true
  error.value = null
  try {
    const res = await api.projectWorkTypes.list()
    items.value = res.items || []
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

function openEdit(row) {
  form.value = {
    id: row.id,
    bitrix24_group_id: row.bitrix24_group_id || '',
    work_type_id: row.work_type_id ?? 1,
  }
}

function clearForm() {
  form.value = { id: null, bitrix24_group_id: '', work_type_id: 1 }
}

async function save() {
  if (!form.value.bitrix24_group_id.trim()) {
    error.value = 'Введите ID группы/проекта Bitrix24'
    return
  }
  saving.value = true
  error.value = null
  try {
    if (form.value.id) {
      await api.projectWorkTypes.update({ id: form.value.id, work_type_id: form.value.work_type_id })
    } else {
      await api.projectWorkTypes.create({ bitrix24_group_id: form.value.bitrix24_group_id.trim(), work_type_id: form.value.work_type_id })
    }
    clearForm()
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

async function remove(row) {
  if (!confirm('Удалить привязку проекта к типу работы?')) return
  try {
    await api.projectWorkTypes.delete(row.id)
    await load()
  } catch (e) {
    error.value = e.message
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <h1 class="page__title">Типы проектов (B24)</h1>
    <p class="page__desc">Привязка проектов Bitrix24 (group_id задачи) к типу работы: регулярка или флайт. Задачи из проектов, не указанных здесь, считаются регулярными. Нужно для расчёта загрузки и проверки лимита часов по флайтам.</p>

    <p v-if="error" class="error">{{ error }}</p>

    <div v-if="loading" class="loading">Загрузка…</div>
    <template v-else>
      <table class="table">
        <thead>
          <tr>
            <th>ID группы B24</th>
            <th>Тип работы</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in items" :key="row.id">
            <td>{{ row.bitrix24_group_id }}</td>
            <td>{{ row.work_type_name || (row.work_type_id === 2 ? 'Флайт' : 'Регулярные') }}</td>
            <td>
              <button type="button" class="btn btn--sm" @click="openEdit(row)">Изменить</button>
              <button type="button" class="btn btn--sm btn--danger" @click="remove(row)">Удалить</button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="items.length === 0" class="muted">Нет привязок. Задачи из всех проектов B24 считаются регулярными. Добавьте проекты-флайты ниже.</p>

      <section class="form-section">
        <h2>{{ form.id ? 'Изменить тип' : 'Новый проект → тип работы' }}</h2>
        <form class="form" @submit.prevent="save">
          <input v-model="form.bitrix24_group_id" type="text" placeholder="ID группы/проекта Bitrix24 *" class="input" :disabled="!!form.id" />
          <select v-model.number="form.work_type_id" class="input">
            <option v-for="wt in workTypes" :key="wt.id" :value="wt.id">{{ wt.name }}</option>
          </select>
          <button type="submit" class="btn btn--primary" :disabled="saving">{{ saving ? 'Сохранение…' : 'Сохранить' }}</button>
          <button v-if="form.id" type="button" class="btn" @click="clearForm">Отмена</button>
        </form>
      </section>
    </template>
  </div>
</template>

<style lang="scss" scoped>
.page__title { margin: 0 0 0.25rem; font-size: 1.5rem; }
.page__desc { margin: 0 0 1rem; color: #64748b; font-size: 0.9rem; }
.error { color: var(--color-error); margin-bottom: 1rem; }
.loading { margin: 1rem 0; }
.muted { color: #94a3b8; font-size: 0.9rem; margin: 0.5rem 0; }
.table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: 0.9rem; }
.table th, .table td { padding: 0.4rem 0.6rem; text-align: left; border-bottom: 1px solid #e2e8f0; }
.table th { background: #f8fafc; font-weight: 600; }
.form-section { margin-top: 1.5rem; padding: 1rem; background: #f8fafc; border-radius: 8px; }
.form-section h2 { margin: 0 0 0.75rem; font-size: 1.1rem; }
.form { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
.input { padding: 0.4rem 0.6rem; border: 1px solid #cbd5e1; border-radius: 4px; }
.btn { padding: 0.4rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer; font-size: 0.9rem; }
.btn--sm { padding: 0.25rem 0.5rem; font-size: 0.85rem; }
.btn--primary { background: var(--color-primary); color: #fff; border-color: var(--color-primary); }
.btn--danger { color: #c53030; border-color: #c53030; }
.btn:disabled { opacity: 0.7; cursor: not-allowed; }
</style>
