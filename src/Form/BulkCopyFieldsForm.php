<?php

namespace Drupal\bulk_copy_fields\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\user\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bulk Copy Fields Form.
 */
class BulkCopyFieldsForm extends FormBase implements FormInterface {

  /**
   * Set a var to make stepthrough form.
   *
   * @var step
   */
  protected $step = 1;
  /**
   * Keep track of user input.
   *
   * @var userInput
   */
  protected $userInput = [];

  /**
   * To store input.
   *
   * @var \Drupal\user\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * The session manager.
   *
   * @var \Drupal\Core\Session\SessionManagerInterface
   */
  private $sessionManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  private $currentUser;

  /**
   * Constructs a \Drupal\bulk_copy_fields\Form\BulkCopyFieldsForm.
   *
   * @param \Drupal\user\PrivateTempStoreFactory $temp_store_factory
   *   Function construct temp store factory.
   * @param \Drupal\Core\Session\SessionManagerInterface $session_manager
   *   Function construct session manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   Function construct current user.
   */
  public function __construct(PrivateTempStoreFactory $temp_store_factory, SessionManagerInterface $session_manager, AccountInterface $current_user) {
    $this->tempStoreFactory = $temp_store_factory;
    $this->sessionManager = $session_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('user.private_tempstore'),
      $container->get('session_manager'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'bulk_copy_fields_form';
  }

  /**
   * {@inheritdoc}
   */
  public function bulkCopyFields() {
    $entities = $this->userInput['entities'];
    $fields = $this->userInput['fields'];
    $batch = [
      'title' => t('Updating Fields...'),
      'operations' => [
        [
          '\Drupal\bulk_copy_fields\BulkCopyFields::copyFields',
          [$entities, $fields],
        ],
      ],
      'finished' => '\Drupal\bulk_copy_fields\BulkCopyFields::bulkCopyFieldsFinishedCallback',
    ];
    batch_set($batch);
    return 'All fields were copied successfully';
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    switch ($this->step) {
      case 1:
        $this->userInput['fields'] = array_filter($form_state->getValues()['table']);
        $form_state->setRebuild();
        break;

      case 2:
        $data_to_process = array_diff_key(
                            $form_state->getValues(),
                            array_flip(
                              [
                                'op',
                                'submit',
                                'form_id',
                                'form_build_id',
                                'form_token',
                              ]
                            )
                          );
        $this->userInput['fields'] = array_merge($this->userInput['fields'], $data_to_process);
        $form_state->setRebuild();
        break;

      case 3:
        if (method_exists($this, 'bulkCopyFields')) {
          $return_verify = $this->bulkCopyFields();
        }
        drupal_set_message($return_verify);
        \Drupal::service("router.builder")->rebuild();
        break;

    }
    $this->step++;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    if (isset($this->form)) {
      $form = $this->form;
    }
    $form['#title'] = t('Bulk Copy Fields');
    $submit_label = 'Next';

    switch ($this->step) {
      case 1:
        // Retrieve IDs from the temporary storage.
        $this->userInput['entities'] = $this->tempStoreFactory
          ->get('bulk_copy_fields_ids')
          ->get($this->currentUser->id());
        $options = [];
        foreach ($this->userInput['entities'] as $id => $entity) {
          $this->entity = $entity;
          $fields = $entity->getFieldDefinitions();
          foreach ($fields as $field) {
            if ($field->getFieldStorageDefinition()->isBaseField() === FALSE && !isset($options[$field->getName()])) {
              $options[$field->getName()]['field_name'] = $field->getName();
            }
          }
        }
        $header = [
          'field_name' => t('Field Name'),
        ];
        $form['#title'] .= ' - ' . t('Select Fields to Copy Values From');
        $form['table'] = [
          '#type' => 'tableselect',
          '#header' => $header,
          '#options' => $options,
          '#empty' => t('No fields found'),
        ];
        break;

      case 2:
        // Get all fields possible.
        $all_options = [];
        $field_types = [];
        foreach ($this->userInput['entities'] as $id => $entity) {
          $fields = $entity->getFieldDefinitions();
          foreach ($fields as $field) {
            if ($field->getFieldStorageDefinition()->isBaseField() === FALSE) {
              $type = $field->getType();
              // Allow er rev to map with er.
              if (strpos($type, 'entity_reference_revisions') !== FALSE) {
                $type = 'entity_reference';
              }
              $all_options[$field->getName()] = $type;
              $field_types[$type][$field->getName()] = $field->getName();
            }
          }
        }
        foreach ($this->userInput['fields'] as $field_name) {
          $options = array_unique($field_types[$all_options[$field_name]]);
          $form[$field_name] = [
            '#type' => 'select',
            '#title' => t('From Field @field_name To Field:', ['@field_name' => $field_name]),
            '#options' => $options,
            '#default_value' => $options[$field_name],
          ];
        }
        $form['#title'] .= ' - ' . t('Enter New Field to copy values to');
        break;

      case 3:
        $form['#title'] .= ' - ' . t('Are you sure you want to copy @count_fields fields on @count_entities entities?',
                                     [
                                       '@count_fields' => count($this->userInput['fields']),
                                       '@count_entities' => count($this->userInput['entities']),
                                     ]);
        $submit_label = 'Copy Fields';
        break;

    }
    drupal_set_message('This module is experiemental. PLEASE do not use on production databases without prior testing and a complete database dump.', 'warning');
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $submit_label,
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // TODO.
  }

}
