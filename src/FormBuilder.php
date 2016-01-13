<?php

/**
 * @file
 * Contains \Drupal\securepages\FormBuilder.
 */

namespace Drupal\securepages;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormBuilder as CoreFormBuilder;
use Drupal\Core\Form\FormStateInterface;

/**
 * FormBuilder decorator for the core formbuilder.
 */
class FormBuilder extends CoreFormBuilder {

  /**
   * {@inheritdoc}
   */
  public function prepareForm($form_id, &$form, FormStateInterface &$form_state) {
    // Override \Drupal\Core\Form\FormBuilder::renderPlaceholderFormAction(),
    // the default form action lazy builder, with Secure Pages' variant. Since
    // Secure Pages allows specific forms to opt in, we need to not only
    // override the #lazy_builder callback but also the arguments: the form ID
    // also needs to be known.

    // Only update the action if it is not already set.
    $config = \Drupal::config('securepages.settings');
    if ($config->get('enabled') && !isset($form['#action'])) {
      // Instead of setting an actual action URL, we set the placeholder, which
      // will be replaced at the very last moment. This ensures forms with
      // dynamically generated action URLs don't have poor cacheability.
      // Use the proper API to generate the placeholder, when we have one. See
      // https://www.drupal.org/node/2562341.
      $placeholder = 'form_action_' . hash('crc32b', __METHOD__);

      $form['#attached']['placeholders'][$placeholder] = [
        '#lazy_builder' => ['securepages.form_builder:renderPlaceholderFormAction', [$form_id]],
      ];
      $form['#action'] = $placeholder;
    }

    return parent::prepareForm($form_id, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function renderPlaceholderFormAction() {
    $form_action = parent::renderPlaceholderFormAction();

    // Due to dependency on Request::isSecure().
    $form_action['#cache']['contexts'][] = 'url.site';

    // Due to dependency on \Drupal\securepages\Securepages::matchCurrentUser().
    $form_action['#cache']['contexts'][] = 'user.roles';

    // The generated form action depends on the Secure Pages configuration.
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency(\Drupal::config('securepages.settings'));
    $cacheability->applyTo($form_action);

    return $form_action;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildFormAction() {
    $url = parent::buildFormAction();

    $request = $this->requestStack->getCurrentRequest();

    $config = \Drupal::config('securepages.settings');
    $is_https = $request->isSecure();

    $path = \Drupal::service('path.current')->getPath($request);
    $path_match = Securepages::matchPath($path);
    $role_match = Securepages::matchCurrentUser();
    $form_match = TRUE; // @todo Port & use securepages_match_form().

    // @todo Question whether all this crazy conditionality is really necessary! It'd be better to remove it. Even more so because Drupal 8 is route-based, not path-based.
    if ($role_match || ($path_match && !$is_https) || !(!$path_match && $is_https && $config->get('switch')) || $form_match) {
      $url = $config->get('basepath_ssl') . $url;
    }

    return $url;
  }

}