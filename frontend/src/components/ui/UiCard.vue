<script setup>
defineProps({
  tag: { type: String, default: 'section' },
})
</script>

<template>
  <component :is="tag" class="ui-card">
    <slot />
  </component>
</template>

<style lang="scss" scoped>
.ui-card {
  background: #fff;
  padding: 1rem;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}
</style>
