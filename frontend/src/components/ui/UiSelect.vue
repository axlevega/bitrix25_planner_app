<script setup>
defineProps({
  modelValue: { type: [String, Number, Array], default: '' },
  multiple: { type: Boolean, default: false },
  size: { type: [String, Number], default: undefined },
  disabled: { type: Boolean, default: false },
})
defineEmits(['update:modelValue'])
</script>

<template>
  <select
    class="ui-select"
    :value="modelValue"
    :multiple="multiple"
    :size="size"
    :disabled="disabled"
    @change="$emit('update:modelValue', $event.target.multiple ? Array.from($event.target.selectedOptions, o => o.value) : $event.target.value)"
  >
    <slot />
  </select>
</template>

<style lang="scss" scoped>
.ui-select {
  padding: 0.5rem 0.75rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  font-size: 0.9rem;
  background: #fff;
  min-width: 120px;
  &:focus {
    outline: none;
    border-color: var(--color-primary);
  }
  &:disabled {
    opacity: 0.7;
    cursor: not-allowed;
    background: #f1f5f9;
  }
}
</style>
