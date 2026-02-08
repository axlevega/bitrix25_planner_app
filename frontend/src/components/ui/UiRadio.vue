<script setup>
defineProps({
  modelValue: { type: [String, Number, Boolean], default: '' },
  value: { type: [String, Number, Boolean], required: true },
  name: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
})
defineEmits(['update:modelValue'])
</script>

<template>
  <label class="ui-radio">
    <input
      type="radio"
      class="ui-radio__input"
      :name="name"
      :value="value"
      :checked="modelValue === value"
      :disabled="disabled"
      @change="$emit('update:modelValue', value)"
    />
    <span class="ui-radio__label"><slot /></span>
  </label>
</template>

<style lang="scss" scoped>
.ui-radio {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  cursor: pointer;
  font-size: 0.9rem;
  user-select: none;
}
.ui-radio__input {
  width: 1rem;
  height: 1rem;
  accent-color: var(--color-primary);
}
</style>
