<script setup>
import { ref, onMounted, onUnmounted, computed, nextTick, watch } from 'vue'
import { api } from '../api/client'
import {
  UiPageHeader,
  UiAlert,
  UiLoading,
  UiRadio,
  UiSelect,
  UiMultiSelect,
  UiInput,
  UiCheckbox,
  UiButton,
  UiModal,
  UiMuted,
} from '../components/ui'
import FilterDropdown from '../components/FilterDropdown.vue'

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
/** Скрыть задачи без планового времени (time_estimate); по умолчанию включено */
const hideTasksWithoutPlan = ref(true)
/** Фильтр по пользовательскому полю: field_code из каталога */
const filterUfFieldCode = ref('')
/** Значение поля для фильтра (например "1" — да, "0" — нет) */
const filterUfValue = ref('')
/** Каталог UF-полей (подписи и список для фильтра) */
const taskUfCatalog = ref([])
/** Группы задач B24 (проекты) для фильтра */
const taskGroups = ref([])
/** Выбранные группы для фильтра (по умолчанию все); пустой массив = все группы */
const selectedGroupIds = ref([])

const specialistOptions = computed(() =>
  (specialists.value || []).map((s) => ({ value: s.id, label: s.name || String(s.id) }))
)
const groupOptions = computed(() =>
  (taskGroups.value || []).map((g) => ({
    value: g.bitrix24_group_id,
    label: g.name || String(g.bitrix24_group_id),
  }))
)

/** Активность фильтров для индикатора на кнопках dropdown */
const periodFilterActive = computed(() => !!(dateFrom.value && dateTo.value))
const employeesFilterActive = computed(() =>
  scopeType.value === 'specialist'
    ? Array.isArray(specialistIds.value) && specialistIds.value.length > 0
    : !!departmentId.value
)
const groupsFilterActive = computed(() => {
  const total = taskGroups.value?.length ?? 0
  const selected = selectedGroupIds.value?.length ?? 0
  return total > 0 && selected > 0 && selected < total
})
const extraFilterActive = computed(
  () => !hideTasksWithoutPlan.value || (!!filterUfFieldCode.value && filterUfValue.value !== '')
)

/** Ref контейнера скролла таблицы (для автоскролла до первого заполненного дня) */
const tableScrollWrapRef = ref(null)
/** Ref блока над таблицей (заголовок страницы + фильтры + заголовок секции) для расчёта высоты таблицы */
const aboveTableRef = ref(null)
/** Высота области таблицы: 100vh минус блок над таблицей (в px) */
const tableHeightPx = ref(400)

/** Модалка переплана */
const showReplanModal = ref(false)
const replanTaskId = ref('')
const replanTaskTitle = ref('')
const taskPlanData = ref(null)
const taskPlanLoading = ref(false)
const taskPlanSaving = ref(false)
const taskPlanError = ref(null)
const replanForm = ref({
  plan_start_date: '',
  plan_end_date: '',
  hoursByDate: {}, // date -> string (input value)
})

/** Черновики плана по задачам для редактирования в сетке: taskId -> { [date]: hours } */
const draftPlanByTask = ref({})
/** Сохранение черновиков в процессе */
const applyPlanSaving = ref(false)
/** Ошибка при сохранении */
const applyPlanError = ref(null)

