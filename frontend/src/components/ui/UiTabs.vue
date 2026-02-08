<script setup>
defineProps({
  modelValue: { type: [String, Number], required: true },
  tabs: { type: Array, required: true }, // [{ id, label }]
})
defineEmits(['update:modelValue'])
</script>

<template>
  <div class="ui-tabs">
    <button
      v-for="t in tabs"
      :key="t.id"
      type="button"
      class="ui-tabs__tab"
      :class="{ 'ui-tabs__tab--active': modelValue === t.id }"
      @click="$emit('update:modelValue', t.id)"
    >
      {{ t.label }}
    </button>
  </div>
</template>

<style lang="scss" scoped>
.ui-tabs {
  display: flex;
  gap: 0.25rem;
  border-bottom: 1px solid #e2e8f0;
  padding-bottom: 0;
}
.ui-tabs__tab {
  padding: 0.5rem 1rem;
  border: none;
  background: none;
  font-size: 0.95rem;
  font-weight: 500;
  color: #64748b;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  border-radius: 4px 4px 0 0;
  &:hover {
    color: var(--color-primary);
  }
  &.ui-tabs__tab--active {
    color: var(--color-primary);
    border-bottom-color: var(--color-primary);
  }
}
</style>
