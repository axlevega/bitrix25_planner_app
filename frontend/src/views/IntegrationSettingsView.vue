<script setup>
import { ref, onMounted } from 'vue'
import { api } from '../api/client'

const settings = ref({
  portal_url: '',
  webhook_token: '',
  sync_interval_minutes: 30,
  last_sync_at: null,
})
const loading = ref(true)
const error = ref(null)
const saving = ref(false)
const syncing = ref(false)
const syncResult = ref(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    const res = await api.integrationSettings.get()
    settings.value = {
      portal_url: res.portal_url || '',
      webhook_token: res.webhook_token ?? '', // бэкенд не отдаёт токен — поле для ввода
      sync_interval_minutes: res.sync_interval_minutes ?? 30,
      last_sync_at: res.last_sync_at ?? null,
    }
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
    })
    await load()
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

async function runSync() {
  syncing.value = true
  error.value = null
  syncResult.value = null
  let totalTasks = 0
  let totalUsers = 0
  try {
    let res
    do {
      res = await api.sync()
      if (res.success) {
        totalTasks += res.tasks_count ?? 0
        totalUsers += res.users_count ?? 0
        if (res.has_more) {
          syncResult.value = { success: true, message: res.message, in_progress: true, tasks_count: totalTasks, users_count: totalUsers }
        }
      } else {
        syncResult.value = res
        break
      }
    } while (res.has_more)
    if (res && res.success && !res.has_more) {
      syncResult.value = { success: true, message: 'Синхронизация завершена', tasks_count: totalTasks, users_count: totalUsers }
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
        {{ syncResult.in_progress ? syncResult.message + '…' : (syncResult.message + (syncResult.tasks_count != null || syncResult.users_count != null ? ` Задач: ${syncResult.tasks_count ?? 0}, пользователей: ${syncResult.users_count ?? 0}` : '')) }}
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
        <button
          type="button"
          class="btn btn--primary btn--sync"
          :disabled="syncing"
          @click="runSync"
        >
          {{ syncing ? 'Синхронизация…' : 'Синхронизировать сейчас' }}
        </button>
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
  max-width: 480px;
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
.btn:disabled {
  opacity: 0.7;
  cursor: not-allowed;
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
