<script setup>
import { ref, onMounted, computed } from 'vue'
import { api } from '../api/client'

const specialists = ref([])
const departments = ref([])
const scopeType = ref('specialist')
const specialistIds = ref([])
const departmentId = ref('')
const dateFrom = ref('')
const dateTo = ref('')
const gridData = ref(null)
const loading = ref(false)
const error = ref(null)

function defaultPeriod() {
  const now = new Date()
  const start = new Date(now.getFullYear(), now.getMonth() - 1, 1)
  const end = new Date(now.getFullYear(), now.getMonth(), 0)
  dateFrom.value = start.toISOString().slice(0, 10)
  dateTo.value = end.toISOString().slice(0, 10)
}

function getDaysBetween(from, to) {
  const days = []
  const start = new Date(from)
  const end = new Date(to)
  const d = new Date(start)
  while (d <= end) {
    days.push(d.toISOString().slice(0, 10))
    d.setDate(d.getDate() + 1)
  }
  return days
}

const days = computed(() => {
  const from = dateFrom.value || gridData.value?.date_from
  const to = dateTo.value || gridData.value?.date_to
  if (!from || !to) return []
  return getDaysBetween(from, to)
})

const elapsedByTaskDate = computed(() => {
  if (!gridData.value?.elapsed?.length) return {}
  const map = {}
  for (const e of gridData.value.elapsed) {
    const key = `${e.task_id}_${e.date}`
    map[key] = (map[key] || 0) + Number(e.minutes || 0)
  }
  return map
})

const specialistNameByB24Id = computed(() => {
  if (!gridData.value?.specialists?.length) return {}
  const map = {}
  for (const s of gridData.value.specialists) {
    if (s.bitrix24_user_id) map[s.bitrix24_user_id] = s.name
  }
  return map
})

function hoursForTaskDay(taskId, date) {
  const key = `${taskId}_${date}`
  const minutes = elapsedByTaskDate.value[key] || 0
  if (minutes === 0) return ''
  const h = (minutes / 60).toFixed(1)
  return h === '0.0' ? '' : h
}

function taskLink(task) {
  const base = (gridData.value && gridData.value.portal_url) ? gridData.value.portal_url.replace(/\/+$/, '') : ''
  if (!base || !task.responsible_user_id || !task.bitrix24_task_id) return null
  return `${base}/company/personal/user/${task.responsible_user_id}/tasks/task/view/${task.bitrix24_task_id}/`
}

function isWeekend(dateStr) {
  const d = new Date(dateStr + 'T12:00:00')
  const day = d.getDay()
  return day === 0 || day === 6
}

function planHours(task) {
  if (task.time_estimate == null) return '—'
  const v = Number(task.time_estimate)
  const hours = v >= 10000 ? v / 3600 : v / 60
  return hours.toFixed(1)
}

async function loadRefs() {
  try {
    const [specRes, depRes] = await Promise.all([api.specialists.list(), api.departments.list()])
    specialists.value = specRes.items || []
    departments.value = depRes.items || []
    if (!dateFrom.value || !dateTo.value) defaultPeriod()
  } catch (e) {
    error.value = e.message
  }
}

