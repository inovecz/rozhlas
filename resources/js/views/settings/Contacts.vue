<script setup>
import ContactList from "./ContactList.vue";
import ContactGroupList from "./ContactGroupList.vue";
import {onMounted} from "vue";
import ContactService from "../../services/ContactService.js";
import {contactGroupStore} from "../../store/contactGroupStore.js";
import {useToast} from "vue-toastification";
import PageContent from "../../components/custom/PageContent.vue";

const contactGroupStoreInfo = contactGroupStore();
const toast = useToast();

onMounted(() => {
  fetchContactGroupsSelect();
});

emitter.on('refetchContactGroupsSelect', () => {
  fetchContactGroupsSelect();
});

function fetchContactGroupsSelect() {
  ContactService.getAllContactGroups().then(response => {
    contactGroupStoreInfo.contactGroups = response;
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst data');
  });
}
</script>

<template>
  <PageContent label="Kontakty">
    <ContactList/>
    <ContactGroupList/>
  </PageContent>
</template>

<style scoped>
</style>