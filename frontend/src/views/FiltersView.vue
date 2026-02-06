<script setup>
import { ref, onMounted } from 'vue'
import { api } from '../api/client'

const { embedded } = defineProps({ embedded: { type: Boolean, default: false } })

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
    <template v-if="!embedded">
      <h1 class="page__title">Фильтры</h1>
      <p class="page__desc">Отдел и группы по умолчанию для планирования, подписи пользовательских полей.</p>
    </template>
    <template v-else>
      <h2 class="page__title page__title--tab">Фильтры</h2>
      <p class="page__desc">Отдел и группы по умолчанию, подписи полей для фильтров в планировании.</p>
    </template>

    <p v-if="error" class="error">{{ error }}</p>
    <div v-if="loading" class="loading">Загрузка…</div>
    <template v-else>
      <section class="form-section">
        <form class="form form--vertical" @submit.prevent="saveFilters">
          <div class="form__block">
            <span class="form__block-title">Отдел по умолчанию в планировании</span>
            <small>При открытии страницы «Планирование» будет выбран этот отдел и подгружены данные.</small>
            <label class="form__select-wrap">
              <select v-model="settings.planning_default_department_id" class="input">
                <option :value="null">— не задан —</option>
                <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
              </select>
            </label>
          </div>

          <div v-if="taskGroups.length" class="form__block">
            <span class="form__block-title">Группы по умолчанию для плана</span>
            <small>В сетке планирования по умолчанию показываются только задачи из выбранных групп (проектов). Пусто — все группы.</small>
            <label class="form__multi-select">
              <select v-model="settings.planning_default_group_ids" class="input" multiple size="4">
                <option v-for="g in taskGroups" :key="g.bitrix24_group_id" :value="g.bitrix24_group_id">
                  {{ g.name || g.bitrix24_group_id }}
                </option>
              </select>
            </label>
          </div>

          <div class="form__actions">
            <button type="submit" class="btn btn--primary" :disabled="saving">
              {{ saving ? 'Сохранение…' : 'Сохранить' }}
            </button>
          </div>
        </form>
      </section>

      <section v-if="taskUfCatalog.length" class="uf-catalog-section">
        <h2>Подписи пользовательских полей задач</h2>
        <p class="sync-desc sync-desc--hint">
          Подписи используются в фильтрах (например, в сетке планирования). Заполненные значения не перезаписываются при синхронизации.
        </p>
        <table class="uf-catalog-table">
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
                <input v-model="item.label_edit" type="text" class="input" placeholder="Например: Флайт" />
              </td>
              <td>
                <button type="button" class="btn btn--outline btn--sm" :disabled="ufLabelSaving === item.field_code" @click="saveUfLabel(item)">
                  {{ ufLabelSaving === item.field_code ? '…' : 'Сохранить' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>
  </div>
</template>

<style lang="scss" scoped>
.page__title {
  margin: 0 0 0.25rem;
  font-size: 1.5rem;
}
.page__title--tab {
  font-size: 1.2rem;
  margin-bottom: 0.5rem;
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
.form-section {
  background: #fff;
  padding: 1rem;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
  margin-bottom: 1.5rem;
}
.form--vertical {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 560px;
}
.form--vertical small {
  color: #64748b;
  font-size: 0.8rem;
}
.form__block {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.form__block-title {
  font-weight: 600;
  font-size: 0.9rem;
}
.form__multi-select {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  max-width: 320px;
}
.form__multi-select .input {
  min-height: 6rem;
}
.form__actions {
  margin-top: 0.5rem;
}
.input {
  padding: 0.5rem 0.75rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
}
.btn {
  padding: 0.5rem 1rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  background: #fff;
  cursor: pointer;
  font-size: 0.9rem;
}
.btn--primary {
  background: var(--color-primary);
  color: #fff;
  border-color: var(--color-primary);
}
.btn--outline {
  background: #fff;
  color: var(--color-primary);
  border-color: var(--color-primary);
}
.btn:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}
.sync-desc--hint {
  font-size: 0.85rem;
  color: #64748b;
  margin-bottom: 0.5rem;
}
.uf-catalog-section {
  padding: 1rem;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}
.uf-catalog-section h2 {
  margin: 0 0 0.5rem;
  font-size: 1.1rem;
}
.uf-catalog-table {
  width: 100%;
  max-width: 560px;
  border-collapse: collapse;
  margin-top: 0.5rem;
}
.uf-catalog-table th,
.uf-catalog-table td {
  padding: 0.5rem 0.75rem;
  text-align: left;
  border-bottom: 1px solid #e2e8f0;
}
.uf-catalog-table th {
  font-weight: 600;
  font-size: 0.85rem;
  color: #64748b;
}
.uf-catalog-table code {
  font-size: 0.85rem;
  background: #f1f5f9;
  padding: 0.2rem 0.4rem;
  border-radius: 4px;
}
.uf-catalog-table .input {
  width: 100%;
  max-width: 200px;
}
.btn--sm {
  padding: 0.35rem 0.65rem;
  font-size: 0.85rem;
}
</style>
