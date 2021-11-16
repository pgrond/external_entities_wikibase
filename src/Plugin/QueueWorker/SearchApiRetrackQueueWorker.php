<?php

/**
 * @file
 * Contains \Drupal\external_entities_wikibase\Plugin\QueueWorker\SearchApiIndexQueueWorker.
 */

namespace Drupal\external_entities_wikibase\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\search_api\Entity\Index;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Triggers retracking of items in the search index.
 *
 * @QueueWorker(
 *   id = "external_entities_wikibase_search_api_retrack_queue",
 *   title = @Translation("Retrack all items in this index."),
 *   cron = {"time" = 60}
 * )
 */
class SearchApiRetrackQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Creates a new SearchApiIndexQueue object.
   *
   * @param EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $storage = $this->entityTypeManager->getStorage('search_api_index');
    $index = $storage->load($data['id']);
    assert($index instanceof Index);
    $index->rebuildTracker();
  }
}
