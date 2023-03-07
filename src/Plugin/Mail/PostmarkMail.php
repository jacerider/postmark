<?php

namespace Drupal\postmark\Plugin\Mail;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\postmark\PostmarkHandler;
use Html2Text\Html2Text;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Postmark\Models\PostmarkAttachment;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Modify the Drupal mail system to use Postmark when sending emails.
 *
 * @Mail(
 *   id = "postmark_mail",
 *   label = @Translation("Postmark mailer"),
 *   description = @Translation("Sends the message using Postmark.")
 * )
 */
class PostmarkMail implements MailInterface, ContainerFactoryPluginInterface {

  /**
   * Configuration object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Postmark handler.
   *
   * @var \Drupal\postmark\PostmarkHandler
   */
  protected $postmarkHandler;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a Postmark mailer.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $settings
   *   The configuration settings.
   * @param \Psr\Log\LoggerInterface $logger
   *   The core logger service.
   * @param \Drupal\postmark\PostmarkHandler $postmark_handler
   *   The Postmark handler.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(ImmutableConfig $settings, LoggerInterface $logger, PostmarkHandler $postmark_handler, RendererInterface $renderer) {
    $this->config = $settings;
    $this->logger = $logger;
    $this->postmarkHandler = $postmark_handler;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('config.factory')->get('postmark.settings'),
      $container->get('logger.factory')->get('postmark'),
      $container->get('postmark.mail_handler'),
      $container->get('renderer')
    );
  }

  /**
   * Concatenate and wrap the e-mail body for either plain-text or HTML e-mails.
   *
   * @param array $message
   *   A message array, as described in hook_mail_alter().
   *
   * @return array
   *   The formatted $message.
   */
  public function format(array $message) {
    $module = $message['module'];
    $key = $message['key'];
    $to = $message['to'];
    $subject = $message['subject'];
    $body = $message['body'];

    if (is_array($body) && !isset($body['#type']) && !isset($body['#theme'])) {
      // We always send HTML e-mails. Prepare strings as HTML.
      foreach ($body as $key => &$item) {
        if (is_string($item)) {
          $item = [
            '#markup' => implode('', array_map(function ($line) use ($message) {
              return check_markup($line, 'plain_text', $message['langcode']);
            }, explode("\n", $item))),
          ];
        }
        elseif ($item instanceof Markup) {
          $item = [
            '#markup' => $item,
          ];
        }
      }
    }

    $body = [
      '#theme' => 'postmark_message',
      '#module' => $module,
      '#key' => $key,
      '#recipient' => $to,
      '#subject' => $subject,
      '#body' => $body,
      '#cta_text' => $message['params']['cta_text'] ?? '',
      '#cta_url' => $message['params']['cta_url'] ?? '',
      '#primary_color' => '',
      '#secondary_color' => '',
    ];

    $body = $this->renderer->renderPlain($body);
    $message['body'] = $body;
    $message['headers']['Content-Type'] = 'text/html';

    return $message;
  }

  /**
   * Send the e-mail message.
   *
   * @param array $message
   *   A message array, as described in hook_mail_alter().
   *   $message['params'] may contain additional parameters.
   *
   * @return bool
   *   TRUE if the mail was successfully accepted or queued, FALSE otherwise.
   *
   * @see drupal_mail()
   */
  public function mail(array $message) {
    // Build the Postmark message array.
    $postmark_message = [
      'from' => $message['from'],
      'to' => $message['to'],
      'subject' => $message['subject'],
      'html' => $message['body'],
    ];

    if (isset($message['plain'])) {
      $postmark_message['text'] = $message['plain'];
    }
    else {
      $converter = new Html2Text($message['body']);
      $postmark_message['text'] = $converter->getText();
    }

    // Add Cc / Bcc headers.
    if (!empty($message['headers']['Cc'])) {
      $postmark_message['cc'] = $message['headers']['Cc'];
    }
    if (!empty($message['headers']['Bcc'])) {
      $postmark_message['bcc'] = $message['headers']['Bcc'];
    }

    // Add Reply-To as header according to Postmark API.
    if (!empty($message['reply-to'])) {
      $postmark_message['reply-to'] = $message['reply-to'];
    }

    // Make sure the files provided in the attachments array exist.
    if (!empty($message['params']['attachments'])) {
      $attachments = [];
      foreach ($message['params']['attachments'] as $attachment) {
        if (file_exists($attachment['filepath']) && !empty($attachment['filename'])) {
          $mime = $attachment['filemime'] ?? NULL;
          $attachments[] = PostmarkAttachment::fromFile($attachment['filepath'], $attachment['filename'], $mime);
        }
      }

      if (count($attachments) > 0) {
        $postmark_message['attachments'] = $attachments;
      }
    }

    return $this->postmarkHandler->sendMail($postmark_message);
  }

}
