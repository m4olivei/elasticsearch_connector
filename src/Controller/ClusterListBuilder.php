<?php

/**
 * @file
 * Contains \Drupal\elasticsearch_connector\Controller\ClusterListBuilder.
 */

namespace Drupal\elasticsearch_connector\Controller;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Url;
use Drupal\elasticsearch_connector\ElasticSearch\ClientConnector;
use Drupal\elasticsearch_connector\ElasticSearch\ClientManager;
use Drupal\elasticsearch_connector\Entity\Cluster;
use Drupal\elasticsearch_connector\Entity\Index;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides a listing of Clusters along with their indices.
 */
class ClusterListBuilder extends ConfigEntityListBuilder {

  /**
   * EntityStorageInterface
   */
  protected $indexStorage;

  /**
   * @var ClientManager
   */
  private $clientManager;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(
    ContainerInterface $container,
    EntityTypeInterface $entity_type
  ) {
    return new static(
      $entity_type,
      $container->get('entity.manager')->getStorage($entity_type->id()),
      $container->get('entity.manager')->getStorage('elasticsearch_index'),
      $container->get('elasticsearch_connector.client_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    EntityStorageInterface $index_storage,
    ClientManager $client_manager
  ) {
    parent::__construct($entity_type, $storage);
    $this->indexStorage = $index_storage;
    $this->clientManager = $client_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function load() {
    /** @var Cluster[] $clusters */
    $clusters = $this->storage->loadMultiple();
    /** @var Index[] $indices */
    $indices = $this->indexStorage->loadMultiple();

    $cluster_groups = array();
    $lone_indices = array();
    foreach ($clusters as $cluster) {
      $cluster_group = array(
        'cluster.' . $cluster->cluster_id => $cluster,
      );
      foreach ($indices as $index) {
        if ($index->server == $cluster->cluster_id) {
          $cluster_group['index.' . $index->index_id] = $index;
        }
        elseif ($index->server == NULL) {
          $lone_indices['index.' . $index->index_id] = $index;
        }
      }
      $cluster_groups['cluster.' . $cluster->cluster_id] = $cluster_group;
    }
    $cluster_groups['cluster.lone'] = $lone_indices;

    return $cluster_groups;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return array(
      'type' => $this->t('Type'),
      'title' => $this->t('Name'),
      'machine_name' => $this->t('Machine Name'),
      'status' => $this->t('Status'),
      'cluster_status' => $this->t('Cluster Status'),
    ) + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    if ($entity instanceof Cluster) {
      $es_client = $this->clientManager->getClientForCluster($entity);
    }
    elseif ($entity instanceof Index) {
      $cluster = Cluster::load($entity->server);
      $es_client = $this->clientManager->getClientForCluster($cluster);
    } else {
      throw new NotFoundHttpException();
    }

    $client_connector = new ClientConnector($es_client);

    $row = parent::buildRow($entity);
    $result = array();
    $status = NULL;
    if (isset($entity->cluster_id)) {
      $cluster = Cluster::load($entity->cluster_id);

      if ($client_connector->isClusterOk()) {
        $cluster_health = $es_client->cluster()->health();
        $status = $cluster_health['status'];
      }
      else {
        $status = t('Not available');
      }
      $result = array(
        'data' => array(
          'type' => array(
            'data' => $this->t('Cluster'),
          ),
          'title' => array(
            'data' => array(
                '#type' => 'link',
                '#title' => $entity->label(),
              ) + $entity->urlInfo('canonical')->toRenderArray(),
          ),
          'machine_name' => array(
            'data' => $entity->id(),
          ),
          'status' => array(
            'data' => $cluster->status ? 'Active' : 'Inactive',
          ),
          'clusterStatus' => array(
            'data' => $status,
          ),
          'operations' => $row['operations'],
        ),
        'title' => $this->t(
          'Machine name: @name',
          array('@name' => $entity->id())
        ),
      );
    }
    elseif (isset($entity->index_id)) {
      $result = array(
        'data' => array(
          'type' => array(
            'data' => $this->t('Index'),
          ),
          'title' => array(
            'data' => $entity->label(),
          ),
          'machine_name' => array(
            'data' => $entity->id(),
          ),
          'status' => array(
            'data' => '',
          ),
          'clusterStatus' => array(
            'data' => '-',
          ),
          'operations' => $row['operations'],
        ),
        'title' => $this->t(
          'Machine name: @name',
          array('@name' => $entity->id())
        ),
      );
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);

    if (isset($entity->cluster_id)) {
      $operations['info'] = array(
        'title' => $this->t('Info'),
        'weight' => 20,
        'url' => new Url(
          'entity.elasticsearch_cluster.canonical',
          array('elasticsearch_cluster' => $entity->id())
        ),
      );
    }
    elseif (isset($entity->index_id)) {
      $operations['delete'] = array(
        'title' => $this->t('Delete'),
        'weight' => 20,
        'url' => new Url(
          'entity.elasticsearch_index.delete_form',
          array('elasticsearch_index' => $entity->id())
        ),
      );
    }
    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $entity_groups = $this->load();
    $list['#type'] = 'container';
    $list['clusters'] = array(
      '#type' => 'table',
      '#header' => $this->buildHeader(),
      '#rows' => array(),
      '#empty' => $this->t(
        'No clusters available. <a href="@link">Add new cluster</a>.',
        array(
          '@link' => \Drupal::urlGenerator()->generate(
            'entity.elasticsearch_cluster.add_form'
          ),
        )
      ),
    );
    foreach ($entity_groups as $cluster_group) {
      foreach ($cluster_group as $entity) {
        $list['clusters']['#rows'][$entity->id()] = $this->buildRow($entity);
      }
    }
    return $list;
  }

}