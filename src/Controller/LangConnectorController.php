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
  public function applyTranslations(Request $request)
  {
    $jobId = $request->get('jobId');
    $data = array("success" => true, "jobId" => $jobId);
    $response = new JsonResponse($data);
    return $response;
  }

  public function getJobItems(Request $request, $jobId)
  {
    // $jobId = $request->get('jobId');
    $job = Job::load($jobId);
    $response = new JsonResponse($job->getData());
    return $response;
  }

}
