<script setup>
import { ref, onMounted, computed, watch, onBeforeUnmount, nextTick } from 'vue'
import { Chart } from 'chart.js/auto'
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
} from '../components/ui'

const chartCanvasRef = ref(null)
let chartInstance = null
const lineChartCanvasRef = ref(null)
let lineChartInstance = null
const loadChartData = ref(null)
const loadChartLoading = ref(false)

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

/** По умолчанию: последние 60 дней и будущие 30 дней от сегодня (как в планировании) */
function defaultPeriod() {
  const now = new Date()
  const start = new Date(now)
  start.setDate(start.getDate() - 60)
  const end = new Date(now)
  end.setDate(end.getDate() + 30)
  dateFrom.value = start.toISOString().slice(0, 10)
  dateTo.value = end.toISOString().slice(0, 10)
}

async function loadRefs() {
  try {
    const [specRes, depRes, settingsRes] = await Promise.all([
      api.specialists.list(),
      api.departments.list(),
      api.integrationSettings.get().catch(() => ({})),
    ])
    specialists.value = specRes.items || []
    departments.value = depRes.items || []
    if (!dateFrom.value || !dateTo.value) defaultPeriod()
    const defaultDepId = settingsRes.planning_default_department_id
    if (defaultDepId && departments.value.some((d) => Number(d.id) === Number(defaultDepId))) {
      loadType.value = 'department'
      departmentId.value = defaultDepId
    } else {
      loadType.value = 'specialist'
      specialistId.value = ''
      departmentId.value = ''
    }
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
    loadChartData.value = null
    loadChartLoading.value = true
    try {
      const chartRes = await api.loadChart(params)
      if (!chartRes.error && chartRes.labels && chartRes.datasets) {
        loadChartData.value = { labels: chartRes.labels, datasets: chartRes.datasets }
      }
    } finally {
      loadChartLoading.value = false
    }
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

function buildChartData() {
  const r = loadResult.value
  if (!r || r.error) return null
  const labels = ['Запланировано', 'Выработано']
  const data = [Number(r.hours_plan) || 0, Number(r.hours_tasks) || 0]
  if (r.norm_hours != null) {
    labels.push('Норма за период')
    data.push(Number(r.norm_hours))
  }
  return { labels, data }
}

function updateChart() {
  if (!chartCanvasRef.value) return
  const payload = buildChartData()
  if (!payload) return
  if (chartInstance) {
    chartInstance.destroy()
    chartInstance = null
  }
  chartInstance = new Chart(chartCanvasRef.value, {
    type: 'bar',
    data: {
      labels: payload.labels,
      datasets: [
        {
          label: 'Часы',
          data: payload.data,
          backgroundColor: [
            'rgba(59, 130, 246, 0.7)',
            'rgba(34, 197, 94, 0.7)',
            'rgba(148, 163, 184, 0.7)',
          ],
          borderColor: ['rgb(59, 130, 246)', 'rgb(34, 197, 94)', 'rgb(148, 163, 184)'],
          borderWidth: 1,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx) => ` ${ctx.raw} ч`,
          },
        },
      },
      scales: {
        y: {
          beginAtZero: true,
          title: { display: true, text: 'Часы' },
          ticks: { callback: (v) => v + ' ч' },
        },
      },
    },
  })
}

const LINE_CHART_COLORS = [
  'rgb(59, 130, 246)',
  'rgb(34, 197, 94)',
  'rgb(234, 88, 12)',
  'rgb(168, 85, 247)',
  'rgb(236, 72, 153)',
  'rgb(14, 165, 233)',
  'rgb(132, 204, 22)',
  'rgb(251, 146, 60)',
  'rgb(99, 102, 241)',
  'rgb(20, 184, 166)',
]

function formatChartDate(ymd) {
  if (!ymd || ymd.length < 10) return ymd
  const [y, m, d] = ymd.split('-')
  return `${d}.${m}`
}

