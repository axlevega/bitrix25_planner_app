<script setup>
import { ref, onMounted, computed } from 'vue'
import { api } from '../api/client'
import {
  UiPageHeader,
  UiAlert,
  UiCard,
  UiRadio,
  UiSelect,
  UiInput,
  UiButton,
  UiTable,
  UiStatus,
  UiMuted,
} from '../components/ui'

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

const statusVariant = computed(() => {
  if (!loadResult.value || loadResult.value.error) return 'normal'
  const s = loadResult.value.status
  if (s === 'overload') return 'overload'
  if (s === 'underload') return 'underload'
  return 'normal'
})

onMounted(loadRefs)
</script>

<template>
  <div class="page">
    <UiPageHeader
      title="Загрузка"
      description="Расчёт загрузки за период по часам задач Bitrix24 (ответственный = специалист с указанным Bitrix24 User ID)."
    />

    <UiAlert v-if="error" variant="error">{{ error }}</UiAlert>

    <section class="dashboard-filter">
      <UiRadio v-model="loadType" name="loadType" value="specialist">Специалист</UiRadio>
      <UiRadio v-model="loadType" name="loadType" value="department">Отдел</UiRadio>
      <label v-if="loadType === 'specialist'" class="dashboard-filter__label">
        <UiSelect v-model="specialistId" style="min-width: 160px">
          <option value="">— выберите —</option>
          <option v-for="s in specialists" :key="s.id" :value="s.id">{{ s.name }}</option>
        </UiSelect>
      </label>
      <label v-else class="dashboard-filter__label">
        <UiSelect v-model="departmentId" style="min-width: 160px">
          <option value="">— выберите —</option>
          <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
        </UiSelect>
      </label>
      <label class="dashboard-filter__label">С <UiInput v-model="dateFrom" type="date" /></label>
      <label class="dashboard-filter__label">По <UiInput v-model="dateTo" type="date" /></label>
      <UiButton variant="primary" :disabled="loading" @click="loadLoad">
        {{ loading ? 'Расчёт…' : 'Рассчитать' }}
      </UiButton>
    </section>

    <UiCard v-if="loadResult && !loadResult.error" tag="section" class="dashboard-result">
      <h2 class="dashboard-result__title">Результат</h2>
      <p><strong>Период:</strong> {{ loadResult.date_from }} — {{ loadResult.date_to }}</p>
      <p v-if="loadResult.specialist_name"><strong>Специалист:</strong> {{ loadResult.specialist_name }}</p>
      <p v-if="loadResult.department_name"><strong>Отдел:</strong> {{ loadResult.department_name }}</p>
      <p><strong>Часы (план):</strong> {{ loadResult.hours_plan }} <UiMuted>(регулярка {{ loadResult.hours_plan_regular ?? 0 }} / флайт {{ loadResult.hours_plan_flight ?? 0 }})</UiMuted></p>
      <p><strong>Часы (задачи B24):</strong> {{ loadResult.hours_tasks }} <UiMuted>(регулярка {{ loadResult.hours_tasks_regular ?? 0 }} / флайт {{ loadResult.hours_tasks_flight ?? 0 }})</UiMuted></p>
      <p><strong>Всего часов:</strong> {{ loadResult.hours_total }} <UiMuted>(регулярка {{ loadResult.hours_regular ?? 0 }} / флайт {{ loadResult.hours_flight ?? 0 }})</UiMuted></p>
      <p v-if="loadResult.norm_hours != null"><strong>Норма за период:</strong> {{ loadResult.norm_hours }}</p>
      <p v-if="loadResult.flight_limit != null"><strong>Лимит флайт за период:</strong> {{ loadResult.flight_limit }} ч</p>
      <p v-if="loadResult.flight_limit_exceeded"><strong><UiStatus variant="overload">Превышен лимит часов по флайтам</UiStatus></strong></p>
      <p><strong>Статус загрузки:</strong> <UiStatus :variant="statusVariant" /></p>
      <div v-if="loadResult.specialists && loadResult.specialists.length" class="dashboard-result__table-wrap">
        <h3 class="dashboard-result__subtitle">По специалистам отдела</h3>
        <UiTable>
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
                <UiStatus v-if="s.flight_limit_exceeded" variant="overload" title="Превышен лимит флайтов"> ⚠</UiStatus>
              </td>
              <td>{{ s.norm_hours ?? '—' }}</td>
              <td>
                <UiStatus :variant="s.status === 'overload' ? 'overload' : s.status === 'underload' ? 'underload' : 'normal'" />
              </td>
            </tr>
          </tbody>
        </UiTable>
      </div>
    </UiCard>
  </div>
</template>

<style lang="scss" scoped>
.dashboard-filter {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: center;
  margin-bottom: 1.5rem;
}
.dashboard-filter__label {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.9rem;
}
.dashboard-result__title {
  margin: 0 0 0.75rem;
  font-size: 1.1rem;
}
.dashboard-result p {
  margin: 0.35rem 0;
}
.dashboard-result__table-wrap {
  margin-top: 1rem;
}
.dashboard-result__subtitle {
  margin: 0 0 0.5rem;
  font-size: 1rem;
}
.dashboard-result__table-wrap .ui-table-wrap {
  margin-top: 0.5rem;
}
.dashboard-result__table-wrap :deep(.ui-table) {
  font-size: 0.9rem;
}
.dashboard-result__table-wrap :deep(th),
.dashboard-result__table-wrap :deep(td) {
  padding: 0.4rem 0.6rem;
}
</style>
