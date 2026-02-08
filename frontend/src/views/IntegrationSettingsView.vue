<script setup>
import { ref, onMounted } from 'vue'
import { api } from '../api/client'
import {
  UiPageHeader,
  UiAlert,
  UiLoading,
  UiCard,
  UiInput,
  UiRadio,
  UiCheckbox,
  UiButton,
  UiMuted,
} from '../components/ui'

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
    <UiPageHeader
      :title="embedded ? 'Синхронизация' : 'Настройки интеграции'"
      :description="
        embedded
          ? 'URL портала Bitrix24, токен вебхука, период и запуск синхронизации.'
          : 'URL портала Bitrix24 и токен вебхука. Токен не отображается после сохранения.'
      "
      :size="embedded ? 'md' : 'lg'"
    />

    <UiAlert v-if="error" variant="error">{{ error }}</UiAlert>
    <UiAlert
      v-if="syncResult"
      :variant="syncResult.success ? 'success' : 'error'"
    >
      <template v-if="syncResult.success">
        {{ syncResult.in_progress ? syncResult.message + '…' : syncResult.message }}
      </template>
      <template v-else>{{ syncResult.message }}</template>
    </UiAlert>

    <UiLoading v-if="loading" />
    <template v-else>
      <UiCard tag="section" class="integration-form">
        <form class="integration-form__form" @submit.prevent="save">
          <label class="integration-form__label">
            <span>URL портала Bitrix24 *</span>
            <UiInput
              v-model="settings.portal_url"
              type="url"
              placeholder="https://ваш-портал.bitrix24.ru"
            />
          </label>
          <label class="integration-form__label">
            <span>Токен вебхука (часть пути после /rest/)</span>
            <UiInput
              v-model="settings.webhook_token"
              type="password"
              placeholder="1/xxxxxxxxxxxx/"
              autocomplete="off"
            />
            <UiMuted tag="small">Например: 1/abc123def/ — вводите без ведущего слэша, сохраняется на сервере.</UiMuted>
          </label>
          <label class="integration-form__label">
            <span>Интервал синхронизации (минут)</span>
            <UiInput
              v-model.number="settings.sync_interval_minutes"
              type="number"
              :min="5"
              :max="1440"
            />
          </label>

          <div class="integration-form__block">
            <span class="integration-form__block-title">Диапазон дат для синхронизации задач</span>
            <div class="integration-form__radios">
              <UiRadio v-model="settings.sync_date_range_type" name="sync_date_range" value="week">Последняя неделя</UiRadio>
              <UiRadio v-model="settings.sync_date_range_type" name="sync_date_range" value="month">Месяц</UiRadio>
              <UiRadio v-model="settings.sync_date_range_type" name="sync_date_range" value="half_year">Полгода</UiRadio>
              <UiRadio v-model="settings.sync_date_range_type" name="sync_date_range" value="year">Год</UiRadio>
              <UiRadio v-model="settings.sync_date_range_type" name="sync_date_range" value="custom">Свой диапазон</UiRadio>
            </div>
            <div v-if="settings.sync_date_range_type === 'custom'" class="integration-form__custom-dates">
              <label class="integration-form__label">
                <span>С</span>
                <UiInput v-model="settings.sync_date_from" type="date" />
              </label>
              <label class="integration-form__label">
                <span>По</span>
                <UiInput v-model="settings.sync_date_to" type="date" />
              </label>
            </div>
          </div>

          <div class="integration-form__block">
            <span class="integration-form__block-title">Специалисты для синхронизации задач</span>
            <UiMuted tag="small">Выберите, по кому загружать задачи из Bitrix24. Пусто — по всем пользователям.</UiMuted>
            <div class="integration-form__checkboxes">
              <UiCheckbox
                v-for="s in specialists"
                :key="s.id"
                v-model="settings.sync_specialist_ids"
                :value="Number(s.id)"
              >
                {{ s.name }}{{ s.department_name ? ` (${s.department_name})` : '' }}
              </UiCheckbox>
            </div>
          </div>

          <div class="integration-form__actions">
            <UiButton type="submit" variant="primary" :disabled="saving">
              {{ saving ? 'Сохранение…' : 'Сохранить настройки' }}
            </UiButton>
          </div>
        </form>
      </UiCard>

      <UiCard tag="section" class="integration-sync">
        <h2 class="integration-sync__title">Синхронизация</h2>
        <p class="integration-sync__desc">
          Последняя синхронизация: <strong>{{ lastSyncText() }}</strong>
        </p>
        <UiMuted tag="p" class="integration-sync__hint">
          Для учёта периода и списка специалистов сначала нажмите «Сохранить» выше. Кнопки:
          <strong>Сотрудники</strong> — только справочник пользователей из Битрикс24;
          <strong>Задачи</strong> — только задачи за выбранный период + учёт времени по ним;
          <strong>Всё подряд</strong> — полный цикл: задачи за период → сотрудники → учёт времени по задачам.
        </UiMuted>
        <div class="integration-sync__buttons">
          <UiButton variant="primary" :disabled="syncing" @click="runSync('users')">
            {{ syncing ? 'Синхронизация…' : 'Синхронизировать сотрудников' }}
          </UiButton>
          <UiButton variant="outline" :disabled="syncing" @click="runSync('tasks')">
            {{ syncing ? 'Синхронизация…' : 'Синхронизировать задачи' }}
          </UiButton>
          <UiButton variant="outline" :disabled="syncing" @click="runSync('full')">
            {{ syncing ? 'Синхронизация…' : 'Всё подряд (задачи → сотрудники → учёт времени)' }}
          </UiButton>
        </div>
      </UiCard>
    </template>
  </div>
</template>

<style lang="scss" scoped>
.integration-form {
  margin-bottom: 1.5rem;
}
.integration-form__form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 560px;
}
.integration-form__label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.9rem;
}
.integration-form__block {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.integration-form__block-title {
  font-weight: 600;
  font-size: 0.9rem;
}
.integration-form__radios {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem 1.25rem;
}
.integration-form__custom-dates {
  display: flex;
  gap: 1rem;
  margin-top: 0.25rem;
}
.integration-form__custom-dates .integration-form__label {
  flex: 1;
}
.integration-form__checkboxes {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  max-height: 200px;
  overflow-y: auto;
  padding: 0.5rem 0;
}
.integration-form__actions {
  margin-top: 0.5rem;
}
.integration-sync__title {
  margin: 0 0 0.5rem;
  font-size: 1.1rem;
}
.integration-sync__desc {
  margin: 0 0 0.75rem;
  font-size: 0.9rem;
}
.integration-sync__hint {
  font-size: 0.85rem;
  margin-bottom: 0.5rem;
}
.integration-sync__buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
</style>
