<script setup>
import { ref, onMounted } from 'vue'
import { api } from '../api/client'

const items = ref([])
const loading = ref(true)
const error = ref(null)
const form = ref({ id: null, name: '', type: '', external_id: '' })
const saving = ref(false)

async function load() {
  loading.value = true
  error.value = null
  try {
    const res = await api.departments.list()
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
    name: row.name || '',
    type: row.type || '',
    external_id: row.external_id || '',
  }
}

function clearForm() {
  form.value = { id: null, name: '', type: '', external_id: '' }
}

async function save() {
  if (!form.value.name.trim()) {
    error.value = 'Введите название отдела'
    return
  }
  saving.value = true
  error.value = null
  try {
    if (form.value.id) {
      await api.departments.update(form.value)
    } else {
      await api.departments.create(form.value)
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
    <h1 class="page__title">Отделы</h1>
    <p class="page__desc">Справочник отделов приложения (SEO, разработка, дизайн и т.п.). Создаётся вручную — это не данные из Bitrix24. Сначала добавьте отделы, затем на вкладке «Специалисты» привяжите людей к отделам.</p>

    <p v-if="error" class="error">{{ error }}</p>

    <div v-if="loading" class="loading">Загрузка…</div>
    <template v-else>
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Название</th>
            <th>Тип</th>
            <th>External ID</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in items" :key="row.id">
            <td>{{ row.id }}</td>
            <td>{{ row.name }}</td>
            <td>{{ row.type || '—' }}</td>
            <td>{{ row.external_id || '—' }}</td>
            <td>
              <button type="button" class="btn btn--sm" @click="openEdit(row)">Изменить</button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="items.length === 0" class="muted">Нет отделов. Добавьте первый ниже.</p>

      <section class="form-section">
        <h2>{{ form.id ? 'Редактирование' : 'Новый отдел' }}</h2>
        <form class="form" @submit.prevent="save">
          <input v-model="form.name" type="text" placeholder="Название *" class="input" required />
          <input v-model="form.type" type="text" placeholder="Тип (SEO, разработка, дизайн)" class="input" />
          <input v-model="form.external_id" type="text" placeholder="External ID (Bitrix24)" class="input" />
          <button type="submit" class="btn btn--primary" :disabled="saving">
            {{ saving ? 'Сохранение…' : (form.id ? 'Сохранить' : 'Добавить') }}
          </button>
          <button v-if="form.id" type="button" class="btn" @click="clearForm">Отмена</button>
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
.table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 1.5rem;
  background: #fff;
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}
.table th,
.table td {
  padding: 0.6rem 0.75rem;
  text-align: left;
  border-bottom: 1px solid #e2e8f0;
}
.table th {
  background: #f8fafc;
  font-weight: 600;
  font-size: 0.85rem;
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
.form {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
}
.input {
  padding: 0.4rem 0.6rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  min-width: 140px;
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