async function loadGrid() {
  if (!dateFrom.value || !dateTo.value) {
    error.value = 'Укажите период (дата начала и окончания)'
    return
  }
  const id = scopeType.value === 'specialist' ? specialistIds.value : departmentId.value
  if (scopeType.value === 'specialist' && (!id || (Array.isArray(id) && id.length === 0))) {
    error.value = 'Выберите хотя бы одного специалиста'
    return
  }
  if (scopeType.value === 'department' && !id) {
    error.value = 'Выберите отдел'
    return
  }
  loading.value = true
  error.value = null
  gridData.value = null
  try {
    const params = { date_from: dateFrom.value, date_to: dateTo.value }
    if (scopeType.value === 'specialist') {
      params.specialist_ids = Array.isArray(id) ? id.join(',') : id
    } else {
      params.department_id = id
    }
    gridData.value = await api.planningGrid(params)
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(loadRefs)
</script>

<template>
  <div class="page">
    <h1 class="page__title">Планирование (сетка)</h1>
    <p class="page__desc">Сетка по задачам Bitrix24: строки — активные задачи выбранных специалистов, колонки — дни. В ячейках — часы из учёта времени B24 (кто сколько трекал в этот день). Сначала запустите синхронизацию в настройках интеграции.</p>

    <p v-if="error" class="error">{{ error }}</p>

    <section class="filter-section">
      <label>
        <input v-model="scopeType" type="radio" value="specialist" /> Специалисты
      </label>
      <label>
        <input v-model="scopeType" type="radio" value="department" /> Отдел
      </label>
      <template v-if="scopeType === 'specialist'">
        <label class="filter-multi">
          <span>Специалисты:</span>
          <select v-model="specialistIds" class="input" multiple size="3">
            <option v-for="s in specialists" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
      </template>
      <label v-else>
        <select v-model="departmentId" class="input">
          <option value="">— отдел —</option>
          <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
        </select>
      </label>
      <label>С <input v-model="dateFrom" type="date" class="input" /></label>
      <label>По <input v-model="dateTo" type="date" class="input" /></label>
      <button type="button" class="btn btn--primary" :disabled="loading" @click="loadGrid">
        {{ loading ? 'Загрузка…' : 'Показать сетку' }}
      </button>
    </section>

    <section v-if="gridData && !gridData.error" class="grid-section">
      <h2>Задачи и учёт времени по дням</h2>
      <p class="muted">Период: {{ gridData.date_from }} — {{ gridData.date_to }}</p>
      <div class="table-scroll-wrap">
        <table class="grid-table">
          <thead>
            <tr>
              <th class="th-fixed th-task">Задача</th>
              <th class="th-fixed th-spec">Специалист</th>
              <th class="th-fixed th-hours">План ч</th>
              <th v-for="day in days" :key="day" class="th-day" :class="{ 'th-day--weekend': isWeekend(day) }">{{ day.slice(8, 10) }}.{{ day.slice(5, 7) }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in (gridData.tasks || [])" :key="t.bitrix24_task_id">
              <td class="td-fixed td-task" :title="t.title">
                <a v-if="taskLink(t)" :href="taskLink(t)" target="_blank" rel="noopener noreferrer" class="task-link">{{ (t.title || '').slice(0, 40) }}{{ (t.title || '').length > 40 ? '…' : '' }}</a>
                <span v-else>{{ (t.title || '').slice(0, 40) }}{{ (t.title || '').length > 40 ? '…' : '' }}</span>
              </td>
              <td class="td-fixed td-spec">{{ specialistNameByB24Id[t.responsible_user_id] || t.responsible_user_id || '—' }}</td>
              <td class="td-fixed td-hours">{{ planHours(t) }}</td>
              <td v-for="day in days" :key="day" class="td-day" :class="{ 'td-day--weekend': isWeekend(day) }">{{ hoursForTaskDay(t.bitrix24_task_id, day) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="gridData.tasks && gridData.tasks.length === 0" class="muted">Нет задач у выбранных специалистов за период. Запустите синхронизацию с Bitrix24.</p>
    </section>
  </div>
</template>

<style lang="scss" scoped>
.page__title { margin: 0 0 0.25rem; font-size: 1.5rem; }
.page__desc { margin: 0 0 1rem; color: #64748b; font-size: 0.9rem; }
.error { color: var(--color-error); margin-bottom: 1rem; }
.muted { color: #94a3b8; font-size: 0.9rem; margin: 0.5rem 0; }
.filter-section { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-start; margin-bottom: 1.5rem; }
.filter-section label { display: flex; align-items: center; gap: 0.35rem; font-size: 0.9rem; }
.filter-multi { flex-direction: column; align-items: flex-start; }
.input { padding: 0.4rem 0.6rem; border: 1px solid #cbd5e1; border-radius: 4px; }
.btn { padding: 0.4rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer; font-size: 0.9rem; }
.btn--primary { background: var(--color-primary); color: #fff; border-color: var(--color-primary); }
.btn:disabled { opacity: 0.7; cursor: not-allowed; }
.grid-section { margin-top: 1rem; }
.grid-section h2 { margin: 0 0 0.5rem; font-size: 1.1rem; }

.table-scroll-wrap {
  overflow-x: auto;
  max-width: 100%;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
}
.grid-table { border-collapse: collapse; font-size: 0.85rem; min-width: 100%; }
.grid-table th, .grid-table td { padding: 0.35rem 0.5rem; border: 1px solid #e2e8f0; text-align: left; white-space: nowrap; }
.grid-table th { background: #f8fafc; font-weight: 600; }
.grid-table .th-day, .grid-table .td-day { text-align: center; min-width: 2.5rem; }

/* Фиксированные колонки (не скроллятся по горизонтали) */
.th-fixed, .td-fixed {
  position: sticky;
  z-index: 1;
  background: #fff;
  box-shadow: 2px 0 4px -2px rgba(0,0,0,0.08);
}
.grid-table th.th-fixed { z-index: 2; background: #f8fafc; }
.th-task, .td-task { left: 0; min-width: 200px; max-width: 200px; white-space: normal; }
.th-spec, .td-spec { left: 200px; min-width: 120px; max-width: 120px; }
.th-hours, .td-hours { left: 320px; min-width: 56px; max-width: 56px; }

.task-link { color: var(--color-primary); text-decoration: none; }
.task-link:hover { text-decoration: underline; }
.grid-table .td-day { color: #475569; background: #fff; }
.grid-table .th-day--weekend, .grid-table .td-day--weekend { background: #f5f5f5; color: #64748b; }
.grid-table .td-fixed + .td-day--weekend { box-shadow: -2px 0 0 0 #f5f5f5; }
</style>
