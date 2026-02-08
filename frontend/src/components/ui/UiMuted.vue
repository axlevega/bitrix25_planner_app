<script setup>
defineProps({
  tag: { type: String, default: 'span' },
})
</script>

<template>
  <component :is="tag" class="ui-muted">
    <slot />
  </component>
</template>

<style lang="scss" scoped>
.ui-muted {
  color: #64748b;
  font-size: 0.9rem;
}
</style>
