<?php

/**
 * @file
 * Contains \Drupal\external_entities_wikibase\Plugin\StorageClient\Wikibase.
 */

namespace Drupal\external_entities_wikibase\Plugin\ExternalEntities\StorageClient;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\external_entities\Plugin\ExternalEntities\StorageClient\Rest;
use Drupal\external_entities\ResponseDecoder\ResponseDecoderFactoryInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * External entities storage client for wikibase endpoint.
 *
 * @ExternalEntityStorageClient(
 *   id = "wikibase",
 *   label = @Translation("WikiBase"),
 *   description = @Translation("Retrieves external entities from a WikiBase install.")
 * )
 */
class WikiBase extends Rest
{

  /**
   * The entity type manager.
   *
   * @var EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module handler service.
   *
   * @var ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The queue factory.
   *
   * @var QueueFactory
   */
  protected $queueFactory;

  /**
   * Constructs a Rest object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param TranslationInterface $string_translation
   *   The string translation service.
   * @param ResponseDecoderFactoryInterface $response_decoder_factory
   *   The response decoder factory service.
   * @param ClientInterface $http_client
   *   A Guzzle client object.
   * @param EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param ModuleHandlerInterface $module_handler
   *   The module handler service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    TranslationInterface $string_translation,
    ResponseDecoderFactoryInterface $response_decoder_factory,
    ClientInterface $http_client,
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler,
    QueueFactory $queue_factory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $string_translation, $response_decoder_factory, $http_client);
    $this->httpClient = $http_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->queueFactory = $queue_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('string_translation'),
      $container->get('external_entities.response_decoder_factory'),
      $container->get('http_client'),
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('queue'),
    );
  }

  /**
   * Config values can not contain periods, so we replace them with something else.
   */
  private const PERIOD_REPLACEMENT = '_-_';

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array
  {
    return [
      'endpoint' => NULL,
      'response_format' => NULL,
      'pager' => [
        'default_limit' => 50,
        'page_parameter' => NULL,
        'page_parameter_type' => NULL,
        'page_size_parameter' => NULL,
        'page_size_parameter_type' => NULL,
      ],
      'api_key' => [
        'header_name' => NULL,
        'key' => NULL,
      ],
      'parameters' => [
        'prefix' => NULL,
        'list' => NULL,
        'single' => NULL,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array
  {
    $form['sparql_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sparql Endpoint'),
      '#required' => TRUE,
      '#default_value' => $this->configuration['sparql_endpoint'],
    ];
    $form['rest_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Rest Endpoint'),
      '#required' => TRUE,
      '#default_value' => $this->configuration['rest_endpoint'],
    ];

    $form['pager'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Pager settings'),
    ];

    $form['pager']['default_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Default number of items per page'),
      '#default_value' => $this->configuration['pager']['default_limit'],
    ];

    $form['parameters'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Parameters'),
    ];

    $form['parameters']['prefix'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prefix'),
      '#description' => $this->t('Enter the prefix for the SPARQL queries.'),
      '#default_value' => str_replace(self::PERIOD_REPLACEMENT, '.', $this->getParametersFormDefaultValue('prefix')),
    ];

    $form['parameters']['list'] = [
      '#type' => 'textarea',
      '#title' => $this->t('SPARQL query'),
      '#description' => $this->t('Enter the SPARQL query for retrieving a list of items. The id variable have to be named ?item. Example query: SELECT ?item WHERE {?item wdt:P14 wd:Q1}'),
      '#default_value' => str_replace(self::PERIOD_REPLACEMENT, '.', $this->getParametersFormDefaultValue('list')),
    ];

    $form['parameters']['single'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Parameters for single entity REST query'),
      '#description' => $this->t('Enter the parameters used in the query for a single object.'),
      '#default_value' => $this->getParametersFormDefaultValue('single'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $parameters = $form_state->getValue('parameters');
    foreach ($parameters as $type => $value) {
      if (in_array($type, ['prefix', 'list'])) {
        $parameters[$type] = str_replace('.', self::PERIOD_REPLACEMENT, $value);
      }
    }
    $form_state->setValue('parameters', $parameters);

    $this->setConfiguration($form_state->getValues());
    parent::validateConfigurationForm($form, $form_state);
    if ($form['parameters']['list']['#default_value'] !== $form_state->getValue(['parameters', 'list']) && $this->moduleHandler->moduleExists('search_api')) {
      $indexes = $this->entityTypeManager->getStorage('search_api_index')->loadMultiple();
      $entity_id = $form_state->getFormObject()->getEntity()->id();
      foreach ($indexes as $index) {
        assert($index instanceof \Drupal\search_api\Entity\Index);
        if (array_key_exists("entity:$entity_id", $index->get('datasource_settings'))) {
          // Off-load to a queue, so retracking will be run on cron and not directly at the end of the request.
          $queue = $this->queueFactory->get('external_entities_wikibase_search_api_retrack_queue');
          assert($queue instanceof QueueInterface);
          $queue->createItem(['id' => $index->id()]);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function load($id) {
    // Be agnostic about the $id. This can be a QID or an integer without the Q used in Drupal.
    $wiki_id = strpos($id, 'Q') === 0 ? $id : 'Q' . $id;
    $query = $this->getSingleQueryParameters($wiki_id);
    $response = $this->httpClient->request(
      'POST',
      $this->configuration['rest_endpoint'],
      [
        'headers' => $this->getHttpHeaders(),
        'query' => $query,
      ]
    );

    $result = $this
      ->getResponseDecoderFactory()
      ->getDecoder('json')
      ->decode($response->getBody());

    if (!empty($result['entities'])) {
      // Replace the QID with an integer to be used with Drupal.
      // For example Search API needs integer id's to sort the results and
      // track indexing status.
      $result['entities'][$wiki_id]['id'] = (integer)substr($result['entities'][$wiki_id]['id'], 1);
      return $result['entities'][$wiki_id];
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function countQuery(array $parameters = []): int {
    $parameters['count'] = TRUE;
    return count( $this->query($parameters));
  }

  /**
   * {@inheritdoc}
   */
  public function query(array $parameters = [], array $sorts = [], $start = NULL, $length = NULL) {
    if (!empty(array_filter($parameters, static function($parameter) {
      return $parameter['field'] === 'change';
    }))) {
      $query = $this->getRecentUpdatedListParameters($parameters);
    }
    else {
      $query = $this->getListQueryParameters($parameters, $start, $length);
    }
    $response = $this->httpClient->request(
      'GET',
      $this->configuration['sparql_endpoint'],
      [
        'headers' => $this->getHttpHeaders(),
        'query' => $query,
      ]
    );

    $body = $response->getBody() . '';

    $results = $this
      ->getResponseDecoderFactory()
      ->getDecoder('json')
      ->decode($body);
    $items = [];
    if (!empty($results['results']['bindings'])) {
      $items = array_values($results['results']['bindings']);
    }

    if (isset($parameters['count']) && $parameters['count']) {
      return $items;
    }

    foreach ($items as &$item) {
      $item['id'] = $item['id']['value'];
    }

    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function getSingleQueryParameters($id, array $parameters = []) {
    return parent::getSingleQueryParameters($id, [
      [
        'field' => 'ids',
        'value' => $id,
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getListQueryParameters(array $parameters = [], $start = NULL, $length = NULL): array {
    $query_parameters = [];

    $prefix = implode(' ', $this->configuration['parameters']['prefix']);
    $query = str_replace(self::PERIOD_REPLACEMENT, '.', $prefix);
    if (!empty($this->configuration['parameters']['list'])) {
      $query .= str_replace(self::PERIOD_REPLACEMENT, '.', implode(' ', $this->configuration['parameters']['list']));
      $query = $this->addIdVariable($query);
      foreach ($parameters as $parameter) {
        if (isset($parameter['field'])) {
          $parameter['operator'] = $parameter['operator'] ?? '=';
          $query = $this->addFilter($query, $parameter);
        }
      }
      $query .= ' ORDER BY ?id';
    }
    $query = $this->addIdSelector($query);
    if (!$parameters['count']) {
      $query .= ' LIMIT ';
      $query .= $this->configuration['pager']['default_limit'];
      $start = $start ?? 0;
      $query .= ' OFFSET ' . $start;
    }
    $query_parameters['query'] = $query;

    return $query_parameters;
  }

  /**
   * Sets the parameters for retrieving items changed since a specific date.
   *
   * @param array $parameters
   * @return array
   */
  public function getRecentUpdatedListParameters(array $parameters): array {
    $query_parameters = [];

    $prefix = implode(' ', $this->configuration['parameters']['prefix']);
    $query = str_replace(self::PERIOD_REPLACEMENT, '.', $prefix);
    if (!empty($this->configuration['parameters']['list'])) {
      $query .= str_replace(self::PERIOD_REPLACEMENT, '.', implode(' ', $this->configuration['parameters']['list']));
      $query = $this->addDateVariable($query);
      $query = $this->addIdVariable($query);
      foreach ($parameters as $parameter) {
        if (isset($parameter['field'])) {
          $parameter['operator'] = $parameter['operator'] ?? '=';
          $query = $this->addFilter($query, $parameter);
        }
      }
      $query .= ' ORDER BY ?change';
    }
    $query = $this->addIdSelector($query);
    $query_parameters['query'] = $query;

    return $query_parameters;

  }

  /**
   * Adds an id variable to the query with the digits of the QID.
   *
   * @param string $query
   * @return string
   */
  private function addIdVariable(string $query): string {
    $id_bind = 'BIND(xsd:integer(STRAFTER(str(?item), "/Q")) as ?id). ';
    $re = '/SELECT(.|\n)*(?=WHERE)(WHERE).*{\K/m';
    return preg_replace($re, $id_bind, $query);
  }

  /**
   * Adds an date variable to filter recent changed items.
   *
   * @param string $query
   * @return string
   */
  private function addDateVariable(string $query): string {
    $id_bind = '?item schema:dateModified ?change. ';
    $re = '/SELECT(.|\n)*(?=WHERE)(WHERE).*{\K/m';
    return preg_replace($re, $id_bind, $query);
  }

  /**
   * Adds a filter used in paging of search api.
   *
   * @param string $query
   * @param array $parameter
   * @return string
   */
  private function addFilter(string $query, array $parameter): string {
    $filter = 'FILTER(?' . $parameter['field'] . $parameter['operator'] . $parameter['value'] . '). ';
    $re = '/SELECT(.|\n)*(?=WHERE)(WHERE).*{\K/m';
    return preg_replace($re, $filter, $query);
  }

  /**
   * Adds the id selector.
   *
   * @param string $query
   * @return string
   */
  private function addIdSelector(string $query): string {
    $re = '/SELECT.*\K(\?item)/mU';
    return preg_replace($re, '?item ?id', $query);
  }

  /**
   * {@inheritdoc}
   */
  public function getHttpHeaders(): array {
    $headers = parent::getHttpHeaders();
    $headers['Content-Type'] = "application/sparql-query+json";
    $headers['Accept'] = "application/json";
    return $headers;
  }
}
