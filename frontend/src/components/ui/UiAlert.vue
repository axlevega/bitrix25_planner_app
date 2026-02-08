<script setup>
defineProps({
  variant: { type: String, default: 'error' }, // error | success | warning
})
</script>

<template>
  <div class="ui-alert" :class="`ui-alert--${variant}`">
    <slot />
  </div>
</template>

<style lang="scss" scoped>
.ui-alert {
  padding: 0.5rem 0.75rem;
  border-radius: 4px;
  margin-bottom: 1rem;
  font-size: 0.9rem;
}
.ui-alert--error {
  background: #fee2e2;
  color: #991b1b;
}
.ui-alert--success {
  background: #d1fae5;
  color: #065f46;
}
.ui-alert--warning {
  background: #fef3c7;
  color: #92400e;
}
</style>
