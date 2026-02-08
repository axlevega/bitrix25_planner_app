<script setup>
import { ref, onMounted } from 'vue'
import { api } from '../api/client'
import {
  UiPageHeader,
  UiAlert,
  UiLoading,
  UiTable,
  UiCard,
  UiInput,
  UiButton,
  UiMuted,
} from '../components/ui'

const { embedded } = defineProps({ embedded: { type: Boolean, default: false } })

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
    <UiPageHeader
      title="Отделы"
      :description="
        embedded
          ? 'Справочник отделов. Сначала добавьте отделы, затем на вкладке «Специалисты» привяжите людей к отделам.'
          : 'Справочник отделов приложения (SEO, разработка, дизайн и т.п.). Создаётся вручную — это не данные из Bitrix24. Сначала добавьте отделы, затем на вкладке «Специалисты» привяжите людей к отделам.'
      "
      :size="embedded ? 'md' : 'lg'"
    />

    <UiAlert v-if="error" variant="error">{{ error }}</UiAlert>

    <UiLoading v-if="loading" />
    <template v-else>
      <UiTable>
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
              <UiButton size="sm" @click="openEdit(row)">Изменить</UiButton>
            </td>
          </tr>
        </tbody>
      </UiTable>
      <UiMuted v-if="items.length === 0" tag="p" class="departments__empty">Нет отделов. Добавьте первый ниже.</UiMuted>

      <UiCard tag="section" class="departments__form-card">
        <h2 class="departments__form-title">{{ form.id ? 'Редактирование' : 'Новый отдел' }}</h2>
        <form class="departments__form" @submit.prevent="save">
          <UiInput v-model="form.name" placeholder="Название *" required />
          <UiInput v-model="form.type" placeholder="Тип (SEO, разработка, дизайн)" />
          <UiInput v-model="form.external_id" placeholder="External ID (Bitrix24)" />
          <UiButton type="submit" variant="primary" :disabled="saving">
            {{ saving ? 'Сохранение…' : (form.id ? 'Сохранить' : 'Добавить') }}
          </UiButton>
          <UiButton v-if="form.id" type="button" @click="clearForm">Отмена</UiButton>
        </form>
      </UiCard>
    </template>
  </div>
</template>

<style lang="scss" scoped>
.departments__empty {
  margin-bottom: 1.5rem;
}
.departments__form-card :deep(h2) {
  margin: 0 0 0.75rem;
  font-size: 1.1rem;
}
.departments__form {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
}
.departments__form .ui-input {
  min-width: 140px;
}
</style>
