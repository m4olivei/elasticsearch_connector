<?php

/**
 * @file
 * Contains \Drupal\elasticsearch_connector\Form\ClusterDeleteForm.
 */

namespace Drupal\elasticsearch_connector\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\elasticsearch_connector\ElasticSearch\ClientManager;
use Drupal\elasticsearch_connector\Entity\Cluster;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a confirmation form for deletion of a custom menu.
 */
class ClusterDeleteForm extends EntityConfirmFormBase {

  /**
   * @var ClientManager
   */
  private $clientManager;

  /**
   * The entity manager.
   *
   * This object members must be set to anything other than private in order for
   * \Drupal\Core\DependencyInjection\DependencySerialization to be detected.
   *
   * @var \Drupal\Core\Entity\EntityManager
   */
  protected $entityManager;

  /**
   * Constructs an IndexForm object.
   *
   * @param \Drupal\Core\Entity\EntityManager|\Drupal\Core\Entity\EntityTypeManager $entity_manager
   *   The entity manager.
   * @param \Drupal\elasticsearch_connector\ElasticSearch\ClientManager             $client_manager
   */
  public function __construct(
    EntityTypeManager $entity_manager,
    ClientManager $client_manager
  ) {
    // Setup object members.
    $this->entityManager = $entity_manager;
    $this->clientManager = $client_manager;
  }


  static public function create(ContainerInterface $container) {
    return new static (
      $container->get('entity_type.manager'),
      $container->get('elasticsearch_connector.client_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return t(
      'Are you sure you want to delete the cluster %title?',
      array('%title' => $this->entity->label())
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t(
      'Deleting a cluster will disable all its indexes and their searches.'
    );
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $storage = $this->entityManager->getStorage('elasticsearch_index');
    $indices = $storage->loadByProperties(
      array('server' => $this->entity->cluster_id)
    );

    // TODO: handle indices linked to the cluster being deleted.
    if (count($indices)) {
      drupal_set_message(
        $this->t(
          'The cluster %title cannot be deleted as it still has indices.',
          array('%title' => $this->entity->label())
        ),
        'error'
      );
      return;
    }

    if ($this->entity->id() == Cluster::getDefaultCluster()) {
      drupal_set_message(
        $this->t(
          'The cluster %title cannot be deleted as it is set as the default cluster.',
          array('%title' => $this->entity->label())
        ),
        'error'
      );
    }
    else {
      $this->entity->delete();
      drupal_set_message(
        $this->t(
          'The cluster %title has been deleted.',
          array('%title' => $this->entity->label())
        )
      );
      $form_state->setRedirect('elasticsearch_connector.clusters');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url(
      'elasticsearch_connector.clusters',
      array('elasticsearch_cluster' => $this->entity->id())
    );
  }

}