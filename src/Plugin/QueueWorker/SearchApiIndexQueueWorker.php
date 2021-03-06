<?php

/**
 * @file
 * Contains \Drupal\external_entities_wikibase\Plugin\QueueWorker\SearchApiIndexQueueWorker.
 */

namespace Drupal\external_entities_wikibase\Plugin\QueueWorker;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\search_api\Plugin\search_api\datasource\ContentEntityTrackingManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add items to the queue for reindexing.
 *
 * @QueueWorker(
 *   id = "external_entities_wikibase_search_api_queue",
 *   title = @Translation("Reindex changed items to Search API."),
 *   cron = {"time" = 60}
 * )
 */
class SearchApiIndexQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The tracking manager.
   *
   * @var ContentEntityTrackingManager
   */
  protected $trackingManager;

  /**
   * The entity type manager.
   *
   * @var EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Creates a new SearchApiIndexQueue object.
   *
   * @param ContentEntityTrackingManager $tracking_manager
   *   The tracking manager.
   * @param EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(ContentEntityTrackingManager $tracking_manager, EntityTypeManagerInterface $entity_type_manager) {
    $this->trackingManager = $tracking_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('search_api.entity_datasource.tracking_manager'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $storage = $this->entityTypeManager->getStorage($data['storage']);
    $entity = $storage->load($data['id']);
    assert ($entity instanceof ContentEntityInterface);
    $this->trackingManager->trackEntityChange($entity);
  }
}
