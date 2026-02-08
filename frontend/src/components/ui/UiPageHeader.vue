<script setup>
defineProps({
  title: { type: String, default: '' },
  description: { type: String, default: '' },
  size: { type: String, default: 'lg' }, // lg | md
})
</script>

<template>
  <header class="ui-page-header" :class="`ui-page-header--${size}`">
    <h1 v-if="title" class="ui-page-header__title">{{ title }}</h1>
    <p v-if="description" class="ui-page-header__desc">{{ description }}</p>
    <slot name="extra" />
  </header>
</template>

<style lang="scss" scoped>
.ui-page-header {
  margin-bottom: 1rem;
}
.ui-page-header__title {
  margin: 0 0 0.25rem;
  font-size: 1.5rem;
}
.ui-page-header--md .ui-page-header__title {
  font-size: 1.2rem;
  margin-bottom: 0.5rem;
}
.ui-page-header__desc {
  margin: 0;
  color: #64748b;
  font-size: 0.9rem;
}
</style>
