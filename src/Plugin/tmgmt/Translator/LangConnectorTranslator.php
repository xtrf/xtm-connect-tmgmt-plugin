<?php

namespace Drupal\tmgmt_lang_connector\Plugin\tmgmt\Translator;

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
 * LangConnector translator plugin.
 *
 * @TranslatorPlugin(
 *   id = "lang_connector",
 *   label = @Translation("Lang Connector"),
 *   description = @Translation("Lang Connector Translator service."),
 *   ui = "Drupal\tmgmt_lang_connector\LangConnectorTranslatorUi",
 *   logo = "icons/lang_connector_logo.png"
 * )
 */
class LangConnectorTranslator extends TranslatorPluginBase implements ContainerFactoryPluginInterface, ContinuousTranslatorInterface
{

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  protected $escapeStart = '<lang_connector translate="no">';

  /**
   * {@inheritdoc}
   */
  protected $escapeEnd = '</lang_connector>';

  /**
   * Name of parameter that contains source string to be translated.
   *
   * @var string
   */
  protected static string $qParamName = 'text';

  /**
   * Max number of text queries for translation sent in one request.
   *
   * @var int
   */
  protected int $qChunkSize = 5;

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
  protected string $translatorUrl = 'https://api.locale.to';

  /**
   * Translation usage service URL.
   *
   * @var string
   */
  protected string $translatorUsageUrl = 'https://api.locale.to';

  /**
   * Translation glossary service URL.
   *
   * @var string
   */
  protected string $translatorGlossaryUrl = 'https://api.locale.to';

  /**
   * Constructs a LangConnectorProTranslator object.
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
      $container->get('queue')->get('lang_connector_translate_worker', TRUE),
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
    if ($translator->getSetting('auth_key')) {
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
    $this->requestJobItemsTranslation($job->getItems());
    if (!$job->isRejected()) {
      $job->submitted('The translation job has been submitted.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultRemoteLanguagesMappings(): array
  {
    return [
      'ar' => 'AR',
      'bg' => 'BG',
      'cs' => 'CS',
      'da' => 'DA',
      'de' => 'DE',
      'el' => 'EL',
      'en' => 'EN',
      'es' => 'ES',
      'et' => 'ET',
      'fi' => 'FI',
      'fr' => 'FR',
      'hu' => 'HU',
      'id' => 'ID',
      'it' => 'IT',
      'ja' => 'JA',
      'ko' => 'KO',
      'lt' => 'LT',
      'lv' => 'LV',
      'nb' => 'NB',
      'nl' => 'NL',
      'pl' => 'PL',
      'pt-br' => 'PT-BR',
      'pt-pt' => 'PT-PT',
      'ro' => 'RO',
      'ru' => 'RU',
      'sk' => 'SK',
      'sl' => 'SL',
      'sv' => 'SV',
      'tr' => 'TR',
      'uk' => 'UK',
      'zh' => 'ZH',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedRemoteLanguages(TranslatorInterface $translator): array
  {
    // Pre-defined array of available languages.
    return [
      'AR' => $this->t('Arabic'),
      'BG' => $this->t('Bulgarian'),
      'CS' => $this->t('Czech'),
      'DA' => $this->t('Danish'),
      'DE' => $this->t('German'),
      'EL' => $this->t('Greek'),
      'EN-GB' => $this->t('English (British)'),
      'EN-US' => $this->t('English (American)'),
      'EN' => $this->t('English'),
      'ES' => $this->t('Spanish'),
      'ET' => $this->t('Estonian'),
      'FI' => $this->t('Finnish'),
      'FR' => $this->t('French'),
      'HU' => $this->t('Hungarian'),
      'ID' => $this->t('Indonesian'),
      'IT' => $this->t('Italian'),
      'JA' => $this->t('Japanese'),
      'KO' => $this->t('Korean'),
      'LT' => $this->t('Lithuanian'),
      'LV' => $this->t('Latvian'),
      'NB' => $this->t('Norwegian (Bokmål)'),
      'NL' => $this->t('Dutch'),
      'PL' => $this->t('Polish'),
      'PT-PT' => $this->t('Portuguese (excluding Brazilian Portuguese)'),
      'PT-BR' => $this->t('Portuguese (Brazilian)'),
      'PT' => $this->t('Portuguese (deprecated, select PT-PT or PT-BR instead)'),
      'RO' => $this->t('Romanian'),
      'RU' => $this->t('Russian'),
      'SK' => $this->t('Slovak'),
      'SL' => $this->t('Slovenian'),
      'SV' => $this->t('Swedish'),
      'TR' => $this->t('Turkish'),
      'UK' => $this->t('Ukrainian'),
      'ZH' => $this->t('Chinese (simplified)'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedTargetLanguages(TranslatorInterface $translator, $source_language): array
  {
    $languages = $this->getSupportedRemoteLanguages($translator);

    // There are no language pairs, any supported language can be translated
    // into the others. If the source language is part of the languages,
    // then return them all, just remove the source language.
    if (array_key_exists($source_language, $languages)) {
      unset($languages[$source_language]);
      return $languages;
    }

    return [];
  }

  /**
   * Source language mapping, cause not all sources as supported as target.
   *
   * @param string $source_lang
   *   The selected source language of the job item.
   *
   * @return string
   *   Fixed language mapping based on LangConnector specification.
   */
  public static function fixSourceLanguageMappings(string $source_lang): string
  {
    $language_mapping = [
      'EN-GB' => 'EN',
      'EN-US' => 'EN',
      'PT-BR' => 'PT',
      'PT-PT' => 'PT',
    ];

    if (isset($language_mapping[$source_lang])) {
      return $language_mapping[$source_lang];
    }

    return $source_lang;
  }

