<?php

declare(strict_types=1);

namespace Drupal\niftybot_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\niftybot_core\Service\FaqContentService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Support and FAQ pages for traders.
 */
class SupportController extends ControllerBase {

  public function __construct(
    protected FaqContentService $faqContent,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('niftybot_core.faq_content'),
    );
  }

  /**
   * Support centre page.
   */
  public function support(): array {
    $site_mail = (string) $this->config('system.site')->get('mail');
    $site_name = (string) $this->config('system.site')->get('name');
    if ($site_name === '' || $site_name === 'Drush Site-Install') {
      $site_name = 'GrassRed';
    }

    return [
      '#theme' => 'niftybot_support',
      '#site_name' => $site_name,
      '#support_email' => $site_mail !== '' ? $site_mail : 'support@grassred.com',
      '#quick_links' => [
        [
          'title' => $this->t('FAQ'),
          'description' => $this->t('Answers to common questions about trading, wallet, and KYC.'),
          'route' => 'niftybot_core.faq',
          'icon' => 'bi-question-circle',
        ],
        [
          'title' => $this->t('KYC verification'),
          'description' => $this->t('Submit or check the status of your identity verification.'),
          'route' => 'niftybot_user.kyc_submit',
          'icon' => 'bi-shield-check',
        ],
        [
          'title' => $this->t('Wallet'),
          'description' => $this->t('Deposit funds, request withdrawals, and view transaction history.'),
          'route' => 'niftybot_user.wallet',
          'icon' => 'bi-wallet2',
        ],
        [
          'title' => $this->t('My profile'),
          'description' => $this->t('Update your contact details and account information.'),
          'route' => 'niftybot_user.profile',
          'icon' => 'bi-person',
        ],
      ],
      '#attached' => [
        'library' => ['niftybot_core/support'],
      ],
    ];
  }

  /**
   * Frequently asked questions page.
   */
  public function faq(): array {
    return [
      '#theme' => 'niftybot_faq',
      '#faq_sections' => $this->faqContent->getSections(),
      '#attached' => [
        'library' => ['niftybot_core/support'],
      ],
    ];
  }

}
