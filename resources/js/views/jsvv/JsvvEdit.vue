<script setup>
import {useRoute} from "vue-router";
import {computed, onMounted, ref} from "vue";
import {useToast} from "vue-toastification";
import JsvvAlarmService from "../../services/JsvvAlarmService.js";
import VueMultiselect from "vue-multiselect";
import router from "../../router.js";
import PageContent from "../../components/custom/PageContent.vue";
import Box from "../../components/custom/Box.vue";
import Input from "../../components/forms/Input.vue";
import Select from "../../components/forms/Select.vue";
import CustomFormControl from "../../components/forms/CustomFormControl.vue";
import Button from "../../components/forms/Button.vue";

const route = useRoute();
const toast = useToast();
const editingJsvvAlarmId = ref(route.params.id);
const audioGroupOptions = ref([]);
const selectedSequenceItems = ref([]);
const errorBag = ref({});
const jsvvAlarm = ref({
  id: null,
  name: '',
  button: null,
  mobile_button: null,
  sequence: null
});

onMounted(() => {
  getJsvvAudios();
})

function getJsvvAlarm() {
  JsvvAlarmService.getJsvvAlarm(editingJsvvAlarmId.value).then(response => {
    jsvvAlarm.value = response.data;
    if (jsvvAlarm.value.sequence) {
      const sequenceSymbols = jsvvAlarm.value.sequence.split('');
      const selectedItems = [];
      sequenceSymbols.forEach(symbol => {
        audioGroupOptions.value.forEach(group => {
          const foundAudio = group.audios.find(audio => audio.symbol === symbol);
          if (foundAudio) {
            selectedItems.push(foundAudio);
          }
        });
      });
      selectedSequenceItems.value = selectedItems;
    }
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst data');
  });
}

function getJsvvAudios() {
  JsvvAlarmService.getJsvvAudios().then(response => {
    const audioGroups = [];
    response.forEach(jsvvAudio => {
      let existingGroup = audioGroups.find(group => group.group_name === jsvvAudio.group);
      if (!existingGroup) {
        existingGroup = {
          group_name: jsvvAudio.group,
          group_label: jsvvAudio.group_label,
          audios: [],
        };
        audioGroups.push(existingGroup);
      }
      existingGroup.audios.push(jsvvAudio);
    });
    audioGroupOptions.value = audioGroups;
    if (editingJsvvAlarmId.value) {
      getJsvvAlarm();
    }
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst data');
  });
}

function saveJsvvAlarm() {
  jsvvAlarm.value.sequence = selectedSequenceItems.value.map(item => item.symbol).join('');
  JsvvAlarmService.saveJsvvAlarm(jsvvAlarm.value).then(() => {
    toast.success('Alarm byl úspěšně upraven');
    router.push({name: 'JSVV'});
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se uložit změny');
  });
}

const cantSave = computed(() => {
  errorBag.value = {};
  let retVal = false;
  if (jsvvAlarm.value.name.length < 3) {
    retVal = true;
  }
  if (selectedSequenceItems.value.length > 4) {
    errorBag.value.sequence = 'Maximálně lze vybrat 4 položky';
    retVal = true;
  }
  return retVal;
});
</script>

<template>
  <PageContent :label="jsvvAlarm.id ? 'Úprava alarmu' : 'Nový alarm'" back-route="JSVV">
    <Box label="Nastavení alarmu">
      <Input v-model="jsvvAlarm.name" label="Název" placeholder="Zadejte název (min. 3 znaky)"/>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <Select v-model="jsvvAlarm.button" label="Tlačítko" :options="jsvvAlarm.available_buttons"/>
        <Select v-model="jsvvAlarm.mobile_button" label="Mobilní tlačítko" :options="jsvvAlarm.available_mobile_buttons"/>
      </div>

      <CustomFormControl :label="'Sekvence JSVV (pořadí dle výběru):' + (selectedSequenceItems.length > 0 ? ' <span class=&quot;font-bold&quot;>'+ selectedSequenceItems.map(item => item.symbol).join('')+'</span>' : '')" :error="errorBag?.sequence">
        <VueMultiselect v-model="selectedSequenceItems" :options="audioGroupOptions" label="name" trackBy="symbol" :multiple="true"
                        group-values="audios" group-label="group_label" :group-select="false"
                        :close-on-select="false"
                        placeholder="Vyhledat skupinu" tagPlaceholder="" noOptions="Seznam je prázdný"
                        selectedLabel="Vybráno" selectLabel="Klikněte pro přidání" deselectLabel="Klikněte pro odebrání">
          <template #noResult>
            <span>Zadaným parametrům neodpovídá žádná skupina</span>
          </template>
        </VueMultiselect>
      </CustomFormControl>

      <div class="flex items-center justify-end space-x-2">
        <Button route-to="JSVV" data-class="btn-ghost" label="Zrušit" size="sm"/>
        <Button icon="mdi-content-save" label="Uložit" size="sm" @click="saveJsvvAlarm" :disabled="cantSave"/>
      </div>
    </Box>
  </PageContent>
</template>

<style scoped>
</style>