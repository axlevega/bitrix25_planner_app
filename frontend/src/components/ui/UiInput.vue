<script setup>
defineProps({
  modelValue: { type: [String, Number], default: '' },
  type: { type: String, default: 'text' },
  placeholder: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  min: { type: [String, Number], default: undefined },
  max: { type: [String, Number], default: undefined },
  step: { type: [String, Number], default: undefined },
  autocomplete: { type: String, default: undefined },
})
defineEmits(['update:modelValue'])
</script>

<template>
  <input
    :type="type"
    class="ui-input"
    :value="modelValue"
    :placeholder="placeholder"
    :disabled="disabled"
    :min="min"
    :max="max"
    :step="step"
    :autocomplete="autocomplete"
    @input="$emit('update:modelValue', ($event.target).value)"
  />
</template>

<style lang="scss" scoped>
.ui-input {
  padding: 0.5rem 0.75rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  font-size: 0.9rem;
  width: 100%;
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
</style>
