<script setup>
import { ref } from 'vue'
import {
  UiButton,
  UiInput,
  UiSelect,
  UiMultiSelect,
  UiCheckbox,
  UiRadio,
  UiTabs,
  UiCard,
  UiTable,
  UiModal,
  UiPageHeader,
  UiAlert,
  UiLoading,
  UiStatus,
  UiMuted,
} from '../components/ui'

const inputValue = ref('')
const selectValue = ref('')
const multiSelectValue = ref([])
const multiSelectOptions = [
  { value: 'js', label: 'JavaScript' },
  { value: 'ts', label: 'TypeScript' },
  { value: 'vue', label: 'Vue.js' },
  { value: 'react', label: 'React' },
  { value: 'php', label: 'PHP' },
  { value: 'python', label: 'Python' },
]
const checkboxValue = ref(false)
const radioValue = ref('a')
const activeTab = ref('one')
const showModal = ref(false)

const tabs = [
  { id: 'one', label: 'Вкладка 1' },
  { id: 'two', label: 'Вкладка 2' },
  { id: 'three', label: 'Вкладка 3' },
]
</script>

<template>
  <div class="showcase">
    <UiPageHeader
      title="UI Showcase"
      description="Каталог переиспользуемых компонентов интерфейса."
    />

    <section class="showcase__section">
      <h2 class="showcase__heading">Кнопки (UiButton)</h2>
      <div class="showcase__row">
        <UiButton>По умолчанию</UiButton>
        <UiButton variant="primary">Primary</UiButton>
        <UiButton variant="outline">Outline</UiButton>
        <UiButton size="sm">Маленькая</UiButton>
        <UiButton disabled>Disabled</UiButton>
      </div>
    </section>

    <section class="showcase__section">
      <h2 class="showcase__heading">Поле ввода (UiInput)</h2>
      <div class="showcase__row showcase__row--col">
        <label>
          <span class="showcase__label">Text</span>
          <UiInput v-model="inputValue" placeholder="Введите текст" />
        </label>
        <label>
          <span class="showcase__label">Number</span>
          <UiInput v-model="inputValue" type="number" placeholder="0" />
        </label>
        <label>
          <span class="showcase__label">Date</span>
          <UiInput type="date" />
        </label>
        <label>
          <span class="showcase__label">Password</span>
          <UiInput type="password" placeholder="••••••••" autocomplete="off" />
        </label>
      </div>
    </section>

    <section class="showcase__section">
      <h2 class="showcase__heading">Выпадающий список (UiSelect)</h2>
      <div class="showcase__row">
        <UiSelect v-model="selectValue" style="min-width: 180px">
          <option value="">— выберите —</option>
          <option value="1">Вариант 1</option>
          <option value="2">Вариант 2</option>
          <option value="3">Вариант 3</option>
        </UiSelect>
      </div>
    </section>

    <section class="showcase__section">
      <h2 class="showcase__heading">Множественный выбор (UiMultiSelect)</h2>
      <p class="showcase__hint">Строка поиска: при фокусе открывается список с чекбоксами. Выбрано: {{ multiSelectValue.length }}</p>
      <div class="showcase__row">
        <UiMultiSelect
          v-model="multiSelectValue"
          :options="multiSelectOptions"
          placeholder="Поиск и выбор технологий…"
          style="min-width: 280px"
        />
      </div>
    </section>

    <section class="showcase__section">
      <h2 class="showcase__heading">Чекбокс (UiCheckbox)</h2>
      <div class="showcase__row">
        <UiCheckbox v-model="checkboxValue">Включить опцию</UiCheckbox>
        <UiCheckbox :model-value="true" disabled>Disabled отмечен</UiCheckbox>
      </div>
    </section>

    <section class="showcase__section">
      <h2 class="showcase__heading">Радиокнопки (UiRadio)</h2>
      <div class="showcase__row">
        <UiRadio v-model="radioValue" name="demo" value="a">Вариант A</UiRadio>
        <UiRadio v-model="radioValue" name="demo" value="b">Вариант B</UiRadio>
        <UiRadio v-model="radioValue" name="demo" value="c">Вариант C</UiRadio>
      </div>
    </section>

    <section class="showcase__section">
      <h2 class="showcase__heading">Табы (UiTabs)</h2>
      <UiTabs v-model="activeTab" :tabs="tabs" />
      <UiCard tag="div" class="showcase__tab-content">
        <UiMuted>Активная вкладка: {{ activeTab }}</UiMuted>
      </UiCard>
    </section>

    <section class="showcase__section">
      <h2 class="showcase__heading">Карточка (UiCard)</h2>
      <UiCard>
        <p>Контент внутри карточки. Можно использовать для секций форм и блоков контента.</p>
      </UiCard>
    </section>

    <section class="showcase__section">
      <h2 class="showcase__heading">Таблица (UiTable)</h2>
      <UiTable>
        <thead>
          <tr>
            <th>Колонка 1</th>
            <th>Колонка 2</th>
            <th>Колонка 3</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Ячейка 1</td>
            <td>Ячейка 2</td>
            <td>Ячейка 3</td>
          </tr>
          <tr>
            <td>Строка 2</td>
            <td>Данные</td>
            <td>—</td>
          </tr>
        </tbody>
      </UiTable>
    </section>

    <section class="showcase__section">
      <h2 class="showcase__heading">Сообщения (UiAlert)</h2>
      <UiAlert variant="error">Сообщение об ошибке.</UiAlert>
      <UiAlert variant="success">Успешное выполнение операции.</UiAlert>
      <UiAlert variant="warning">Предупреждение.</UiAlert>
    </section>

    <section class="showcase__section">
      <h2 class="showcase__heading">Загрузка (UiLoading)</h2>
      <UiLoading />
      <UiLoading text="Подождите…" />
    </section>

    <section class="showcase__section">
      <h2 class="showcase__heading">Статус (UiStatus)</h2>
      <div class="showcase__row">
        <UiStatus variant="normal" />
        <UiStatus variant="overload" />
        <UiStatus variant="underload" />
        <UiStatus variant="normal">Свой текст</UiStatus>
      </div>
    </section>

    <section class="showcase__section">
      <h2 class="showcase__heading">Приглушённый текст (UiMuted)</h2>
      <p>Обычный текст и <UiMuted>вспомогательный приглушённый текст</UiMuted> в строке.</p>
      <UiMuted tag="p">Целый абзац приглушённым цветом.</UiMuted>
    </section>

    <section class="showcase__section">
      <h2 class="showcase__heading">Заголовок страницы (UiPageHeader)</h2>
      <UiPageHeader
        title="Заголовок секции"
        description="Описание под заголовком (size=md)."
        size="md"
      />
    </section>

    <section class="showcase__section">
      <h2 class="showcase__heading">Модальное окно (UiModal)</h2>
      <UiButton variant="primary" @click="showModal = true">Открыть модалку</UiButton>
      <UiModal :show="showModal" title="Пример модального окна" @close="showModal = false">
        <p>Контент модального окна. Кнопки в слоте <code>actions</code>.</p>
        <template #actions>
          <UiButton @click="showModal = false">Закрыть</UiButton>
          <UiButton variant="primary">Сохранить</UiButton>
        </template>
      </UiModal>
    </section>
  </div>
</template>

<style lang="scss" scoped>
.showcase {
  padding-bottom: 2rem;
}
.showcase__section {
  margin-bottom: 2rem;
}
.showcase__heading {
  margin: 0 0 0.75rem;
  font-size: 1.15rem;
  color: #475569;
  font-weight: 600;
}
.showcase__row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
}
.showcase__row--col {
  flex-direction: column;
  align-items: stretch;
  max-width: 320px;
}
.showcase__row--col label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
.showcase__label {
  font-size: 0.85rem;
  font-weight: 500;
}
.showcase__hint {
  margin: 0 0 0.5rem;
  font-size: 0.85rem;
  color: #64748b;
}
.showcase__tab-content {
  margin-top: 0.5rem;
}
.showcase__tab-content .ui-card {
  padding: 0.75rem;
}
code {
  font-size: 0.85rem;
  background: #f1f5f9;
  padding: 0.2rem 0.4rem;
  border-radius: 4px;
}
</style>
