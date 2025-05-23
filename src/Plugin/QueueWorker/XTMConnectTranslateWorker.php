<?php

declare(strict_types=1);

namespace Drupal\tmgmt_xtm_connect\Plugin\QueueWorker;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tmgmt_xtm_connect\Plugin\tmgmt\Translator\XTMConnectTranslator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 *  @QueueWorker(
 *    id = "xtm_connect_translate_worker",
 *    title = @Translation("TMGMT XTMConnect translate queue worker"),
 *    cron = {"time" = 120}
 *  )
 */
class XTMConnectTranslateWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface
{
  use StringTranslationTrait;

  /**
   * The logger channel interface.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|null
   */
  protected ?LoggerChannelInterface $logger;

  /**
   * The container.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerInterface
   */
  private ContainerInterface $container;

  /**
   * {@inheritDoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ContainerInterface $container)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->container = $container;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): XTMConnectTranslateWorker
  {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container,
    );
  }

  /**
   * Job item translation handler via Cron.
   *
   * @param $data
   *   An associative array containing the following, passed from XTMConnectTranslator.
   *   [
   *     'job' => $job,
   *     'job_item' => $job,
   *     'q' => $q,
   *     'translation' => $translation,
   *     'keys_sequence' => $keys_sequence,
   *   ]
   *
   * @return void
   */
  public function processItem($data): void
  {
    try {
      $context = [];
      $job = $data['job'];
      XTMConnectTranslator::batchRequestTranslation($job, $context);
    } catch (\Exception $exception) {
      $this->logger()->error($this->t(
        'Unable to translate job: @id, the following exception was thrown: @message',
        [
          '@id' => $job->id(),
          '@message' => $exception->getMessage()
        ],
      ));
    }
  }

  /**
   * Getter for the logger.
   *
   * @return \Drupal\Core\Logger\LoggerChannelInterface
   */
  public function logger(): LoggerChannelInterface
  {
    if (empty($this->logger)) {
      $this->logger = $this->container->get('logger.factory')->get('tmgmt_xtm_connect');
    }
    return $this->logger;
  }

}
