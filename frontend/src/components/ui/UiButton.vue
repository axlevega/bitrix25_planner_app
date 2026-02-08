<script setup>
defineProps({
  type: { type: String, default: 'button' },
  variant: { type: String, default: 'default' }, // default | primary | outline
  size: { type: String, default: 'md' }, // sm | md
  disabled: { type: Boolean, default: false },
})
</script>

<template>
  <button
    :type="type"
    class="ui-btn"
    :class="[
      `ui-btn--${variant}`,
      size === 'sm' && 'ui-btn--sm',
    ]"
    :disabled="disabled"
  >
    <slot />
  </button>
</template>

<style lang="scss" scoped>
.ui-btn {
  padding: 0.4rem 0.75rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  background: #fff;
  cursor: pointer;
  font-size: 0.9rem;
  font-weight: 500;
  &:disabled {
    opacity: 0.7;
    cursor: not-allowed;
  }
}
.ui-btn--primary {
  background: var(--color-primary);
  color: #fff;
  border-color: var(--color-primary);
}
.ui-btn--outline {
  background: #fff;
  color: var(--color-primary);
  border-color: var(--color-primary);
}
.ui-btn--sm {
  padding: 0.25rem 0.5rem;
  font-size: 0.85rem;
}
</style>
