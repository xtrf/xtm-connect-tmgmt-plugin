<?php

namespace Drupal\tmgmt_xtm_connect;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\TranslatorPluginUiBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * XTMConnect translator UI.
 */
class XTMConnectTranslatorUi extends TranslatorPluginUiBase
{

  use StringTranslationTrait;

  /**
   * Overrides TMGMTDefaultTranslatorUIController::pluginSettingsForm().
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array
  {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Check for valid form object - we should receive entity form object here.
    if (!$form_state->getFormObject() instanceof EntityFormInterface) {
      return $form;
    }

    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();

    /** @var \Drupal\tmgmt_xtm_connect\Plugin\tmgmt\Translator\XTMConnectTranslator $xtm_connect_translator */
    $xtm_connect_translator = $translator->getPlugin();

    $form['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Url'),
      '#required' => TRUE,
      '#default_value' => $translator->getSetting('url') ?? $xtm_connect_translator->getTranslatorUrl(),
    ];

    $form['auth_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#required' => TRUE,
      '#default_value' => $translator->getSetting('auth_key'),
    ];
    // Add 'connect' button for testing valid key.
    $form += parent::addConnectButton();

    // Allow alteration of buildConfigurationForm.
    \Drupal::moduleHandler()->alter('tmgmt_xtm_connect_build_configuration_form', $form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void
  {
    parent::validateConfigurationForm($form, $form_state);

    // Check for valid form object - we should receive entity form object here.
    if (!$form_state->getFormObject() instanceof EntityFormInterface) {
      return;
    }
    $translator = $form_state->getFormObject()->getEntity();
    $xtm_connect_translator = $translator->getPlugin();
    $res = $xtm_connect_translator->validateAPI($translator);
    // Reset outline_detection, if tag_handling is not set.
    if ($res["data"] != "ok") {
      $form_state->setErrorByName('settings][auth_key', $this->t('Please check the url and key.'));
      $form_state->setErrorByName('settings][url', $this->t('Please check the url and key.'));
    }
    $settings = $form_state->getValue('settings');
    if ($settings['tag_handling'] === '0') {
      $form_state->setValueForElement($form['plugin_wrapper']['settings']['outline_detection'], 0);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkoutSettingsForm(array $form, FormStateInterface $form_state, JobInterface $job): array
  {
    // Allow alteration of checkoutSettingsForm.
    \Drupal::moduleHandler()->alter('tmgmt_xtm_connect_checkout_settings_form', $form, $job);
    $form['settings']['active'] = array(
      '#type' => 'radios',
      '#title' => $this->t('Format'),
      '#default_value' => 0,
      '#options' => array(0 => $this->t('JSON'))
    );
    return $form;
  }

}
