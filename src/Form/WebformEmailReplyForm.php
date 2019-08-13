<?php

/**
 * @file
 * Contains \Drupal\webform_email_reply\Form\WebformEmailReplyForm.
 */

namespace Drupal\webform_email_reply\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\webform\WebformRequestInterface;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\file\Entity\File;
use Drupal\Core\Url;

class WebformEmailReplyForm extends FormBase {

  /**
   * A webform submission.
   *
   * @var \Drupal\webform\WebformSubmissionInterface
   */
  protected $webformSubmission;

  /**
   * The source entity.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $entity;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'webform_email_reply_form';
  }

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
   * Constructs a WebformResultsResendForm object.
   *
   * @param \Drupal\webform\WebformRequestInterface $request_handler
   *   The webform request handler.
   */
  public function __construct(WebformRequestInterface $request_handler, AccountInterface $current_user) {
    $this->requestHandler = $request_handler;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('webform.request'),
      $container->get('current_user')
    );
  }

  /**
   * Check that webform submission resend access check.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   A webform submission.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Run access checks for this account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public static function checkReplyAccess(WebformSubmissionInterface $webform_submission, AccountInterface $account) {
    if ($webform_submission->getWebform()->hasMessageHandler()) {
      if ($account->hasPermission('send email replies to all webforms')
        || $account->hasPermission('send email replies to own webforms')) {
        return AccessResult::allowed();
      }
    }
    return AccessResult::forbidden();
  }

  public function buildForm(array $form, FormStateInterface $form_state, $node = NULL, WebformSubmissionInterface $webform_submission = NULL) {
    $this->webformSubmission = $webform_submission;

    // Prepopulate values.
    $user = $this->currentUser;
    $default_from_email = \Drupal::config('system.site')->get('mail');
    $title = $webform_submission->getWebform()->label();
    $webform_id = $webform_submission->getWebform()->id();
    $sid = $webform_submission->id();

    // Only display link if there are replies.
    $replies = webform_email_reply_get_replies($webform_id, $sid);
    $replies_count = count($replies);

    if ($replies_count) {
      $form['previous_replies'] = [
        '#type' => 'link',
        '#title' => \Drupal::translation()->formatPlural($replies_count, '1 previous reply', '@count previous replies'),
        '#url' => Url::fromRoute('webform_email_reply.previous', ['webform' => $webform_id, 'webform_submission' => $sid]),
      ];
    }

    $form['#tree'] = TRUE;
    $form['details']['webform_id'] = [
      '#type' => 'value',
      '#value' => $webform_id,
    ];
    $form['details']['sid'] = [
      '#type' => 'value',
      '#value' => $sid,
    ];
    $form['details']['from_address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('From'),
      '#default_value' => $default_from_email,
      '#description' => $this->t('The email address to send from.'),
      '#required' => TRUE,
    ];
    $form['details']['email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email'),
      '#description' => $this->t('The email address(es) to send to. Multiple emails should be separated by a comma, with no spaces.'),
      '#required' => TRUE,
    ];
    $form['details']['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#default_value' => $this->t('RE: @title', [
        '@title' => strip_tags($title)
        ]),
      '#required' => TRUE,
    ];
    $form['details']['message'] = [
      '#type' => 'webform_html_editor',
      '#title' => $this->t('Message'),
      '#required' => TRUE,
    ];
    $form['details']['attachement'] = [
      '#type' => 'managed_file',
      '#title' => $this->t("Attachment"),
      '#upload_location' => 'public://webform_email_reply/',
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send'),
    ];

    // Add submission navigation.
    $source_entity = $this->requestHandler->getCurrentSourceEntity('webform_submission');
    $form['navigation'] = [
      '#type' => 'webform_submission_navigation',
      '#webform_submission' => $webform_submission,
      '#weight' => -20,
    ];
    $form['information'] = [
      '#type' => 'webform_submission_information',
      '#webform_submission' => $webform_submission,
      '#source_entity' => $source_entity,
      '#weight' => -19,
    ];
    $form['#attached']['library'][] = 'webform/webform.admin';
    $form['#attached']['library'][] = 'webform/webform.element.html_editor';
    return $form;
  }

  public function validateForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {
    $from_email = $form_state->getValue(['details', 'from_address']);
    if (!valid_email_address($from_email)) {
      $form_state->setErrorByName('details][from_address', $this->t('The from email address, @email, is not valid. Please enter a valid email address.', [
        '@email' => $from_email
        ]));
    }
    $valid_email = explode(',', $form_state->getValue(['details', 'email']));
    foreach ($valid_email as $email) {
      if (!valid_email_address($email)) {
        $form_state->setErrorByName('details][email', $this->t('The email address, @email, is not valid. Please enter a valid email address.', [
          '@email' => $email
          ]));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {

    $webform_id = $form_state->getValue(['details', 'webform_id']);
    $sid = $form_state->getValue(['details', 'sid']);

    $emails = explode(',', $form_state->getValue(['details', 'email']));
    $body = $form_state->getValue(['details', 'message']);
    $subject = $form_state->getValue(['details', 'subject']);
    $file = $form_state->getValue(['details', 'attachement']);

    $params = [
      'body' => $body,
      'subject' => $subject,
    ];

    $from_address = $form_state->getValue(['details', 'from_address']);
    $params['from'] = $from_address;

    // Data for saving in schema.
    $data = $form_state->getValue(['details']);
    // Saving files permanently.
    if (isset($file[0])) {
      $file = File::load($file[0]);
      $file->setPermanent();
      $file->save();
      $data['fid'] = $file->id();

      $params['attachments'][] = [
        'filecontent' => file_get_contents($file->getFileUri()),
        'filename' => $file->getFilename(),
        'filemime' => $file->getMimeType(),
        'filepath' => \Drupal::service('file_system')->realpath($file->getFileUri()),
        '_uri' => file_create_url($file->getFileUri()),
      ];
      $params['headers'] = [
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/html; charset=UTF-8; format=flowed; delsp=yes',
        'Content-Transfer-Encoding' => '8Bit',
        'X-Mailer' => 'Drupal',
      ];
    }

    // Send each emails individually.
    foreach ($emails as $email) {
      $mail_sent = \Drupal::service('plugin.manager.mail')->mail('webform_email_reply', 'email', $email, $this->currentUser->getPreferredLangcode(), $params, NULL, TRUE);
      if ($mail_sent) {
        \Drupal::messenger()->addMessage($this->t('Reply email sent to @email from @from_address.', [
          '@email' => $email,
          '@from_address' => $from_address,
        ]));

        // Insert the values into the database.
        webform_email_reply_insert($data);
      }
      else {
        \Drupal::messenger()->addError($this->t('There was an error sending the email to @email, please contact the site admin.', [
          '@email' => $email
        ]));
      }
    }
  }

}
?>
