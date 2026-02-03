<script setup>
import { ref, onMounted, computed } from 'vue'
import { api } from '../api/client'

const specialists = ref([])
const departments = ref([])
const loadType = ref('specialist')
const specialistId = ref('')
const departmentId = ref('')
const dateFrom = ref('')
const dateTo = ref('')
const loadResult = ref(null)
const loading = ref(false)
const error = ref(null)

function defaultPeriod() {
  const now = new Date()
  const monday = new Date(now)
  const d = now.getDay()
  const diff = d === 0 ? -6 : 1 - d
  monday.setDate(now.getDate() + diff)
  const sunday = new Date(monday)
  sunday.setDate(monday.getDate() + 6)
  dateFrom.value = monday.toISOString().slice(0, 10)
  dateTo.value = sunday.toISOString().slice(0, 10)
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

async function loadLoad() {
  if (!dateFrom.value || !dateTo.value) {
    error.value = 'Укажите период (дата начала и окончания)'
    return
  }
  const id = loadType.value === 'specialist' ? specialistId.value : departmentId.value
  if (!id) {
    error.value = loadType.value === 'specialist' ? 'Выберите специалиста' : 'Выберите отдел'
    return
  }
  loading.value = true
  error.value = null
  loadResult.value = null
  try {
    const params = { date_from: dateFrom.value, date_to: dateTo.value }
    if (loadType.value === 'specialist') params.specialist_id = id
    else params.department_id = id
    loadResult.value = await api.load(params)
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

const statusText = computed(() => {
  if (!loadResult.value || loadResult.value.error) return ''
  const s = loadResult.value.status
  if (s === 'overload') return 'Перегруз'
  if (s === 'underload') return 'Недогруз'
  return 'Норма'
})

const statusClass = computed(() => {
  if (!loadResult.value || loadResult.value.error) return ''
  const s = loadResult.value.status
  if (s === 'overload') return 'status--overload'
  if (s === 'underload') return 'status--underload'
  return 'status--normal'
})

onMounted(loadRefs)
</script>

<template>
  <div class="page">
    <h1 class="page__title">Загрузка</h1>
    <p class="page__desc">Расчёт загрузки за период: плановые записи + часы по задачам Bitrix24 (ответственный = специалист с указанным Bitrix24 User ID).</p>

    <p v-if="error" class="error">{{ error }}</p>

    <section class="filter-section">
      <label>
        <input v-model="loadType" type="radio" value="specialist" /> Специалист
      </label>
      <label>
        <input v-model="loadType" type="radio" value="department" /> Отдел
      </label>
      <label v-if="loadType === 'specialist'">
        <select v-model="specialistId" class="input">
          <option value="">— выберите —</option>
          <option v-for="s in specialists" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
      </label>
      <label v-else>
        <select v-model="departmentId" class="input">
          <option value="">— выберите —</option>
          <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
        </select>
      </label>
      <label>С <input v-model="dateFrom" type="date" class="input" /></label>
      <label>По <input v-model="dateTo" type="date" class="input" /></label>
      <button type="button" class="btn btn--primary" :disabled="loading" @click="loadLoad">
        {{ loading ? 'Расчёт…' : 'Рассчитать' }}
      </button>
    </section>

    <section v-if="loadResult && !loadResult.error" class="result-section">
      <h2>Результат</h2>
      <p><strong>Период:</strong> {{ loadResult.date_from }} — {{ loadResult.date_to }}</p>
      <p v-if="loadResult.specialist_name"><strong>Специалист:</strong> {{ loadResult.specialist_name }}</p>
      <p v-if="loadResult.department_name"><strong>Отдел:</strong> {{ loadResult.department_name }}</p>
      <p><strong>Часы (план):</strong> {{ loadResult.hours_plan }} <span class="muted">(регулярка {{ loadResult.hours_plan_regular ?? 0 }} / флайт {{ loadResult.hours_plan_flight ?? 0 }})</span></p>
      <p><strong>Часы (задачи B24):</strong> {{ loadResult.hours_tasks }} <span class="muted">(регулярка {{ loadResult.hours_tasks_regular ?? 0 }} / флайт {{ loadResult.hours_tasks_flight ?? 0 }})</span></p>
      <p><strong>Всего часов:</strong> {{ loadResult.hours_total }} <span class="muted">(регулярка {{ loadResult.hours_regular ?? 0 }} / флайт {{ loadResult.hours_flight ?? 0 }})</span></p>
      <p v-if="loadResult.norm_hours != null"><strong>Норма за период:</strong> {{ loadResult.norm_hours }}</p>
      <p v-if="loadResult.flight_limit != null"><strong>Лимит флайт за период:</strong> {{ loadResult.flight_limit }} ч</p>
      <p v-if="loadResult.flight_limit_exceeded" class="status status--overload"><strong>Превышен лимит часов по флайтам</strong></p>
      <p :class="['status', statusClass]"><strong>Статус загрузки:</strong> {{ statusText }}</p>
      <div v-if="loadResult.specialists && loadResult.specialists.length" class="specialists-list">
        <h3>По специалистам отдела</h3>
        <table class="table">
          <thead>
            <tr>
              <th>Специалист</th>
              <th>План</th>
              <th>Задачи</th>
              <th>Регулярка</th>
              <th>Флайт</th>
              <th>Лимит флайт</th>
              <th>Норма</th>
              <th>Статус</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in loadResult.specialists" :key="s.specialist_id">
              <td>{{ s.specialist_name }}</td>
              <td>{{ s.hours_plan }}</td>
              <td>{{ s.hours_tasks }}</td>
              <td>{{ s.hours_regular ?? '—' }}</td>
              <td>{{ s.hours_flight ?? '—' }}</td>
              <td>
                <span v-if="s.flight_limit != null">{{ s.flight_limit }}</span>
                <span v-else>—</span>
                <span v-if="s.flight_limit_exceeded" class="status status--overload" title="Превышен лимит флайтов"> ⚠</span>
              </td>
              <td>{{ s.norm_hours ?? '—' }}</td>
              <td :class="['status', s.status === 'overload' ? 'status--overload' : s.status === 'underload' ? 'status--underload' : 'status--normal']">
                {{ s.status === 'overload' ? 'Перегруз' : s.status === 'underload' ? 'Недогруз' : 'Норма' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style lang="scss" scoped>
.page__title { margin: 0 0 0.25rem; font-size: 1.5rem; }
.page__desc { margin: 0 0 1rem; color: #64748b; font-size: 0.9rem; }
.muted { color: #94a3b8; font-size: 0.85rem; }
.error { color: var(--color-error); margin-bottom: 1rem; }
.filter-section { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; margin-bottom: 1.5rem; }
.filter-section label { display: flex; align-items: center; gap: 0.35rem; font-size: 0.9rem; }
.input { padding: 0.4rem 0.6rem; border: 1px solid #cbd5e1; border-radius: 4px; }
.btn { padding: 0.4rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer; font-size: 0.9rem; }
.btn--primary { background: var(--color-primary); color: #fff; border-color: var(--color-primary); }
.btn:disabled { opacity: 0.7; cursor: not-allowed; }
.result-section { background: #fff; padding: 1rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
.result-section h2 { margin: 0 0 0.75rem; font-size: 1.1rem; }
.result-section p { margin: 0.35rem 0; }
.status--normal { color: #276749; }
.status--overload { color: #c53030; }
.status--underload { color: #744210; }
.specialists-list { margin-top: 1rem; }
.specialists-list h3 { margin: 0 0 0.5rem; font-size: 1rem; }
.table { width: 100%; border-collapse: collapse; margin-top: 0.5rem; font-size: 0.9rem; }
.table th, .table td { padding: 0.4rem 0.6rem; text-align: left; border-bottom: 1px solid #e2e8f0; }
.table th { background: #f8fafc; font-weight: 600; }
</style>
