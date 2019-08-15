let $slugFieldInputContainer,
  $slugFieldInput,
  $slugField,
  $toggleContainer,
  $toggleInput,
  lightSwitch;

const isPageSuitable = () => {
  const $fields = document.querySelectorAll('#content [name="entryId"]');
  return $fields.length > 0;
};

const buildLightSwitch = () => {
  const hiddenFieldName = 'slugEqualsTitle_shouldRewrite';
  const $markup = `
  <div class="lightswitch fieldtoggle" tabindex="0" data-value="1" id="${hiddenFieldName}" data-target="${hiddenFieldName}-field" role="checkbox" aria-checked="false">
    <div class="lightswitch-container">
        <div class="label on"></div>
        <div class="handle"></div>
        <div class="label off"></div>
    </div>
    <input type="hidden" name="${hiddenFieldName}" value=""></div>
`;
  const $lightSwitch = document.createElement('div');
  $lightSwitch.innerHTML = $markup;
  $slugField.appendChild($lightSwitch);
  $toggleContainer = $slugField.querySelector(`#${hiddenFieldName}`);

  lightSwitch = new Craft.LightSwitch($toggleContainer);
  const width = $slugFieldInputContainer.getBoundingClientRect().width - $toggleContainer.getBoundingClientRect().width;
  $slugFieldInputContainer.style.width = `${width}px`;
  $toggleInput = document.querySelector(`[name=${hiddenFieldName}]`);
};

const switchToggle = (status) => {
  if (status) {
    lightSwitch.turnOn();
    $slugFieldInput.disabled = 'disabled';
    $slugFieldInput.classList.add('disabled');
    $slugFieldInput.dataset.value = $slugFieldInput.value;
    $slugFieldInput.value = 'overwritten by title';
  } else {
    lightSwitch.turnOff();
    $slugFieldInput.disabled = '';
    $slugFieldInput.classList.remove('disabled');
    if ($slugFieldInput.dataset.hasOwnProperty('value')) {
      $slugFieldInput.value = $slugFieldInput.dataset.value;
    }
  }
};

const init = async () => {
  if (!isPageSuitable()) return;
  $slugField = document.querySelector('#slug-field.field');
  if (!$slugField) return;
  const isEnabled = document.querySelector('meta[name="slugEqualsTitleOverwriteEnabled"]').content === "true";
  $slugFieldInputContainer = $slugField.querySelector('.input');
  $slugFieldInput = $slugFieldInputContainer.querySelector('input');
  buildLightSwitch();
  switchToggle(isEnabled);

  $toggleContainer.addEventListener('click', () => {
    switchToggle($toggleInput.value === "1");
  });
};

export default {
  init,
};
