<?php

declare(strict_types=1);

namespace Drupal\openid_connect_username_sanitizer\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for the OpenID Connect Username Sanitizer module.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['openid_connect_username_sanitizer.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'openid_connect_username_sanitizer_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('openid_connect_username_sanitizer.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Sanitise OpenID Connect username claims'),
      '#description' => $this->t('When enabled, the preferred_username and name claims returned by an OpenID Connect identity provider are checked against the same rules Drupal uses to validate usernames, and any character Drupal would reject is stripped before openid_connect uses the claim to create or update a user account. The result is also truncated, leaving headroom for the "_1", "_2", etc. suffix openid_connect adds to a duplicate username. See this module\'s README.md for the full background. Disable this only if you have a specific reason to see openid_connect\'s original, unmodified behaviour.'),
      '#default_value' => $config->get('enabled'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('openid_connect_username_sanitizer.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
