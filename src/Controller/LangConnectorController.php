<?php /**
  * @file
  * Contains \Drupal\tmgmt_lang_connector\Controller\LangConnectorController.
  */

namespace Drupal\tmgmt_lang_connector\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\tmgmt\Entity\Job;

/**
 * Route controller class for the tmgmt_lang_connector module.
 */
class LangConnectorController extends ControllerBase
{
  /**
   * Provides a callback function for tmgmt_lang_connector translator.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to handle.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response to return.
   */
  public function applyTranslations(Request $request, $jobId)
  {
    $job = Job::load($jobId);
    $requestData = json_decode($request->getContent(), true);
    $translator_plugin = $job->getTranslatorPlugin();
    $translator_plugin->applyTranslations($job, $requestData);
    $data = array("success" => true, "jobId" => $jobId);
    $response = new JsonResponse($data);
    return $response;
  }

  public function getJobItems(Request $request, $jobId)
  {
    $job = Job::load($jobId);
    $job_items = $job->getItems();
    $job_item_list = [];
    foreach ($job_items as $job_item) {
      if (!$job_item->isActive()) continue;
      $resource_id = $job_item->getItemId();
      $entity = \Drupal::entityTypeManager()->getStorage("node")->load($resource_id);
      $job_item_list[] = [
        'resource_id' => $resource_id,
        'resource_type' => $entity->bundle(),
        'job_item_id' => $job_item->id(),
        'content' => $job_item->getData(),
      ];
    }
    return new JsonResponse($job_item_list);
  }

}