function updateLineChart() {
  if (!lineChartCanvasRef.value || !loadChartData.value) return
  const { labels, datasets } = loadChartData.value
  if (!labels.length) return
  if (lineChartInstance) {
    lineChartInstance.destroy()
    lineChartInstance = null
  }
  const chartDatasets = datasets.map((ds, i) => ({
    label: ds.specialist_name || `Специалист ${i + 1}`,
    data: ds.data,
    borderColor: LINE_CHART_COLORS[i % LINE_CHART_COLORS.length],
    backgroundColor: 'transparent',
    borderWidth: 2,
    tension: 0.2,
    pointRadius: labels.length > 31 ? 0 : 2,
  }))
  lineChartInstance = new Chart(lineChartCanvasRef.value, {
    type: 'line',
    data: {
      labels: labels.map(formatChartDate),
      datasets: chartDatasets,
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top' },
        tooltip: {
          callbacks: {
            label: (ctx) => ` ${ctx.dataset.label}: ${ctx.raw} ч`,
          },
        },
      },
      scales: {
        y: {
          beginAtZero: true,
          title: { display: true, text: 'Часы' },
          ticks: { callback: (v) => v + ' ч' },
        },
        x: {
          title: { display: true, text: 'Дата' },
          ticks: {
            maxRotation: 45,
            maxTicksLimit: labels.length > 31 ? 15 : 25,
          },
        },
      },
    },
  })
}

watch(loadResult, async (val) => {
  if (chartInstance) {
    chartInstance.destroy()
    chartInstance = null
  }
  if (val && !val.error) {
    await nextTick()
    updateChart()
  }
}, { immediate: false })

watch(loadChartData, async (val) => {
  if (lineChartInstance) {
    lineChartInstance.destroy()
    lineChartInstance = null
  }
  if (val && val.labels?.length) {
    await nextTick()
    updateLineChart()
  }
}, { immediate: false })

onMounted(loadRefs)
onBeforeUnmount(() => {
  if (chartInstance) {
    chartInstance.destroy()
    chartInstance = null
  }
  if (lineChartInstance) {
    lineChartInstance.destroy()
    lineChartInstance = null
  }
})
</script>

<template>
  <div class="page">
    <UiPageHeader
      title="Нагрузка"
      description="Расчёт нагрузки за период по часам задач Bitrix24 (ответственный = специалист с указанным Bitrix24 User ID)."
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
      <p><strong>Запланировано:</strong> {{ loadResult.hours_plan }} <span class="dashboard-result__hint">— план нагрузки из Битрикс, при переплане учитывается переплан</span></p>
      <p><strong>Выработано:</strong> {{ loadResult.hours_tasks }} <span class="dashboard-result__hint">— факт</span></p>
      <p v-if="loadResult.norm_hours != null"><strong>Норма за период:</strong> {{ loadResult.norm_hours }}</p>
      <p><strong>Статус загрузки:</strong> <UiStatus :variant="statusVariant" /></p>
      <div class="dashboard-result__chart-wrap">
        <canvas ref="chartCanvasRef"></canvas>
      </div>
      <div class="dashboard-result__line-chart-section">
        <h3 class="dashboard-result__subtitle">Нагрузка по дням (план)</h3>
        <p v-if="loadChartLoading" class="dashboard-result__chart-loading">Загрузка графика…</p>
        <div v-else-if="loadChartData?.labels?.length" class="dashboard-result__line-chart-wrap">
          <canvas ref="lineChartCanvasRef"></canvas>
        </div>
      </div>
      <div v-if="loadResult.specialists && loadResult.specialists.length" class="dashboard-result__table-wrap">
        <h3 class="dashboard-result__subtitle">По специалистам отдела</h3>
        <UiTable>
          <thead>
            <tr>
              <th>Специалист</th>
              <th>Запланировано</th>
              <th>Выработано</th>
              <th>Норма</th>
              <th>Статус</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in loadResult.specialists" :key="s.specialist_id">
              <td>{{ s.specialist_name }}</td>
              <td>{{ s.hours_plan }}</td>
              <td>{{ s.hours_tasks }}</td>
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
.dashboard-result__hint {
  font-size: 0.85em;
  color: var(--color-muted, #64748b);
}
.dashboard-result__chart-wrap {
  margin-top: 1.5rem;
  height: 260px;
  position: relative;
}
.dashboard-result__chart-wrap canvas {
  max-width: 100%;
}
.dashboard-result__line-chart-section {
  margin-top: 2rem;
}
.dashboard-result__line-chart-section .dashboard-result__subtitle {
  margin-bottom: 0.5rem;
}
.dashboard-result__chart-loading {
  margin: 0.5rem 0;
  color: var(--color-muted, #64748b);
  font-size: 0.9rem;
}
.dashboard-result__line-chart-wrap {
  height: 320px;
  position: relative;
}
.dashboard-result__line-chart-wrap canvas {
  max-width: 100%;
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
