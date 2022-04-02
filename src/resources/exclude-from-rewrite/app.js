(() => {
  if (window.SlugEqualsTitle) { return }
  window.SlugEqualsTitle = ($slugField, $toggleInput, isEnabled, fieldName) => {
    const $slugFieldInputContainer = $slugField.querySelector('.input')

    let lightSwitch, $toggleContainer;

    const buildLightswitch = () => {
      const $markup = `
      <div class="lightswitch fieldtoggle" tabindex="0" data-value="1" id="${fieldName}" data-target="${fieldName}-field" role="checkbox" aria-checked="false">
        <div class="lightswitch-container">
            <div class="label on"></div>
            <div class="handle"></div>
            <div class="label off"></div>
        </div>
      </div>
    `
      const $lightSwitch = document.createElement('div')
      $lightSwitch.innerHTML = $markup
      $slugField.appendChild($lightSwitch)
      $toggleContainer = $slugField.querySelector(`#${fieldName}`)
      lightSwitch = new window.Craft.LightSwitch($toggleContainer)
      const width = $slugFieldInputContainer.getBoundingClientRect().width - $toggleContainer.getBoundingClientRect().width
      const spaceRight = 10
      $slugFieldInputContainer.style.width = `${width - spaceRight}px`
      $slugFieldInputContainer.style.marginRight = `${spaceRight}px`
    }

    const switchToggle = (status) => {
      console.log(status)

      $toggleInput.value = status ? '1' : ''

      if (status) {
        lightSwitch.turnOn()
        $slugFieldInputContainer.classList.add('slugEqualsTitle-overwrite-enabled')
      } else {
        lightSwitch.turnOff()
        $slugFieldInputContainer.classList.remove('slugEqualsTitle-overwrite-enabled')
      }
    }

    buildLightswitch()

    // Make sure the hidden input is in the sidebar.
    const $toggleInput2 = $toggleContainer.parentNode.appendChild($toggleInput.cloneNode())
    $toggleInput.remove()
    $toggleInput = $toggleInput2

    switchToggle(isEnabled)

    $toggleContainer.addEventListener('click', () => {
      const checked = $toggleContainer.getAttribute('aria-checked') === 'true'
      switchToggle(checked);
    });
  }
})()
