<script setup>
import { ref, onMounted } from 'vue'
import { api } from '../api/client'

const settings = ref({
  portal_url: '',
  webhook_token: '',
  sync_interval_minutes: 30,
  last_sync_at: null,
  sync_date_range_type: 'month',
  sync_date_from: null,
  sync_date_to: null,
  sync_specialist_ids: [],
  planning_default_department_id: null,
  planning_default_group_ids: [],
})
const specialists = ref([])
const departments = ref([])
const taskGroups = ref([])
const taskUfCatalog = ref([])
const loading = ref(true)
const error = ref(null)
const saving = ref(false)
const syncing = ref(false)
const syncResult = ref(null)
const ufLabelSaving = ref(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    const [res, specRes, depRes, catalogRes, groupsRes] = await Promise.all([
      api.integrationSettings.get(),
      api.specialists.list(),
      api.departments.list(),
      api.taskUfCatalog.list().catch(() => ({ items: [] })),
      api.taskGroups.list().catch(() => ({ items: [] })),
    ])
    taskUfCatalog.value = (catalogRes.items || []).map((it) => ({ ...it, label_edit: it.label ?? '' }))
    taskGroups.value = groupsRes.items ?? []
    settings.value = {
      portal_url: res.portal_url || '',
      webhook_token: res.webhook_token ?? '',
      sync_interval_minutes: res.sync_interval_minutes ?? 30,
      last_sync_at: res.last_sync_at ?? null,
      sync_date_range_type: res.sync_date_range_type ?? 'month',
      sync_date_from: res.sync_date_from ?? null,
      sync_date_to: res.sync_date_to ?? null,
      sync_specialist_ids: Array.isArray(res.sync_specialist_ids) ? res.sync_specialist_ids : [],
      planning_default_department_id: res.planning_default_department_id ?? null,
      planning_default_group_ids: Array.isArray(res.planning_default_group_ids) ? res.planning_default_group_ids : [],
    }
    specialists.value = specRes.items ?? []
    departments.value = depRes.items ?? []
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

async function save() {
  saving.value = true
  error.value = null
  syncResult.value = null
  try {
    await api.integrationSettings.save({
      portal_url: settings.value.portal_url,
      webhook_token: settings.value.webhook_token,
      sync_interval_minutes: settings.value.sync_interval_minutes,
      sync_date_range_type: settings.value.sync_date_range_type,
      sync_date_from: settings.value.sync_date_range_type === 'custom' ? settings.value.sync_date_from : null,
      sync_date_to: settings.value.sync_date_range_type === 'custom' ? settings.value.sync_date_to : null,
      sync_specialist_ids: settings.value.sync_specialist_ids,
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

function toggleSpecialist(id) {
  const ids = settings.value.sync_specialist_ids
  const numId = Number(id)
  if (ids.includes(numId)) {
    settings.value.sync_specialist_ids = ids.filter((i) => i !== numId)
  } else {
    settings.value.sync_specialist_ids = [...ids, numId]
  }
}

async function runSync(mode = 'full') {
  syncing.value = true
  error.value = null
  syncResult.value = null
  let totalTasks = 0
  let totalUsers = 0
  let totalElapsed = 0
  try {
    let res
    do {
      res = await api.sync(mode)
      if (res.success) {
        totalTasks += res.tasks_count ?? 0
        totalUsers += res.users_count ?? 0
        totalElapsed += res.elapsed_count ?? 0
        if (res.has_more) {
          syncResult.value = { success: true, message: res.message, in_progress: true, tasks_count: totalTasks, users_count: totalUsers, elapsed_count: totalElapsed }
        }
      } else {
        syncResult.value = res
        break
      }
    } while (res.has_more)
    if (res && res.success && !res.has_more) {
      const parts = []
      if (totalTasks > 0) parts.push(`задач: ${totalTasks}`)
      if (totalUsers > 0) parts.push(`пользователей: ${totalUsers}`)
      if (totalElapsed > 0) parts.push(`учёт времени: ${totalElapsed}`)
      syncResult.value = { success: true, message: 'Синхронизация завершена' + (parts.length ? '. ' + parts.join(', ') : ''), tasks_count: totalTasks, users_count: totalUsers, elapsed_count: totalElapsed }
    }
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    syncing.value = false
  }
}

function lastSyncText() {
  const t = settings.value.last_sync_at
  if (!t) return 'Ещё не запускалась'
  try {
    const d = new Date(t)
    return d.toLocaleString('ru-RU')
  } catch {
    return t
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <h1 class="page__title">Настройки интеграции</h1>
    <p class="page__desc">URL портала Bitrix24 и токен вебхука. Токен не отображается после сохранения.</p>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="syncResult" class="sync-result" :class="{ 'sync-result--ok': syncResult.success, 'sync-result--err': !syncResult.success }">
      <template v-if="syncResult.success">
        {{ syncResult.in_progress ? syncResult.message + '…' : syncResult.message }}
      </template>
      <template v-else>{{ syncResult.message }}</template>
    </p>

    <div v-if="loading" class="loading">Загрузка…</div>
    <template v-else>
      <section class="form-section">
        <form class="form form--vertical" @submit.prevent="save">
          <label>
            <span>URL портала Bitrix24 *</span>
            <input
              v-model="settings.portal_url"
              type="url"
              class="input"
              placeholder="https://ваш-портал.bitrix24.ru"
            />
          </label>
          <label>
            <span>Токен вебхука (часть пути после /rest/)</span>
            <input
              v-model="settings.webhook_token"
              type="password"
              class="input"
              placeholder="1/xxxxxxxxxxxx/"
              autocomplete="off"
            />
            <small>Например: 1/abc123def/ — вводите без ведущего слэша, сохраняется на сервере.</small>
          </label>
          <label>
            <span>Интервал синхронизации (минут)</span>
            <input
              v-model.number="settings.sync_interval_minutes"
              type="number"
              min="5"
              max="1440"
              class="input"
            />
          </label>

          <div class="form__block">
            <span class="form__block-title">Диапазон дат для синхронизации задач</span>
            <div class="form__radios">
              <label class="form__radio">
                <input v-model="settings.sync_date_range_type" type="radio" value="week" />
                <span>Последняя неделя</span>
              </label>
              <label class="form__radio">
                <input v-model="settings.sync_date_range_type" type="radio" value="month" />
                <span>Месяц</span>
              </label>
              <label class="form__radio">
                <input v-model="settings.sync_date_range_type" type="radio" value="half_year" />
                <span>Полгода</span>
              </label>
              <label class="form__radio">
                <input v-model="settings.sync_date_range_type" type="radio" value="year" />
                <span>Год</span>
              </label>
              <label class="form__radio">
                <input v-model="settings.sync_date_range_type" type="radio" value="custom" />
                <span>Свой диапазон</span>
              </label>
            </div>
            <div v-if="settings.sync_date_range_type === 'custom'" class="form__custom-dates">
              <label>
                <span>С</span>
                <input v-model="settings.sync_date_from" type="date" class="input" />
              </label>
              <label>
                <span>По</span>
                <input v-model="settings.sync_date_to" type="date" class="input" />
              </label>
            </div>
          </div>

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

          <div class="form__block">
            <span class="form__block-title">Специалисты для синхронизации задач</span>
            <small>Выберите, по кому загружать задачи из Bitrix24. Пусто — по всем пользователям.</small>
            <div class="specialists-checkboxes">
              <label
                v-for="s in specialists"
                :key="s.id"
                class="form__checkbox"
              >
                <input
                  type="checkbox"
                  :checked="settings.sync_specialist_ids.includes(Number(s.id))"
                  @change="toggleSpecialist(s.id)"
                />
                <span>{{ s.name }}{{ s.department_name ? ` (${s.department_name})` : '' }}</span>
              </label>
            </div>
          </div>

          <div class="form__actions">
            <button type="submit" class="btn btn--primary" :disabled="saving">
              {{ saving ? 'Сохранение…' : 'Сохранить настройки' }}
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

      <section class="sync-section">
        <h2>Синхронизация</h2>
        <p class="sync-desc">
          Последняя синхронизация: <strong>{{ lastSyncText() }}</strong>
        </p>
        <p class="sync-desc sync-desc--hint">
          Для учёта периода и списка специалистов сначала нажмите «Сохранить» выше. Кнопки:
          <strong>Сотрудники</strong> — только справочник пользователей из Битрикс24;
          <strong>Задачи</strong> — только задачи за выбранный период + учёт времени по ним;
          <strong>Всё подряд</strong> — полный цикл: задачи за период → сотрудники → учёт времени по задачам.
        </p>
        <div class="sync-buttons">
          <button
            type="button"
            class="btn btn--primary btn--sync"
            :disabled="syncing"
            @click="runSync('users')"
          >
            {{ syncing ? 'Синхронизация…' : 'Синхронизировать сотрудников' }}
          </button>
          <button
            type="button"
            class="btn btn--outline btn--sync"
            :disabled="syncing"
            @click="runSync('tasks')"
          >
            {{ syncing ? 'Синхронизация…' : 'Синхронизировать задачи' }}
          </button>
          <button
            type="button"
            class="btn btn--outline btn--sync"
            :disabled="syncing"
            @click="runSync('full')"
          >
            {{ syncing ? 'Синхронизация…' : 'Всё подряд (задачи → сотрудники → учёт времени)' }}
          </button>
        </div>
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
.sync-result {
  padding: 0.5rem 0.75rem;
  border-radius: 4px;
  margin-bottom: 1rem;
}
.sync-result--ok {
  background: #d1fae5;
  color: #065f46;
}
.sync-result--err {
  background: #fee2e2;
  color: #991b1b;
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
.form--vertical label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.9rem;
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
.form__radios {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem 1.25rem;
}
.form__radio {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  cursor: pointer;
  font-size: 0.9rem;
}
.form__custom-dates {
  display: flex;
  gap: 1rem;
  margin-top: 0.25rem;
}
.form__custom-dates label {
  flex: 1;
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
.form__checkbox {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  cursor: pointer;
  font-size: 0.9rem;
}
.specialists-checkboxes {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  max-height: 200px;
  overflow-y: auto;
  padding: 0.5rem 0;
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
.sync-buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.sync-desc--hint {
  font-size: 0.85rem;
  color: #64748b;
  margin-bottom: 0.5rem;
}
.uf-catalog-section {
  margin-top: 1.5rem;
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
.sync-section {
  background: #fff;
  padding: 1rem;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}
.sync-section h2 {
  margin: 0 0 0.5rem;
  font-size: 1.1rem;
}
.sync-desc {
  margin: 0 0 0.75rem;
  font-size: 0.9rem;
}
.btn--sync {
  padding: 0.6rem 1.2rem;
}
</style>
