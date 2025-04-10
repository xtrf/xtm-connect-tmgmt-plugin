<?php

namespace Drupal\tmgmt_xtm_connect\Plugin\tmgmt\Translator;

use Drupal\Component\Utility\Html;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueInterface;
use Drupal\tmgmt\ContinuousTranslatorInterface;
use Drupal\tmgmt\Data;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\Entity\Translator;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt\Translator\AvailableResult;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\tmgmt\TranslatorPluginBase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * XTMConnect translator plugin.
 *
 * @TranslatorPlugin(
 *   id = "xtm_connect",
 *   label = @Translation("XTM Connect"),
 *   description = @Translation("XTM Connect Translator service."),
 *   ui = "Drupal\tmgmt_xtm_connect\XTMConnectTranslatorUi",
 *   logo = "icons/xtm_connect_logo.png",
 *   map_remote_languages = FALSE
 * )
 */
class XTMConnectTranslator extends TranslatorPluginBase implements ContainerFactoryPluginInterface, ContinuousTranslatorInterface
{

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  protected $escapeStart = '<xtm_connect translate="no">';

  /**
   * {@inheritdoc}
   */
  protected $escapeEnd = '</xtm_connect>';

  /**
   * Name of parameter that contains source string to be translated.
   *
   * @var string
   */
  protected static string $qParamName = 'text';


  /**
   * Guzzle HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $client;

  /**
   * TMGMT data service.
   *
   * @var \Drupal\tmgmt\Data
   */
  protected Data $tmgmtData;

  /**
   * The queue object.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected QueueInterface $queue;

  /**
   * If the process is being run via cron or not.
   *
   * @var bool|null
   */
  protected ?bool $isCron;

  /**
   * Translation service URL.
   *
   * @var string
   */
  protected string $translatorUrl = '';

  /**
   * Translation usage service URL.
   *
   * @var string
   */
  protected string $translatorUsageUrl = '';

  /**
   * Translation glossary service URL.
   *
   * @var string
   */
  protected string $translatorGlossaryUrl = '';

