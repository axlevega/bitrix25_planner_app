<script setup>
const props = defineProps({
  modelValue: { type: [Boolean, String, Number, Array], default: false },
  value: { type: [String, Number, Boolean], default: true },
  disabled: { type: Boolean, default: false },
})
defineEmits(['update:modelValue'])

function isArrayMode() {
  return Array.isArray(props.modelValue)
}
</script>

<template>
  <label class="ui-checkbox">
    <input
      type="checkbox"
      class="ui-checkbox__input"
      :checked="isArrayMode() ? modelValue.includes(value) : !!modelValue"
      :disabled="disabled"
      @change="
        $emit(
          'update:modelValue',
          isArrayMode()
            ? ($event.target).checked
              ? [...modelValue, value]
              : modelValue.filter((v) => v !== value)
            : ($event.target).checked
        )
      "
    />
    <span class="ui-checkbox__label"><slot /></span>
  </label>
</template>

<style lang="scss" scoped>
.ui-checkbox {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  cursor: pointer;
  font-size: 0.9rem;
  user-select: none;
}
.ui-checkbox__input {
  width: 1rem;
  height: 1rem;
  accent-color: var(--color-primary);
}
.ui-checkbox__label {
  flex: 1;
}
</style>
