import {onMounted} from 'vue';

export function useDisableAutocomplete() {
    function disableAutoComplete() {
        let elements = document.querySelectorAll('[autocomplete="off"]');

        if (!elements.length) {
            return;
        }

        elements.forEach(element => {
            element.setAttribute('readonly', 'readonly');
            element.style.backgroundColor = 'inherit';

            window.addEventListener('load', () => {
                setTimeout(() => {
                    element.removeAttribute('readonly');
                }, 500);
            });
        });
    }

    onMounted(() => {
        disableAutoComplete();
    });
}