/** По умолчанию: последние 60 дней и будущие 30 дней от сегодня */
function defaultPeriod() {
  const now = new Date()
  const start = new Date(now)
  start.setDate(start.getDate() - 60)
  const end = new Date(now)
  end.setDate(end.getDate() + 30)
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

/** Задачи для таблицы: при включённой галочке — только с плановым временем (time_estimate > 0) */
const filteredTasks = computed(() => {
  const list = gridData.value?.tasks || []
  if (!hideTasksWithoutPlan.value) return list
  return list.filter((t) => {
    const v = t.time_estimate
    if (v == null) return false
    const n = Number(v)
    return n > 0
  })
})

/** Есть ли в этот день хотя бы одно заполненное значение (план или факт по любой задаче) */
function hasFilledValueForDay(day) {
  for (const t of filteredTasks.value) {
    if (planHoursForTaskDay(t, day)) return true
    if (hoursForTaskDay(t.bitrix24_task_id, day)) return true
  }
  return false
}

/** Первая дата в периоде с заполненной ячейкой (план или факт) */
const firstFilledDate = computed(() => {
  const list = days.value || []
  return list.find((d) => hasFilledValueForDay(d)) ?? null
})

/** Стиль контейнера таблицы: фиксированная высота под viewport минус шапка */
const tableScrollWrapStyle = computed(() => ({ height: `${tableHeightPx.value}px` }))

function hoursForTaskDay(taskId, date) {
  const key = `${taskId}_${date}`
  const minutes = elapsedByTaskDate.value[key] || 0
  if (minutes === 0) return ''
  const h = (minutes / 60).toFixed(1)
  return h === '0.0' ? '' : h
}

/** Количество подстрок по задаче: всегда 2 (План/Переплан и Факт); при переплане строка плана показывает переплан. */
function taskRowCount(task) {
  return 2
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

/** Индексы дней периода, являющихся рабочими (не выходные) */
function getWorkdayIndices() {
  const dayList = days.value || []
  return dayList.map((d, i) => (isWeekend(d) ? -1 : i)).filter((i) => i >= 0)
}

/** Ближайший рабочий день: для выходного — предыдущий (preferLeft) или следующий рабочий день */
function snapToWorkday(dayIndex, preferLeft = true) {
  const dayList = days.value || []
  if (dayIndex < 0) return 0
  if (dayIndex >= dayList.length) return dayList.length - 1
  if (!isWeekend(dayList[dayIndex])) return dayIndex
  if (preferLeft) {
    for (let i = dayIndex - 1; i >= 0; i--) if (!isWeekend(dayList[i])) return i
    return 0
  }
  for (let i = dayIndex + 1; i < dayList.length; i++) if (!isWeekend(dayList[i])) return i
  return dayList.length - 1
}

/** Общее плановое время задачи. В БД time_estimate хранится в минутах → часы = / 60. Формат: целое (36, 102) или один знак (0.5). */
function planHours(task) {
  if (task.time_estimate == null) return '—'
  const v = Number(task.time_estimate)
  const hours = v / 60
  const rounded = Math.round(hours * 10) / 10
  return rounded % 1 === 0 ? String(Math.round(rounded)) : rounded.toFixed(1)
}

/** Отображаемые плановые часы по задаче: черновик или данные с сервера */
function getDisplayPlanHoursByDate(task) {
  const taskId = String(task.bitrix24_task_id)
  if (draftPlanByTask.value[taskId]) return draftPlanByTask.value[taskId]
  return task.plan_hours_by_date || {}
}

/** Плановые часы по дню (скорректированный план из API plan_hours_by_date); в ячейке показываем с одним знаком. */
function planHoursForTaskDay(task, date) {
  const byDate = getDisplayPlanHoursByDate(task)
  const h = byDate[date]
  if (h == null || h === 0) return ''
  const n = Number(h)
  return n === 0 ? '' : (Math.round(n * 10) / 10).toFixed(1).replace(/\.0$/, '')
}

/** Начальный и конечный индекс дней с плановыми часами по задаче; null если нет часов */
function planBarRange(task) {
  const byDate = getDisplayPlanHoursByDate(task)
  const dayList = days.value || []
  let startIdx = -1
  let endIdx = -1
  for (let i = 0; i < dayList.length; i++) {
    const h = byDate[dayList[i]]
    if (h != null && Number(h) > 0) {
      if (startIdx < 0) startIdx = i
      endIdx = i
    }
  }
  if (startIdx < 0) return null
  return { startIndex: startIdx, endIndex: endIdx, startDate: dayList[startIdx], endDate: dayList[endIdx] }
}

/** Диапазон полосы по черновику задачи (для перетаскивания — текущая позиция из draft) */
function planBarRangeFromDraft(taskId) {
  const byDate = draftPlanByTask.value[taskId]
  if (!byDate) return null
  const dayList = days.value || []
  let startIdx = -1
  let endIdx = -1
  for (let i = 0; i < dayList.length; i++) {
    const h = byDate[dayList[i]]
    if (h != null && Number(h) > 0) {
      if (startIdx < 0) startIdx = i
      endIdx = i
    }
  }
  if (startIdx < 0) return null
  return { startIndex: startIdx, endIndex: endIdx }
}

/** Часы по дням в диапазоне полосы (для отображения в полосе по каждому дню) */
function planBarHoursByDayInRange(task) {
  const range = planBarRange(task)
  if (!range) return []
  const byDate = getDisplayPlanHoursByDate(task)
  const dayList = days.value || []
  const result = []
  for (let i = range.startIndex; i <= range.endIndex; i++) {
    const date = dayList[i]
    const h = byDate[date]
    const hours = h != null && Number(h) > 0 ? Number(h) : 0
    result.push({ date, hours, dayIndex: i })
  }
  return result
}

/** Исходные плановые часы по дню (original_plan_hours_by_date, только при переплане) */
function originalPlanHoursForTaskDay(task, date) {
  const byDate = task.original_plan_hours_by_date || {}
  const h = byDate[date]
  if (h == null || h === 0) return ''
  const n = Number(h)
  return n === 0 ? '' : (Math.round(n * 10) / 10).toFixed(1).replace(/\.0$/, '')
}

/** Сумма плановых часов за период по задаче (переплан или авто). Итог округляем до 2 знаков. */
function planHoursTotalInPeriod(task) {
  const byDate = getDisplayPlanHoursByDate(task)
  const dayList = days.value || []
  let sum = 0
  for (const d of dayList) {
    const h = byDate[d]
    if (h != null) sum += Number(h)
  }
  if (sum <= 0) return planHours(task) !== '—' ? planHours(task) : '—'
  const rounded = Math.round(sum * 100) / 100
  return rounded.toFixed(rounded % 1 === 0 ? 0 : 1)
}

/** Сумма исходных плановых часов за период (только при переплане); итог округляем как в planHoursTotalInPeriod. */
function originalPlanHoursTotalInPeriod(task) {
  const byDate = task.original_plan_hours_by_date || {}
  const dayList = days.value || []
  let sum = 0
  for (const d of dayList) {
    const h = byDate[d]
    if (h != null) sum += Number(h)
  }
  if (sum <= 0) return '—'
  const rounded = Math.round(sum * 100) / 100
  return rounded.toFixed(rounded % 1 === 0 ? 0 : 1)
}

/** Фактические часы по задаче: в БД минуты → часы = / 60 */
function factHoursTotal(task) {
  if (task.time_spent == null) return '—'
  const v = Number(task.time_spent)
  const hours = v / 60
  return hours.toFixed(1)
}

async function loadRefs() {
  try {
    const [specRes, depRes, catalogRes, groupsRes, settingsRes] = await Promise.all([
      api.specialists.list(),
      api.departments.list(),
      api.taskUfCatalog.list().catch(() => ({ items: [] })),
      api.taskGroups.list().catch(() => ({ items: [] })),
      api.integrationSettings.get().catch(() => ({})),
    ])
    specialists.value = specRes.items || []
    departments.value = depRes.items || []
    taskUfCatalog.value = catalogRes.items || []
    taskGroups.value = groupsRes.items || []
    const defaultGroupIds = settingsRes.planning_default_group_ids
    if (Array.isArray(defaultGroupIds) && defaultGroupIds.length > 0) {
      selectedGroupIds.value = defaultGroupIds.map(String)
    } else if (selectedGroupIds.value.length === 0 && taskGroups.value.length > 0) {
      selectedGroupIds.value = taskGroups.value.map((g) => g.bitrix24_group_id)
    }
    if (!dateFrom.value || !dateTo.value) defaultPeriod()

    const defaultDepId = settingsRes.planning_default_department_id
    if (defaultDepId && departments.value.some((d) => Number(d.id) === Number(defaultDepId))) {
      scopeType.value = 'department'
      departmentId.value = defaultDepId
      await loadGrid()
    }
  } catch (e) {
    error.value = e.message
  }
}

/** Сброс фильтров до значений из настроек по умолчанию */
const resetFiltersLoading = ref(false)
async function resetFilters() {
  resetFiltersLoading.value = true
  error.value = null
  try {
    const settingsRes = await api.integrationSettings.get().catch(() => ({}))
    defaultPeriod()
    const defaultGroupIds = settingsRes.planning_default_group_ids
    if (Array.isArray(defaultGroupIds) && defaultGroupIds.length > 0) {
      selectedGroupIds.value = defaultGroupIds.map(String)
    } else if (taskGroups.value?.length) {
      selectedGroupIds.value = taskGroups.value.map((g) => g.bitrix24_group_id)
    } else {
      selectedGroupIds.value = []
    }
    const defaultDepId = settingsRes.planning_default_department_id
    if (defaultDepId && departments.value?.some((d) => Number(d.id) === Number(defaultDepId))) {
      scopeType.value = 'department'
      departmentId.value = defaultDepId
      specialistIds.value = []
    } else {
      scopeType.value = 'specialist'
      departmentId.value = ''
      specialistIds.value = []
    }
    hideTasksWithoutPlan.value = true
    filterUfFieldCode.value = ''
    filterUfValue.value = ''
    if (scopeType.value === 'department' && departmentId.value) {
      await loadGrid()
    }
  } catch (e) {
    error.value = e.message
  } finally {
    resetFiltersLoading.value = false
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
    if (filterUfFieldCode.value && filterUfValue.value !== '') {
      params.filter_uf_field_code = filterUfFieldCode.value
      params.filter_uf_value = filterUfValue.value
    }
    const totalGroups = taskGroups.value.length
    const selected = selectedGroupIds.value || []
    if (totalGroups > 0 && selected.length > 0 && selected.length < totalGroups) {
      params.group_ids = selected.join(',')
    }
    gridData.value = await api.planningGrid(params)
    if (gridData.value?.task_uf_catalog?.length) taskUfCatalog.value = gridData.value.task_uf_catalog
    await nextTick()
    scrollToFirstFilledColumn()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

/** Скроллит таблицу по горизонтали до первого дня с заполненной ячейкой (план или факт). Учитывает ширину зафиксированных колонок (Задача, Специалист, Часы). */
function scrollToFirstFilledColumn() {
  const date = firstFilledDate.value
  if (!date) return
  const wrap = tableScrollWrapRef.value
  if (!wrap) return
  const th = wrap.querySelector(`th.th-day[data-date="${date}"]`)
  if (!th) return
  const lastFixed = wrap.querySelector('th.th-hours')
  if (!lastFixed) return
  const wrapRect = wrap.getBoundingClientRect()
  const thRect = th.getBoundingClientRect()
  const fixedRight = lastFixed.getBoundingClientRect().right
  const fixedWidth = fixedRight - wrapRect.left
  const scrollDelta = thRect.left - (wrapRect.left + fixedWidth)
  if (scrollDelta > 0) wrap.scrollLeft += scrollDelta
}

/** Дни для модалки переплана (период сетки) */
const replanModalDays = computed(() => {
  const from = dateFrom.value || gridData.value?.date_from
  const to = dateTo.value || gridData.value?.date_to
  if (!from || !to) return []
  return getDaysBetween(from, to)
})

function openReplanModal(task) {
  replanTaskId.value = task.bitrix24_task_id
  replanTaskTitle.value = (task.title || '').slice(0, 60) + ((task.title || '').length > 60 ? '…' : '')
  taskPlanData.value = null
  taskPlanError.value = null
  replanForm.value = { plan_start_date: '', plan_end_date: '', hoursByDate: {} }
  showReplanModal.value = true
  taskPlanLoading.value = true
  api.taskPlan
    .get(task.bitrix24_task_id)
    .then((data) => {
      taskPlanData.value = data
      const r = data.replanned || {}
      replanForm.value.plan_start_date = r.plan_start || ''
      replanForm.value.plan_end_date = r.plan_end || ''
      const byDate = r.plan_hours_by_date || {}
      const next = {}
      for (const d of replanModalDays.value) {
        const h = byDate[d]
        next[d] = h != null && h !== '' ? String(h) : ''
      }
      replanForm.value.hoursByDate = next
    })
    .catch((e) => {
      taskPlanError.value = e.message
    })
    .finally(() => {
      taskPlanLoading.value = false
    })
}

function closeReplanModal() {
  showReplanModal.value = false
  replanTaskId.value = ''
  taskPlanData.value = null
  taskPlanError.value = null
}

function getReplanHoursForDay(date) {
  return replanForm.value.hoursByDate[date] ?? ''
}

function setReplanHoursForDay(date, value) {
  const next = { ...replanForm.value.hoursByDate }
  next[date] = value
  replanForm.value = { ...replanForm.value, hoursByDate: next }
}

function originalHoursLabel(taskPlan) {
  if (!taskPlan?.original) return '—'
  const o = taskPlan.original
  const est = o.time_estimate
  const h = Number(est) / 60
  return `${o.plan_start || '—'} … ${o.plan_end || '—'}, ${h.toFixed(1)} ч`
}

async function saveReplan() {
  taskPlanSaving.value = true
  taskPlanError.value = null
  const planHoursByDate = {}
  for (const d of replanModalDays.value) {
    const v = replanForm.value.hoursByDate[d]
    const num = v === '' ? 0 : parseFloat(String(v).replace(',', '.'))
    if (!Number.isNaN(num) && num >= 0) {
      planHoursByDate[d] = num
    }
  }
  try {
    await api.taskPlan.save({
      bitrix24_task_id: replanTaskId.value,
      plan_start_date: replanForm.value.plan_start_date || undefined,
      plan_end_date: replanForm.value.plan_end_date || undefined,
      plan_hours_by_date: planHoursByDate,
    })
    delete draftPlanByTask.value[String(replanTaskId.value)]
    closeReplanModal()
    await loadGrid()
  } catch (e) {
    taskPlanError.value = e.message
  } finally {
    taskPlanSaving.value = false
  }
}

/** Ширина одной колонки дня в таймлайне (rem) */
const DAY_CELL_WIDTH_REM = 2.5

/** Состояние перетаскивания полосы плана */
const planBarDragState = ref(null)
/** Состояние изменения размера полосы (левая/правая граница) */
const planBarResizeState = ref(null)

function ensureDraftForTask(task) {
  const taskId = String(task.bitrix24_task_id)
  if (!draftPlanByTask.value[taskId]) {
    const current = getDisplayPlanHoursByDate(task)
    draftPlanByTask.value[taskId] = { ...current }
  }
  return String(task.bitrix24_task_id)
}

/**
 * Позиционирование полосы по курсору: точка внутри полосы (offsetRatio 0..1) остаётся под курсором.
 * Так не накапливается рассинхрон при перескоке через выходные.
 */
function applyMoveToDraftFromCursor(taskId, timelineEl, clientX, offsetInBarRatio) {
  const range = planBarRangeFromDraft(taskId)
  if (!range) return
  const dayList = days.value || []
  const len = dayList.length
  const workdayIndices = getWorkdayIndices()
  if (!timelineEl || len === 0 || workdayIndices.length === 0) return
  const rect = timelineEl.getBoundingClientRect()
  const dayWidthPx = rect.width / len
  const barLen = range.endIndex - range.startIndex + 1
  const barWidthPx = barLen * dayWidthPx
  const cursorXInTimeline = clientX - rect.left
  const barLeftPx = cursorXInTimeline - offsetInBarRatio * barWidthPx
  const targetDayIndex = barLeftPx / dayWidthPx
  const newStartDayIndex = Math.max(0, Math.min(targetDayIndex, len - barLen))
  const newStartSnapped = snapToWorkday(Math.floor(newStartDayIndex), true)
  let newStartBarWorkdayIdx = workdayIndices.findIndex((wd) => wd >= newStartSnapped)
  if (newStartBarWorkdayIdx < 0) newStartBarWorkdayIdx = workdayIndices.length - 1
  const current = { ...draftPlanByTask.value[taskId] }
  const barWeekdayIndices = workdayIndices.filter((wd) => wd >= range.startIndex && wd <= range.endIndex)
  if (barWeekdayIndices.length === 0) return
  newStartBarWorkdayIdx = Math.max(0, Math.min(newStartBarWorkdayIdx, workdayIndices.length - barWeekdayIndices.length))
  const hoursInOrder = barWeekdayIndices.map((wd) => {
    const h = current[dayList[wd]]
    return h != null ? Number(h) : 0
  })
  const newByDate = {}
  for (let i = 0; i < barWeekdayIndices.length; i++) {
    const newDayIndex = workdayIndices[newStartBarWorkdayIdx + i]
    if (newDayIndex != null && !isWeekend(dayList[newDayIndex])) {
      newByDate[dayList[newDayIndex]] = Math.round(hoursInOrder[i] * 10) / 10
    }
  }
  draftPlanByTask.value[taskId] = newByDate
}

/** Обновить черновик плана: изменить границы; часы только на рабочие дни, перераспределение по рабочим дням нового диапазона */
function applyResizeToDraft(taskId, range, newStartIndex, newEndIndex) {
  const dayList = days.value || []
  const workdayIndices = getWorkdayIndices()
  if (workdayIndices.length === 0) return
  const current = { ...draftPlanByTask.value[taskId] }
  let totalHours = 0
  for (let i = range.startIndex; i <= range.endIndex; i++) {
    if (!isWeekend(dayList[i])) {
      const h = current[dayList[i]]
      if (h != null) totalHours += Number(h)
    }
  }
  const newRangeWeekdays = workdayIndices.filter((wd) => wd >= newStartIndex && wd <= newEndIndex)
  if (newRangeWeekdays.length <= 0) return
  const hoursPerDay = totalHours / newRangeWeekdays.length
  const newByDate = {}
  for (const wd of newRangeWeekdays) {
    newByDate[dayList[wd]] = Math.round(hoursPerDay * 10) / 10
  }
  draftPlanByTask.value[taskId] = newByDate
}

function onPlanBarMouseDown(task, event, edge) {
  if (event.button !== 0) return
  event.preventDefault()
  const taskId = ensureDraftForTask(task)
  const range = planBarRange(task)
  if (!range) return
  const timelineEl = event.target.closest('.timeline-wrap')
  if (edge === 'move') {
    const rect = timelineEl.getBoundingClientRect()
    const len = days.value?.length || 1
    const dayWidthPx = rect.width / len
    const barLeftPx = (range.startIndex / len) * rect.width
    const barWidthPx = ((range.endIndex - range.startIndex + 1) / len) * rect.width
    const clickXInTimeline = event.clientX - rect.left
    let offsetInBarRatio = barWidthPx > 0 ? (clickXInTimeline - barLeftPx) / barWidthPx : 0
    offsetInBarRatio = Math.max(0, Math.min(1, offsetInBarRatio))
    document.body.style.cursor = 'grabbing'
    document.body.style.userSelect = 'none'
    planBarDragState.value = {
      taskId,
      task,
      timelineEl,
      offsetInBarRatio,
    }
  } else {
    document.body.style.cursor = 'col-resize'
    document.body.style.userSelect = 'none'
    planBarResizeState.value = {
      taskId,
      task,
      timelineEl,
      startRange: { startIndex: range.startIndex, endIndex: range.endIndex },
      startClientX: event.clientX,
      edge,
    }
  }
}

function onPlanBarDocumentMouseMove(event) {
  const dayList = days.value || []
  const len = dayList.length
  if (planBarDragState.value) {
    const { taskId, timelineEl, offsetInBarRatio } = planBarDragState.value
    applyMoveToDraftFromCursor(taskId, timelineEl, event.clientX, offsetInBarRatio)
  }
  if (planBarResizeState.value) {
    const { taskId, task, timelineEl, startRange, startClientX, edge } = planBarResizeState.value
    const workdayIndicesResize = getWorkdayIndices()
    if (!timelineEl || len === 0) return
    const rect = timelineEl.getBoundingClientRect()
    const dayWidth = rect.width / len
    const cursorDayIndex = Math.floor((event.clientX - rect.left) / dayWidth)
    const clamped = Math.max(0, Math.min(len - 1, cursorDayIndex))
    const snapped = edge === 'left' ? snapToWorkday(clamped, true) : snapToWorkday(clamped, false)
    let newStart = startRange.startIndex
    let newEnd = startRange.endIndex
    if (edge === 'left') {
      newStart = Math.min(snapped, startRange.endIndex)
      if (newEnd - newStart < 1) {
        const idx = workdayIndicesResize.indexOf(newStart)
        newEnd = workdayIndicesResize[idx + 1] ?? newEnd
      }
    } else {
      newEnd = Math.max(snapped, startRange.startIndex)
      if (newEnd - newStart < 1) {
        const idx = workdayIndicesResize.indexOf(newEnd)
        newStart = workdayIndicesResize[idx - 1] ?? newStart
      }
    }
    newStart = snapToWorkday(newStart, true)
    newEnd = snapToWorkday(newEnd, false)
    if (newEnd < newStart) [newStart, newEnd] = [newEnd, newStart]
    if (newStart !== startRange.startIndex || newEnd !== startRange.endIndex) {
      applyResizeToDraft(taskId, startRange, newStart, newEnd)
      planBarResizeState.value.startRange = { startIndex: newStart, endIndex: newEnd }
    }
  }
}

function onPlanBarDocumentMouseUp() {
  if (planBarDragState.value || planBarResizeState.value) {
    document.body.style.cursor = ''
    document.body.style.userSelect = ''
  }
  planBarDragState.value = null
  planBarResizeState.value = null
}

/** Есть ли несохранённые черновики */
const hasDraftPlans = computed(() => Object.keys(draftPlanByTask.value).length > 0)

async function applyDraftPlans() {
  if (!hasDraftPlans.value) return
  applyPlanSaving.value = true
  applyPlanError.value = null
  try {
    for (const [taskId, planHoursByDate] of Object.entries(draftPlanByTask.value)) {
      await api.taskPlan.save({
        bitrix24_task_id: taskId,
        plan_hours_by_date: planHoursByDate,
      })
    }
    draftPlanByTask.value = {}
    await loadGrid()
  } catch (e) {
    applyPlanError.value = e.message
  } finally {
    applyPlanSaving.value = false
  }
}

function updateTableHeight() {
  if (!aboveTableRef.value) return
  const rect = aboveTableRef.value.getBoundingClientRect()
  const margin = 24
  tableHeightPx.value = Math.max(200, window.innerHeight - rect.bottom - margin)
}

let resizeObserver = null
onMounted(() => {
  loadRefs()
  nextTick(() => {
    updateTableHeight()
    resizeObserver = new ResizeObserver(updateTableHeight)
    if (aboveTableRef.value) resizeObserver.observe(aboveTableRef.value)
  })
  window.addEventListener('resize', updateTableHeight)
  document.addEventListener('mousemove', onPlanBarDocumentMouseMove)
  document.addEventListener('mouseup', onPlanBarDocumentMouseUp)
})
watch(() => gridData.value, () => nextTick(updateTableHeight), { flush: 'post' })
onUnmounted(() => {
  window.removeEventListener('resize', updateTableHeight)
  document.removeEventListener('mousemove', onPlanBarDocumentMouseMove)
  document.removeEventListener('mouseup', onPlanBarDocumentMouseUp)
  if (resizeObserver && aboveTableRef.value) resizeObserver.unobserve(aboveTableRef.value)
  resizeObserver = null
})
</script>

<template>
  <div class="page">
    <div ref="aboveTableRef" class="planning-above-table">
      <UiPageHeader
        title="Планирование (сетка)"
        description="Сетка по задачам Bitrix24: у каждой задачи подстроки — План (светло-голубой), Факт (светло-оранжевый). При переплане ПМ в строке плана отображаются данные переплана. Колонки — дни. Запустите синхронизацию в настройках интеграции."
      />

      <UiAlert v-if="error" variant="error">{{ error }}</UiAlert>

      <section class="planning-filter">
        <div class="planning-filter__dropdowns">
          <FilterDropdown label="Период" :active="periodFilterActive">
            <div class="planning-filter-panel">
              <label class="planning-filter-panel__row">
                <span class="planning-filter-panel__label">С</span>
                <UiInput v-model="dateFrom" type="date" />
              </label>
              <label class="planning-filter-panel__row">
                <span class="planning-filter-panel__label">По</span>
                <UiInput v-model="dateTo" type="date" />
              </label>
            </div>
          </FilterDropdown>
          <FilterDropdown label="Сотрудники" :active="employeesFilterActive">
            <div class="planning-filter-panel">
              <div class="planning-filter-panel__row planning-filter-panel__row--radio">
                <UiRadio v-model="scopeType" name="scopeType" value="specialist">Специалисты</UiRadio>
                <UiRadio v-model="scopeType" name="scopeType" value="department">Отдел</UiRadio>
              </div>
              <template v-if="scopeType === 'specialist'">
                <div class="planning-filter-panel__row">
                  <UiMultiSelect
                    v-model="specialistIds"
                    :options="specialistOptions"
                    placeholder="Поиск и выбор…"
                    style="width: 100%; min-width: 200px"
                  />
                </div>
              </template>
              <div v-else class="planning-filter-panel__row">
                <UiSelect v-model="departmentId" style="width: 100%; min-width: 180px">
                  <option value="">— отдел —</option>
                  <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                </UiSelect>
              </div>
            </div>
          </FilterDropdown>
          <FilterDropdown v-if="groupOptions.length" label="Группы (проекты)" :active="groupsFilterActive">
            <div class="planning-filter-panel">
              <UiMultiSelect
                v-model="selectedGroupIds"
                :options="groupOptions"
                placeholder="Поиск и выбор…"
                style="width: 100%; min-width: 200px"
              />
              <UiMuted tag="p" class="planning-filter-panel__hint">Пусто — все группы</UiMuted>
            </div>
          </FilterDropdown>
          <FilterDropdown label="Дополнительно" :active="extraFilterActive">
            <div class="planning-filter-panel">
              <UiCheckbox v-model="hideTasksWithoutPlan" class="planning-filter-panel__checkbox">
                Скрыть задачи без планового времени
              </UiCheckbox>
              <template v-if="taskUfCatalog.length">
                <label class="planning-filter-panel__row">
                  <span class="planning-filter-panel__label">Фильтр по полю</span>
                  <UiSelect v-model="filterUfFieldCode" style="width: 100%">
                    <option value="">— не фильтровать —</option>
                    <option v-for="f in taskUfCatalog" :key="f.field_code" :value="f.field_code">
                      {{ f.label || f.field_code }}
                    </option>
                  </UiSelect>
                </label>
                <label v-if="filterUfFieldCode" class="planning-filter-panel__row">
                  <span class="planning-filter-panel__label">Значение</span>
                  <UiSelect v-model="filterUfValue" style="width: 100%">
                    <option value="">— любое —</option>
                    <option value="1">да (1)</option>
                    <option value="0">нет (0)</option>
                  </UiSelect>
                </label>
              </template>
            </div>
          </FilterDropdown>
        </div>
        <UiButton variant="outline" :disabled="resetFiltersLoading" class="planning-filter__reset" @click="resetFilters">
          {{ resetFiltersLoading ? 'Сброс…' : 'Сбросить' }}
        </UiButton>
        <UiButton variant="primary" :disabled="loading" class="planning-filter__submit" @click="loadGrid">
          {{ loading ? 'Загрузка…' : 'Показать сетку' }}
        </UiButton>
      </section>

      <template v-if="gridData && !gridData.error">
        <h2 class="grid-section__title">Задачи и учёт времени по дням</h2>
        <UiMuted tag="p" class="grid-section__period">Период: {{ gridData.date_from }} — {{ gridData.date_to }}</UiMuted>
        <UiAlert v-if="applyPlanError" variant="error">{{ applyPlanError }}</UiAlert>
        <p v-if="hasDraftPlans" class="planning-draft-actions">
          <UiButton variant="primary" :disabled="applyPlanSaving" @click="applyDraftPlans">
            {{ applyPlanSaving ? 'Сохранение…' : 'Применить изменения плана' }}
          </UiButton>
          <UiMuted>Несохранённые правки плановых полос (сдвиг/растягивание) будут сохранены на сервер.</UiMuted>
        </p>
      </template>
    </div>

    <section v-if="gridData && !gridData.error" class="grid-section">
      <div ref="tableScrollWrapRef" class="table-scroll-wrap" :style="tableScrollWrapStyle">
        <table class="grid-table">
          <thead>
            <tr>
              <th class="th-fixed th-task">Задача / тип</th>
              <th class="th-fixed th-spec">Специалист</th>
              <th class="th-fixed th-hours">Часы</th>
              <th v-for="day in days" :key="day" class="th-day" :class="{ 'th-day--weekend': isWeekend(day) }" :data-date="day">{{ day.slice(8, 10) }}.{{ day.slice(5, 7) }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="t in filteredTasks" :key="t.bitrix24_task_id">
              <!-- Подстрока План / Переплан (светло-голубой); при переплане отображаются данные переплана вместо исходного плана -->
              <tr class="row-plan" :class="{ 'row-plan--replan': t.has_plan_override }">
                <td :rowspan="taskRowCount(t)" class="td-fixed td-task" :title="t.title">
                  <a v-if="taskLink(t)" :href="taskLink(t)" target="_blank" rel="noopener noreferrer" class="task-link">{{ (t.title || '').slice(0, 40) }}{{ (t.title || '').length > 40 ? '…' : '' }}</a>
                  <span v-else>{{ (t.title || '').slice(0, 40) }}{{ (t.title || '').length > 40 ? '…' : '' }}</span>
                </td>
                <td :rowspan="taskRowCount(t)" class="td-fixed td-spec">{{ specialistNameByB24Id[t.responsible_user_id] || t.responsible_user_id || '—' }}</td>
                <td class="td-fixed td-hours">{{ t.has_plan_override ? 'Переплан: ' : 'План: ' }} 
                  {{ planHoursTotalInPeriod(t) }}
                  <button type="button" class="btn-replan" title="Изменить план в модальном окне" @click="openReplanModal(t)" aria-label="Изменить план">
                  <svg class="icon-pencil" viewBox="0 0 24 24" width="12" height="12" aria-hidden="true"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                </button>
                </td>
                <td :colspan="days.length" class="td-timeline-cell">
                  <div class="timeline-wrap" :style="{ width: (days.length * DAY_CELL_WIDTH_REM) + 'rem' }">
                    <div v-for="day in days" :key="day" class="timeline-day" :class="{ 'timeline-day--weekend': isWeekend(day) }" :data-date="day">{{ planHoursForTaskDay(t, day) || '—' }}</div>
                    <template v-if="planBarRange(t)">
                      <div
                        class="plan-bar"
                        :class="{ 'plan-bar--replan': t.has_plan_override }"
                        :style="{
                          left: (100 * planBarRange(t).startIndex / days.length) + '%',
                          width: (100 * (planBarRange(t).endIndex - planBarRange(t).startIndex + 1) / days.length) + '%'
                        }"
                        title="Тяните для сдвига, за края — для растягивания; двойной клик — редактирование в модалке"
                        @mousedown="onPlanBarMouseDown(t, $event, 'move')"
                        @dblclick="openReplanModal(t)"
                      >
                        <span class="plan-bar__resize plan-bar__resize--left" @mousedown.stop="onPlanBarMouseDown(t, $event, 'left')" @dblclick.stop></span>
                        <span class="plan-bar__segments">
                          <span
                            v-for="seg in planBarHoursByDayInRange(t)"
                            :key="seg.date"
                            class="plan-bar__segment"
                            :class="{ 'plan-bar__segment--weekend': isWeekend(seg.date) }"
                            :title="seg.date + ': ' + (seg.hours ? seg.hours : '0') + ' ч'"
                          >{{ seg.hours ? (seg.hours % 1 === 0 ? seg.hours : seg.hours.toFixed(1)) : '—' }}</span>
                        </span>
                        <span class="plan-bar__resize plan-bar__resize--right" @mousedown.stop="onPlanBarMouseDown(t, $event, 'right')" @dblclick.stop></span>
                      </div>
                    </template>
                  </div>
                </td>
              </tr>
              <!-- Подстрока Факт (светло-оранжевый); ячейки Задача и Специалист объединены сверху -->
              <tr class="row-fact">
                <td class="td-fixed td-hours">Факт: {{ factHoursTotal(t) }}</td>
                <td v-for="day in days" :key="day" class="td-day td-day--fact" :class="{ 'td-day--weekend': isWeekend(day), 'td-day--filled': hoursForTaskDay(t.bitrix24_task_id, day) }">{{ hoursForTaskDay(t.bitrix24_task_id, day) }}</td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
      <UiMuted v-if="filteredTasks.length === 0" tag="p">
        {{ gridData.tasks?.length === 0 ? 'Нет задач у выбранных специалистов за период. Запустите синхронизацию с Bitrix24.' : 'Нет задач с плановым временем. Снимите галочку «Скрыть задачи без планового времени», чтобы показать все.' }}
      </UiMuted>
    </section>

    <UiModal
      :show="showReplanModal"
      title="Переплан задачи"
      @close="closeReplanModal"
    >
      <p v-if="replanTaskTitle" class="replan-task-title">{{ replanTaskTitle }}</p>
      <UiAlert v-if="taskPlanError" variant="error">{{ taskPlanError }}</UiAlert>
      <UiLoading v-if="taskPlanLoading" />
      <template v-else-if="taskPlanData">
        <section class="replan-section">
          <h4>Исходный план (только чтение)</h4>
          <p class="replan-original">{{ originalHoursLabel(taskPlanData) }}</p>
        </section>
        <section class="replan-section">
          <h4>Переплан</h4>
          <div class="replan-dates">
            <label class="replan-dates__label">Начало <UiInput v-model="replanForm.plan_start_date" type="date" /></label>
            <label class="replan-dates__label">Окончание <UiInput v-model="replanForm.plan_end_date" type="date" /></label>
          </div>
          <UiMuted tag="p">Часы по дням (период сетки):</UiMuted>
          <div class="replan-days-grid">
            <template v-for="day in replanModalDays" :key="day">
              <label class="replan-day-cell" :class="{ 'replan-day-cell--weekend': isWeekend(day) }">
                <span class="replan-day-label">{{ day.slice(8, 10) }}.{{ day.slice(5, 7) }}</span>
                <UiInput
                  type="number"
                  :min="0"
                  :step="0.5"
                  :model-value="getReplanHoursForDay(day)"
                  class="replan-day-input"
                  @update:model-value="setReplanHoursForDay(day, $event)"
                />
              </label>
            </template>
          </div>
        </section>
      </template>
      <template #actions>
        <UiButton @click="closeReplanModal">Отмена</UiButton>
        <UiButton variant="primary" :disabled="taskPlanLoading || taskPlanSaving" @click="saveReplan">
          {{ taskPlanSaving ? 'Сохранение…' : 'Сохранить' }}
        </UiButton>
      </template>
    </UiModal>
  </div>
</template>

<style lang="scss" scoped>
.planning-filter {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: center;
  margin-bottom: 1.5rem;
}
.planning-filter__dropdowns {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
}
.planning-filter__submit {
  flex-shrink: 0;
}

/* Содержимое панелей фильтров в dropdown */
.planning-filter-panel {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  font-size: 0.9rem;
}
.planning-filter-panel__row {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
.planning-filter-panel__row--radio {
  flex-direction: row;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.planning-filter-panel__label {
  font-weight: 500;
  color: #475569;
}
.planning-filter-panel__checkbox {
  white-space: normal;
}
.planning-filter-panel__hint {
  margin: 0;
  font-size: 0.8rem;
}
.planning-above-table { margin-bottom: 0; }
.planning-above-table .grid-section__title { margin: 1rem 0 0.25rem; font-size: 1.1rem; }
.planning-above-table .grid-section__period { margin: 0 0 0.5rem; }
.planning-draft-actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}
.replan-task-title { margin: 0 0 0.5rem; font-size: 0.95rem; }
.replan-dates { display: flex; gap: 1rem; margin-bottom: 0.5rem; }
.replan-dates__label { display: flex; align-items: center; gap: 0.35rem; font-size: 0.9rem; }
.replan-day-input { width: 2.8rem; text-align: center; padding: 0.25rem; box-sizing: border-box; }

.grid-section { margin-top: 0; }
.grid-section .table-scroll-wrap { margin-top: 0; }

.table-scroll-wrap {
  overflow: auto;
  max-width: 100%;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
}
.grid-table { border-collapse: collapse; font-size: 0.75rem; min-width: 100%; background: #fff; }
.grid-table th, .grid-table td { padding: 0.05rem 0.2rem; border: 1px solid #e2e8f0; text-align: left; white-space: nowrap; background: #fff; }
.grid-table th { background: #f8fafc; font-weight: 600; }
/* Фиксированная шапка таблицы при вертикальной прокрутке */
.grid-table thead th { position: sticky; top: 0; z-index: 3; background: #f8fafc; box-shadow: 0 1px 0 0 #e2e8f0; }
.grid-table thead th.th-fixed { z-index: 4; }
.grid-table .th-day, .grid-table .td-day { text-align: center; min-width: 2.5rem; }

/* Таймлайн плановой полосы (одна ячейка на всю строку плана) */
.td-timeline-cell { padding: 0; vertical-align: top; overflow: visible; }
.timeline-wrap {
  position: relative;
  display: flex;
  min-height: 1.6rem;
  background: #fff;
}
.timeline-day {
  flex: 0 0 2.5rem;
  min-width: 2.5rem;
  text-align: center;
  font-size: 0.75rem;
  color: #64748b;
  border-left: 1px solid #e2e8f0;
  padding: 0.1rem 0.2rem;
}
.timeline-day--weekend { background: #f1f5f9; }
.plan-bar {
  position: absolute;
  top: 2px;
  bottom: 2px;
  background: #7dd3fc;
  border: 1px solid #0ea5e9;
  border-radius: 4px;
  cursor: move;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: auto;
  user-select: none;
}
.plan-bar--replan { background: #fca5a5; border-color: #dc2626; }
.plan-bar__segments { display: flex; flex: 1; min-width: 0; align-items: center; justify-content: stretch; pointer-events: none; }
.plan-bar__segment { flex: 1; font-size: 0.65rem; font-weight: 600; color: #0c4a6e; text-align: center; overflow: hidden; white-space: nowrap; }
.plan-bar__segment--weekend { color: #64748b; opacity: 0.85; }
.plan-bar--replan .plan-bar__segment { color: #7f1d1d; }
.plan-bar--replan .plan-bar__segment--weekend { color: #94a3b8; }
.plan-bar__resize {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 6px;
  cursor: col-resize;
  z-index: 1;
}
.plan-bar__resize--left { left: 0; }
.plan-bar__resize--right { right: 0; }
.draft-actions { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin: 0.5rem 0; }

/* Фиксированные колонки (не скроллятся по горизонтали) */
.th-fixed, .td-fixed {
  position: sticky;
  z-index: 1;
  background: #fff;
  box-shadow: 2px 0 4px -2px rgba(0,0,0,0.08);
}
.grid-table th.th-fixed { z-index: 2; background: #f8fafc; }
.th-task, .td-task { left: 0; min-width: 200px; max-width: 200px; white-space: normal; }
.td-task, .td-spec {font-size: 0.9rem;}
.th-spec, .td-spec { left: 200px; min-width: 120px; max-width: 120px; }
.th-hours, .td-hours { left: 320px; min-width: 56px; max-width: 56px; }
.grid-table .row-plan .td-hours { min-width: 8rem; max-width: none; white-space: normal; display: flex; align-items: center; justify-content: space-between;}

.task-link { color: var(--color-primary); text-decoration: none; }
.task-link:hover { text-decoration: underline; }
.grid-table .td-day { color: #475569; }

/* Выходные дни — серый фон */
.grid-table .th-day--weekend { background: #f1f5f9; color: #64748b; }
.grid-table .td-day--weekend { background: #f1f5f9 !important; }

/* Заполненные плановые ячейки — голубой; заполненные фактические — оранжевый */
.grid-table .row-plan .td-day--plan.td-day--filled { background: #e0f2fe; }
.grid-table .row-plan .td-day--plan.td-day--filled.td-day--weekend { background: #bae6fd !important; }
/* Переплан — заполненные ячейки светло-красные */
.grid-table .row-plan--replan .td-day--plan.td-day--filled { background: #fecaca; }
.grid-table .row-plan--replan .td-day--plan.td-day--filled.td-day--weekend { background: #fca5a5 !important; }
.grid-table .row-fact .td-day--fact.td-day--filled { background: #ffedd5; }
.grid-table .row-fact .td-day--fact.td-day--filled.td-day--weekend { background: #fed7aa !important; }

.grid-table .row-plan .td-fixed, .grid-table .row-fact .td-fixed { background-color: #f5f5f5; }

/* Подписи типа строки */
.row-type { font-size: 0.75rem; margin-left: 0.35rem; }
.row-type--original { color: #166534; }
.row-type--plan, .row-type--replanned { color: #0369a1; }
.row-type--fact { color: #c2410c; }
.td-task-fact { padding-left: 1rem; }
.btn-replan { margin-left: 0.2rem; padding: 0.02rem; border: none; background-color: transparent; border-radius: 4px; color: #0369a1; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
.btn-replan:hover { background: #bae6fd; }
.icon-pencil { display: block; }

/* Модалка переплана */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 1rem; }
.modal-panel { background: #fff; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); max-width: 90vw; max-height: 90vh; overflow: auto; padding: 1.25rem; }
.modal-title { margin: 0 0 0.5rem; font-size: 1.25rem; }
.modal-task-title { margin: 0 0 1rem; color: #64748b; font-size: 0.9rem; }
.replan-section { margin-bottom: 1.25rem; }
.replan-section h4 { margin: 0 0 0.5rem; font-size: 0.95rem; }
.replan-original { margin: 0; padding: 0.5rem; background: #f1f5f9; border-radius: 4px; font-size: 0.9rem; }
.replan-dates { display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 0.75rem; }
.replan-dates label { display: flex; align-items: center; gap: 0.35rem; }
.replan-days-grid { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem; }
.replan-day-cell { display: flex; flex-direction: column; align-items: center; gap: 0.2rem; padding: 0.35rem; border: 1px solid #e2e8f0; border-radius: 4px; min-width: 3rem; }
.replan-day-cell--weekend { background: #f8fafc; }
.replan-day-label { font-size: 0.75rem; color: #64748b; }
.modal-actions { display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid #e2e8f0; }
</style>
