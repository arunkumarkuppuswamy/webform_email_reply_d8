<?php /**
 * @file
 * Contains \Drupal\webform_email_reply\Controller\DefaultController.
 */

namespace Drupal\webform_email_reply\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\webform\WebformRequestInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\file\Entity\File;
use Drupal\Core\Url;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Default controller for the webform_email_reply module.
 */
class DefaultController extends ControllerBase {
  /**
   * A webform submission.
   *
   * @var \Drupal\webform\WebformSubmissionInterface
   */
  protected $webformSubmission;

  /**
   * Webform request handler.
   *
   * @var \Drupal\webform\WebformRequestInterface
   */
  protected $requestHandler;

  /**
   * Current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $current_user;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs a WebformResultsResendForm object.
   *
   * @param \Drupal\webform\WebformRequestInterface $request_handler
   *   The webform request handler.
   */
  public function __construct(WebformRequestInterface $request_handler, AccountInterface $current_user, DateFormatterInterface $date_formatter) {
    $this->requestHandler = $request_handler;
    $this->currentUser = $current_user;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('webform.request'),
      $container->get('current_user'),
      $container->get('date.formatter')
    );
  }

  /**
   * Check that webform submission view permisiion.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   A webform submission.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Run access checks for this account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public static function checkReplyAccess(AccountInterface $account) {
    if ($account->hasPermission('send email replies to all webforms')
      || $account->hasPermission('send email replies to own webforms')) {
      return AccessResult::allowed();
    }
    return AccessResult::forbidden();
  }

  public function webform_email_reply_previous($webform, $webform_submission) {

    // Set the header.
    $header = [
      ['data' => t('#'), 'field' => 'eid', 'sort' => 'desc',],
      ['data' => t('Sent by')],
      ['data' => t('Sent at'), 'field' => 'replied', ],
      ['data' => t('Message')],
      ['data' => t('Attachment')],
    ];

    // Get the submissions.
    $replies = webform_email_reply_get_replies($webform, $webform_submission);
    $rows = [];
    foreach ($replies as $key => $reply) {
      $row = [];
      $row['eid'] = ++$key;
      $row['from'] = $reply->from_address;
      $row['replied'] = $this->dateFormatter->format($reply->replied, 'short');
      $row['message'] = $reply->message;
      $file_display = 'none';
      if ($reply->fid) {
        $file = File::load($reply->fid);
        $uri = file_create_url($file->getFileUri());
        // $file_display = [
        //   '#type' => 'link',
        //   '#title' => $this->t('View'),
        //   '#url' => $uri,
        // ];
      }
      $row['attachment'] = $file_display;
      $rows[] = $row;
    }
    $output = [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
    ];
    $output['pager'] = ['#type' => 'pager'];
    return $output;
  }

}
