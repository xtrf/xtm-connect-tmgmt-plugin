<?php

/**
 * @file
 * Hooks provided by the tmgmt_xtm_connect module.
 */

use Drupal\Core\Form\FormStateInterface;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\JobInterface;

/**
 * Modify the XTMConnectTranslatorUi checkoutSettingsForm.
 *
 * @param array $form
 *   The form array.
 * @param \Drupal\tmgmt\JobInterface $job
 *   The tmgmt job entity.
 */
function hook_tmgmt_xtm_connect_checkout_settings_form_alter(array &$form, JobInterface $job): void {
  $form['additional_info'] = [
    '#markup' => t('Additional information shown in checkoutSettingsForm'),
  ];
}

/**
 * Modify the XTMConnectTranslatorUi buildConfigurationForm.
 *
 * @param array $form
 *   The form array.
 * @param \Drupal\Core\Form\FormStateInterface $form_state
 *   The current state of the form.
 */
function hook_tmgmt_xtm_connect_build_configuration_form_alter(array &$form, FormStateInterface $form_state): void {
  $form['additional_info'] = [
    '#markup' => t('Additional information shown in buildConfigurationForm'),
  ];
}

/**
 * Modify the XTMConnectTranslator hasCheckoutSettings method.
 *
 * @param bool $has_checkout_settings
 *   Whether job should have checkout settings.
 * @param \Drupal\tmgmt\JobInterface $job
 *   The tmgmt job entity.
 */
function hook_tmgmt_xtm_connect_has_checkout_settings_alter(bool &$has_checkout_settings, JobInterface $job): void {
  $has_checkout_settings = TRUE;
}

/**
 * Alter xtm_connect translation query before translation request.
 *
 * @param \Drupal\tmgmt\Entity\Job $job
 *   TMGMT Job to be used for translation.
 * @param string $query_string
 *   THe query string.
 * @param array $query_params
 *   The query parameters array.
 */
function hook_tmgmt_xtm_connect_query_string_alter(Job $job, string &$query_string, array $query_params): void {
  if ($job->getSetting('custom_setting') == 1 && $query_params['xyz'] == 1) {
    $query_string .= '&my_custom_var=1';
  }
}