  /**
   * Constructs a XTMConnectProTranslator object.
   *
   * @param \GuzzleHttp\ClientInterface $client
   *   The Guzzle HTTP client.
   * @param \Drupal\tmgmt\Data $tmgmt_data
   *   The Guzzle HTTP client.
   * @param \Drupal\Core\Queue\QueueInterface $queue
   *   The queue object.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   */
  public function __construct(ClientInterface $client, Data $tmgmt_data, QueueInterface $queue, array $configuration, string $plugin_id, array $plugin_definition)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->client = $client;
    $this->tmgmtData = $tmgmt_data;
    $this->queue = $queue;
    $this->isCron = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self
  {
    // @phpstan-ignore-next-line
    return new static(
      $container->get('http_client'),
      $container->get('tmgmt.data'),
      $container->get('queue')->get('xtm_connect_translate_worker', TRUE),
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  /**
   * {@inheritdoc}
   */
  public function checkAvailable(TranslatorInterface $translator): AvailableResult
  {
    if ($translator->getSetting('auth_key') && $translator->getSetting('url')) {
      return AvailableResult::yes();
    }

    return AvailableResult::no($this->t('@translator is not available. Make sure it is properly <a href=:configured>configured</a>.', [
      '@translator' => $translator->label(),
      ':configured' => $translator->toUrl()->toString(),
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function requestTranslation(JobInterface $job): void
  {
    $this->sendJobForTranslation($job);
    if (!$job->isRejected()) {
      $job->submitted('The translation job has been submitted.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function hasCheckoutSettings(JobInterface $job): bool
  {
    // Defaults to not having checkout settings.
    $has_checkout_settings = FALSE;

    // Allow alteration of hasCheckoutSettings.
    \Drupal::moduleHandler()->alter('tmgmt_xtm_connect_has_checkout_settings', $has_checkout_settings, $job);

    return $has_checkout_settings;
  }

  /**
   * Local method to do request to XTMConnect Translate service.
   *
   * @param \Drupal\tmgmt\Entity\Job $job
   *   TMGMT Job to be used for translation.
   * @param array $query_params
   *   (Optional) Additional query params to be passed into the request.
   * @param array $options
   *   (Optional) Additional options that will passed to drupal_http_request().
   *
   * @return array
   *   Unserialized JSON response from XTMConnect API.
   *
   * @throws \Drupal\tmgmt\TMGMTException|\GuzzleHttp\Exception\GuzzleException
   *   - Unable to connect to the XTMConnect API Service
   *   - Error returned by the XTMConnect API Service.
   */
  protected static function doRequest(Job $job, array $query_params = [], array $options = []): array
  {
    // Get translator of job.
    $translator = $job->getTranslator();
    $jobId = $job->id();
    $url = $translator->getSetting('url');
    // Define headers.
    $headers = [
      'Content-Type' => 'application/json',
      'Authorization' => $translator->getSetting('auth_key'),
    ];

    // build payload
    $payload = json_encode([
      'jobs' => $query_params["jobs"],
      'batch_id' => $query_params['batch_id'],
    ]);

    // Allow alteration of query string.
    \Drupal::moduleHandler()->alter('tmgmt_xtm_connect_query_string', $job, $payload, $query_params);

    // Build request object.
    $request = new Request('POST', $url, $headers, $payload);

    // Send the request with the query.
    try {
      $response = \Drupal::httpClient()->send($request);
    } catch (RequestException $e) {
      if ($e->hasResponse()) {
        $response = $e->getResponse();
        if ($response instanceof ResponseInterface) {
          throw new TMGMTException('XTMConnect API service returned following error: @error', ['@error' => $response->getReasonPhrase()]);
        }
      } else {
        throw new TMGMTException('XTMConnect API service returned following error: @error', ['@error' => $e->getMessage()]);
      }
    }

    // Process the JSON result into array.
    if ($response instanceof ResponseInterface) {
      $statusCode = $response->getStatusCode();
      if ($statusCode === 204) {
        // Return empty array if 204 (No Content) response.
        return ['translations' => []];
      } else {
        $return = json_decode($response->getBody(), TRUE);
        return $return;
      }
    }
    return ['translations' => []];
  }

  /**
   * Get translatorUrl.
   */
  final public function getTranslatorUrl(): string
  {
    return $this->translatorUrl ?? '';
  }

  /**
   * Get translatorUsageUrl.
   */
  final public function getUsageUrl(): string
  {
    return $this->translatorUsageUrl ?? '';
  }

  /**
   * Get translatorGlossaryUrl.
   */
  final public function getGlossaryUrl(): string
  {
    return $this->translatorGlossaryUrl ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function sendJobForTranslation(Job $job): void
  {
    /** @var \Drupal\tmgmt\Entity\Job $job */
    if ($job->getSetting('processed') && !$job->isContinuous()) {
      return;
    }

    $batch_id = $job->getSetting('batch_id');
    $rawJobs = \Drupal::entityQuery('tmgmt_job')
      ->condition('settings', $batch_id, 'CONTAINS')
      ->sort('tjid', 'DESC')
      ->accessCheck(FALSE)
      ->execute();

    $settings['batch_id'] = $batch_id;
    $settings['processed'] = true;

    $sibling_jobs = Job::loadMultiple($rawJobs);
    foreach ($sibling_jobs as $sibling_job) {
      $sibling_job->set('settings', $settings);
    }

    // Set batch operations.
    $batch = [
      'operations' => [
        [
          [self::class, 'batchRequestTranslation'],
          [$job]
        ]
      ],
      'title' => t('Processing translation request'),
      'init_message' => t('Starting translation request.'),
      'progress_message' => t('Processed @current out of @total.'),
      'error_message' => t('Translation request has encountered an error.')
    ];
    batch_set($batch);
  }

  public function requestJobItemsTranslation(array $job_items): void
  {
    if (empty($job_items)) {
      return;
    }
    foreach ($job_items as $job_item) {
      $job = $job_item->getJob();
      if ($job->isContinuous()) {
        $job_item->active();
      }
    }

    if ($this->isCron()) {
      $this->queue->createItem([
        'job' => $job,
      ]);
    } else {
      $this->sendJobForTranslation($job);
    }

  }
  /**
   * Batch 'operation' callback for requesting translation.
   *
   * @param \Drupal\tmgmt\Entity\Job $job
   *   The tmgmt job entity.
   * @param array $text
   *   The text to be translated.
   * @param array $translation
   *   The translated text.
   * @param array $keys_sequence
   *   Array of field name keys.
   * @param array $context
   *   The sandbox context.
   */
  public static function batchRequestTranslation(Job $job, array &$context): void
  {

    $batch_id = $job->getSetting('batch_id');
    $rawJobs = \Drupal::entityQuery('tmgmt_job')
      ->condition('settings', $batch_id, 'CONTAINS')
      ->sort('tjid', 'DESC')
      ->accessCheck(FALSE)
      ->execute();

    $sibling_jobs = Job::loadMultiple($rawJobs);
    $jobs = [];

    foreach ($sibling_jobs as $sibling_job) {
      $jobItems = $sibling_job->getItems();
      if (count($jobItems) > 0) {
        $jobs[] = array(
          'job_id' => $sibling_job->id(),
          'source_lang' => $sibling_job->getRemoteSourceLanguage(),
          'target_lang' => $sibling_job->getRemoteTargetLanguage(),
        );
      }
    }

    // Build query params.
    $query_params = [
      'jobs' => $jobs,
      'batch_id' => $batch_id,
    ];

    self::doRequest($job, $query_params);

  }


  /**
   * Local method to do request to XTMConnect API Usage service.
   *
   * @param \Drupal\tmgmt\Entity\Translator $translator
   *   The translator entity to get the settings from.
   *
   * @return array|int
   *   Unserialized JSON response from XTMConnect API or error code.
   *
   * @throws \GuzzleHttp\Exception\BadResponseException|\GuzzleHttp\Exception\GuzzleException
   *   - Unable to connect to the XTMConnect API Service
   *   - Error returned by the XTMConnect API Service.
   */
  public function validateAPI(Translator $translator)
  {
    // Set custom data for testing purposes, if available.
    $url = rtrim($translator->getSetting('url'), '/') . '/health';
    // Prepare Guzzle Object.
    $headers = [
      'Content-Type' => 'application/json',
      'Authorization' => $translator->getSetting('auth_key'),
    ];
    $request = new Request('GET', $url, $headers);

    try {
      $response = $this->client->send($request);
      return json_decode($response->getBody(), TRUE);
    } catch (\Exception $e) {
      return false;
    }
  }

  /**
   * Determine whether the process is being run via TMGMT cron.
   *
   * @param  int $backtrace_limit
   *   The amount of items to limit in the backtrace.
   *
   * @return bool
   */
  protected function isCron(int $backtrace_limit = 3): bool
  {
    if (!is_null($this->isCron)) {
      return $this->isCron;
    }
    $this->isCron = FALSE;
    foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, $backtrace_limit) as $item) {
      if ($item['function'] === 'tmgmt_cron') {
        $this->isCron = TRUE;
        break;
      }
    }
    return $this->isCron;
  }

  public function applyTranslations(Job $job, $data = null)
  {
    $tmgmtData = \Drupal::service('tmgmt.data');
    $job->addTranslatedData($tmgmtData->unflatten($data));
  }

  /**
   * Batch 'finished' callback.
   */
  public static function batchFinished($success, $results, $operations)
  {
    if ($success) {
      \Drupal::messenger()->addMessage(t('Translation request processed successfully.'));
    } else {
      \Drupal::messenger()->addError(t('Translation request failed to process. Please check the logs for more information.'));
    }
  }
}