  /**
   * {@inheritdoc}
   */
  public function hasCheckoutSettings(JobInterface $job): bool
  {
    // Defaults to not having checkout settings.
    $has_checkout_settings = FALSE;

    // Allow alteration of hasCheckoutSettings.
    \Drupal::moduleHandler()->alter('tmgmt_lang_connector_has_checkout_settings', $has_checkout_settings, $job);

    return $has_checkout_settings;
  }

  /**
   * Local method to do request to LangConnector Translate service.
   *
   * @param \Drupal\tmgmt\Entity\Job $job
   *   TMGMT Job to be used for translation.
   * @param array $query_params
   *   (Optional) Additional query params to be passed into the request.
   * @param array $options
   *   (Optional) Additional options that will passed to drupal_http_request().
   *
   * @return array
   *   Unserialized JSON response from LangConnector API.
   *
   * @throws \Drupal\tmgmt\TMGMTException|\GuzzleHttp\Exception\GuzzleException
   *   - Unable to connect to the LangConnector API Service
   *   - Error returned by the LangConnector API Service.
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
      'job_id' => $jobId,
      'source_lang' => $query_params['source_lang'],
      'target_lang' => $query_params['target_lang'],
    ]);

    // Allow alteration of query string.
    \Drupal::moduleHandler()->alter('tmgmt_lang_connector_query_string', $job, $payload, $query_params);

    // Build request object.
    $request = new Request('POST', $url, $headers, json_encode($payload));

    // Send the request with the query.
    try {
      $response = \Drupal::httpClient()->send($request);
    } catch (RequestException $e) {
      if ($e->hasResponse()) {
        $response = $e->getResponse();
        if ($response instanceof ResponseInterface) {
          throw new TMGMTException('LangConnector API service returned following error: @error', ['@error' => $response->getReasonPhrase()]);
        }
      } else {
        throw new TMGMTException('LangConnector API service returned following error: @error', ['@error' => $e->getMessage()]);
      }
    }

    // Process the JSON result into array.
    if ($response instanceof ResponseInterface) {
      /** @var array $return */
      $return = json_decode($response->getBody(), TRUE);
      return $return;
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
  public function requestJobItemsTranslation(array $job_items): void
  {
    $job_item = reset($job_items);
    if ($job_item instanceof JobItemInterface) {
      /** @var \Drupal\tmgmt\Entity\Job $job */
      $job = $job_item->getJob();
      if ($job->isContinuous()) {
        $job_item->active();
      }
      // Pull the source data array through the job and flatten it.
      $data = $this->tmgmtData->filterTranslatable($job_item->getData());

      $translation = [];
      $q = [];
      $keys_sequence = [];

      // Build LangConnector API q param and preserve initial array keys.
      foreach ($data as $key => $value) {
        $q[] = $this->escapeText($value);
        $keys_sequence[] = $key;
      }

      // Use the Queue Worker if running via tmgmt_cron.
      if ($this->isCron()) {
        $this->queue->createItem([
          'job' => $job,
          'job_item' => $job_item,
          'q' => $q,
          'translation' => $translation,
          'keys_sequence' => $keys_sequence,
        ]);
      } else {
        $operations = [];
        $batch = [
          'title' => 'Translating job items',
          'finished' => [LangConnectorTranslator::class, 'batchFinished'],
        ];

        // Split $q into chunks of self::qChunkSize.
        foreach (array_chunk($q, $this->qChunkSize) as $_q) {
          // Build operations array.
          $arg_array = [$job, $_q, $translation, $keys_sequence];
          $operations[] = [
            '\Drupal\tmgmt_lang_connector\Plugin\tmgmt\Translator\LangConnectorTranslator::batchRequestTranslation',
            $arg_array,
          ];
        }

        // Add beforeBatchFinished operation.
        $arg2_array = [$job_item];
        $operations[] = [
          '\Drupal\tmgmt_lang_connector\Plugin\tmgmt\Translator\LangConnectorTranslator::beforeBatchFinished',
          $arg2_array,
        ];
        // Set batch operations.
        $batch['operations'] = $operations;
        batch_set($batch);
      }
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
  public static function batchRequestTranslation(Job $job, array $text, array $translation, array $keys_sequence, array &$context): void
  {
    // Context handling.
    if (isset($context['results']) && isset($context['results']['i']) && $context['results']['i'] != NULL) {
      $i = $context['results']['i'];
    } else {
      $i = 0;
    }

    // Get the translator.
    $translator_plugin = $job->getTranslator()->getPlugin();

    // Fix source language mapping.
    $source_lang = self::fixSourceLanguageMappings($job->getRemoteSourceLanguage());

    // Build query params.
    $query_params = [
      'source_lang' => $source_lang,
      'target_lang' => $job->getRemoteTargetLanguage(),
      'text' => $text,
    ];
    $result = self::doRequest($job, $query_params);
    // Collect translated texts with use of initial keys.
    foreach ($result['translations'] as $translated) {
      $translation[$keys_sequence[$i]]['#text'] = $translator_plugin->unescapeText(rawurldecode(Html::decodeEntities($translated['text'])));
      $i++;
    }
    if (isset($context['results']) && isset($context['results']['translation']) && $context['results']['translation'] != NULL) {
      $context['results']['translation'] = array_merge($context['results']['translation'], $translation);
    } else {
      $context['results']['translation'] = $translation;
    }
    $context['results']['i'] = $i;
  }

  /**
   * Batch 'operation' callback.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   The job item.
   * @param array $context
   *   The sandbox context.
   */
  public static function beforeBatchFinished(JobItemInterface $job_item, &$context): void
  {
    $context['results']['job_item'] = $job_item;
  }

  /**
   * Batch 'operation' callback.
   *
   * @param bool $success
   *   Batch success.
   * @param array $results
   *   Results.
   * @param array $operations
   *   Operations.
   */
  public static function batchFinished(bool $success, array $results, array $operations): void
  {
    $tmgmtData = \Drupal::service('tmgmt.data');

    if (isset($results['job_item']) && $results['job_item'] instanceof JobItemInterface) {
      $job_item = $results['job_item'];
      $job_item->addTranslatedData($tmgmtData->unflatten($results['translation']));
      $job = $job_item->getJob();
      tmgmt_write_request_messages($job);
    }
  }

  /**
   * Local method to do request to LangConnector API Usage service.
   *
   * @param \Drupal\tmgmt\Entity\Translator $translator
   *   The translator entity to get the settings from.
   *
   * @return array|int
   *   Unserialized JSON response from LangConnector API or error code.
   *
   * @throws \GuzzleHttp\Exception\BadResponseException|\GuzzleHttp\Exception\GuzzleException
   *   - Unable to connect to the LangConnector API Service
   *   - Error returned by the LangConnector API Service.
   */
  public function validateAPI(Translator $translator)
  {
    // Set custom data for testing purposes, if available.
    $url = $translator->getSetting('url');
    // Prepare Guzzle Object.
    $headers = [
      'Content-Type' => 'application/json',
      'Authorization' => $translator->getSetting('auth_key'),
    ];
    $request = new Request('GET', $url, $headers);

    try {
      $response = $this->client->send($request);
    } catch (BadResponseException $e) {
      return $e->getCode();
    }

    // Process the JSON result into array.
    /** @var array $return */
    $return = json_decode($response->getBody(), TRUE);
    return $return;
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
}
