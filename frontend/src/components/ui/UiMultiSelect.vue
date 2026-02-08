<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  options: { type: Array, required: true }, // [{ value, label }] or primitive[]
  placeholder: { type: String, default: 'Поиск и выбор…' },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const isOpen = ref(false)
const searchQuery = ref('')
const wrapperRef = ref(null)

const normalizedOptions = computed(() => {
  return props.options.map((opt) =>
    typeof opt === 'object' && opt !== null && 'value' in opt
      ? { value: opt.value, label: String(opt.label ?? opt.value) }
      : { value: opt, label: String(opt) }
  )
})

const filteredOptions = computed(() => {
  const q = (searchQuery.value || '').trim().toLowerCase()
  if (!q) return normalizedOptions.value
  return normalizedOptions.value.filter((opt) =>
    opt.label.toLowerCase().includes(q)
  )
})

const selectedSet = computed(() => new Set(props.modelValue))

function toggle(value) {
  const arr = [...(props.modelValue || [])]
  const idx = arr.indexOf(value)
  if (idx >= 0) arr.splice(idx, 1)
  else arr.push(value)
  emit('update:modelValue', arr)
}

function isSelected(value) {
  return selectedSet.value.has(value)
}

function handleClickOutside(e) {
  if (wrapperRef.value && !wrapperRef.value.contains(e.target)) {
    isOpen.value = false
  }
}

function onKeydown(e) {
  if (e.key === 'Escape') {
    isOpen.value = false
    wrapperRef.value?.querySelector('input')?.blur()
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
  document.addEventListener('keydown', onKeydown)
})
onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div ref="wrapperRef" class="ui-multi-select">
    <input
      type="text"
      class="ui-multi-select__input"
      :placeholder="placeholder"
      :disabled="disabled"
      :value="searchQuery"
      autocomplete="off"
      @input="searchQuery = ($event.target).value"
      @focus="isOpen = true"
    />
    <Transition name="ui-multi-select-drop">
      <div v-show="isOpen" class="ui-multi-select__dropdown">
        <ul class="ui-multi-select__list">
          <li
            v-for="opt in filteredOptions"
            :key="opt.value"
            class="ui-multi-select__option"
            @click.prevent="toggle(opt.value)"
          >
            <input
              type="checkbox"
              class="ui-multi-select__checkbox"
              :checked="isSelected(opt.value)"
              :disabled="disabled"
              tabindex="-1"
              @click.stop="toggle(opt.value)"
            />
            <span class="ui-multi-select__label">{{ opt.label }}</span>
          </li>
          <li v-if="filteredOptions.length === 0" class="ui-multi-select__empty">
            Ничего не найдено
          </li>
        </ul>
      </div>
    </Transition>
  </div>
</template>

<style lang="scss" scoped>
.ui-multi-select {
  position: relative;
  min-width: 200px;
}
.ui-multi-select__input {
  width: 100%;
  padding: 0.5rem 0.75rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  font-size: 0.9rem;
  background: #fff;
  box-sizing: border-box;
  &:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 2px rgba(44, 82, 130, 0.2);
  }
  &:disabled {
    opacity: 0.7;
    cursor: not-allowed;
    background: #f1f5f9;
  }
}
.ui-multi-select__dropdown {
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  margin-top: 2px;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
  max-height: 240px;
  overflow-y: auto;
  z-index: 100;
}
.ui-multi-select__list {
  margin: 0;
  padding: 0.25rem 0;
  list-style: none;
}
.ui-multi-select__option {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.4rem 0.75rem;
  cursor: pointer;
  font-size: 0.9rem;
  &:hover {
    background: #f1f5f9;
  }
}
.ui-multi-select__checkbox {
  flex-shrink: 0;
  width: 1rem;
  height: 1rem;
  accent-color: var(--color-primary);
  cursor: pointer;
}
.ui-multi-select__label {
  flex: 1;
  user-select: none;
}
.ui-multi-select__empty {
  padding: 0.75rem 1rem;
  color: #64748b;
  font-size: 0.9rem;
}

.ui-multi-select-drop-enter-active,
.ui-multi-select-drop-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.ui-multi-select-drop-enter-from,
.ui-multi-select-drop-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
