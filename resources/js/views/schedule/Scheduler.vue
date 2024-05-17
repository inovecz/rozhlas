<script setup>
import ScheduleList from "./ScheduleList.vue";
import PageContent from "../../components/custom/PageContent.vue";
</script>

<template>
  <PageContent label="Plán vysílání">
    <ScheduleList type="planned"/>
    <ScheduleList type="archive"/>
  </PageContent>
</template>