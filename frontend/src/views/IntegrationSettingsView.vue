<script setup>
import { ref, onMounted } from 'vue'
import { api } from '../api/client'

const { embedded } = defineProps({ embedded: { type: Boolean, default: false } })

const settings = ref({
  portal_url: '',
  webhook_token: '',
  sync_interval_minutes: 30,
  last_sync_at: null,
  sync_date_range_type: 'month',
  sync_date_from: null,
  sync_date_to: null,
  sync_specialist_ids: [],
})
const specialists = ref([])
const loading = ref(true)
const error = ref(null)
const saving = ref(false)
const syncing = ref(false)
const syncResult = ref(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    const [res, specRes] = await Promise.all([
      api.integrationSettings.get(),
      api.specialists.list(),
    ])
    settings.value = {
      portal_url: res.portal_url || '',
      webhook_token: res.webhook_token ?? '',
      sync_interval_minutes: res.sync_interval_minutes ?? 30,
      last_sync_at: res.last_sync_at ?? null,
      sync_date_range_type: res.sync_date_range_type ?? 'month',
      sync_date_from: res.sync_date_from ?? null,
      sync_date_to: res.sync_date_to ?? null,
      sync_specialist_ids: Array.isArray(res.sync_specialist_ids) ? res.sync_specialist_ids : [],
    }
    specialists.value = specRes.items ?? []
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
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
    <template v-if="!embedded">
      <h1 class="page__title">Настройки интеграции</h1>
      <p class="page__desc">URL портала Bitrix24 и токен вебхука. Токен не отображается после сохранения.</p>
    </template>
    <template v-else>
      <h2 class="page__title page__title--tab">Синхронизация</h2>
      <p class="page__desc">URL портала Bitrix24, токен вебхука, период и запуск синхронизации.</p>
    </template>

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
