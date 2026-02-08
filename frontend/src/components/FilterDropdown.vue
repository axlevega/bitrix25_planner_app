<script setup>
import { ref, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  /** Текст кнопки */
  label: { type: String, required: true },
  /** Показывать индикатор «есть выбранные значения» */
  active: { type: Boolean, default: false },
})

const open = ref(false)
const triggerRef = ref(null)
const panelRef = ref(null)

function toggle() {
  open.value = !open.value
}

function close() {
  open.value = false
}

function onDocumentClick(e) {
  if (!open.value) return
  const trigger = triggerRef.value
  const panel = panelRef.value
  if (!trigger || !panel) return
  const target = e.target
  if (trigger.contains(target) || panel.contains(target)) return
  close()
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
})
onUnmounted(() => {
  document.removeEventListener('click', onDocumentClick)
})
</script>

<template>
  <div class="filter-dropdown">
    <button
      ref="triggerRef"
      type="button"
      class="filter-dropdown__trigger"
      :class="{ 'filter-dropdown__trigger--active': active, 'filter-dropdown__trigger--open': open }"
      aria-haspopup="true"
      :aria-expanded="open"
      @click="toggle"
    >
      <span class="filter-dropdown__label">{{ label }}</span>
      <span v-if="active" class="filter-dropdown__dot" aria-hidden="true"></span>
      <svg class="filter-dropdown__chevron" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
        <path fill="currentColor" d="M7 10l5 5 5-5z"/>
      </svg>
    </button>
    <Transition name="filter-dropdown">
      <div
        v-show="open"
        ref="panelRef"
        class="filter-dropdown__panel"
        role="dialog"
        :aria-label="label"
      >
        <slot />
      </div>
    </Transition>
  </div>
</template>

<style lang="scss" scoped>
.filter-dropdown {
  position: relative;
  display: inline-block;
}

.filter-dropdown__trigger {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.4rem 0.75rem;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #fff;
  font-size: 0.9rem;
  font-weight: 500;
  color: #334155;
  cursor: pointer;
  white-space: nowrap;
  transition: border-color 0.15s, background 0.15s;

  &:hover {
    border-color: #94a3b8;
    background: #f8fafc;
  }

  &--active {
    border-color: var(--color-primary, #0ea5e9);
    background: #f0f9ff;
    color: var(--color-primary, #0ea5e9);
  }

  &--open {
    border-color: var(--color-primary, #0ea5e9);
    background: #f0f9ff;
    color: var(--color-primary, #0ea5e9);
  }
}

.filter-dropdown__dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--color-primary, #0ea5e9);
  flex-shrink: 0;
}

.filter-dropdown__chevron {
  flex-shrink: 0;
  transition: transform 0.2s;
}
.filter-dropdown__trigger--open .filter-dropdown__chevron {
  transform: rotate(180deg);
}

.filter-dropdown__panel {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  min-width: 220px;
  max-width: 360px;
  padding: 0.75rem 1rem;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  z-index: 50;
}

.filter-dropdown-enter-active,
.filter-dropdown-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.filter-dropdown-enter-from,
.filter-dropdown-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
