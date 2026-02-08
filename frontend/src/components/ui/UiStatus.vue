<script setup>
defineProps({
  variant: { type: String, default: 'normal' }, // normal | overload | underload
})
const labels = {
  normal: 'Норма',
  overload: 'Перегруз',
  underload: 'Недогруз',
}
</script>

<template>
  <span class="ui-status" :class="`ui-status--${variant}`">
    <slot>{{ labels[variant] }}</slot>
  </span>
</template>

<style lang="scss" scoped>
.ui-status {
  font-weight: 500;
  font-size: 0.9rem;
}
.ui-status--normal {
  color: #276749;
}
.ui-status--overload {
  color: #c53030;
}
.ui-status--underload {
  color: #744210;
}
</style>
